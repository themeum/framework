<?php
/**
 * Fluent route registrar for WordPress REST and front-end site routes.
 * Resolves controller actions via reflection and dependency injection from the container.
 * REST routes register on rest_api_init; site routes register via SiteRouter on init.
 *
 * @package Framework
 * @since   1.0.0
 */
namespace Framework;

defined('ABSPATH') || exit;

use Closure;
use Framework\Contracts\Request as RequestContract;
use Framework\Database\Query\Model;
use Exception;
use Framework\Collections\Collection;
use Framework\Contracts\Middleware;
use Framework\Exceptions\AuthorizationException;
use Framework\Exceptions\HttpException;
use Framework\Exceptions\InvalidMiddlewareException;
use Framework\Exceptions\InvalidRoutActionException;
use Framework\Exceptions\ModelNotFoundException;
use Framework\Http\Request;
use Framework\Routing\CurrentRoute;
use Framework\Routing\RouteParser;
use Framework\Routing\SiteRouter;
use Framework\View\View;
use Framework\Wordpress\Constants\HookNames;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use WP_Error;
use WP_REST_Request;

use function Framework\app;
use function Framework\Polyfill\array_first;
use function Framework\Polyfill\array_last;

class Route
{
    /**
     * Routing method: real WP rewrite rules.
     *
     * @since 1.0.0
     */
    public const ROUTING_REWRITE_RULES = 'rewrite_rules';

    /**
     * Routing method: match the request path directly on parse_request.
     *
     * @since 1.0.0
     */
    public const ROUTING_PARSE_REQUEST = 'parse_request';

    /**
     * Match a route against the current request path.
     *
     * @since 1.0.0
     */
    public const MATCH_PATH = 'path';

    /**
     * Match a route against an existing WordPress Page via is_page().
     *
     * @since 1.0.0
     */
    public const MATCH_PAGE = 'page';

    /**
     * REST API namespace.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected static $namespace = '';

    /**
     * Site route namespace.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected static $site_namespace = '';

    /**
     * Global site routing method.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected static $routing_method = self::ROUTING_PARSE_REQUEST;

    /**
     * Default WordPress hook used to dispatch site routes.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected static $default_hook_name = HookNames::TEMPLATE_INCLUDE;

    /**
     * Array of registered routes.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected static $routes = [];

    /**
     * Map of route name to route instance.
     *
     * @var array<string, Route>
     *
     * @since 1.0.0
     */
    protected static $named_routes = [];

    /**
     * Group stack to hold the group options.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected static $group_stack = [];

    /**
     * Middleware classes registered under a short alias, keyed by alias.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected static $middleware_aliases = [];

    /**
     * Shared SiteRouter instance for URL generation and flush.
     *
     * @var SiteRouter|null
     *
     * @since 1.0.0
     */
    protected static $site_router = null;

    /**
     * HTTP method for the route.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected $method;

    /**
     * The endpoint path for the route.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected $endpoint;

    /**
     * Controller class and method for handling the route.
     *
     * @var array|Closure|null
     *
     * @since 1.0.0
     */
    protected $action;

    /**
     * Array of middleware classes.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected $middlewares = [];

    /**
     * Regex patterns from where().
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected $patterns = [];

    /**
     * Callable param validators from where().
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected $param_validators = [];

    /**
     * Parsed URI segments.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected $segments = [];

    /**
     * Param types from inline syntax and where().
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected $param_types = [];

    /**
     * Array of class instances.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected static $instances = [];

    /**
     * The resolved request.
     *
     * @var Request|null
     *
     * @since 1.0.0
     */
    protected $resolved_request;

    /**
     * The REST request the cached permission verdict below belongs to.
     *
     * WordPress core re-invokes a matched route's permission_callback a second time for the same
     * request object when it builds the Allow response header (rest_send_allow_header(), hooked on
     * rest_post_dispatch), independently of whether the request succeeded. Middleware with a side
     * effect, such as the rate limiter, would otherwise be charged twice for one client request.
     *
     * @var WP_REST_Request|null
     *
     * @since 1.0.0
     */
    protected $permission_checked_request;

    /**
     * The permission verdict cached for permission_checked_request above.
     *
     * @var bool|WP_Error|null
     *
     * @since 1.0.0
     */
    protected $permission_result;

    /**
     * Whether routes are being registered inside Route::site().
     *
     * @var bool
     *
     * @since 1.0.0
     */
    protected static $with_site_route = false;

    /**
     * Whether the route is a site route.
     *
     * @var bool
     *
     * @since 1.0.0
     */
    protected $is_site_route = false;

    /**
     * Route name for URL generation.
     *
     * @var string|null
     *
     * @since 1.0.0
     */
    protected $name = null;

    /**
     * How the site route is matched.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected $match_using = self::MATCH_PATH;

    /**
     * Dispatch hook name for site routes.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected $hook_name;

    /**
     * Dispatch hook priority for site routes.
     *
     * @var int
     *
     * @since 1.0.0
     */
    protected $hook_priority = 10;

    /**
     * Route-level redirect configuration.
     *
     * @var array{url:string,status:int}|null
     *
     * @since 1.0.0
     */
    protected $redirect = null;

    /**
     * Route-level template path.
     *
     * @var string|null
     *
     * @since 1.0.0
     */
    protected $template = null;

    /**
     * Extra data attached to the route.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected $with_data = [];

    /**
     * Whether views returned by this route use layout wrapping.
     *
     * @var bool
     *
     * @since 1.0.0
     */
    protected $with_layout = true;

