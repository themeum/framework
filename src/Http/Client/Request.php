<?php
/**
 * Fluent HTTP client built on wp_remote_request with chainable headers, body, and auth options.
 * Supports GET, POST, PUT, PATCH, DELETE and multipart or JSON body formats.
 * Macroable for extending with custom request helpers.
 *
 * @package    Framework
 * @subpackage Http\Client
 * @since      1.0.0
 */
namespace Framework\Http\Client;

defined('ABSPATH') || exit;

use BadMethodCallException;
use InvalidArgumentException;
use Framework\Http\Cookie;
use Framework\Supports\Arr;
use Framework\Supports\Str;
use Framework\Supports\Traits\Macroable;
use RuntimeException;
use WP_Http_Cookie;

use function Framework\collection;
use function Framework\Polyfill\str_contains;
use function Framework\throw_anyway;
use function Framework\throw_unless;

class Request
{
    /**
     * The macroable trait.
     *
     * @var Macroable
     */
    use Macroable {
        __call as call_macro;
    }

    /**
     * HTTP GET method.
     */
    public const GET = 'GET';

    /**
     * HTTP POST method.
     */
    public const POST = 'POST';

    /**
     * HTTP PUT method.
     */
    public const PUT = 'PUT';

    /**
     * HTTP PATCH method.
     */
    public const PATCH = 'PATCH';

    /**
     * HTTP DELETE method.
     */
    public const DELETE = 'DELETE';

    /**
     * HTTP HEAD method.
     */
    public const HEAD = 'HEAD';

    /**
     * HTTP OPTIONS method.
     */
    public const OPTIONS = 'OPTIONS';

    /**
     * The base URL for the request.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected string $base_url = '';

    /**
     * The options for the request.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected array $options = [];

    /**
     * The body format for the request.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected string $body_format;

    /**
     * The content for the request.
     *
     * @var mixed
     *
     * @since 1.0.0
     */
    protected $content = null;

    /**
     * The files for the request.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected $files = [];

    /**
     * The HTTP methods for the request.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected array $methods = [
        self::GET,
        self::POST,
        self::PUT,
        self::PATCH,
        self::DELETE,
        self::HEAD,
        self::OPTIONS,
    ];

    /**
     * The constructor.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function __construct()
    {
        $this->options = [
            'method' => 'GET',
            'timeout' => 30,
            'redirection' => 5,
            'httpversion' => '1.0',
            'reject_unsafe_url' => false,
            'blocking' => true,
            'cookies' => [],
            'body' => null,
            'compress' => false,
            'decompress' => true,
            'sslverify' => true,
            'stream' => false,
            'filename' => null,
            'limit_response_size' => null,
            'headers' => [],
        ];

        $this->as_json();
    }

    /**
     * Set the base URL for the request.
     *
     * @param string $url The url.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function base_url(string $url)
    {
        $this->base_url = $url;

        return $this;
    }

    /**
     * Get the base URL for the request.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function get_base_url()
    {
        return $this->base_url;
    }

    /**
     * Set the headers for the request.
     *
     * @param array $headers The headers.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function with_headers(array $headers)
    {
        $this->options = array_merge_recursive($this->options, [
            'headers' => $headers,
        ]);

        return $this;
    }

    /**
     * Replace the headers for the request.
     *
     * @param array $headers The headers.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function replace_headers(array $headers)
    {
        $this->options['headers'] = array_merge(
            $this->options['headers'] ?? [],
            $headers
        );

        return $this;
    }

    /**
     * Set the token for the request.
     *
     * @param string $token The token.
     * @param mixed $type The type.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function with_token(string $token, $type = 'Bearer')
    {
        return $this->with_headers([
            'Authorization' => trim($type . ' ' . $token),
        ]);
    }

    /**
     * Set the user agent for the request.
     *
     * @param mixed $user_agent The user agent.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function with_user_agent($user_agent)
    {
        return $this->with_headers([
            'User-Agent' => trim($user_agent),
        ]);
    }

    /**
     * Attach a cookie to the request.
     *
     * @param string $name The name of the cookie.
     * @param string $value The value of the cookie.
     * @param string|null $domain The domain the cookie is scoped to.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function with_cookie(string $name, string $value, ?string $domain = null)
    {
        $this->options['cookies'][$name] = $this->normalize_cookie($name, $value, $domain);

        return $this;
    }

    /**
     * Attach several cookies to the request.
     *
     * Accepts name and value pairs, Cookie instances, or WP_Http_Cookie instances.
     *
     * @param array $cookies The cookies to attach.
     * @param string|null $domain The domain the cookies are scoped to.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function with_cookies(array $cookies, ?string $domain = null)
    {
        foreach ($cookies as $name => $cookie) {
            $normalized = $this->normalize_cookie($name, $cookie, $domain);

            $this->options['cookies'][$normalized->name] = $normalized;
        }

        return $this;
    }

    /**
     * Normalize a cookie of any supported shape into a WP_Http_Cookie instance.
     *
     * @param string|int $name The cookie name, when the value is a plain string.
     * @param mixed $cookie The cookie value, Cookie instance, or WP_Http_Cookie instance.
     * @param string|null $domain The domain the cookie is scoped to.
     *
     * @return \WP_Http_Cookie
     *
     * @since 1.0.0
     */
    protected function normalize_cookie($name, $cookie, ?string $domain = null)
    {
        if ($cookie instanceof WP_Http_Cookie) {
            return $cookie;
        }

        if ($cookie instanceof Cookie) {
            return new WP_Http_Cookie([
                'name' => $cookie->get_name(),
                'value' => $cookie->get_value(),
                'expires' => $cookie->get_expires_time() ?: null,
                'path' => $cookie->get_path(),
                'domain' => $cookie->get_domain() ?? $domain,
            ]);
        }

        return new WP_Http_Cookie([
            'name' => (string) $name,
            'value' => (string) $cookie,
            'domain' => $domain,
        ]);
    }

