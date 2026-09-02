<?php
/**
 * The framework helper functions.
 *
 * @package    Framework
 * @subpackage Helpers
 * @since      1.0.0
 */

namespace Framework;

defined('ABSPATH') || exit;

use Closure;
use Faker\Factory;
use Faker\Generator;
use Framework\Application;
use Framework\Cache\CacheManager;
use Framework\Collections\Collection;
use Framework\Database\Migrations\Migrator;
use Framework\Http\Cookie;
use Framework\Http\Request;
use Framework\Managers\CookieManager;
use Framework\Managers\SessionManager;
use Framework\Http\RedirectResponse;
use Framework\Wordpress\User;
use Framework\Http\Response;
use Framework\Supports\Arr;
use Framework\Supports\ErrorBag;
use Framework\Supports\HigherOrderTapProxy;
use Framework\Supports\MessagesBag;
use Framework\Supports\Str;
use Framework\Supports\Url;
use Framework\Supports\Utils;
use Framework\View\TemplateEngine;
use Framework\View\View;
use Framework\View\ViewContext;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Symfony\Component\VarDumper\Dumper\HtmlDumper;
use Symfony\Component\VarDumper\VarDumper;

use function Framework\Polyfill\array_key_first;
use function Framework\Polyfill\array_key_last;
use function Framework\Polyfill\is_iterable;

if (!function_exists('Framework\app')) {
    /**
     * Get the container instance.
     *
     * @template TClass
     *
     * @param string|class-string<TClass>|null $abstract
     * @param array $parameters
     *
     * @return ($abstract is class-string<TClass> ? TClass : ($abstract is null ? Application : mixed))
     */
    function app($abstract = null, array $parameters = [])
    {
        if (is_null($abstract)) {
            return Application::get_instance();
        }

        return Application::get_instance()->make($abstract, $parameters);
    }
}

if (!function_exists('Framework\deep_get')) {
    /**
     * Get a value from an array using a dot notation key.
     *
     * @param   array $target         The target array to get the value from.
     * @param   string|array $key     The key to get the value from.
     * @param   mixed $default        The default value to return if the key is not found.
     * 
     * @return  mixed                 The value from the array or the default value if the key is not found.
     * 
     * @since 1.0.0
     */
    function deep_get($target, $key, $default = null)
    {
        if (is_null($key)) {
            return $target;
        }

        $key = is_array($key) ? $key : explode('.', $key);

        foreach ($key as $index => $segment) {
            unset($key[$index]);

            if (is_null($segment)) {
                return $target;
            }

            if ($segment === '*') {
                if ($target instanceof Collection) {
                    $target = $target->all();
                } elseif (!is_iterable($target)) {
                    return $default;
                }

                $result = [];

                foreach ($target as $item) {
                    $result[] = deep_get($item, $key);
                }

                return in_array('*', $key) ? Arr::collapse($result) : $result;
            }

            switch ($segment) {
                case '\*':
                    $segment = '*';
                    break;
                case '\{first}':
                    $segment = '{first}';
                    break;
                case '{first}':
                    $segment = array_key_first(Arr::from($target));
                    break;
                case '\{last}':
                    $segment = '{last}';
                    break;
                case '{last}':
                    $segment = array_key_last(Arr::from($target));
                    break;
            }

            if (Arr::accessible($target) && Arr::exists($target, $segment)) {
                $target = $target[$segment];
            } elseif (is_object($target) && isset($target->{$segment})) {
                $target = $target->{$segment};
            } else {
                return value($default);
            }
        }

        return $target;
    }
}

