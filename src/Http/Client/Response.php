<?php
/**
 * Wraps wp_remote_request results with JSON decoding, status checks, and dot-notation body access.
 * Implements ArrayAccess for convenient response data retrieval.
 * Provides ok, failed, and json helper methods for API integrations.
 *
 * @package    Framework
 * @subpackage Http\Client
 * @since      1.0.0
 */
namespace Framework\Http\Client;

defined('ABSPATH') || exit;

use ArrayAccess;
use Framework\Concerns\DeepGettable;

use function Framework\collection;
use function Framework\throw_anyway;

defined('ABSPATH') || exit;

class Response implements ArrayAccess
{
    use DeepGettable;

    /**
     * The raw response data.
     *
     * @var array|\WP_Error
     *
     * @since 1.0.0
     */
    protected $response;

    /**
     * The decoded JSON response.
     *
     * @var array|null
     *
     * @since 1.0.0
     */
    protected $decoded;

    /**
     * The response cookies.
     *
     * @var array|null
     *
     * @since 1.0.0
     */
    protected $cookies;

    /**
     * Create a new response instance.
     *
     * @param mixed $response The response instance.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function __construct($response)
    {
        $this->response = $response;
    }

    /**
     * Get the body of the response.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function body()
    {
        return wp_remote_retrieve_body($this->response);
    }

    /**
     * Get the JSON decoded body of the response as an associative array.
     *
     * @param mixed $key The key.
     * @param mixed $default The default.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public function json($key = null, $default = null)
    {
        if (!$this->decoded) {
            $this->decoded = json_decode($this->body(), true);
        }

        if (is_null($key)) {
            return $this->decoded;
        }

        return $this->deep_get($this->decoded, $key, $default);
    }

    /**
     * Get the JSON decoded body of the response as an object.
     *
     * @return object|null
     *
     * @since 1.0.0
     */
    public function object()
    {
        return json_decode($this->body(), false);
    }

    /**
     * Get the JSON decoded body of the response as a collection.
     *
     * @param mixed $key The key.
     *
     * @return \Framework\Collections\Collection
     *
     * @since 1.0.0
     */
    public function collect($key = null)
    {
        return collection($this->json($key));
    }

    /**
     * Get a header from the response.
     *
     * @param mixed $name The name.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function header($name)
    {
        return wp_remote_retrieve_header($this->response, $name);
    }

    /**
     * Get all headers from the response.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function headers()
    {
        return wp_remote_retrieve_headers($this->response)->getAll();
    }

    /**
     * Get the cookies from the response.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function cookies()
    {
        return wp_remote_retrieve_cookies($this->response);
    }

    /**
     * Get the status code of the response.
     *
     * @return int
     *
     * @since 1.0.0
     */
    public function status()
    {
        return (int) wp_remote_retrieve_response_code($this->response);
    }

    /**
     * Get the reason phrase of the response.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function reason()
    {
        return wp_remote_retrieve_response_message($this->response);
    }

    /**
     * Determine if the response status code was successful (2xx).
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function successful()
    {
        return $this->status() >= 200 && $this->status() < 300;
    }

    /**
     * Determine if the response status code was a redirection (3xx).
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function redirected()
    {
        return $this->status() >= 300 && $this->status() < 400;
    }

    /**
     * Determine if the response status code was a client error (4xx).
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function client_error()
    {
        return $this->status() >= 400 && $this->status() < 500;
    }

    /**
     * Determine if the response status code was a server error (5xx).
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function server_error()
    {
        return $this->status() >= 500;
    }

    /**
     * Determine if the request failed: a transport error, or a client or server error status.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function failed()
    {
        return is_wp_error($this->response) || $this->server_error() || $this->client_error();
    }

    /**
     * Execute the given callback if the response has a failed status code.
     *
     * @param callable $callback The callback to invoke.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function on_error(callable $callback)
    {
        if ($this->failed()) {
            $callback($this);
        }

        return $this;
    }

    /**
     * Determine if the given offset exists in the JSON response.
     *
     * @param mixed $offset The offset.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function offsetExists($offset): bool
    {
        return isset($this->json()[$offset]);
    }

    /**
     * Get the value at the given offset from the JSON response.
     *
     * @param mixed $offset The offset.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->json()[$offset];
    }

    /**
     * Set the value at the given offset.
     *
     * @param mixed $offset The offset.
     * @param mixed $value The value.
     *
     * @return void
     *
     * @throws \Exception
     *
     * @since 1.0.0
     */
    public function offsetSet($offset, $value): void
    {
        throw_anyway('Response data may not be mutated using array access.');
    }

    /**
     * Unset the value at the given offset.
     *
     * @param mixed $offset The offset.
     *
     * @return void
     *
     * @throws \Exception
     *
     * @since 1.0.0
     */
    public function offsetUnset($offset): void
    {
        throw_anyway('Response data may not be mutated using array access.');
    }

    /**
     * Get the body of the response as a string.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function __toString()
    {
        return $this->body();
    }
}