    /**
     * Set the API namespace for all registered routes.
     *
     * @param string $namespace The namespace for REST API routes.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public static function set_namespace(string $namespace)
    {
        static::$namespace = $namespace;
    }

    /**
     * Set the site route namespace for all registered routes.
     *
     * @param string $namespace The namespace for site routes.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public static function set_site_namespace(string $namespace)
    {
        static::$site_namespace = $namespace;
    }

    /**
     * Get the site route namespace.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public static function get_site_namespace()
    {
        return static::$site_namespace !== '' ? static::$site_namespace : 'siteroute';
    }

    /**
     * Choose how site requests are matched to routes.
     *
     * @param string $method static::ROUTING_REWRITE_RULES or static::ROUTING_PARSE_REQUEST.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public static function set_routing_method(string $method)
    {
        static::$routing_method = $method === static::ROUTING_PARSE_REQUEST
            ? static::ROUTING_PARSE_REQUEST
            : static::ROUTING_REWRITE_RULES;
    }

    /**
     * Get the site routing method.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public static function get_routing_method()
    {
        return static::$routing_method;
    }

    /**
     * Set the default WordPress hook used to dispatch site routes.
     *
     * @param string $hook HookNames::TEMPLATE_REDIRECT or HookNames::TEMPLATE_INCLUDE.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public static function set_default_hook(string $hook)
    {
        static::$default_hook_name = $hook === HookNames::TEMPLATE_INCLUDE
            ? HookNames::TEMPLATE_INCLUDE
            : HookNames::TEMPLATE_REDIRECT;
    }

    /**
     * Bind the active SiteRouter instance used for URL generation and flush.
     *
     * @param SiteRouter $router The site router.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public static function set_site_router(SiteRouter $router)
    {
        static::$site_router = $router;
    }

    /**
     * Get the active SiteRouter instance.
     *
     * @return SiteRouter|null
     *
     * @since 1.0.0
     */
    public static function get_site_router()
    {
        return static::$site_router;
    }

    /**
     * Force a rewrite rule flush. Call from an activation hook only.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public static function flush()
    {
        if (static::$site_router !== null) {
            static::$site_router->flush();
            return;
        }

        $router = new SiteRouter(static::get_site_namespace(), static::$routing_method);
        $router->boot(static::get_site_routes());
        $router->flush();
    }

    /**
     * Build an absolute URL for a named site route.
     *
     * @param string $name Named route.
     * @param array $params Path param values.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public static function site_url(string $name, array $params = [])
    {
        if (static::$site_router !== null) {
            return static::$site_router->url($name, $params);
        }

        $router = new SiteRouter(static::get_site_namespace(), static::$routing_method);

        return $router->url($name, $params);
    }

    /**
     * Find a named route instance.
     *
     * @param string $name The route name.
     *
     * @return Route|null
     *
     * @since 1.0.0
     */
    public static function find_named_route(string $name)
    {
        return static::$named_routes[$name] ?? null;
    }

    /**
     * Set the currently dispatching site route context.
     *
     * @param string|null $name Route name.
     * @param array $params Route params.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public static function set_current_route($name, array $params = [])
    {
        CurrentRoute::set($name, $params);
    }

    /**
     * Whether the currently dispatching route is the one named $name.
     *
     * @param string $name Route name.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public static function is(string $name)
    {
        return CurrentRoute::is($name);
    }

    /**
     * Get a single param from the currently dispatching route.
     *
     * @param string $key Param name.
     * @param mixed $default Fallback when missing.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public static function route_param(string $key, $default = null)
    {
        return CurrentRoute::param($key, $default);
    }

    /**
     * Get all params for the currently dispatching route.
     *
     * @param mixed $default Fallback when no params are available.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public static function route_params($default = [])
    {
        return CurrentRoute::params($default);
    }

    /**
     * Get all registered site routes.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public static function get_site_routes()
    {
        return array_values(array_filter(static::$routes, function (Route $route) {
            return $route->is_site_route();
        }));
    }

    /**
     * Get the API namespace.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public static function get_namespace()
    {
        return static::$namespace;
    }

    /**
     * Get the URL for a specific route.
     *
     * @param string $path The route path.
     *
     * @return string The URL for the route.
     *
     * @since 1.0.0
     */
    public static function url(string $path)
    {
        return rest_url('/' . static::$namespace . '/' . $path);
    }

    /**
     * Attach middleware to the current route.
     *
     * @param string|array $middleware The fully qualified class name of the middleware.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function middleware($middleware)
    {
        if (is_array($middleware)) {
            $this->middlewares = array_merge($this->middlewares, $middleware);

            return $this;
        }

        $this->middlewares[] = $middleware;

        return $this;
    }

    /**
     * Rate limit the current route.
     *
     * Sugar over the throttle middleware, so that a limit can be declared without writing the
     * string form. The decay is expressed in minutes, matching the string form and Laravel.
     *
     * @param int|string $max_attempts The number of attempts allowed per decay window, or the
     *                                 name of a registered rate limiter.
     * @param int $decay_minutes The length of the window in minutes. Ignored when a limiter name
     *                           is given, because the limiter carries its own window.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function throttle($max_attempts, int $decay_minutes = 1)
    {
        if (!is_numeric($max_attempts)) {
            return $this->middleware('throttle:' . $max_attempts);
        }

        return $this->middleware(sprintf('throttle:%d,%d', $max_attempts, $decay_minutes));
    }

    /**
     * Set a regex pattern or callable validator for the specific route param.
     *
     * @param string|array $name The param name, or map of param to rule.
     * @param string|callable|null $regex The regex, type keyword, or callable validator.
     *
     * @return static
     *
     * @since 1.0.0
     */
    public function where($name, $regex = null)
    {
        if (is_array($name)) {
            foreach ($name as $param => $rule) {
                $this->where($param, $rule);
            }

            return $this;
        }

        if (is_callable($regex)) {
            $this->param_validators[$name] = $regex;

            return $this;
        }

        $this->patterns[$name] = (string) $regex;
        $this->param_types[$name] = (string) $regex;

        return $this;
    }

