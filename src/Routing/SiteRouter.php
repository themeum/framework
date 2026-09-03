<?php
/**
 * Internal front-end router for WordPress rewrite rules and site route dispatch.
 * Matches path and page routes, then delegates DI/middleware to Route instances.
 *
 * @package    Framework
 * @subpackage Routing
 * @since      1.0.0
 */
namespace Framework\Routing;

defined('ABSPATH') || exit;

use Framework\Http\JsonResponse;
use Framework\Http\RedirectResponse;
use Framework\Managers\CookieManager;
use Framework\Managers\SessionManager;
use Framework\Route;
use Framework\Sanitizer;
use Framework\SiteExceptionHandler;
use Framework\View\TemplateEngine;
use Framework\View\View;
use Framework\View\ViewContext;
use Framework\Wordpress\Constants\HookNames;
use Exception;

use function Framework\app;

class SiteRouter
{
    /**
     * Short name used to prefix query vars and route IDs.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected $namespace;

    /**
     * How routes are matched against the current request.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected $routing_method;

    /**
     * Site route instances keyed by internal route id.
     *
     * @var array<string, Route>
     *
     * @since 1.0.0
     */
    protected $routes = [];

    /**
     * Registered dispatch hook name and priority combinations.
     *
     * @var array<string, bool>
     *
     * @since 1.0.0
     */
    protected $registered_hooks = [];

    /**
     * Whether boot hooks have been registered.
     *
     * @var bool
     *
     * @since 1.0.0
     */
    protected $hooked = false;

    /**
     * Shared route parser.
     *
     * @var RouteParser
     *
     * @since 1.0.0
     */
    protected $parser;