    /**
     * Disable SSL verification for the request.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function without_verifying()
    {
        $this->options['sslverify'] = false;

        return $this;
    }

    /**
     * Set the method for the request.
     *
     * @param mixed $method The method name.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function method($method)
    {
        $this->options['method'] = $method;

        return $this;
    }

    /**
     * Set the accept header for the request.
     *
     * @param mixed $value The value.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function accept($value)
    {
        return $this->with_headers([
            'Accept' => $value,
        ]);
    }

    /**
     * Set the accept header for the request to JSON.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function accept_json()
    {
        return $this->accept('application/json');
    }

    /**
     * Set the body format for the request.
     *
     * @param string $format The format.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function body_format(string $format)
    {
        $this->body_format = $format;

        return $this;
    }

    /**
     * Set the body for the request.
     *
     * @param mixed $content The content.
     * @param mixed $type The type.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function with_body($content, $type = 'application/json')
    {
        $this->body_format = 'body';

        $this->content = $content;

        $this->content_type($type);

        return $this;
    }

    /**
     * Set the body format for the request to JSON.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function as_json()
    {
        return $this->body_format('json')->content_type('application/json');
    }

    /**
     * Set the body format for the request to form.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function as_form()
    {
        return $this->body_format('form')->content_type('application/x-www-form-urlencoded');
    }

    /**
     * Set the body format for the request to multipart.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function as_multipart()
    {
        return $this->body_format('multipart')->content_type('multipart/form-data');
    }

    /**
     * Attach a file to the request.
     *
     * @param mixed $name The name.
     * @param mixed $contents The contents.
     * @param mixed $filename The filename.
     * @param array $headers The headers.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function attach($name, $contents = '', $filename = null, array $headers = [])
    {
        if (is_array($name)) {
            foreach ($name as $file) {
                $this->attach(...$file);
            }

            return $this;
        }

        $this->as_multipart();

        $this->files[] = array_filter([
            'name' => $name,
            'contents' => $contents,
            'headers' => $headers,
            'filename' => $filename,
        ]);

        return $this;
    }

    /**
     * Set the content type for the request.
     *
     * @param mixed $type The type.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function content_type($type)
    {
        $this->options['headers'] ??= [];
        $this->options['headers']['Content-Type'] = $type;

        return $this;
    }

    /**
     * Set the timeout for the request.
     *
     * @param mixed $seconds The seconds.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function timeout($seconds)
    {
        $this->options['timeout'] = $seconds;

        return $this;
    }

    /**
     * Set the options for the request.
     *
     * @param array $options The options array.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function with_options(array $options)
    {
        $this->options = array_replace_recursive(
            array_merge_recursive(
                $this->options,
                Arr::only($options, ['headers', 'json', 'multipart', 'query', 'cookies'])
            ),
            $options
        );

        return $this;
    }

    /**
     * Set the body for the request.
     *
     * @param mixed $data The data payload.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    protected function with_data($data)
    {
        $this->options['body'] = $data;

        return $this;
    }

    /**
     * Send a GET request.
     *
     * @param string $url The url.
     * @param mixed $query The query builder instance.
     *
     * @return Response
     *
     * @since 1.0.0
     */
    public function get(string $url, $query = null)
    {
        return $this->send('GET', $url, func_num_args() === 1 ? [] : [
            'query' => $query
        ]);
    }

