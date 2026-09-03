<?php
/**
 * Handles REST API request data abstraction for operations.
 *
 * @package    Framework
 * @subpackage Http
 * @since      1.0.0
 */
namespace Framework\Http;

defined('ABSPATH') || exit;

use BadMethodCallException;
use Framework\Contracts\Request as RequestContract;
use Framework\Contracts\Support\Arrayable;
use Framework\Sanitizer;
use Framework\Exceptions\AuthorizationException;
use Framework\Exceptions\ValidationException;
use Framework\Managers\SessionManager;
use Framework\Http\Concerns\InteractsWithFiles;
use Framework\Supports\Arr;
use Framework\Validation\Validator;
use WP_REST_Request;
use InvalidArgumentException;

use function Framework\app;
use function Framework\config;
use function Framework\message;
use function Framework\user;
use function Framework\value;

/**
 * The Request class for handling HTTP requests.
 */
class Request implements RequestContract, Arrayable
{
    use InteractsWithFiles;

    /**
     * The request's input attributes.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected $attributes = [];

    /**
     * The HTTP method used for the request (e.g. GET, POST).
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected $method;

    /**
     * The route URI for the request.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected $route;

    /**
     * The headers associated with the request.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected $headers;

    /**
     * The server parameters the request was built from.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected $server = [];

    /**
     * The cookies sent by the client.
     *
     * This bag is deliberately kept out of the request attributes so that a
     * client supplied cookie can never satisfy or override a validated input.
     *
     * @var array<string,mixed>
     *
     * @since 1.0.0
     */
    protected array $cookies = [];

    /**
     * The sanitized data.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected array $sanitized = [];

    /**
     * The validated data.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected array $validated = [];

    /**
     * Whether the validation has been resolved.
     *
     * @var bool
     *
     * @since 1.0.0
     */
    protected bool $validation_resolved = false;

    /**
     * The route parameters.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected array $route_params = [];

    /**
     * Registered sanitizer method suffixes for typed request accessors.
     *
     * @var array<int, string>
     *
     * @since 1.0.0
     */
    protected static array $types = [
        'string',
        'date',
        'datetime',
        'text',
        'html',
        'email',
        'url',
        'key',
        'title',
        'file_name',
        'mime_type',
        'int',
        'bool',
        'float',
        'array',
        'whitelisted',
    ];

    /**
     * Magic getter to retrieve request attributes.
     *
     * @param string $name The name of the attribute.
     *
     * @return mixed|null The attribute value or null if not set.
     *
     * @since 1.0.0
     */
    public function __get(string $name)
    {
        return Arr::get($this->all(), $name, null);
    }

    /**
     * Magic isset to check if an attribute exists.
     *
     * @param string $name The name of the attribute.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function __isset(string $name)
    {
        return !is_null($this->__get($name));
    }

    /**
     * Magic setter to set request attributes.
     *
     * @param string $name The name of the attribute.
     * @param mixed $value The value to assign.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function __set(string $name, $value)
    {
        $this->attributes[$name] = $value;
    }

    /**
     * Dynamically handle calls to the class.
     *
     * @param string $name The name of the method.
     * @param array $arguments The arguments of the method.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public function __call(string $name, array $arguments)
    {
        $name = strtolower($name);

        if (!in_array($name, static::$types, true)) {
            throw new BadMethodCallException(
                sprintf('Method %s::%s does not exist.', static::class, $name)
            );
        }

        $method_name = 'get_' . $name;

        return $this->$method_name(...$arguments);
    }

    /**
     * Create a new Request instance from a WP_REST_Request.
     *
     * @param WP_REST_Request $request The WordPress REST request object.
     *
     * @return self
     *
     * @since 1.0.0
     */
    public static function from_wp_rest_request(WP_REST_Request $request)
    {
        return (new static())->make_request($request);
    }