    /**
     * Mark this route as a site (front-end) route.
     *
     * Prefer Route::site(Closure) for groups of site routes.
     * PHP cannot expose both Route::site(Closure) and ->site() under the same name,
     * so the fluent marker is as_site().
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function as_site()
    {
        $this->is_site_route = true;

        return $this;
    }

    /**
     * Name the route for URL generation and Route::is().
     *
     * @param string $name The route name.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function name(string $name)
    {
        $this->name = $name;
        static::$named_routes[$name] = $this;

        return $this;
    }

    /**
     * Match this site route against an existing WordPress Page.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function match_page()
    {
        $this->match_using = static::MATCH_PAGE;

        return $this;
    }

    /**
     * Choose which WordPress hook dispatches this site route.
     *
     * @param string $hook_name HookNames::TEMPLATE_REDIRECT or HookNames::TEMPLATE_INCLUDE.
     * @param int $priority WordPress hook priority.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function hook(string $hook_name, int $priority = 10)
    {
        $this->hook_name = $hook_name === HookNames::TEMPLATE_INCLUDE
            ? HookNames::TEMPLATE_INCLUDE
            : HookNames::TEMPLATE_REDIRECT;
        $this->hook_priority = $priority;

        return $this;
    }

    /**
     * Dispatch this site route on the template_redirect hook.
     *
     * @param int $priority WordPress hook priority.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function template_redirect(int $priority = 10)
    {
        return $this->hook(HookNames::TEMPLATE_REDIRECT, $priority);
    }

    /**
     * Dispatch this site route on the template_include hook.
     *
     * @param int $priority WordPress hook priority.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function template_include(int $priority = 10)
    {
        return $this->hook(HookNames::TEMPLATE_INCLUDE, $priority);
    }

    /**
     * Set a route-level redirect (used when no controller action is set).
     *
     * @param string $url Redirect target URL.
     * @param int $status HTTP redirect status code.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function redirect(string $url, int $status = 302)
    {
        $this->redirect = ['url' => $url, 'status' => $status];

        return $this;
    }

    /**
     * Set a route-level template (used when no controller action is set).
     *
     * @param string $path Theme-relative or absolute template path.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function template(string $path)
    {
        $this->template = $path;

        return $this;
    }

    /**
     * Attach extra data to the route context.
     *
     * @param array $data Extra route data.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function with(array $data)
    {
        $this->with_data = array_merge($this->with_data, $data);

        return $this;
    }

    /**
     * Enable layout wrapping for views returned by this route.
     *
     * @param bool $enabled Whether layout wrapping is enabled.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function layout($enabled = true)
    {
        $this->with_layout = (bool) $enabled;

        return $this;
    }

    /**
     * Disable layout wrapping for views returned by this route.
     *
     * Applies on template_redirect dispatch only. On template_include,
     * use View::partial() on the returned view instead.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function partial()
    {
        $this->with_layout = false;

        return $this;
    }

    /**
     * Get the endpoint in proper format that register_rest_route() expects.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function get_formatted_endpoint()
    {
        $patterns = array_merge($this->param_types, $this->patterns);

        return (new RouteParser())->format_rest_endpoint($this->endpoint, $patterns);
    }

    /**
     * New instance.
     *
     * @return static
     *
     * @since 1.0.0
     */
    protected static function new_instance()
    {
        $instance = new static();

        return $instance;
    }

    /**
     * Register a GET route.
     *
     * @param string $endpoint The route endpoint.
     * @param array|Closure|null $action The controller and method to handle the route.
     *
     * @return static
     *
     * @since 1.0.0
     */
    public static function get(string $endpoint, $action = null)
    {
        return static::new_instance()->add('get', $endpoint, $action);
    }

    /**
     * Register a POST route.
     *
     * @param string $endpoint The route endpoint.
     * @param array|Closure|null $action The controller and method to handle the route.
     *
     * @return static
     *
     * @since 1.0.0
     */
    public static function post(string $endpoint, $action = null)
    {
        return static::new_instance()->add('post', $endpoint, $action);
    }

    /**
     * Register a PUT route.
     *
     * @param string $endpoint The route endpoint.
     * @param array|Closure|null $action The controller and method to handle the route.
     *
     * @return static
     *
     * @since 1.0.0
     */
    public static function put(string $endpoint, $action = null)
    {
        return static::new_instance()->add('put', $endpoint, $action);
    }

    /**
     * Register a PATCH route.
     *
     * @param string $endpoint The route endpoint.
     * @param array|Closure|null $action The controller and method to handle the route.
     *
     * @return static
     *
     * @since 1.0.0
     */
    public static function patch(string $endpoint, $action = null)
    {
        return static::new_instance()->add('patch', $endpoint, $action);
    }

    /**
     * Register a DELETE route.
     *
     * @param string $endpoint The route endpoint.
     * @param array|Closure|null $action The controller and method to handle the route.
     *
     * @return static
     *
     * @since 1.0.0
     */
    public static function delete(string $endpoint, $action = null)
    {
        return static::new_instance()->add('delete', $endpoint, $action);
    }