if (!function_exists('Framework\deep_set')) {
    /**
     * Set a value in an array using a dot notation key.
     *
     * @param array $target The target array to set the value in.
     * @param string|array $key The key to set the value in.
     * @param mixed $value The value to set.
     * @return void
     */
    function deep_set(&$target, $key, $value, $overwrite = true)
    {
        $segments = is_array($key) ? $key : explode('.', $key);

        $segment = array_shift($segments);

        if ($segment === '*') {
            if (!Arr::accessible($target)) {
                $target = [];
            }

            if ($segments) {
                foreach ($target as &$inner) {
                    deep_set($inner, $segments, $value, $overwrite);
                }

                unset($inner);
            } elseif ($overwrite) {
                foreach ($target as &$inner) {
                    $inner = $value;
                }
            }
        } elseif (Arr::accessible($target)) {
            if ($segments) {
                if (!Arr::exists($target, $segment)) {
                    $target[$segment] = [];
                }

                deep_set($target[$segment], $segments, $value, $overwrite);
            } elseif ($overwrite || !Arr::exists($target, $segment)) {
                $target[$segment] = $value;
            }
        } elseif (is_object($target)) {
            if ($segments) {
                if (!isset($target->{$segment})) {
                    $target->{$segment} = [];
                }

                deep_set($target->{$segment}, $segments, $value, $overwrite);
            } elseif ($overwrite || !isset($target->{$segment})) {
                $target->{$segment} = $value;
            }
        } else {
            $target = [];

            if ($segments) {
                deep_set($target[$segment], $segments, $value, $overwrite);
            } elseif ($overwrite) {
                $target[$segment] = $value;
            }
        }

        return $target;
    }
}


if (!function_exists('Framework\config')) {
    /**
     * Get the config
     *
     * @param string|null $key
     * @param mixed $default
     * @return mixed
     */
    function config($key = null, $default = null)
    {
        static $cache = [];

        $filename = strpos($key, '.') ? substr($key, 0, strpos($key, '.')) : $key;
        $key = strpos($key, '.') ? substr($key, strpos($key, '.') + 1) : null;

        if (!isset($cache[$filename])) {
            $path = app()->config_path("{$filename}.php");

            if (file_exists($path)) {
                $cache[$filename] = include $path;
            } else {
                $cache[$filename] = null;
            }
        }

        if (is_null($cache[$filename])) {
            return value($default);
        }

        return deep_get($cache[$filename], $key, $default);
    }
}

if (!function_exists('Framework\user')) {
    /**
     * Get the user instance.
     *
     * @return User
     */
    function user($user_id = null)
    {
        return app()->make(User::class, ['user_id' => $user_id]);
    }
}

if (!function_exists('Framework\response')) {
    /**
     * Get the response instance.
     *
     * @return Response
     */
    function response()
    {
        return app()->make(Response::class)->with_headers([
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-XSS-Protection' => '1; mode=block',
            'Referrer-Policy' => 'no-referrer-when-downgrade',
            'Cache-Control' => 'public, max-age=60, stale-while-revalidate=30',
        ]);
    }
}

if (!function_exists('Framework\cookie')) {
    /**
     * Create a cookie instance, or get the cookie manager when called without arguments.
     *
     * The returned cookie is not sent. Queue it with the Cookie facade or attach it
     * to a response to have it emitted.
     *
     * @param string|null $name The name of the cookie.
     * @param string $value The value of the cookie.
     * @param int $minutes The number of minutes the cookie lives, zero for a session cookie.
     * @param string|null $path The path the cookie is scoped to.
     * @param string|null $domain The domain the cookie is scoped to.
     * @param bool|null $secure Whether the cookie is restricted to secure connections.
     * @param bool $http_only Whether the cookie is hidden from client side scripts.
     * @param bool $raw Whether the value is sent without URL encoding.
     * @param string|null $same_site The same site policy of the cookie.
     *
     * @return ($name is null ? CookieManager : Cookie)
     *
     * @since 1.0.0
     */
    function cookie(
        $name = null,
        $value = '',
        $minutes = 0,
        $path = null,
        $domain = null,
        $secure = null,
        $http_only = true,
        $raw = false,
        $same_site = null
    ) {
        $manager = app('cookie');

        if (is_null($name)) {
            return $manager;
        }

        return $manager->make($name, $value, $minutes, $path, $domain, $secure, $http_only, $raw, $same_site);
    }
}

if (!function_exists('Framework\session')) {
    /**
     * Access the session store.
     *
     * With no arguments the manager is returned. A string key reads a value,
     * and an array writes each key and value pair.
     *
     * @param string|array|null $key
     * @param mixed $default
     *
     * @return ($key is null ? SessionManager : ($key is array ? null : mixed))
     *
     * @since 1.0.0
     */
    function session($key = null, $default = null)
    {
        $manager = app('session');

        if (is_null($key)) {
            return $manager;
        }

        if (is_array($key)) {
            $manager->put($key);

            return null;
        }

        return $manager->get($key, $default);
    }
}

