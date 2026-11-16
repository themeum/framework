<?php
/**
 * Contract for interacting with an HTTP request in a normalized way.
 * This interface abstracts key methods for accessing request data, headers, routes, and HTTP method.
 *
 * @package    Framework
 * @subpackage Contracts
 * @since      1.0.0
 */
namespace Framework\Contracts;

defined('ABSPATH') || exit;

interface Request
{
    /**
     * Get the HTTP method of the request (GET, POST, etc).
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function get_method();

    /**
     * Get the requested route or endpoint.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function get_route();

    /**
     * Get all HTTP headers from the request.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function get_headers();

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
    public function get_header(string $name, $default = null);

    /**
     * Set a single HTTP header on the request, replacing any existing values.
     *
     * @param string $name The name.
     * @param mixed $value A scalar, an array of scalars, or null to remove the header.
     *
     * @return $this
     *
     * @throws \InvalidArgumentException When the value is neither null, a scalar, nor an array of scalars.
     *
     * @since 3.3.4
     */
    public function set_header(string $name, $value);

    /**
     * Set several HTTP headers on the request, keeping headers that are not named.
     *
     * @param array $headers The headers, keyed by name.
     *
     * @return $this
     *
     * @throws \InvalidArgumentException When a value is neither null, a scalar, nor an array of scalars.
     *
     * @since 3.3.4
     */
    public function with_headers(array $headers);

    /**
     * Remove an HTTP header from the request.
     *
     * @param string $name The name.
     *
     * @return $this
     *
     * @since 3.3.4
     */
    public function remove_header(string $name);

    /**
     * Determine whether the request carries an HTTP header.
     *
     * @param string $name The name.
     *
     * @return bool
     *
     * @since 3.3.4
     */
    public function has_header(string $name);

    /**
     * Get all request input attributes.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function all();

    /**
     * Get the validated data from the request.
     *
     * @return array|null
     *
     * @since 1.0.0
     */
    public function validated();

    /**
     * Validate the request data.
     *
     * @param array $rules The rules to validate.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function validate(array $rules);

    /**
     * Get the sanitized data from the request.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function sanitized();

    /**
     * Get request data excluding specified attributes.
     *
     * @param array $attributes Keys to exclude.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function except(array $attributes);

    /**
     * Get a single attribute by key.
     *
     * @param string $key The key to retrieve.
     *
     * @return mixed|null
     *
     * @since 1.0.0
     */
    public function only(string $key);

    /**
     * Alias of `only()`. Get input value by key.
     *
     * @param string $key The key to retrieve.
     *
     * @return mixed|null
     *
     * @since 1.0.0
     */
    public function input(string $key);

    /**
     * Get a value from the request with optional default and type casting.
     *
     * @param string $key The key to retrieve.
     * @param mixed $default Default value if the key doesn't exist.
     * @param string|null $type Optional type to cast the result to: 
     * int, float, bool, string, array with proper sanitization.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public function get(string $key, $default = null, $type = null);

    /**
     * Check if the request has the provided key
     *
     * @param string $key The key.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function has(string $key);

    /**
     * Get a string value.
     *
     * @param string $key The key to retrieve.
     * @param string|null $default Default value if the key doesn't exist.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function get_string(string $key, $default = null);

    /**
     * Alias of `get_string()`.
     *
     * @param string $key The key to retrieve.
     * @param string|null $default Default value if the key doesn't exist.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function string(string $key, $default = null);

    /**
     * Get a safe input value with whitelist.
     *
     * @param string $key The key to retrieve.
     * @param mixed $default Default value if the key doesn't exist.
     * @param array $whitelist Array of allowed values.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public function get_whitelisted(string $key, $default = null, array $whitelist = []);

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
    public function whitelisted(string $key, $default = null, array $whitelist = []);

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
    public function get_date(string $key, $default = null);

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
    public function date(string $key, $default = null);

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
    public function get_datetime(string $key, $default = null);

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
    public function datetime(string $key, $default = null);

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
    public function get_text(string $key, $default = null);

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
    public function text(string $key, $default = null);

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
    public function get_html(string $key, $default = null);

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
    public function html(string $key, $default = null);

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
    public function get_email(string $key, $default = null);

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
    public function email(string $key, $default = null);

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
    public function get_url(string $key, $default = null);

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
    public function url(string $key, $default = null);

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
    public function get_key(string $key, $default = null);

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
    public function key(string $key, $default = null);

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
    public function get_title(string $key, $default = null);

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
    public function title(string $key, $default = null);

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
    public function get_file_name(string $key, $default = null);

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
    public function file_name(string $key, $default = null);

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
    public function get_mime_type(string $key, $default = null);

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
    public function mime_type(string $key, $default = null);

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
    public function get_int(string $key, $default = null);

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
    public function int(string $key, $default = null);

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
    public function get_bool(string $key, bool $default = false);

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
    public function bool(string $key, bool $default = false);

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
    public function get_float(string $key, $default = null);

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
    public function float(string $key, $default = null);

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
    public function get_array(string $key, $default = null);

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
    public function array(string $key, $default = null);
}