    /**
     * Add a route to the routes array.
     *
     * @param string $method The HTTP method.
     * @param string $endpoint The route endpoint.
     * @param array|Closure|null $action The controller and method to handle the route.
     *
     * @return static
     *
     * @since 1.0.0
     */
    protected function add(string $method, string $endpoint, $action)
    {
        $instance = new static();
        $instance->method = $method;
        $instance->endpoint = trim($endpoint, '/');
        $instance->action = $action;
        $instance->is_site_route = static::$with_site_route;
        $instance->hook_name = static::$default_hook_name;
        $instance->hook_priority = 10;

        $parser = new RouteParser();
        $instance->segments = $parser->parse_segments($instance->endpoint);
        $instance->param_types = $parser->extract_param_types($instance->segments);

        foreach ($instance->param_types as $name => $type) {
            $instance->patterns[$name] = $parser->resolve_regex($type);
        }

        $instance->apply_group_options();

        static::$routes[] = $instance;

        return $instance;
    }

    /**
     * Register a group of site routes.
     *
     * @param Closure $callback The callback that defines the site routes.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public static function site(Closure $callback)
    {
        static::$with_site_route = true;

        $callback();

        static::$with_site_route = false;
    }

    /**
     * Register a group of routes with shared options.
     *
     * This method allows grouping routes under common configuration options
     * like middleware, or prefix. The closure receives the context
     * of the group and defines the routes within it.
     *
     * @param array $options The shared configuration options for the group.
     * @param \Closure $closure The callback that defines the grouped routes.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public static function group(array $options, Closure $closure)
    {
        static::$group_stack[] = $options;

        $closure();

        array_pop(static::$group_stack);
    }

    /**
     * Get all registered routes.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public static function get_routes()
    {
        return static::$routes;
    }

    /**
     * Apply route group options like prefix and middleware to the route.
     *
     * This method is typically called when a route is defined within a group,
     * applying any shared prefix or middleware from the group stack.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function apply_group_options()
    {
        if (empty(static::$group_stack)) {
            return;
        }

        $prefixes = [];
        $middlewares = [];

        foreach (static::$group_stack as $group) {
            if (!empty($group['prefix'])) {
                $prefixes[] = trim($group['prefix'], '/');
            }

            if (!empty($group['middleware'])) {
                $middlewares = array_merge(
                    $middlewares,
                    is_array($group['middleware']) ? $group['middleware'] : [$group['middleware']]
                );
            }
        }

        if (!empty($prefixes)) {
            $this->endpoint = trim(implode('/', $prefixes) . '/' . ltrim($this->endpoint, '/'), '/');

            $parser = new RouteParser();
            $this->segments = $parser->parse_segments($this->endpoint);
            $inline_types = $parser->extract_param_types($this->segments);
            $this->param_types = array_merge($inline_types, $this->param_types);

            foreach ($inline_types as $name => $type) {
                if (!isset($this->patterns[$name])) {
                    $this->patterns[$name] = $parser->resolve_regex($type);
                }
            }
        }

        if (!empty($middlewares)) {
            $this->middleware($middlewares);
        }
    }

    /**
     * Register the route with WordPress REST API.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function register()
    {
        if ($this->is_site_route) {
            return;
        }

        register_rest_route(static::$namespace, $this->get_formatted_endpoint(), [
            'methods' => strtoupper($this->method),
            'callback' => $this->resolve_route(),
            'permission_callback' => fn ($rest_request) => $this->resolve_permission_callback($rest_request)
        ]);
    }

    /**
     * Cache a class instance.
     *
     * @param string $abstract The class name to bind
     * @param object $instance The instance of the class
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function cache(string $abstract, $instance)
    {
        static::$instances[$abstract] = $instance;
    }

    /**
     * Check if a class instance is cached.
     *
     * @param string $abstract The class name to check
     *
     * @return bool
     *
     * @since 1.0.0
     */
    protected function is_cached(string $abstract)
    {
        return isset(static::$instances[$abstract]);
    }

    /**
     * Get a cached class instance.
     *
     * @param string $abstract The class name to get
     *
     * @return object
     *
     * @since 1.0.0
     */
    protected function get_cached(string $abstract)
    {
        return static::$instances[$abstract];
    }

    /**
     * Resolve a class and its dependencies.
     *
     * @param string $abstract The class name to resolve
     * @param array $resolving Stack of classes being resolved (for
     *
     * @return object The resolved instance
     *
     * @throws \Exception
     *
     * @since 1.0.0
     */
    protected function make(string $abstract, array $resolving = [])
    {
        if ($this->is_cached($abstract)) {
            return $this->get_cached($abstract);
        }

        if (in_array($abstract, $resolving, true)) {
            throw new Exception(sprintf('Circular dependency detected for class "%s".', $abstract));
        }

        if (!class_exists($abstract)) {
            throw new Exception(sprintf('Class "%s" does not exist.', $abstract));
        }

        $reflector = new ReflectionClass($abstract);

        if ($reflector->isAbstract()) {
            throw new Exception(sprintf('Class "%s" is abstract and cannot be instantiated.', $abstract));
        }

        $constructor = $reflector->getConstructor();

        if (!$constructor) {
            return new $abstract();
        }

        if (!$constructor->isPublic()) {
            throw new Exception(
                sprintf('Class "%s" has a non-public constructor and cannot be instantiated.', $abstract)
            );
        }

        $dependencies = [];
        $resolving[] = $abstract;

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (!$type) {
                throw new Exception(
                    sprintf(
                        'Parameter "%s" is missing a type hint in the constructor. Please add a class type hint.',
                        $parameter->getName()
                    )
                );
            }

            if ($type->isBuiltin()) {
                throw new Exception(
                    sprintf(
                        'Parameter "%s" must be a class type, not a built-in type. Please specify a valid class dependency.', // phpcs:ignore Generic.Files.LineLength.TooLong
                        $parameter->getName()
                    )
                );
            }

            $dependencies[] = $this->is_cached($type->getName())
                ? $this->get_cached($type->getName())
                : $this->make($type->getName(), $resolving);
        }

        $instance = $reflector->newInstanceArgs($dependencies);
        $this->cache($abstract, $instance);