if (!function_exists('Framework\cache')) {
    /**
     * Access the cache.
     *
     * With no arguments the manager is returned. A string key reads a value, and an array
     * writes each key and value pair using the second argument as the time to live.
     *
     * @param string|array|null $key
     * @param mixed $default The default on a read, or the time to live on a write.
     *
     * @return ($key is null ? CacheManager : ($key is array ? bool : mixed))
     *
     * @since 1.0.0
     */
    function cache($key = null, $default = null)
    {
        $manager = app('cache');

        if (is_null($key)) {
            return $manager;
        }

        if (is_array($key)) {
            return $manager->put_many($key, $default);
        }

        return $manager->get($key, $default);
    }
}

if (!function_exists('Framework\old')) {
    /**
     * Get a value from the input flashed by the previous request.
     *
     * @param string|null $key
     * @param mixed $default
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    function old($key = null, $default = null)
    {
        return app('session')->get_old_input($key, $default);
    }
}

if (!function_exists('Framework\errors')) {
    /**
     * Get the validation errors flashed by the previous request.
     *
     * @return ErrorBag
     *
     * @since 1.0.0
     */
    function errors()
    {
        $errors = app('session')->get('errors', []);

        return new ErrorBag(is_array($errors) ? $errors : []);
    }
}

if (!function_exists('Framework\back')) {
    /**
     * Redirect back to the page the visitor came from.
     *
     * Resolves the WordPress referer first, then a previously recorded session
     * URL when a session is already started, then the given fallback, and
     * finally the site home URL. Resolving never starts a session.
     *
     * @param string|null $fallback
     * @param int $status
     *
     * @return RedirectResponse
     *
     * @since 1.0.0
     */
    function back($fallback = null, $status = 302)
    {
        $url = function_exists('wp_get_referer') ? wp_get_referer() : false;

        if (!is_string($url) || $url === '') {
            $session = app('session');
            $url = $session->is_started() ? $session->previous_url() : null;
        }

        if (!is_string($url) || $url === '') {
            $url = $fallback;
        }

        if (!is_string($url) || $url === '') {
            $url = function_exists('home_url') ? home_url('/') : '/';
        }

        return new RedirectResponse($url, $status);
    }
}

if (!function_exists('Framework\view')) {
    /**
     * Create a view instance for the given template.
     *
     * @param string $template The template name in dot notation.
     * @param array $data The data to pass to the template.
     *
     * @return View
     *
     * @since 1.0.0
     */
    function view(string $template, array $data = [])
    {
        return new View($template, $data);
    }
}

if (!function_exists('Framework\view_data')) {
    /**
     * Read data from the innermost authorized view context.
     *
     * Supports dot notation for nested keys (e.g. `product.name`).
     *
     * @param string|null $key Data key (dot notation allowed), or null for the full array.
     * @param mixed $default Default when missing or unauthorized.
     *
     * @return mixed
     *
     * @since 2.1.2
     */
    function view_data($key = null, $default = null)
    {
        return app(ViewContext::class)->get($key, $default);
    }
}

if (!function_exists('Framework\template_engine')) {
    /**
     * Get the template engine instance.
     *
     * @return TemplateEngine
     */
    function template_engine()
    {
        return app(TemplateEngine::class);
    }
}

if (!function_exists('Framework\include_view')) {
    /**
     * Include a nested pure-PHP view partial (Laravel @include equivalent).
     *
     * Explicit $data (plus TemplateEngine shared data) is pushed as a child
     * ViewContext frame. The partial must read values via view_data().
     *
     * @param string $view The view name in dot notation.
     * @param array $data Data for the partial.
     *
     * @return void
     *
     * @since 2.1.2
     * @throws \RuntimeException When the view cannot be resolved.
     */
    function include_view(string $view, array $data = [])
    {
        app(\Framework\View\TemplateEngine::class)->include($view, $data, false);
    }
}

if (!function_exists('Framework\redirect')) {
    /**
     * Create a redirect response.
     *
     * @param string $url The redirect target URL.
     * @param int $status The HTTP status code.
     *
     * @return RedirectResponse
     *
     * @since 1.0.0
     */
    function redirect(string $url, int $status = 302)
    {
        return new RedirectResponse($url, $status);
    }
}

if (!function_exists('Framework\request')) {
    /**
     * Get the request instance.
     *
     * @param string|null $key
     * @param mixed $default
     * 
     * @return ($key is null ? Request : ($key is array ? array : mixed))
     * 
     * @since 1.0.0
     */
    function request($key = null, $default = null)
    {
        if (is_null($key)) {
            return app('request');
        }

        if (is_array($key)) {
            return app('request')->only($key);
        }

        $value = app('request')->get($key, $default);

        return is_null($value) ? value($default) : $value;
    }
}