    /**
     * Send a HEAD request.
     *
     * @param string $url The url.
     * @param mixed $query The query builder instance.
     *
     * @return Response
     *
     * @since 1.0.0
     */
    public function head(string $url, $query = null)
    {
        return $this->send('HEAD', $url, func_num_args() === 1 ? [] : [
            'query' => $query
        ]);
    }

    /**
     * Send a POST request.
     *
     * @param string $url The url.
     * @param mixed $data The data payload.
     *
     * @return Response
     *
     * @since 1.0.0
     */
    public function post(string $url, $data = [])
    {
        return $this->send('POST', $url, func_num_args() === 1 ? [] : [
            $this->body_format => $data
        ]);
    }

    /**
     * Send a PUT request.
     *
     * @param string $url The url.
     * @param mixed $data The data payload.
     *
     * @return Response
     *
     * @since 1.0.0
     */
    public function put(string $url, $data = [])
    {
        return $this->send('PUT', $url, func_num_args() === 1 ? [] : [
            $this->body_format => $data
        ]);
    }

    /**
     * Send a PATCH request.
     *
     * @param string $url The url.
     * @param mixed $data The data payload.
     *
     * @return Response
     *
     * @since 1.0.0
     */
    public function patch(string $url, $data = [])
    {
        return $this->send('PATCH', $url, func_num_args() === 1 ? [] : [
            $this->body_format => $data
        ]);
    }

    /**
     * Send a DELETE request.
     *
     * @param string $url The url.
     * @param mixed $data The data payload.
     *
     * @return Response
     *
     * @since 1.0.0
     */
    public function delete(string $url, $data = [])
    {
        return $this->send('DELETE', $url, func_num_args() === 1 ? [] : [
            $this->body_format => $data
        ]);
    }

    /**
     * Send an OPTIONS request.
     *
     * @param string $url The url.
     * @param mixed $data The data payload.
     *
     * @return Response
     *
     * @since 1.0.0
     */
    public function options(string $url, $data = [])
    {
        return $this->send('OPTIONS', $url, func_num_args() === 1 ? [] : [
            $this->body_format => $data
        ]);
    }

    /**
     * Send a request.
     *
     * @param string $method The method name.
     * @param string $url The url.
     * @param array $options The options array.
     *
     * @return Response
     *
     * @since 1.0.0
     */
    public function send(string $method, string $url, array $options = [])
    {
        if (!Str::starts_with($url, ['http', 'https'])) {
            $url = ltrim(
                rtrim($this->base_url, '/') . '/' . ltrim($url, '/'),
                '/'
            );
        }

        $this->method($method);

        return $this->send_request($method, $url, $options);
    }

    /**
     * Send several requests concurrently and return their responses.
     *
     * The callback receives a Pool. Each $pool->as($key) or $pool->method() call starts
     * a fresh request that does not inherit this request's settings. Verb methods on a
     * pooled request record it; the pool sends everything once the callback returns.
     *
     * @param callable $callback Receives the Pool to add requests to.
     * @param array $options Pool options: 'concurrency', the maximum requests in flight (default 10).
     *
     * @return array<string|int, Response>
     *
     * @throws \InvalidArgumentException When the concurrency is not a positive integer.
     *
     * @since 3.3.0
     */
    public function pool(callable $callback, array $options = [])
    {
        $concurrency = $options['concurrency'] ?? Pool::DEFAULT_CONCURRENCY;

        throw_unless(
            is_int($concurrency) && $concurrency >= 1,
            'The pool concurrency must be a positive integer.',
            InvalidArgumentException::class
        );

        $pool = $this->new_pool();

        $callback($pool);

        return $pool->send($concurrency);
    }

    /**
     * Create a new pool.
     *
     * @return Pool
     *
     * @since 3.3.0
     */
    protected function new_pool()
    {
        return new Pool();
    }

    /**
     * Send a request.
     *
     * @param string $method The method name.
     * @param string $url The url.
     * @param array $options The options array.
     *
     * @return Response
     *
     * @since 1.0.0
     */
    protected function send_request(string $method, string $url, array $options = [])
    {
        [$url, $args] = $this->build_request($method, $url, $options);

        return $this->dispatch($url, $args);
    }

    /**
     * Build the final URL and the arguments the WordPress HTTP layer receives.
     *
     * Kept apart from dispatch so a pooled request is built by exactly the same
     * code as a single one and only the sending differs.
     *
     * @param string $method The method name.
     * @param string $url The url.
     * @param array $options The options array.
     *
     * @return array
     *
     * @throws \RuntimeException
     *
     * @since 3.3.0
     */
    protected function build_request(string $method, string $url, array $options = [])
    {
        throw_unless(
            $this->is_valid_method($method),
            sprintf('Invalid HTTP method: %s', $method),
            RuntimeException::class
        );

        $data = $this->parse_request_data($method, $url, $options);
        $url = $this->prepare_request_url($method, $url, $data);
        $body = $this->prepare_request_body($method, $data);
        $args = $this->prepare_request_args($body);

        return [$url, $args];
    }