        return $instance;
    }

    /**
     * Make the method dependencies.
     *
     * @param string $abstract The class name to make the dependencies.
     * @param string $method The method name to make the dependencies.
     *
     * @return array
     *
     * @throws \Exception
     * @throws \InvalidArgumentException
     *
     * @since 1.0.0
     */
    protected function resolve_method_dependencies($abstract, $method)
    {
        $method_reflection = new ReflectionMethod($abstract, $method);

        if (!$method_reflection->isPublic()) {
            throw new Exception(sprintf('Method "%s" is not public and cannot be called.', $method));
        }

        $dependencies = $this->categorize_parameters($method_reflection->getParameters());
        $this->assert_single_request_dependency($dependencies, $method);

        return $dependencies;
    }

    /**
     * Resolve dependency metadata from a closure route action.
     *
     * @param Closure $closure The closure route action.
     *
     * @return array
     *
     * @throws \InvalidArgumentException
     *
     * @since 1.0.0
     */
    protected function resolve_closure_dependencies(Closure $closure)
    {
        $reflection = new ReflectionFunction($closure);
        $dependencies = $this->categorize_parameters($reflection->getParameters());
        $this->assert_single_request_dependency($dependencies, 'closure');

        return $dependencies;
    }

    /**
     * Categorize reflected parameters into requests, builtins, models, and abstracts.
     *
     * @param array $parameters Reflection parameters.
     *
     * @return array
     *
     * @since 1.0.0
     */
    protected function categorize_parameters(array $parameters)
    {
        $dependencies = [
            'requests' => [],
            'builtins' => [],
            'models' => [],
            'abstracts' => [],
        ];

        foreach ($parameters as $parameter) {
            $type = $parameter->getType() ?? 'string';
            $variable = $parameter->getName();
            $position = $parameter->getPosition();

            $type_name = $type instanceof ReflectionNamedType
                ? $type->getName()
                : (string) $type;

            if ($type === 'string' || $type->isBuiltin()) {
                $dependencies['builtins'][] = $this->add_dependency($type_name, $variable, $position);
            } elseif ($type_name === Request::class || $type_name === RequestContract::class || is_subclass_of($type_name, Request::class)) { // phpcs:ignore Generic.Files.LineLength.TooLong
                $dependencies['requests'][] = $this->add_dependency($type_name, $variable, $position);
            } elseif (is_subclass_of($type_name, Model::class)) {
                $dependencies['models'][] = $this->add_dependency($type_name, $variable, $position);
            } else {
                $dependencies['abstracts'][] = $this->add_dependency($type_name, $variable, $position);
            }
        }

        return $dependencies;
    }

    /**
     * Ensure the handler declares exactly one request dependency.
     *
     * @param array $dependencies Categorized dependencies.
     * @param string $handler Handler name for error messages.
     *
     * @return void
     *
     * @throws \InvalidArgumentException
     *
     * @since 1.0.0
     */
    protected function assert_single_request_dependency(array $dependencies, string $handler)
    {
        if (count($dependencies['requests']) < 1) {
            throw new InvalidArgumentException(
                sprintf('The method "%s" must have at least one request dependency.', $handler)
            );
        }

        if (count($dependencies['requests']) > 1) {
            throw new InvalidArgumentException(
                sprintf('The method "%s" must have only one request dependency.', $handler)
            );
        }
    }

    /**
     * Add a dependency to the dependencies array.
     *
     * @param string $type The type of the dependency.
     * @param string $variable The variable name of the dependency.
     * @param int $position The position of the dependency.
     *
     * @return array
     *
     * @since 1.0.0
     */
    protected function add_dependency($type, $variable, $position)
    {
        return compact('type', 'variable', 'position');
    }

    /**
     * Add a resolved dependency to the dependencies array.
     *
     * @param mixed $resolved The resolved dependency.
     * @param int $position The position of the dependency.
     *
     * @return array
     *
     * @since 1.0.0
     */
    protected function add_resolved_dependency($resolved, int $position)
    {
        return compact('resolved', 'position');
    }

    /**
     * Resolve the models.
     *
     * @param array $models The models to resolve.
     * @param Request $request The request object.
     *
     * @return array
     *
     * @since 1.0.0
     */
    protected function resolve_models(array $models, Request $request)
    {
        $resolved_models = [];

        foreach ($models as $model) {
            $position = $model['position'];
            $type = $model['type'];
            $variable = $model['variable'];
            $value = $request->get($variable);

            $model = $this->resolve_model($type, $value);
            $resolved_models[] = $this->add_resolved_dependency($model, $position);
        }

        return $resolved_models;
    }

    /**
     * Resolve the built-in types.
     *
     * @param array $builtins The built-in types to resolve.
     * @param Request $request The request object.
     *
     * @return array
     *
     * @since 1.0.0
     */
    protected function resolve_builtins(array $builtins, Request $request)
    {
        $resolved_builtins = [];

        foreach ($builtins as $builtin) {
            $type = $builtin['type'];
            $variable = $builtin['variable'];
            $position = $builtin['position'];
            $value = $request->get($variable, null, $type);
            $resolved_builtins[] = $this->add_resolved_dependency($value, $position);
        }

        return $resolved_builtins;
    }

    /**
     * Resolve the abstracts.
     *
     * @param array $abstracts The abstracts to resolve.
     * @param Request $request The request object.
     *
     * @return array
     *
     * @since 1.0.0
     */

    protected function resolve_abstracts(array $abstracts, Request $request)
    {
        $resolved_abstracts = [];

        foreach ($abstracts as $abstract) {
            $position = $abstract['position'];
            $resolved = app()->make($abstract['type']);
            $resolved_abstracts[] = $this->add_resolved_dependency($resolved, $position);
        }

        return $resolved_abstracts;
    }

    /**
     * Resolve a model from the request.
     *
     * @param class-string<Model> $model The model class name
     * @param mixed $value The value of the model
     *
     * @return Model
     *
     * @since 1.0.0
     */
    protected function resolve_model($model, $value)
    {
        $key_name = (new $model())->get_route_key();

        try {
            return $model::where($key_name, $value)->first_or_fail();
        } catch (ModelNotFoundException $exception) {
            $exception->set_model($model);
            $exception->set_ids($value);

            throw $exception;
        }
    }

    /**
     * Resolve the route handler.
     *
     * @return callable
     *
     * @throws InvalidRoutActionException
     *
     * @since 1.0.0
     */
    protected function resolve_route()
    {
        return $this->action instanceof Closure
            ? $this->resolve_closure_action()
            : $this->resolve_controller_action();
    }

    /**
     * Check if the route is a site route.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function is_site_route()
    {
        return $this->is_site_route;
    }

    /**
     * Get the HTTP method.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function get_method()
    {
        return $this->method;
    }

    /**
     * Get the endpoint path.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function get_endpoint()
    {
        return $this->endpoint;
    }

    /**
     * Get the route action.
     *
     * @return array|Closure|null
     *
     * @since 1.0.0
     */
    public function get_action()
    {
        return $this->action;
    }

    /**
     * Get the route name.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    public function get_name()
    {
        return $this->name;
    }

    /**
     * Get the match strategy.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function get_match_using()
    {
        return $this->match_using;
    }

    /**
     * Get the dispatch hook name.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function get_hook_name()
    {
        return $this->hook_name ?: static::$default_hook_name;
    }

    /**
     * Get the dispatch hook priority.
     *
     * @return int
     *
     * @since 1.0.0
     */
    public function get_hook_priority()
    {
        return $this->hook_priority;
    }

    /**
     * Get the route-level redirect config.
     *
     * @return array{url:string,status:int}|null
     *
     * @since 1.0.0
     */
    public function get_redirect()
    {
        return $this->redirect;
    }

    /**
     * Get the route-level template path.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    public function get_template()
    {
        return $this->template;
    }

    /**
     * Get extra route data.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function get_with_data()
    {
        return $this->with_data;
    }

    /**
     * Get parsed URI segments.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function get_segments()
    {
        return $this->segments;
    }

    /**
     * Get param types.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function get_param_types()
    {
        return $this->param_types;
    }

    /**
     * Get callable param validators.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function get_param_validators()
    {
        return $this->param_validators;
    }

    /**
     * Whether views use layout wrapping.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function uses_layout()
    {
        return $this->with_layout;
    }

    /**
     * Dispatch a site route with the same authorize → middleware → validate → DI flow as REST.
     *
     * @param array $route_params Sanitized matched route parameters.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public function dispatch_site(array $route_params = [])
    {
        $request_class = $this->resolve_request_class();
        $request = app()->make($request_class)->make_from_http(
            // phpcs:ignore Framework.NamingConventions.SnakeCaseVariable.NotSnakeCase
            $_GET,
            // phpcs:ignore Framework.NamingConventions.SnakeCaseVariable.NotSnakeCase
            $_POST,
            // phpcs:ignore Framework.NamingConventions.SnakeCaseVariable.NotSnakeCase
            $_FILES,
            // phpcs:ignore Framework.NamingConventions.SnakeCaseVariable.NotSnakeCase
            $_SERVER,
            $route_params,
            // phpcs:ignore Framework.NamingConventions.SnakeCaseVariable.NotSnakeCase
            $_COOKIE
        );

        $request->authorize_request();

        if (!empty($this->middlewares)) {
            $pipeline = $this->build_middleware_pipeline(function ($request) {
                return $request;
            });
            $request = $pipeline($request);
        }

        $this->resolved_request = $this->expose($request);
        $request = $this->resolved_request->validate_request();

        if ($this->action instanceof Closure) {
            return $this->dispatch_closure_with_request($request);
        }

        if ($this->action === null) {
            return null;
        }

        return $this->dispatch_with_request($request);
    }

    /**
     * Resolve the closure route action.
     *
     * @return callable
     *
     * @since 1.0.0
     */
    protected function resolve_closure_action()
    {
        return function ($rest_request) {
            try {
                $request = $this->get_resolved_request($rest_request);

                return $this->dispatch_closure_with_request($request);
            } catch (Exception $exception) {
                return ApiExceptionHandler::get_response($exception);
            }
        };
    }

    /**
     * Resolve the controller route action.
     *
     * @return callable
     *
     * @since 1.0.0
     */
    protected function resolve_controller_action()
    {
        return function ($rest_request) {
            try {
                return $this->dispatch_controller($rest_request);
            } catch (Exception $exception) {
                return ApiExceptionHandler::get_response($exception);
            }
        };
    }

    /**
     * Dispatch the controller action with the middleware-enriched request.
     *
     * @param WP_REST_Request $rest_request The REST request object.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    protected function dispatch_controller($rest_request)
    {
        $request = $this->get_resolved_request($rest_request);

        return $this->dispatch_with_request($request);
    }

    /**
     * Dispatch a closure action with resolved route dependencies.
     *
     * @param Request $request The middleware-enriched request object.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    protected function dispatch_closure_with_request(Request $request)
    {
        $handler = $this->resolve_closure_handler($request);
        $dependencies = $this->update_request(
            $handler['dependencies'],
            $this->add_resolved_dependency($request, $handler['request_position'])
        );
        $dependencies = $this->sort_dependencies($dependencies);
        $parameters = (new Collection($dependencies))->pluck('resolved')->all();

        $result = ($this->action)(...$parameters);

        if ($result instanceof View && !$this->with_layout) {
            $result->partial();
        }

        return $result;
    }

    /**
     * Resolve closure dependencies for dispatch.
     *
     * @param Request $request The middleware-enriched request object.
     *
     * @return array
     *
     * @since 1.0.0
     */
    protected function resolve_closure_handler(Request $request)
    {
        $dependencies = $this->resolve_closure_dependencies($this->action);
        $first_request = array_first($dependencies['requests']);
        $dependency_array = $this->resolve_dependencies($dependencies, $request);

        return [
            'dependencies' => $dependency_array,
            'request_position' => $first_request['position'],
        ];
    }

    /**
     * Dispatch the controller action with a framework request.
     *
     * @param Request $request The middleware-enriched request object.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    protected function dispatch_with_request(Request $request)
    {
        $controller = $this->resolve_controller($request);
        $dependecies = $this->update_request(
            $controller['dependencies'],
            $this->add_resolved_dependency($request, $controller['request_position'])
        );
        $dependecies = $this->sort_dependencies($dependecies);
        $parameters = (new Collection($dependecies))->pluck('resolved')->all();

        $instance = $controller['instance'];
        $method = $controller['method'];

        $result = $instance->$method(...$parameters);

        if ($result instanceof View && !$this->with_layout) {
            $result->partial();
        }

        return $result;
    }

    /**
     * Resolve the controller for the route.
     *
     * @param Request $request The middleware-enriched request object.
     *
     * @return array
     *
     * @since 1.0.0
     */
    protected function resolve_controller(Request $request)
    {
        if (!is_array($this->action)) {
            throw new InvalidRoutActionException(
                sprintf('Invalid method registered for the route %s', $this->endpoint)
            );
        }

        if (count($this->action) !== 2) {
            throw new InvalidRoutActionException(
                sprintf('Invalid controller syntax for the route %s', $this->endpoint)
            );
        }

        [$controller, $method] = $this->action;

        if (!class_exists($controller)) {
            throw new InvalidRoutActionException(sprintf('Controller %s not found', $controller));
        }

        $controller_instance = $this->make($controller);

        if (!method_exists($controller_instance, $method)) {
            throw new InvalidRoutActionException(
                sprintf('The method %s is missing in the controller %s', $method, $controller)
            );
        }

        $dependencies = $this->resolve_method_dependencies($controller_instance, $method);
        $first_request = array_first($dependencies['requests']);
        $request_position = $first_request['position'];
        $dependency_array = $this->resolve_dependencies($dependencies, $request);

        return [
            'instance' => $controller_instance,
            'method' => $method,
            'request' => $request,
            'dependencies' => $dependency_array,
            'request_position' => $request_position,
        ];
    }

    /**
     * Build the middleware pipeline.
     *
     * @param callable $destination The destination callback.
     *
     * @return callable
     *
     * @since 1.0.0
     */
    protected function build_middleware_pipeline(callable $destination)
    {
        return array_reduce(
            array_reverse($this->middlewares),
            function ($next, $middleware) {
                return function ($request) use ($next, $middleware) {
                    [$class, $parameters] = static::parse_middleware($middleware);

                    if (!is_subclass_of($class, Middleware::class)) {
                        throw new InvalidArgumentException(
                            sprintf('Middleware %s must implement the %s interface.', $class, Middleware::class)
                        );
                    }

                    return (new $class())->handle($request, $next, ...$parameters);
                };
            },
            $destination
        );
    }

    /**
     * Register a middleware class under a short alias.
     *
     * The alias can then be used anywhere a route declares middleware, on its own or carrying
     * arguments after a colon.
     *
     * @param string $name The alias.
     * @param string $class The middleware class the alias resolves to.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public static function middleware_alias(string $name, string $class)
    {
        static::$middleware_aliases[$name] = $class;
    }

    /**
     * Get every registered middleware alias.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public static function get_middleware_aliases()
    {
        return static::$middleware_aliases;
    }

    /**
     * Split a middleware reference into the class it names and the arguments it carries.
     *
     * Everything before the first colon is the name, which is resolved through the alias registry
     * and otherwise treated as a class name. Everything after it is a comma separated argument
     * list. A reference without a colon carries no arguments, which is why a fully qualified class
     * name keeps working untouched.
     *
     * @param mixed $middleware The middleware reference declared on the route.
     *
     * @return array The resolved class name and its arguments.
     *
     * @throws InvalidMiddlewareException When an alias cannot be resolved to a class.
     *
     * @since 1.0.0
     */
    protected static function parse_middleware($middleware)
    {
        if (!is_string($middleware)) {
            return [$middleware, []];
        }

        $name = $middleware;
        $parameters = [];

        if (strpos($middleware, ':') !== false) {
            [$name, $arguments] = explode(':', $middleware, 2);

            $parameters = $arguments === '' ? [] : explode(',', $arguments);
        }

        if (isset(static::$middleware_aliases[$name])) {
            return [static::$middleware_aliases[$name], $parameters];
        }

        if (!class_exists($name)) {
            throw new InvalidMiddlewareException(
                sprintf('Middleware [%s] is not a registered alias and is not a resolvable class.', $name)
            );
        }

        return [$name, $parameters];
    }

    /**
     * Resolve the permission callback for the route.
     *
     * Memoized per request object: WordPress core calls this a second time for the same request
     * when it computes the Allow response header, and re-running middleware for that second call
     * would charge any middleware with a side effect, such as the rate limiter, twice for what the
     * client experiences as a single request.
     *
     * @param WP_REST_Request $rest_request The REST request object.
     *
     * @return bool|WP_Error
     *
     * @since 1.0.0
     */
    protected function resolve_permission_callback($rest_request)
    {
        if ($this->permission_checked_request === $rest_request) {
            return $this->permission_result;
        }

        $result = $this->compute_permission_result($rest_request);

        $this->permission_checked_request = $rest_request;
        $this->permission_result = $result;

        return $result;
    }

    /**
     * Run the route's middleware pipeline and compute the permission verdict.
     *
     * @param WP_REST_Request $rest_request The REST request object.
     *
     * @return bool|WP_Error
     *
     * @since 1.0.0
     */
    protected function compute_permission_result($rest_request)
    {
        $request = $this->make_framework_request($rest_request);

        try {
            $request->authorize_request();

            if (empty($this->middlewares)) {
                $this->resolved_request = $this->expose($request);

                return true;
            }

            $pipeline = $this->build_middleware_pipeline(fn ($request) => true);
            $pipeline($request);
            $this->resolved_request = $this->expose($request);

            return true;
        } catch (HttpException $exception) {
            return new WP_Error(
                $exception->error_code(),
                $exception->getMessage(),
                array_merge(
                    ['status' => $exception->get_status()],
                    $exception->get_headers() ? ['headers' => $exception->get_headers()] : []
                )
            );
        } catch (AuthorizationException $exception) {
            return new WP_Error(
                'rest_forbidden',
                $exception->getMessage(),
                ['status' => $exception->getCode()]
            );
        }
    }

    /**
     * Create a framework request from a WordPress REST request.
     *
     * @param WP_REST_Request $rest_request The REST request object.
     *
     * @return Request
     *
     * @since 1.0.0
     */
    protected function make_framework_request(WP_REST_Request $rest_request)
    {
        $request_class = $this->resolve_request_class();

        return app()->make($request_class)->make_request($rest_request);
    }

    /**
     * Resolve the request class from the route action.
     *
     * @return class-string<Request>
     *
     * @since 1.0.0
     */
    protected function resolve_request_class()
    {
        if ($this->action === null) {
            return Request::class;
        }

        if ($this->action instanceof Closure) {
            return $this->resolve_closure_request_class($this->action);
        }

        if (!is_array($this->action) || count($this->action) !== 2) {
            return Request::class;
        }

        [$controller, $method] = $this->action;

        if (!class_exists($controller) || !method_exists($controller, $method)) {
            return Request::class;
        }

        $dependencies = $this->resolve_method_dependencies($controller, $method);
        $first_request = array_first($dependencies['requests']);

        return $this->normalize_request_class($first_request['type']);
    }

    /**
     * Resolve the request class from a closure route action.
     *
     * @param Closure $closure The closure route action.
     *
     * @return class-string<Request>
     *
     * @since 1.0.0
     */
    protected function resolve_closure_request_class(Closure $closure)
    {
        $reflection = new ReflectionFunction($closure);

        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $type_name = $type->getName();

            if (
                $type_name === Request::class
                || $type_name === RequestContract::class
                || is_subclass_of($type_name, Request::class)
            ) {
                return $this->normalize_request_class($type_name);
            }
        }

        return Request::class;
    }

    /**
     * Normalize a reflected request type to a concrete request class.
     *
     * @param string $type_name The reflected request type name.
     *
     * @return class-string<Request>
     *
     * @since 1.0.0
     */
    protected function normalize_request_class($type_name)
    {
        if ($type_name === RequestContract::class) {
            return Request::class;
        }

        return $type_name;
    }

    /**
     * Get the request enriched by middleware during permission checking.
     *
     * @param WP_REST_Request $rest_request The REST request object.
     *
     * @return Request
     *
     * @since 1.0.0
     */
    protected function get_resolved_request(WP_REST_Request $rest_request)
    {
        if (!is_null($this->resolved_request)) {
            return $this->resolved_request->validate_request();
        }

        $request = $this->expose($this->make_framework_request($rest_request));

        return $request->validate_request();
    }

    /**
     * Expose the request to the container to use
     * the current request instance to the underneath classes and methods.
     *
     * @param Request $request The request object.
     *
     * @return Request
     *
     * @since 1.0.0
     */
    protected function expose(Request $request)
    {
        app()->instance('request', $request);

        return $request;
    }

    /**
     * Prepare the dependencies for the route. This will resolved the models,
     * abstract classes like services, repositories, built-in types and requests.
     * We are not appending the requests to the dependencies array because we will resolve them later
     * after all the middlewares are handled.
     *
     * @param array $dependencies The dependencies of the route.
     * @param Request $request The request object.
     *
     * @return array
     *
     * @since 1.0.0
     */
    protected function resolve_dependencies(array $dependencies, Request $request)
    {
        $models = $this->resolve_models($dependencies['models'], $request);
        $builtins = $this->resolve_builtins($dependencies['builtins'], $request);
        $abstracts = $this->resolve_abstracts($dependencies['abstracts'], $request);

        return array_values(
            array_merge(
                $models,
                $builtins,
                $abstracts
            )
        );
    }

    /**
     * Update the dependencies array with the resolved request.
     * Here we are attaching the request with the dependencies.
     * And this request is the request object after passing all the middlewares.
     *
     * @param array $dependencies The dependencies of the route.
     * @param array $resolved_request The resolved request.
     *
     * @return array
     *
     * @since 1.0.0
     */
    protected function update_request(array $dependencies, array $resolved_request)
    {
        return array_merge(
            $dependencies,
            [$resolved_request]
        );
    }

    /**
     * Sort the dependencies array by position so that it matches the original sequence of the dependencies.
     *
     * @param array $dependencies The dependencies of the route.
     *
     * @return array
     *
     * @since 1.0.0
     */
    protected function sort_dependencies(array $dependencies)
    {
        usort($dependencies, function ($first, $second) {
            return $first['position'] - $second['position'];
        });

        return $dependencies;
    }
}