    /**
     * Make a new request instance from a WP_REST_Request.
     *
     * @param WP_REST_Request $request The WordPress REST request object.
     *
     * @return self
     *
     * @since 1.0.0
     */
    public function make_request(WP_REST_Request $request)
    {
        $this->attributes = array_merge(
            $this->attributes,
            $request->get_params(),
            $request->get_file_params()
        );

        $this->method = $request->get_method();
        $this->route = $request->get_route();
        $this->headers = $request->get_headers();
        $this->route_params = $request->get_url_params();

        // WP_REST_Request carries no cookie params, so read them from the superglobal.
        // phpcs:ignore Framework.NamingConventions.SnakeCaseVariable.NotSnakeCase
        $this->cookies = $this->unslash_array($_COOKIE ?? []);

        return $this;
    }

    /**
     * Capture the current HTTP request from PHP superglobals.
     *
     * @return self
     *
     * @since 1.0.0
     */
    public static function capture()
    {
        // phpcs:ignore Framework.NamingConventions.SnakeCaseVariable.NotSnakeCase
        return (new static())->make_from_http($_GET, $_POST, $_FILES, $_SERVER, [], $_COOKIE);
    }

    /**
     * Make a request instance from raw HTTP input arrays.
     *
     * @param array $query Query string parameters.
     * @param array $body Request body parameters.
     * @param array $files Uploaded files.
     * @param array $server Server parameters.
     * @param array $route_params Matched route parameters.
     * @param array $cookies Cookies sent by the client.
     *
     * @return self
     *
     * @since 1.0.0
     */
    public function make_from_http(
        array $query = [],
        array $body = [],
        array $files = [],
        array $server = [],
        array $route_params = [],
        array $cookies = []
    ) {
        $query = $this->unslash_array($query);
        $body = $this->unslash_array($body);

        $this->attributes = array_merge($query, $body, $route_params);
        $this->method = strtoupper($server['REQUEST_METHOD'] ?? 'GET');
        $this->route = $this->resolve_request_path($server);
        $this->headers = $this->extract_headers($server);
        $this->server = $server;
        $this->route_params = $route_params;
        $this->cookies = $this->unslash_array($cookies);
        $this->files = [];

        if (!empty($files)) {
            $this->load_files_from_array($files);
        }

        return $this;
    }

    /**
     * Set the matched route parameters and merge them into attributes.
     *
     * @param array $params The route parameters.
     *
     * @return self
     *
     * @since 1.0.0
     */
    public function set_route_params(array $params)
    {
        $this->route_params = $params;
        $this->attributes = array_merge($this->attributes, $params);

        return $this;
    }

    /**
     * Get all route parameters.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function route_params()
    {
        return $this->route_params;
    }

    /**
     * Unslash an array of input values.
     *
     * @param array $values The values to unslash.
     *
     * @return array
     *
     * @since 1.0.0
     */
    protected function unslash_array(array $values)
    {
        if (!function_exists('wp_unslash')) {
            return $values;
        }

        return array_map(function ($value) {
            if (is_array($value)) {
                return $this->unslash_array($value);
            }

            return is_string($value) ? wp_unslash($value) : $value;
        }, $values);
    }