    /**
     * Create a SiteRouter instance.
     *
     * @param string $namespace Unique short name for the plugin.
     * @param string $routing_method Routing method constant.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function __construct(string $namespace, string $routing_method = Route::ROUTING_REWRITE_RULES)
    {
        $safe = Sanitizer::apply_rule($namespace, Sanitizer::KEY);
        $this->namespace = $safe !== '' ? $safe : 'siteroute';
        $this->routing_method = $routing_method === Route::ROUTING_PARSE_REQUEST
            ? Route::ROUTING_PARSE_REQUEST
            : Route::ROUTING_REWRITE_RULES;
        $this->parser = new RouteParser();
    }

    /**
     * Boot the router with the given site routes.
     *
     * @param array<int, Route> $routes Site route instances.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function boot(array $routes)
    {
        foreach ($routes as $route) {
            if (!$route->is_site_route()) {
                continue;
            }

            $id = $this->route_id($route);
            $this->routes[$id] = $route;
            $this->ensure_hook_registered(
                $route->get_hook_name(),
                $route->get_hook_priority()
            );
        }

        if ($this->hooked) {
            return;
        }

        $this->hooked = true;

        if ($this->routing_method === Route::ROUTING_PARSE_REQUEST) {
            add_action('parse_request', [$this, 'intercept_parse_request']);
            return;
        }

        $this->register_rewrite_rules();
        add_filter('query_vars', [$this, 'register_query_vars']);
    }

    /**
     * Flush rewrite rules after registering them.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function flush()
    {
        if ($this->routing_method !== Route::ROUTING_REWRITE_RULES) {
            return;
        }

        $this->register_rewrite_rules();
        flush_rewrite_rules();
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
    public function url(string $name, array $params = [])
    {
        $route = Route::find_named_route($name);

        if ($route === null || !$route->is_site_route()) {
            return '';
        }

        if ($route->get_match_using() === Route::MATCH_PAGE) {
            $page_id = $this->resolve_page_id($route->get_endpoint());

            return $page_id ? (string) get_permalink($page_id) : '';
        }

        $segments = $route->get_segments();
        $pieces = [];

        foreach ($segments as $segment) {
            if ($segment['type'] === 'literal') {
                $pieces[] = $segment['value'];
                continue;
            }

            $value = $params[$segment['name']] ?? ('{' . $segment['name'] . '}');
            $pieces[] = rawurlencode((string) $value);
        }

        return esc_url(home_url('/' . implode('/', $pieces)));
    }

    /**
     * Register rewrite rules for path-matched routes.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function register_rewrite_rules()
    {
        foreach ($this->routes as $id => $route) {
            if ($route->get_match_using() === Route::MATCH_PAGE) {
                continue;
            }

            [$pattern, $param_names] = $this->parser->build_site_pattern(
                $route->get_segments(),
                $route->get_param_types()
            );

            $query = 'index.php?' . $this->namespace . '_route=' . $id;

            foreach ($param_names as $index => $name) {
                $query .= '&' . $this->query_var_name($name) . '=$matches[' . ($index + 1) . ']';
            }

            add_rewrite_rule($pattern, $query, 'top');
        }
    }

    /**
     * Whitelist query vars for rewrite-based routing.
     *
     * @param array $vars Existing WordPress query vars.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function register_query_vars(array $vars)
    {
        $vars[] = $this->namespace . '_route';

        foreach ($this->routes as $route) {
            if ($route->get_match_using() === Route::MATCH_PAGE) {
                continue;
            }

            foreach ($route->get_segments() as $segment) {
                if ($segment['type'] === 'param') {
                    $vars[] = $this->query_var_name($segment['name']);
                }
            }
        }

        return $vars;
    }

    /**
     * Match path routes during parse_request and replace query vars on hit.
     *
     * @param \WP $wp Main WordPress request object.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function intercept_parse_request($wp)
    {
        $path = isset($wp->request) && $wp->request !== ''
            ? trim((string) $wp->request, '/')
            : $this->resolve_request_path();

        foreach ($this->routes as $id => $route) {
            if ($route->get_match_using() === Route::MATCH_PAGE) {
                continue;
            }

            [$pattern, $param_names] = $this->parser->build_site_pattern(
                $route->get_segments(),
                $route->get_param_types()
            );

            if (!preg_match('#' . $pattern . '#', $path, $matches)) {
                continue;
            }

            $query_vars = [$this->namespace . '_route' => $id];

            foreach ($param_names as $index => $name) {
                $query_vars[$this->query_var_name($name)] = $matches[$index + 1] ?? '';
            }

            $wp->query_vars = $query_vars;
            add_filter('redirect_canonical', '__return_false');

            return;
        }
    }

    /**
     * Dispatch matched routes registered on the template_redirect hook.
     *
     * @param int $priority Hook priority being handled.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function handle_template_redirect(int $priority)
    {
        $match = $this->match_current_request(HookNames::TEMPLATE_REDIRECT, $priority);

        if ($match === null) {
            return;
        }

        $this->dispatch_match($match, true);
    }

    /**
     * Dispatch matched routes registered on the template_include filter.
     *
     * The session is saved and queued cookies are emitted before the template
     * path is handed back, because WordPress includes that file straight away
     * and output leaves no further opportunity to send headers.
     *
     * @param string $template Default template path from WordPress.
     * @param int $priority Hook priority being handled.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function handle_template_include(string $template, int $priority)
    {
        $match = $this->match_current_request(HookNames::TEMPLATE_INCLUDE, $priority);

        if ($match === null) {
            return $template;
        }

        $route = $match['route'];
        $params = $match['params'];

        if ($route->get_redirect() !== null) {
            $this->flush_queued_cookies();
            $this->send_route_redirect($route, $params);
        }

        if ($route->get_template() !== null && $route->get_action() === null) {
            $file = $this->locate_route_template($route);

            if ($file === '') {
                SiteExceptionHandler::handle(
                    new Exception('Template not found: ' . $route->get_template(), 500)
                );
            }

            $this->flush_queued_cookies();

            return $file;
        }

        try {
            $result = $this->run_route($route, $params);

            if ($result instanceof View) {
                $path = $result->get_template();
                $engine = app(TemplateEngine::class);
                $resolved = $engine->resolve_path($path);

                if ($resolved !== '') {
                    $view_context = app(ViewContext::class);
                    $view_context->prepare(
                        $result,
                        (string) $route->get_name(),
                        $resolved
                    );

                    $master_layout = $result->get_master_layout();
                    if ($master_layout !== null) {
                        $view_context->set_active_attribute('master_layout', $master_layout);
                    }

                    // WordPress includes the returned path immediately after this
                    // filter, which starts output. This is the last point at which
                    // the identifier cookie can still be sent.
                    $this->flush_queued_cookies();

                    if ($result->uses_layout()) {
                        return $engine->layout_wrapper_path();
                    }

                    return $resolved;
                }
            }

            if (is_string($result) && $result !== '' && file_exists($result)) {
                $this->flush_queued_cookies();

                return $result;
            }

            SiteExceptionHandler::handle(
                new Exception('Site route template_include callback must return a valid template file path.', 500)
            );
        } catch (Exception $exception) {
            SiteExceptionHandler::handle($exception);
        }

        return $template;
    }

    /**
     * Ensure a WordPress action or filter is registered for a hook and priority.
     *
     * @param string $hook_name Dispatch hook name.
     * @param int $priority Hook priority.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function ensure_hook_registered(string $hook_name, int $priority)
    {
        $key = $hook_name . ':' . $priority;

        if (isset($this->registered_hooks[$key])) {
            return;
        }

        $this->registered_hooks[$key] = true;

        if ($hook_name === HookNames::TEMPLATE_INCLUDE) {
            add_filter(HookNames::TEMPLATE_INCLUDE, function ($template) use ($priority) {
                return $this->handle_template_include($template, $priority);
            }, $priority);

            return;
        }

        add_action(HookNames::TEMPLATE_REDIRECT, function () use ($priority) {
            $this->handle_template_redirect($priority);
        }, $priority);
    }

    /**
     * Find and authorize the route due to dispatch on the given hook and priority.
     *
     * @param string $expected_hook_name Expected dispatch hook name.
     * @param int $expected_priority Expected dispatch priority.
     *
     * @return array{route:Route,params:array}|null
     *
     * @since 1.0.0
     */
    protected function match_current_request(string $expected_hook_name, int $expected_priority)
    {
        $route = $this->find_matching_path_route($expected_hook_name, $expected_priority);

        if ($route === null) {
            $route = $this->find_matching_page_route($expected_hook_name, $expected_priority);
        }

        if ($route === null) {
            return null;
        }

        // phpcs:ignore Framework.NamingConventions.SnakeCaseVariable.NotSnakeCase
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if (strtoupper($route->get_method()) !== $method) {
            SiteExceptionHandler::handle(new Exception('Method Not Allowed', 405));
        }

        $params = $this->collect_route_params($route);

        foreach ($route->get_param_validators() as $name => $validator) {
            if (!call_user_func($validator, $params[$name] ?? null)) {
                SiteExceptionHandler::handle(new Exception('Not Found', 404));
            }
        }

        $params = array_merge($route->get_with_data(), $params);

        if ($route->get_match_using() === Route::MATCH_PAGE) {
            $params['page_id'] = $this->resolve_page_id($route->get_endpoint());
            $params['current_page_id'] = get_queried_object_id();
        }

        CurrentRoute::set($route->get_name(), $params);

        return ['route' => $route, 'params' => $params];
    }

