<?php
/**
 * Collects the requests built inside Http::pool() and sends them concurrently with
 * WpOrg\Requests\Requests::request_multiple(). That call bypasses WP_Http::request(),
 * so this class reproduces what WP_Http::request() (WordPress 6.9.4) does around the
 * transport: default arguments and filters, the pre_http_request short-circuit, URL
 * checks, Requests options, the response array shape, and the debug and response hooks.
 *
 * @package    Framework
 * @subpackage Http\Client
 * @since      3.3.0
 */
namespace Framework\Http\Client;

defined('ABSPATH') || exit;

use InvalidArgumentException;
use RuntimeException;

use function Framework\throw_if;
use function Framework\throw_unless;

class Pool
{
    /**
     * The number of requests in flight at once when no concurrency is given.
     *
     * @since 3.3.0
     */
    public const DEFAULT_CONCURRENCY = 10;

    /**
     * The pooled requests keyed by their response key, in the order they were added.
     *
     * @var array<string|int, PooledRequest>
     *
     * @since 3.3.0
     */
    protected array $requests = [];

    /**
     * The integer key the next unkeyed request receives, following PHP array-append rules.
     *
     * @var int
     *
     * @since 3.3.0
     */
    protected int $next_index = 0;

    /**
     * Start a pooled request whose response is stored under the given key.
     *
     * @param string|int $key The response key.
     *
     * @return PooledRequest
     *
     * @throws \InvalidArgumentException When the key is not a string or integer, or is already used.
     *
     * @since 3.3.0
     */
    public function as($key)
    {
        throw_unless(
            is_string($key) || is_int($key),
            'A pooled request key must be a string or an integer.',
            InvalidArgumentException::class
        );

        // Normalise the key the way PHP does, so '5' and 5 are the same slot.
        $key = array_key_first([$key => true]);

        throw_if(
            array_key_exists($key, $this->requests),
            sprintf('The pooled request key [%s] is already used.', $key),
            InvalidArgumentException::class
        );

        return $this->add_request($key);
    }

    /**
     * Start an unkeyed pooled request and forward the call to it.
     *
     * @param string $method The method name.
     * @param array $parameters The parameters array.
     *
     * @return mixed
     *
     * @since 3.3.0
     */
    public function __call($method, $parameters)
    {
        return $this->add_request($this->next_index)->$method(...$parameters);
    }

    /**
     * Register a new pooled request under the given key.
     *
     * @param string|int $key The response key.
     *
     * @return PooledRequest
     *
     * @since 3.3.0
     */
    protected function add_request($key)
    {
        if (is_int($key) && $key >= $this->next_index) {
            $this->next_index = $key + 1;
        }

        return $this->requests[$key] = $this->new_request();
    }

    /**
     * Create a new pooled request.
     *
     * @return PooledRequest
     *
     * @since 3.3.0
     */
    protected function new_request()
    {
        return new PooledRequest();
    }

    /**
     * Send every pooled request and return their responses in the order they were added.
     *
     * @param int $concurrency The maximum number of requests in flight at once.
     *
     * @return array<string|int, Response>
     *
     * @throws \RuntimeException When a pooled request was never given a verb method.
     *
     * @since 3.3.0
     */
    public function send(int $concurrency = self::DEFAULT_CONCURRENCY)
    {
        if (empty($this->requests)) {
            return [];
        }

        foreach ($this->requests as $key => $request) {
            throw_unless(
                $request->is_recorded(),
                sprintf('The pooled request [%s] was never sent with a verb method.', $key),
                RuntimeException::class
            );
        }

        $http = new \WP_Http();
        $results = [];
        $pending = [];

        foreach ($this->requests as $key => $request) {
            [$url, $args] = $request->get_recorded();

            $prepared = $this->prepare_request($http, $url, $args);

            if (array_key_exists('response', $prepared)) {
                $results[$key] = $prepared['response'];
            } else {
                $results[$key] = null;
                $pending[$key] = $prepared;
            }
        }

        foreach (array_chunk($pending, $concurrency, true) as $batch) {
            foreach ($this->send_prepared_batch($batch) as $key => $response) {
                $results[$key] = $response;
            }
        }

        return array_map(function ($response) {
            return new Response($response);
        }, $results);
    }