    /**
     * Resolve the request path from server parameters.
     *
     * @param array $server Server parameters.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function resolve_request_path(array $server)
    {
        $request_uri = isset($server['REQUEST_URI']) ? (string) $server['REQUEST_URI'] : '';
        $path = (string) parse_url($request_uri, PHP_URL_PATH);

        if (function_exists('home_url')) {
            $home_path = (string) parse_url(home_url(), PHP_URL_PATH);

            if ($home_path !== '' && $home_path !== '/' && strpos($path, $home_path) === 0) {
                $path = substr($path, strlen($home_path));
            }
        }

        return trim($path, '/');
    }

    /**
     * Extract HTTP headers from server parameters.
     *
     * @param array $server Server parameters.
     *
     * @return array
     *
     * @since 1.0.0
     */
    protected function extract_headers(array $server)
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $name = $this->prepare_header_name(substr($key, 5));
                $headers[$name] = [$value];
                continue;
            }

            if (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $name = $this->prepare_header_name($key);
                $headers[$name] = [$value];
            }
        }

        return $headers;
    }

    /**
     * Convert a header name to a WordPress compatible name.
     *
     * @param string $name The name to convert.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function prepare_header_name(string $name)
    {
        return str_replace(' ', '_', str_replace('-', ' ', strtolower($name)));
    }

    /**
     * Load uploaded files from a provided files array.
     *
     * @param array $files The files array.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function load_files_from_array(array $files)
    {
        $converted = array_map(function ($file) {
            if (isset($file['name']) && is_array($file['name'])) {
                return $this->convert_uploaded_files($file);
            }

            return \Framework\Filesystem\UploadedFile::create_from_base($file);
        }, $files);

        $keys = array_keys($files);
        $this->files = array_combine($keys, array_values($converted)) ?: [];
    }

    /**
     * Get the validation rules for the request.
     *
     * @return array
     *
     * @since 1.0.0
     */
    protected function rules()
    {
        return [];
    }

    /**
     * Run the validation on the request data.
     *
     * @param array $data The data to validate.
     * @param array $rules The rules.
     *
     * @return array
     * 
     * @throws ValidationException if fails to validate.
     *
     * @since 1.0.0
     */
    protected function run_validation(array $data, array $rules)
    {
        $validator = Validator::make($data, $rules, $this->messages());

        return $validator->validated();
    }

    /**
     * Define the sanitization filters for the request.
     * This will be defined into the extended request class.
     *
     * @return array<string,string|callable(mixed):mixed|array>
     *
     * @since 1.0.0
     */
    protected function filters()
    {
        return [];
    }

    /**
     * Define the validation messages for the request.
     * This will be defined into the extended request class.
     *
     * @return array<string,string|callable(mixed):mixed|array>
     *
     * @since 1.0.0
     */
    protected function messages()
    {
        return [];
    }

    /**
     * Run the sanitization on the data.
     *
     * @param array $data The data to sanitize.
     * @param array $filters The filters to apply.
     *
     * @return array
     *
     * @since 1.0.0
     */
    protected function run_sanitization(array $data, array $filters)
    {
        return Sanitizer::make($data, $filters)->get_sanitized_data();
    }

    /**
     * Get the current user instance from the request.
     *
     * @return \Framework\Wordpress\User
     *
     * @since 1.0.0
     */
    public function user()
    {
        return user();
    }

    /**
     * Get the HTTP method used in the request.
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
     * Alias of `get_method()`.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function method()
    {
        return $this->get_method();
    }

    /**
     * Get the route URI of the request.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function get_route()
    {
        return $this->route;
    }

    /**
     * Get the headers associated with the request.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function get_headers()
    {
        return $this->headers;
    }

    /**
     * Get a single HTTP header from the request.
     *
     * @param string $name The name.
     * @param mixed $default The default.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public function get_header(string $name, $default = null)
    {
        $name = $this->prepare_header_name($name);
        $value = $this->headers[$name] ?? $default;

        if (is_array($value)) {
            return $value[0] ?? $default;
        }

        return $value;
    }

    /**
     * Alias of `get_header()`.
     *
     * @param string $name The name.
     * @param mixed $default The default.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public function header(string $name, $default = null)
    {
        return $this->get_header($name, $default);
    }

    /**
     * Get all cookies sent by the client.
     *
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    public function cookies()
    {
        return $this->cookies;
    }

    /**
     * Get a cookie sent by the client.
     *
     * Cookie values are client supplied and must be treated as untrusted input.
     *
     * @param string|null $key The name of the cookie, or null for every cookie.
     * @param mixed $default The default value when the cookie is not present.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public function cookie(?string $key = null, $default = null)
    {
        if (is_null($key)) {
            return $this->cookies;
        }

        return $this->cookies[$key] ?? value($default);
    }

    /**
     * Check whether a cookie was sent by the client.
     *
     * @param string $key The name of the cookie.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function has_cookie(string $key)
    {
        return array_key_exists($key, $this->cookies);
    }

    /**
     * Get the session store.
     *
     * Session values are held by the store, never on the request attributes, so
     * a stored value can never satisfy or override a validated input.
     *
     * @return SessionManager
     *
     * @since 1.0.0
     */
    public function session()
    {
        return app(SessionManager::class);
    }

    /**
     * Get a value from the input flashed by the previous request.
     *
     * @param string|null $key The key, or null for every value.
     * @param mixed $default The default when the key is absent.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public function old(?string $key = null, $default = null)
    {
        return $this->session()->get_old_input($key, $default);
    }

    /**
     * Flash this request's input so the next request can read it back.
     *
     * Sensitive fields and uploaded files are never flashed.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function flash()
    {
        $this->session()->flash_input($this->flashable_input());

        return $this;
    }

    /**
     * Flash only the given input keys.
     *
     * @param array $keys The keys to flash.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function flash_only(array $keys)
    {
        $input = $this->flashable_input();

        $this->session()->flash_input(array_intersect_key($input, array_flip($keys)));

        return $this;
    }

    /**
     * Flash every input key except the given ones.
     *
     * @param array $keys The keys to exclude.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function flash_except(array $keys)
    {
        $input = $this->flashable_input();

        $this->session()->flash_input(array_diff_key($input, array_flip($keys)));

        return $this;
    }

    /**
     * Get the input that may safely be written to the session as old input.
     *
     * Reads the attributes only, so uploaded files are excluded by construction,
     * then removes the fields the session is configured never to flash. Without
     * this a failed submit would store a plaintext password in the session.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function flashable_input()
    {
        return $this->except($this->session()->get_never_flash());
    }

    /**
     * Get all input attributes.
     *
     * @param array|null $keys The keys to get.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function all($keys = null)
    {
        $input = array_merge($this->attributes, $this->all_files());

        if (!$keys) {
            return $input;
        }

        $results = [];

        foreach (is_array($keys) ? $keys : func_get_args() as $key) {
            Arr::set($results, $key, Arr::get($input, $key));
        }

        return $results;
    }

    /**
     * Get the route parameters.
     *
     * @param string $key The key of the route parameter.
     * @param mixed $default The default value.
     *
     * @return array|mixed
     *
     * @since 1.0.0
     */
    public function route($key, $default = null)
    {
        if (empty($key)) {
            throw new InvalidArgumentException('The route key is required.');
        }

        return Arr::get($this->route_params, $key, $default);
    }

    /**
     * Get the sanitized data.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function sanitized()
    {
        return $this->sanitized;
    }

    /**
     * Validate the request data.
     *
     * @param array $rules The rules to validate.
     *
     * @return array
     *
     * @throws ValidationException if fails to validate.
     *
     * @since 1.0.0
     */
    public function validate(array $rules)
    {
        return $this->run_validation($this->attributes(), $rules);
    }

    /**
     * Get the validated data.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function validated()
    {
        return $this->validated;
    }

    /**
     * Get all input attributes.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function attributes()
    {
        if ($this->has('_method')) {
            $this->remove('_method');
        }

        return $this->attributes;
    }

    /**
     * Get the client's network address.
     *
     * The connecting address is used by default. Addresses forwarded by an upstream proxy are only
     * consulted when the connecting address is a configured trusted proxy, because a caller can
     * otherwise set those headers freely: trusting them unconditionally would let one client
     * present itself as an unlimited number of distinct callers, which would defeat anything built
     * on top of this, rate limiting included.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    public function ip()
    {
        // phpcs:ignore Framework.NamingConventions.SnakeCaseVariable.NotSnakeCase
        $server = !empty($this->server) ? $this->server : $_SERVER;
        $remote = isset($server['REMOTE_ADDR']) ? trim((string) $server['REMOTE_ADDR']) : null;

        if (empty($remote) || !$this->is_trusted_proxy($remote)) {
            return $remote ?: null;
        }

        return $this->forwarded_ip($server) ?: $remote;
    }

    /**
     * Get the identifier of the authenticated user, if there is one.
     *
     * @return int|null
     *
     * @since 1.0.0
     */
    public function user_id()
    {
        if (!function_exists('get_current_user_id')) {
            return null;
        }

        $id = (int) get_current_user_id();

        return $id > 0 ? $id : null;
    }

    /**
     * Get the client address a trusted proxy forwarded.
     *
     * The leftmost entry of a forwarded chain is the original client. Entries that are themselves
     * trusted proxies are skipped from the right, so a chain of known proxies resolves to the
     * client rather than to the nearest hop. The leftmost entry is never skipped, because it is
     * the client even when it looks like a proxy, which is what a wildcard trust list makes of
     * every entry.
     *
     * @param array $server The server parameters.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    protected function forwarded_ip(array $server)
    {
        if (!empty($server['HTTP_CF_CONNECTING_IP'])) {
            return trim((string) $server['HTTP_CF_CONNECTING_IP']);
        }

        if (empty($server['HTTP_X_FORWARDED_FOR'])) {
            return null;
        }

        $chain = array_filter(array_map('trim', explode(',', (string) $server['HTTP_X_FORWARDED_FOR'])));

        while (count($chain) > 1 && $this->is_trusted_proxy(end($chain))) {
            array_pop($chain);
        }

        $client = end($chain);

        return false === $client || '' === $client ? null : $client;
    }

    /**
     * Determine whether an address is a configured trusted proxy.
     *
     * The default is to trust nothing, so forwarded headers are ignored until a site opts in.
     *
     * @param string $ip The address to test.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    protected function is_trusted_proxy(string $ip)
    {
        $proxies = $this->trusted_proxies();

        if (empty($proxies)) {
            return false;
        }

        foreach ($proxies as $proxy) {
            if ('*' === $proxy || $ip === $proxy) {
                return true;
            }

            if (false !== strpos($proxy, '/') && $this->ip_in_range($ip, $proxy)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the proxies whose forwarded address headers may be believed.
     *
     * @return array
     *
     * @since 1.0.0
     */
    protected function trusted_proxies()
    {
        $proxies = config('app.trusted_proxies');

        if (empty($proxies)) {
            return [];
        }

        return is_array($proxies) ? $proxies : [$proxies];
    }

    /**
     * Determine whether an address falls inside a CIDR range.
     *
     * Both address families are handled by comparing the leading bits of the packed addresses, so
     * the same code covers IPv4 and IPv6.
     *
     * @param string $ip The address to test.
     * @param string $range The range in CIDR notation.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    protected function ip_in_range(string $ip, string $range)
    {
        [$subnet, $bits] = explode('/', $range, 2);

        $address = @inet_pton($ip);
        $network = @inet_pton($subnet);

        if (false === $address || false === $network || strlen($address) !== strlen($network)) {
            return false;
        }

        $bits = (int) $bits;

        if ($bits < 0 || $bits > strlen($address) * 8) {
            return false;
        }

        $whole = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($whole > 0 && strncmp($address, $network, $whole) !== 0) {
            return false;
        }

        if (0 === $remainder) {
            return true;
        }

        $mask = chr(0xFF << (8 - $remainder) & 0xFF);

        return (($address[$whole] & $mask) === ($network[$whole] & $mask));
    }

    /**
     * Authorize the request.
     *
     * @return static
     *
     * @throws AuthorizationException
     *
     * @since 1.0.0
     */
    public function authorize_request()
    {
        if (!$this->authorize()) {
            throw new AuthorizationException(message('auth.unauthorized_request'));
        }

        return $this;
    }

    /**
     * Validate the request data and sanitize the data.
     *
     * @return static
     *
     * @since 1.0.0
     */
    public function validate_request()
    {
        if ($this->validation_resolved) {
            return $this;
        }

        $this->resolve_validation_and_sanitization();
        $this->validation_resolved = true;

        return $this;
    }

    /**
     * Resolve the validation and sanitization.
     *
     * @return void
     *
     * @throws AuthorizationException
     *
     * @since 1.0.0
     */
    protected function resolve_validation_and_sanitization()
    {
        $this->prepare_for_validation();

        $validated = $this->run_validation($this->attributes(), $this->rules());
        $this->validated = $validated;

        $sanitized = $this->run_sanitization($this->attributes(), $this->filters());
        $this->sanitized = $sanitized;
        $this->merge($sanitized);

        $this->passed_validation();
    }

    /**
     * Prepare the request data for validation.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function prepare_for_validation()
    {
        // Override this method to prepare the request data for validation.
    }

    /**
     * Handle the passed validation.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function passed_validation()
    {
        // Override this method to handle the passed validation.
    }

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    protected function authorize()
    {
        return true;
    }

    /**
     * Merge the given input with the existing attributes.
     *
     * @param array $input The input to merge.
     *
     * @return static
     *
     * @since 1.0.0
     */
    public function merge(array $input)
    {
        $this->attributes = array_merge(
            $this->attributes,
            $input
        );

        return $this;
    }


    /**
     * Check if an attribute exists.
     *
     * @param string $key The key of the attribute.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function has(string $key)
    {
        return isset($this->attributes[$key]);
    }

    /**
     * Remove an attribute.
     *
     * @param string $key The key of the attribute.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function remove(string $key)
    {
        unset($this->attributes[$key]);
    }

    /**
     * Get all input attributes except the specified keys.
     *
     * @param array $attributes The attribute keys to exclude.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function except(array $attributes)
    {
        return array_diff_key($this->attributes, array_flip($attributes));
    }

    /**
     * Get a single input attribute by key.
     *
     * @param string $key The key of the attribute.
     *
     * @return mixed|null
     *
     * @since 1.0.0
     */
    public function only(string $key)
    {
        return $this->attributes[$key] ?? null;
    }

    /**
     * Alias for the `only()` method.
     *
     * @param string $key The key of the attribute.
     *
     * @return mixed|null
     *
     * @since 1.0.0
     */
    public function input(string $key)
    {
        return $this->only($key);
    }

    /**
     * Get a value from the request with optional default and type casting.
     *
     * @param string $key The key to retrieve.
     * @param mixed $default Default value if the key doesn't exist.
     * @param string|null $type Optional type to cast the result to: 
     * int, float, bool, string, array with proper sanitization.
     *
     * @return mixed|null
     *
     * @since 1.0.0
     */
    public function get(string $key, $default = null, $type = null)
    {
        $value = $this->attributes[$key] ?? null;

        if ($value === null) {
            return value($default);
        }

        $value = Sanitizer::apply_rule($value, $type);

        return $value ?? value($default);
    }

    /**
     * Get a value from the request with optional default and type casting.
     *
     * @param string $key The key to retrieve.
     * @param mixed $default Default value if the key doesn't exist.
     * @param array $whitelist Optional whitelist of allowed values.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public function get_whitelisted(string $key, $default = null, array $whitelist = [])
    {
        $value = $this->get($key);

        if (!in_array($value, $whitelist, true)) {
            return $default;
        }

        return $value;
    }

    /**
     * Alias of `get_whitelisted()`.
     *
     * @param string $key The key to retrieve.
     * @param mixed $default Default value if the key doesn't exist.
     * @param array $whitelist Array of allowed values.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public function whitelisted(string $key, $default = null, array $whitelist = [])
    {
        return $this->get_whitelisted($key, $default, $whitelist);
    }

    /**
     * Get a string value with sanitization applied.
     *
     * @param string $key The key to retrieve.
     * @param string|null $default Default value if the key doesn't exist.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    public function get_string(string $key, $default = null)
    {
        return $this->get($key, $default, Sanitizer::TEXT);
    }

    /**
     * Alias of `get_string()`.
     *
     * @param string $key The key to retrieve.
     * @param string|null $default Default value if the key doesn't exist.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    public function string(string $key, $default = null)
    {
        return $this->get_string($key, $default);
    }

    /**
     * Get a date value.
     *
     * @param string $key The key to retrieve.
     * @param string|null $default Default value if the key doesn't exist.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function get_date(string $key, $default = null)
    {
        return $this->get($key, $default, Sanitizer::DATE);
    }

    /**
     * Alias of `get_date()`.
     *
     * @param string $key The key to retrieve.
     * @param string|null $default Default value if the key doesn't exist.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function date(string $key, $default = null)
    {
        return $this->get_date($key, $default);
    }

    /**
     * Get a datetime value.
     *
     * @param string $key The key to retrieve.
     * @param string|null $default Default value if the key doesn't exist.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function get_datetime(string $key, $default = null)
    {
        return $this->get($key, $default, Sanitizer::DATETIME);
    }

    /**
     * Alias of `get_datetime()`.
     *
     * @param string $key The key to retrieve.
     * @param string|null $default Default value if the key doesn't exist.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function datetime(string $key, $default = null)
    {
        return $this->get_datetime($key, $default);
    }

    /**
     * Get a text with sanitization applied.
     *
     * @param string $key The key to retrieve.
     * @param string|null $default Default value if the key doesn't exist.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    public function get_text(string $key, $default = null)
    {
        return $this->get_string($key, $default);
    }

    /**
     * Alias of `get_text()`.
     *
     * @param string $key The key to retrieve.
     * @param string|null $default Default value if the key doesn't exist.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    public function text(string $key, $default = null)
    {
        return $this->get_text($key, $default);
    }

    /**
     * Get a html supported content with sanitization applied.
     *
     * @param string $key The key to retrieve.
     * @param string|null $default Default value if the key doesn't exist.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    public function get_html(string $key, $default = null)
    {
        return $this->get($key, $default, Sanitizer::RICH_TEXT);
    }

    /**
     * Alias of `get_html()`.
     *
     * @param string $key The key to retrieve.
     * @param string|null $default Default value if the key doesn't exist.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    public function html(string $key, $default = null)
    {
        return $this->get_html($key, $default);
    }

    /**
     * Get a email with sanitization applied.
     *
     * @param string $key The key to retrieve.
     * @param string|null $default Default value if the key doesn't exist.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    public function get_email(string $key, $default = null)
    {
        return $this->get($key, $default, Sanitizer::EMAIL);
    }

    /**
     * Alias of `get_email()`.
     *
     * @param string $key The key to retrieve.
     * @param string|null $default Default value if the key doesn't exist.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    public function email(string $key, $default = null)
    {
        return $this->get_email($key, $default);
    }

    /**
     * Get a url with sanitization applied.
     *
     * @param string $key The key to retrieve.
     * @param string|null $default Default value if the key doesn't exist.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    public function get_url(string $key, $default = null)
    {
        return $this->get($key, $default, Sanitizer::URL);
    }

    /**
     * Alias of `get_url()`.
     *
     * @param string $key The key to retrieve.
     * @param string|null $default Default value if the key doesn't exist.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    public function url(string $key, $default = null)
    {
        return $this->get_url($key, $default);
    }

    /**
     * Get a key value with sanitization applied.
     *
     * @param string $key The key to retrieve.
     * @param string|null $default Default value if the key doesn't exist.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    public function get_key(string $key, $default = null)
    {
        return $this->get($key, $default, Sanitizer::KEY);
    }

    /**
     * Alias of `get_key()`.
     *
     * @param string $key The key to retrieve.
     * @param string|null $default Default value if the key doesn't exist.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    public function key(string $key, $default = null)
    {
        return $this->get_key($key, $default);
    }

    /**
     * Get a title value with sanitization applied.
     *
     * @param string $key The key to retrieve.
     * @param string|null $default Default value if the key doesn't exist.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    public function get_title(string $key, $default = null)
    {
        return $this->get($key, $default, Sanitizer::TITLE);
    }

    /**
     * Alias of `get_title()`.
     *
     * @param string $key The key to retrieve.
     * @param string|null $default Default value if the key doesn't exist.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    public function title(string $key, $default = null)
    {
        return $this->get_title($key, $default);
    }

    /**
     * Get a file name with sanitization applied.
     *
     * @param string $key The key to retrieve.
     * @param string|null $default Default value if the key doesn't exist.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    public function get_file_name(string $key, $default = null)
    {
        return $this->get($key, $default, Sanitizer::FILE_NAME);
    }

    /**
     * Alias of `get_file_name()`.
     *
     * @param string $key The key to retrieve.
     * @param string|null $default Default value if the key doesn't exist.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    public function file_name(string $key, $default = null)
    {
        return $this->get_file_name($key, $default);
    }

    /**
     * Get mime type with sanitization applied.
     *
     * @param string $key The key to retrieve.
     * @param string|null $default Default value if the key doesn't exist.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    public function get_mime_type(string $key, $default = null)
    {
        return $this->get($key, $default, Sanitizer::MIME_TYPE);
    }

    /**
     * Alias of `get_mime_type()`.
     *
     * @param string $key The key to retrieve.
     * @param string|null $default Default value if the key doesn't exist.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    public function mime_type(string $key, $default = null)
    {
        return $this->get_mime_type($key, $default);
    }

    /**
     * Get an integer value.
     *
     * @param string $key The key to retrieve.
     * @param int|null $default Default value if the key doesn't exist.
     *
     * @return int|null
     *
     * @since 1.0.0
     */
    public function get_int(string $key, $default = null)
    {
        return $this->get($key, $default, Sanitizer::INT);
    }

    /**
     * Alias of `get_int()`.
     *
     * @param string $key The key to retrieve.
     * @param int|null $default Default value if the key doesn't exist.
     *
     * @return int|null
     *
     * @since 1.0.0
     */
    public function int(string $key, $default = null)
    {
        return $this->get_int($key, $default);
    }

    /**
     * Get a boolean value.
     *
     * @param string $key The key to retrieve.
     * @param bool $default Default value if the key doesn't exist.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function get_bool(string $key, bool $default = false)
    {
        return $this->get($key, $default, Sanitizer::BOOL);
    }

    /**
     * Alias of `get_bool()`.
     *
     * @param string $key The key to retrieve.
     * @param bool $default Default value if the key doesn't exist.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function bool(string $key, bool $default = false)
    {
        return $this->get_bool($key, $default);
    }

    /**
     * Get a float value.
     *
     * @param string $key The key to retrieve.
     * @param float|null $default Default value if the key doesn't exist.
     *
     * @return float|null
     *
     * @since 1.0.0
     */
    public function get_float(string $key, $default = null)
    {
        return $this->get($key, $default, Sanitizer::FLOAT);
    }

    /**
     * Alias of `get_float()`.
     *
     * @param string $key The key to retrieve.
     * @param float|null $default Default value if the key doesn't exist.
     *
     * @return float|null
     *
     * @since 1.0.0
     */
    public function float(string $key, $default = null)
    {
        return $this->get_float($key, $default);
    }

    /**
     * Get an array value.
     *
     * @param string $key The key to retrieve.
     * @param array|null $default Default value if the key doesn't exist.
     *
     * @return array|null
     *
     * @since 1.0.0
     */
    public function get_array(string $key, $default = null)
    {
        return $this->get($key, $default, Sanitizer::ARRAY);
    }

    /**
     * Alias of `get_array()`.
     *
     * @param string $key The key to retrieve.
     * @param array|null $default Default value if the key doesn't exist.
     *
     * @return array|null
     *
     * @since 1.0.0
     */
    public function array(string $key, $default = null)
    {
        return $this->get_array($key, $default);
    }

    /**
     * Convert the request to an array.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function to_array()
    {
        return $this->all();
    }
}