    /**
     * Dispatch a matched route on template_redirect.
     *
     * @param array{route:Route,params:array} $match Matched route data.
     * @param bool $exit Whether to exit after sending the response.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function dispatch_match(array $match, bool $exit = true)
    {
        $route = $match['route'];
        $params = $match['params'];

        status_header(200);
        nocache_headers();

        if ($route->get_redirect() !== null && $route->get_action() === null) {
            $this->flush_queued_cookies();
            $this->send_route_redirect($route, $params);
        }

        if ($route->get_template() !== null && $route->get_action() === null) {
            $file = $this->locate_route_template($route);

            if ($file === '') {
                SiteExceptionHandler::handle(
                    new Exception('Template not found: ' . $route->get_template(), 500)
                );
            }

            $this->flush_queued_cookies();

            extract($params, EXTR_SKIP);
            include $file;

            if ($exit) {
                exit;
            }

            return;
        }

        try {
            $result = $this->run_route($route, $params);
            $this->send_response($result);
        } catch (Exception $exception) {
            SiteExceptionHandler::handle($exception);
        }

        if ($exit) {
            exit;
        }
    }

    /**
     * Run authorize, middleware, validate, and controller DI for a site route.
     *
     * @param Route $route The matched route.
     * @param array $params Sanitized route params.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    protected function run_route(Route $route, array $params)
    {
        return $route->dispatch_site($params);
    }

    /**
     * Emit any queued cookies before response output begins.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function flush_queued_cookies()
    {
        // Saving first is required, not cosmetic: starting or changing the
        // session queues the identifier cookie that this flush emits.
        app(SessionManager::class)->save();

        app(CookieManager::class)->flush_queued_cookies();
    }

    /**
     * Send a controller/closure return value to the browser.
     *
     * @param mixed $result The route return value.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function send_response($result)
    {
        $this->flush_queued_cookies();

        if ($result instanceof RedirectResponse) {
            $result->send();
        }

        if ($result instanceof JsonResponse) {
            status_header($result->get_status());
            header('Content-Type: application/json; charset=utf-8');

            foreach ($result->get_headers() as $name => $values) {
                $value = is_array($values) ? implode(', ', $values) : $values;
                header($name . ': ' . $value);
            }

            echo wp_json_encode($result->get_data());
            return;
        }

        if ($result instanceof View) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Assembled view HTML; dynamic data is escaped in templates via esc_*.
            echo $result->render();
            return;
        }

        if (is_string($result)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Controller string returns must be pre-escaped safe HTML.
            echo $result;
        }
    }

    /**
     * Collect and sanitize route params from query vars.
     *
     * @param Route $route The matched route.
     *
     * @return array
     *
     * @since 1.0.0
     */
    protected function collect_route_params(Route $route)
    {
        $params = [];

        foreach ($route->get_segments() as $segment) {
            if ($segment['type'] !== 'param') {
                continue;
            }

            $name = $segment['name'];
            $raw = get_query_var($this->query_var_name($name));
            $raw = is_string($raw) ? wp_unslash($raw) : $raw;
            $type = $route->get_param_types()[$name] ?? 'any';
            $sanitizer = $this->parser->resolve_sanitizer($type);
            $params[$name] = Sanitizer::apply_rule($raw, $sanitizer);
        }

        return $params;
    }