    /**
     * Send one batch of prepared requests and convert each result.
     *
     * A Requests exception for the whole batch, such as no working transport, fails
     * every request in it rather than escaping the pool.
     *
     * @param array $batch The prepared requests keyed by response key.
     *
     * @return array
     *
     * @since 3.3.0
     */
    protected function send_prepared_batch(array $batch)
    {
        $requests = array_map(function ($prepared) {
            return $prepared['request'];
        }, $batch);

        try {
            $results = $this->send_batch($requests);
        } catch (\WpOrg\Requests\Exception $exception) {
            $results = array_fill_keys(array_keys($batch), $exception);
        }

        $responses = [];

        foreach ($batch as $key => $prepared) {
            $responses[$key] = $this->convert_result($results[$key], $prepared['url'], $prepared['args']);
        }

        return $responses;
    }

    /**
     * Send a batch of requests concurrently. This is the only place the network is touched.
     *
     * @param array $requests The Requests payloads keyed by response key.
     *
     * @return array The Requests response or exception for each key.
     *
     * @since 3.3.0
     */
    protected function send_batch(array $requests)
    {
        mbstring_binary_safe_encoding();

        try {
            return \WpOrg\Requests\Requests::request_multiple($requests);
        } finally {
            reset_mbstring_encoding();
        }
    }

    /**
     * Run everything WP_Http::request() does before the transport for one request.
     *
     * Returns ['response' => $value] when the request is answered without the network
     * (short-circuited, rejected or blocked), otherwise the Requests payload with the
     * final URL and arguments.
     *
     * @param \WP_Http $http The WordPress HTTP instance used for host blocking.
     * @param string $url The url.
     * @param array $args The request arguments.
     *
     * @return array
     *
     * @since 3.3.0
     */
    protected function prepare_request($http, string $url, array $args)
    {
        $args = wp_parse_args($args, $this->default_args($url, $args));
        $args = apply_filters('http_request_args', $args, $url);

        if (!isset($args['_redirection'])) {
            $args['_redirection'] = $args['redirection'];
        }

        // WordPress returns a short-circuit as is, with no debug action or response filter.
        $pre = apply_filters('pre_http_request', false, $args, $url);

        if ($pre !== false) {
            return ['response' => $pre];
        }

        if (function_exists('wp_kses_bad_protocol')) {
            if ($args['reject_unsafe_urls']) {
                $url = wp_http_validate_url($url);
            }

            if ($url) {
                $url = wp_kses_bad_protocol($url, ['http', 'https', 'ssl']);
            }
        }

        $parsed_url = parse_url((string) $url);

        if (empty($url) || empty($parsed_url['scheme'])) {
            return ['response' => $this->reject(
                new \WP_Error('http_request_failed', __('A valid URL was not provided.')),
                (string) $url,
                $args
            )];
        }

        if ($http->block_request($url)) {
            return ['response' => $this->reject(
                new \WP_Error('http_request_not_executed', __('User has blocked requests through HTTP.')),
                $url,
                $args
            )];
        }

        if ($args['stream']) {
            if (empty($args['filename'])) {
                $args['filename'] = get_temp_dir() . basename($url);
            }

            $args['blocking'] = true;

            if (!wp_is_writable(dirname($args['filename']))) {
                return ['response' => $this->reject(
                    new \WP_Error(
                        'http_request_failed',
                        __('Destination directory for file streaming does not exist or is not writable.')
                    ),
                    $url,
                    $args
                )];
            }
        }

        if (is_null($args['headers'])) {
            $args['headers'] = [];
        }

        if (!is_array($args['headers'])) {
            $processed_headers = \WP_Http::processHeaders($args['headers']);
            $args['headers'] = $processed_headers['headers'];
        }

        return [
            'request' => [
                'url' => $url,
                'headers' => $args['headers'],
                'data' => $args['body'],
                'type' => $args['method'],
                'options' => $this->requests_options($url, $args),
            ],
            'url' => $url,
            'args' => $args,
        ];
    }

