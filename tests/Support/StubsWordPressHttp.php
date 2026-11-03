<?php

/**
 * Stand-ins for the WordPress HTTP API classes and the Requests result types the
 * client pool touches. Each is defined only when the real class is absent, so the
 * suite still runs against a real WordPress if one is loaded.
 */

namespace WpOrg\Requests {
    if (!class_exists(Exception::class)) {
        class Exception extends \Exception
        {
        }
    }

    if (!class_exists(Response::class)) {
        class Response
        {
            public $body = '';

            public $status_code = 200;

            public $headers = [];

            public $url = '';

            public function __construct(int $status_code = 200, string $body = '', array $headers = [])
            {
                $this->status_code = $status_code;
                $this->body = $body;
                $this->headers = $headers;
            }
        }
    }
}

namespace WpOrg\Requests\Proxy {
    if (!class_exists(Http::class)) {
        class Http
        {
            public $proxy;

            public $use_authentication = false;

            public $user;

            public $pass;

            public function __construct($args = null)
            {
                $this->proxy = $args;
            }
        }
    }
}

namespace {
    if (!class_exists('WP_Http')) {
        class WP_Http
        {
            public function block_request($uri)
            {
                if (empty($GLOBALS['framework_test_block_external'])) {
                    return false;
                }

                $host = parse_url($uri, PHP_URL_HOST);

                if ($host === 'localhost' || $host === parse_url(get_bloginfo('url'), PHP_URL_HOST)) {
                    return false;
                }

                return !in_array($host, $GLOBALS['framework_test_accessible_hosts'] ?? [], true);
            }

            public static function normalize_cookies($cookies)
            {
                return $cookies;
            }

            public static function processHeaders($headers, $url = '')
            {
                $parsed = [];

                foreach (preg_split('/\r?\n/', (string) $headers) as $line) {
                    if (strpos($line, ':') !== false) {
                        [$name, $value] = explode(':', $line, 2);
                        $parsed[strtolower(trim($name))] = trim($value);
                    }
                }

                return ['headers' => $parsed];
            }

            public static function browser_redirect_compatibility($location, $headers, $data, &$options, $original)
            {
            }

            public static function validate_redirects($location)
            {
            }
        }
    }

    if (!class_exists('WP_HTTP_Proxy')) {
        class WP_HTTP_Proxy
        {
            public function is_enabled()
            {
                return !empty($GLOBALS['framework_test_proxy']);
            }

            public function send_through_proxy($uri)
            {
                return true;
            }

            public function host()
            {
                return $GLOBALS['framework_test_proxy']['host'] ?? '';
            }

            public function port()
            {
                return $GLOBALS['framework_test_proxy']['port'] ?? '';
            }

            public function use_authentication()
            {
                return isset($GLOBALS['framework_test_proxy']['user']);
            }

            public function username()
            {
                return $GLOBALS['framework_test_proxy']['user'] ?? '';
            }

            public function password()
            {
                return $GLOBALS['framework_test_proxy']['pass'] ?? '';
            }
        }
    }

    if (!class_exists('WP_HTTP_Requests_Hooks')) {
        class WP_HTTP_Requests_Hooks
        {
            public $url;

            public $request;

            public $registered = [];

            public function __construct($url, $request)
            {
                $this->url = $url;
                $this->request = $request;
            }

            public function register($hook, $callback, $priority = 0)
            {
                $this->registered[$hook][] = $callback;
            }
        }
    }

    if (!class_exists('WP_HTTP_Requests_Response')) {
        class WP_HTTP_Requests_Response
        {
            public $response;

            public $filename;

            public function __construct($response, $filename = '')
            {
                $this->response = $response;
                $this->filename = $filename;
            }

            public function to_array()
            {
                return [
                    'headers' => $this->response->headers,
                    'body' => $this->response->body,
                    'response' => [
                        'code' => $this->response->status_code,
                        'message' => $this->response->status_code === 200 ? 'OK' : 'Error',
                    ],
                    'cookies' => [],
                    'filename' => $this->filename,
                ];
            }
        }
    }
}