if (!function_exists('Framework\with_prefix')) {
    /**
     * Get the key with prefix applied.
     *
     * @param string $key
     * @return string
     */
    function with_prefix(string $key)
    {
        $prefix = app()->prefix();

        if (Str::starts_with($key, $prefix)) {
            return $key;
        }

        return $prefix . $key;
    }
}

if (!function_exists('Framework\without_prefix')) {
    /**
     * Get the key without prefix applied.
     *
     * @param string $key
     * @return string
     */
    function without_prefix(string $key)
    {
        $prefix = app()->prefix();

        if (!Str::starts_with($key, $prefix)) {
            return $key;
        }

        return substr($key, strlen($prefix));
    }
}


if (!function_exists('Framework\redirect')) {
    /**
     * Redirect to the given location.
     * 
     * @param string $location
     * @return void
     * 
     * @since 1.0.0
     */
    function redirect($location)
    {
        Url::redirect($location);
    }
}

if (!function_exists('Framework\is_valid_json')) {
    /**
     * Check if the string is a valid JSON.
     * 
     * @param string $string
     * @return bool
     */
    function is_valid_json($string)
    {
        if (!is_string($string)) {
            return false;
        }

        json_decode($string);

        return (json_last_error() === JSON_ERROR_NONE);
    }
}

if (!function_exists('Framework\clean_path')) {
    /**
     * Clean and normalize file paths for consistency.
     *
     * @param string $path
     * @param bool   $trailing_slash Add a trailing slash? Default true.
     * @return string
     */
    function clean_path(string $path, bool $trailing_slash = true)
    {
        $path = wp_normalize_path($path);

        return $trailing_slash
            ? trailingslashit($path)
            : untrailingslashit($path);
    }
}

if (!function_exists('Framework\uuid')) {
    /**
     * Generate a UUID.
     * 
     * @return string
     */
    function uuid()
    {
        return Utils::uuid();
    }
}

if (!function_exists('Framework\url')) {
    /**
     * Generate a URL.
     * 
     * @param string $url
     * @param array $query_vars
     * @return string
     */
    function url($url, $query_vars = [])
    {
        return Url::make($url, $query_vars);
    }
}

if (!function_exists('Framework\is_block_theme')) {
    /**
     * Check if the site is using a block template
     *
     * This function will return true if the site is using a block template and false otherwise.
     *
     * @return bool True if the site is using a block template, false otherwise.
     */
    function is_block_theme()
    {
        return function_exists('wp_is_block_theme') && wp_is_block_theme();
    }
}

if (!function_exists('Framework\migrator')) {
    /**
     * Get the migrator instance.
     *
     * @return Migrator
     */
    function migrator()
    {
        return app()->make(Migrator::class);
    }
}

if (!function_exists('Framework\tap')) {
    /**
     * Call the given Closure with the given value.
     *
     * @param  mixed  $value
     * @param  \Closure  $callback
     * @return mixed
     */
    function tap($value, $callback = null)
    {
        if (is_null($callback)) {
            return new HigherOrderTapProxy($value);
        }

        $callback($value);

        return $value;
    }
}

if (!function_exists('Framework\faker')) {
    /**
     * Get the fake instance.
     *
     * @return Generator
     */
    function faker()
    {
        return app()->make(Factory::class);
    }
}

if (!function_exists('Framework\configure_dumper')) {
    
    function configure_dumper()
    {
        static $configured = false;

        if ($configured) {
            return;
        }

        $configured = true;
        $is_cli = defined('WP_CLI') && \WP_CLI;

        if ($is_cli) {
            VarDumper::setHandler(function ($var) {
                $dumper = new CliDumper();
                $dumper->dump((new VarCloner())->cloneVar($var));
            });
            return;
        }

        VarDumper::setHandler(function ($var) {
            $dumper = new HtmlDumper();
            $dumper->dump((new VarCloner())->cloneVar($var));
        });
    }
}

if (!function_exists('Framework\dump')) {
    /**
     * Dump the given arguments.
     *
     * @param mixed ...$args
     * 
     * @return void
     * 
     * @since 1.0.0
     */
    function dump(...$args)
    {
        if (!class_exists(VarDumper::class) || !app()->is_dev_mode()) {
            return;
        }

        configure_dumper();

        foreach ($args as $arg) {
            VarDumper::dump($arg);
        }
    }
}