    /**
     * Build the default arguments WP_Http::request() merges under the caller's arguments.
     *
     * @param string $url The url.
     * @param array $args The caller's arguments, read only for the HEAD redirection rule.
     *
     * @return array
     *
     * @since 3.3.0
     */
    protected function default_args(string $url, array $args)
    {
        $defaults = [
            'method' => 'GET',
            'timeout' => apply_filters('http_request_timeout', 5, $url),
            'redirection' => apply_filters('http_request_redirection_count', 5, $url),
            'httpversion' => apply_filters('http_request_version', '1.0', $url),
            'user-agent' => apply_filters(
                'http_headers_useragent',
                'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
                $url
            ),
            'reject_unsafe_urls' => apply_filters('http_request_reject_unsafe_urls', false, $url),
            'blocking' => true,
            'headers' => [],
            'cookies' => [],
            'body' => null,
            'compress' => false,
            'decompress' => true,
            'sslverify' => true,
            'sslcertificates' => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
            'stream' => false,
            'filename' => null,
            'limit_response_size' => null,
        ];

        // By default, HEAD requests do not follow redirects.
        if (isset($args['method']) && $args['method'] === 'HEAD') {
            $defaults['redirection'] = 0;
        }

        return $defaults;
    }

    /**
     * Convert WordPress request arguments to Requests options, as WP_Http::request() does.
     *
     * @param string $url The final url.
     * @param array $args The final request arguments.
     *
     * @return array
     *
     * @since 3.3.0
     */
    protected function requests_options(string $url, array $args)
    {
        $options = [
            'timeout' => $args['timeout'],
            'useragent' => $args['user-agent'],
            'blocking' => $args['blocking'],
            'hooks' => new \WP_HTTP_Requests_Hooks($url, $args),
        ];

        $options['hooks']->register('requests.before_redirect', [\WP_Http::class, 'browser_redirect_compatibility']);

        if (function_exists('wp_kses_bad_protocol') && $args['reject_unsafe_urls']) {
            $options['hooks']->register('requests.before_redirect', [\WP_Http::class, 'validate_redirects']);
        }

        if ($args['stream']) {
            $options['filename'] = $args['filename'];
        }

        if (empty($args['redirection'])) {
            $options['follow_redirects'] = false;
        } else {
            $options['redirects'] = $args['redirection'];
        }

        if (isset($args['limit_response_size'])) {
            $options['max_bytes'] = $args['limit_response_size'];
        }

        if (!empty($args['cookies'])) {
            $options['cookies'] = \WP_Http::normalize_cookies($args['cookies']);
        }

        if (!$args['sslverify']) {
            $options['verify'] = false;
            $options['verifyname'] = false;
        } else {
            $options['verify'] = $args['sslcertificates'];
        }

        if ($args['method'] !== 'HEAD' && $args['method'] !== 'GET') {
            $options['data_format'] = 'body';
        }

        $options['verify'] = apply_filters('https_ssl_verify', $options['verify'], $url);

        $proxy = new \WP_HTTP_Proxy();

        if ($proxy->is_enabled() && $proxy->send_through_proxy($url)) {
            $options['proxy'] = new \WpOrg\Requests\Proxy\Http($proxy->host() . ':' . $proxy->port());

            if ($proxy->use_authentication()) {
                $options['proxy']->use_authentication = true;
                $options['proxy']->user = $proxy->username();
                $options['proxy']->pass = $proxy->password();
            }
        }

        return $options;
    }

    /**
     * Convert one Requests result to the value wp_remote_request() would return.
     *
     * @param mixed $result The Requests response or exception.
     * @param string $url The final url.
     * @param array $args The final request arguments.
     *
     * @return array|\WP_Error
     *
     * @since 3.3.0
     */
    protected function convert_result($result, string $url, array $args)
    {
        if ($result instanceof \WpOrg\Requests\Exception) {
            $response = new \WP_Error('http_request_failed', $result->getMessage());
        } else {
            $http_response = new \WP_HTTP_Requests_Response($result, $args['filename']);
            $response = $http_response->to_array();
            $response['http_response'] = $http_response;
        }

        do_action('http_api_debug', $response, 'response', \WpOrg\Requests\Requests::class, $args, $url);

        if (is_wp_error($response)) {
            return $response;
        }

        if (!$args['blocking']) {
            return [
                'headers' => [],
                'body' => '',
                'response' => [
                    'code' => false,
                    'message' => false,
                ],
                'cookies' => [],
                'http_response' => null,
            ];
        }

        return apply_filters('http_response', $response, $args, $url);
    }

    /**
     * Fire the debug action for a request rejected before the transport and return the error.
     *
     * @param \WP_Error $error The rejection.
     * @param string $url The url.
     * @param array $args The request arguments.
     *
     * @return \WP_Error
     *
     * @since 3.3.0
     */
    protected function reject($error, string $url, array $args)
    {
        do_action('http_api_debug', $error, 'response', \WpOrg\Requests\Requests::class, $args, $url);

        return $error;
    }
}