    /**
     * Get the matched route by checking current endpoint and request method.
     *
     * @param Route $route The route to get the matched route for.
     *
     * @return Route
     *
     * @since 1.0.0
     */
    protected function get_matched_route(Route $route)
    {
        // phpcs:ignore Framework.NamingConventions.SnakeCaseVariable.NotSnakeCase
        $request_method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        foreach ($this->routes as $route_entry) {
            if (
                $route_entry->get_endpoint() === $route->get_endpoint() &&
                strtoupper($route_entry->get_method()) === $request_method
            ) {
                return $route_entry;
            }
        }

        return $route;
    }

    /**
     * Find a path-matched route for the current request and hook context.
     *
     * @param string $expected_hook_name Expected dispatch hook name.
     * @param int $expected_priority Expected dispatch priority.
     *
     * @return Route|null
     *
     * @since 1.0.0
     */
    protected function find_matching_path_route(string $expected_hook_name, int $expected_priority)
    {
        $id = get_query_var($this->namespace . '_route');

        if (empty($id) || !isset($this->routes[$id])) {
            return null;
        }

        $route = $this->routes[$id];
        $route = $this->get_matched_route($route);

        if ($route->get_match_using() !== Route::MATCH_PATH) {
            return null;
        }

        if ($route->get_hook_name() !== $expected_hook_name || $route->get_hook_priority() !== $expected_priority) {
            return null;
        }

        return $route;
    }

    /**
     * Find a page-matched route for the current request and hook context.
     *
     * @param string $expected_hook_name Expected dispatch hook name.
     * @param int $expected_priority Expected dispatch priority.
     *
     * @return Route|null
     *
     * @since 1.0.0
     */
    protected function find_matching_page_route(string $expected_hook_name, int $expected_priority)
    {
        if (is_admin() || !is_page()) {
            return null;
        }

        $current_page_id = get_queried_object_id();

        foreach ($this->routes as $route) {
            if ($route->get_match_using() !== Route::MATCH_PAGE) {
                continue;
            }

            if ($route->get_hook_name() !== $expected_hook_name || $route->get_hook_priority() !== $expected_priority) {
                continue;
            }

            if ($this->resolve_page_id($route->get_endpoint()) === $current_page_id) {
                $route = $this->get_matched_route($route);
                return $route;
            }
        }

        return null;
    }

    /**
     * Resolve a page ID or slug to a page ID.
     *
     * @param mixed $page_ref Page ID or slug.
     *
     * @return int
     *
     * @since 1.0.0
     */
    protected function resolve_page_id($page_ref)
    {
        if (is_numeric($page_ref)) {
            return (int) $page_ref;
        }

        $page = get_page_by_path((string) $page_ref);

        return $page ? (int) $page->ID : 0;
    }

    /**
     * Locate a route-level template file.
     *
     * @param Route $route The route.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function locate_route_template(Route $route)
    {
        $path = $route->get_template();
        $located = locate_template($path);
        $file = $located !== '' ? $located : $path;

        return file_exists($file) ? $file : '';
    }

    /**
     * Send a route-level redirect.
     *
     * @param Route $route The route.
     * @param array $params Route params.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function send_route_redirect(Route $route, array $params)
    {
        $redirect = $route->get_redirect();
        $url = preg_replace_callback('/\{(\w+)\}/', function ($matches) use ($params) {
            return isset($params[$matches[1]]) ? rawurlencode((string) $params[$matches[1]]) : $matches[0];
        }, $redirect['url']);

        wp_safe_redirect($url, $redirect['status']);
        exit;
    }

    /**
     * Resolve the current request path relative to the site home path.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function resolve_request_path()
    {
        // phpcs:ignore Framework.NamingConventions.SnakeCaseVariable.NotSnakeCase
        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $path = (string) parse_url($request_uri, PHP_URL_PATH);
        $home_path = (string) parse_url(home_url(), PHP_URL_PATH);

        if ($home_path !== '' && $home_path !== '/' && strpos($path, $home_path) === 0) {
            $path = substr($path, strlen($home_path));
        }

        return trim($path, '/');
    }

    /**
     * Build a stable internal route id.
     *
     * @param Route $route The route.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function route_id(Route $route)
    {
        return $this->namespace . '_' . substr(
            md5(strtoupper($route->get_method()) . '|' . $route->get_endpoint()),
            0,
            12
        );
    }

    /**
     * Build the namespaced query var name for a route param.
     *
     * @param string $name Param name.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function query_var_name(string $name)
    {
        return $this->namespace . '_p_' . $name;
    }
}