if (!function_exists('Framework\dd')) {
    /**
     * Dump the given arguments and die.
     *
     * @param mixed ...$args
     * 
     * @return void
     * 
     * @since 1.0.0
     */
    function dd(...$args)
    {
        dump(...$args);
        die(1);
    }
}

if (!function_exists('Framework\app_path')) {
    /**
     * Get the path to the application directory.
     *
     * @param string $path
     * @return string
     */
    function app_path($path = '')
    {
        return app()->path($path);
    }
}

if (!function_exists('Framework\config_path')) {
    /**
     * Get the path to the config directory.
     *
     * @param string $path
     * @return string
     */
    function config_path($path = '')
    {
        return app()->config_path($path);
    }
}

if (!function_exists('Framework\database_path')) {
    /**
     * Get the path to the database directory.
     *
     * @param string $path
     * @return string
     */
    function database_path($path = '')
    {
        return app()->database_path($path);
    }
}

if (!function_exists('Framework\base_path')) {
    /**
     * Get the path to the base directory.
     *
     * @param string $path
     * @return string
     */
    function base_path($path = '')
    {
        return app()->base_path($path);
    }
}

if (!function_exists('Framework\resource_path')) {
    /**
     * Get the path to the resources directory.
     *
     * @param string $path
     * @return string
     */
    function resource_path($path = '')
    {
        return app()->resource_path($path);
    }
}

if (!function_exists('Framework\view_path')) {
    /**
     * Get the path to the views directory.
     *
     * @param string $path
     *
     * @return string
     *
     * @since 1.0.0
     */
    function view_path($path = '')
    {
        return app()->view_path($path);
    }
}

if (!function_exists('Framework\bootstrap_path')) {
    /**
     * Get the path to the bootstrap directory.
     *
     * @param string $path
     * @return string
     */
    function bootstrap_path($path = '')
    {
        return app()->bootstrap_path($path);
    }
}

if (!function_exists('Framework\collection')) {
    /**
     * Create a collection instance from an array.
     *
     * @param array $array
     * @return Collection
     */
    function collection(array $array = [])
    {
        return new Collection($array);
    }
}

if (!function_exists('Framework\resource_url')) {
    /**
     * Get the path to the resources directory.
     *
     * @param string $path
     * @return string
     */
    function resource_url($path = '')
    {
        return app()->base_url(path_join('resources', $path));
    }
}

if (!function_exists('Framework\json_decoded_data')) {
    /**
     * Get the decoded JSON data from a file.
     * 
     * @param string $file_path
     * @param bool $associative
     * @return mixed
     */
    function json_decoded_data(string $file_path, bool $associative = true)
    {
        if (!file_exists($file_path)) {
            return null;
        }

        $content = file_get_contents($file_path);

        return json_decode($content, $associative);
    }
}

if (!function_exists('Framework\value')) {
    /**
     * Get the value of a variable.
     * 
     * @param mixed $value
     * @param mixed ...$args
     * @return mixed
     */
    function value($value, ...$args) {
        return $value instanceof Closure ? $value(...$args) : $value;
    }
}

if (!function_exists('Framework\is_rest_request')) {
    /**
     * Check if the current request is a REST request.
     *
     * @return bool
     */
    function is_rest_request() {
        $is_rest = defined('REST_REQUEST') && REST_REQUEST;

        if ($is_rest) {
            return true;
        }

        $rest_route = Sanitizer::apply_rule(
            filter_input(INPUT_GET, 'rest_route', FILTER_UNSAFE_RAW),
            Sanitizer::BOOL
        );

        if ($rest_route) {
            return true;
        }

        $request_uri = filter_input(INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_URL);

        if (empty($request_uri)) {
            return false;
        }

        $is_rest = strpos($request_uri, '/' . rest_get_url_prefix() . '/') !== false;

        return $is_rest;
    }
}

if (!function_exists('Framework\message')) {
    /**
     * Get a message by key.
     *
     * @param string $key the key of the message
     * @param mixed $args the arguments to pass to the message
     *
     * @return string|null
     * 
     * @since 1.0.0
     */
    function message($key, ...$args)
    {
        return app()->make(MessagesBag::class)->get($key, ...$args);
    }
}