    /**
     * Dispatch a built request through the WordPress HTTP API.
     *
     * @param string $url The final url.
     * @param array $args The request arguments.
     *
     * @return Response
     *
     * @since 3.3.0
     */
    protected function dispatch(string $url, array $args)
    {
        return $this->new_response(wp_remote_request($url, $args));
    }

    /**
     * Prepare request url.
     *
     * @param string $method The method name.
     * @param string $url The url.
     * @param array $data The data payload.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function prepare_request_url(string $method, string $url, array $data)
    {
        if (!in_array($method, ['GET', 'HEAD'])) {
            return $url;
        }

        if (empty($data)) {
            return $url;
        }

        return $url . '?' . http_build_query($data);
    }

    /**
     * Create a new response instance.
     *
     * @param mixed $response The response instance.
     *
     * @return Response
     *
     * @since 1.0.0
     */
    protected function new_response($response)
    {
        return new Response($response);
    }

    /**
     * Parse the request data.
     *
     * @param string $method The method name.
     * @param string $url The url.
     * @param array $options The options array.
     *
     * @return array
     *
     * @since 1.0.0
     */
    protected function parse_request_data(string $method, string $url, array $options = [])
    {
        if ($this->body_format === 'body') {
            return [];
        }

        $request_data = $options[$this->body_format] ?? $options['query'] ?? [];

        if (empty($request_data) && $method === 'GET' && str_contains($url, '?')) {
            $request_data = substr($url, strpos($url, '?') + 1);
        }

        if (is_string($request_data)) {
            parse_str($request_data, $parsed_data);
            $request_data = is_array($parsed_data) ? $parsed_data : [];
        }

        return is_array($request_data) ? $request_data : [];
    }

    /**
     * Prepare the request body.
     *
     * @param string $method The method name.
     * @param array $data The data payload.
     *
     * @return string|array
     *
     * @throws \RuntimeException
     *
     * @since 1.0.0
     */
    protected function prepare_request_body(string $method, array $data)
    {
        if (in_array($method, ['GET', 'HEAD'])) {
            return [];
        }

        switch ($this->body_format) {
            case 'json':
                return empty($data) ? '' : Arr::json_encode($data);
            case 'body':
                return $this->content ?? [];
            case 'form':
                return $data;
            case 'multipart':
                return $this->make_multipart_body($data);
            default:
                throw_anyway(sprintf('Invalid body format: %s', $this->body_format), RuntimeException::class);
        }
    }

    /**
     * Make a multipart body.
     *
     * @param array $data The data payload.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function make_multipart_body(array $data)
    {
        $data = $this->prepare_multipart_data($data);
        $stream = new MultipartStream(
            $data
        );
        $boundary = $stream->boundary();

        $this->content_type("multipart/form-data; boundary={$boundary}");

        return $stream->payload();
    }

    /**
     * Prepare the multipart data.
     *
     * @param array $data The data payload.
     *
     * @return array
     *
     * @since 1.0.0
     */
    protected function prepare_multipart_data(array $data)
    {
        return collection($data)->flat_map(function ($item, $key) {
            if (is_array($item)) {
                if (isset($item['name']) && isset($item['contents'])) {
                    return $item;
                }

                return collection($item)->map(function ($value) use ($key) {
                    return [
                        'name' => $key . '[]',
                        'contents' => $value,
                    ];
                })->all();
            }

            return [
                [
                    'name' => $key,
                    'contents' => $item,
                ]
            ];
        })->values()->merge($this->files ?? [])->all();
    }


    /**
     * Prepare the request arguments.
     *
     * @param mixed $data The data payload.
     *
     * @return array
     *
     * @since 1.0.0
     */
    protected function prepare_request_args($data)
    {
        return array_merge($this->options, [
            'body' => $data ?: null,
        ]);
    }

    /**
     * Check if the method is valid.
     *
     * @param string $method The method name.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    protected function is_valid_method(string $method)
    {
        return in_array(strtoupper($method), $this->methods, true);
    }

    /**
     * Call a macro.
     *
     * @param mixed $method The method name.
     * @param mixed $parameters The parameters array.
     *
     * @return mixed
     *
     * @throws \BadMethodCallException
     *
     * @since 1.0.0
     */
    public function __call($method, $parameters)
    {
        if (static::has_macro($method)) {
            return $this->call_macro($method, $parameters);
        }

        throw_anyway(sprintf('Call to undefined method %s::%s', static::class, $method), BadMethodCallException::class);
    }
}
