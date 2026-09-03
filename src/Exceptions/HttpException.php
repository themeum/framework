<?php
/**
 * Exception carrying an HTTP status, message and response headers.
 * Lets any layer of the framework reject a request with a specific status without having to
 * reach the response object, which matters most in middleware: on REST routes middleware runs
 * inside the permission callback, where a returned response would be discarded and only a thrown
 * exception can reach the caller.
 *
 * @package    Framework
 * @subpackage Exceptions
 * @since      1.0.0
 */
namespace Framework\Exceptions;

defined('ABSPATH') || exit;

use Framework\Http\Response;
use RuntimeException;

class HttpException extends RuntimeException
{
    /**
     * The headers that should travel with the response.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected $headers = [];

    /**
     * The code identifying the error to a machine reader.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected $error_code = 'http_error';

    /**
     * The body the response should carry instead of the default message envelope.
     *
     * @var mixed
     *
     * @since 1.0.0
     */
    protected $payload = null;

    /**
     * Create an HTTP exception.
     *
     * @param string $message The message shown to the caller.
     * @param int $status The HTTP status the response should carry.
     * @param array $headers The headers the response should carry.
     * @param string|null $error_code The machine readable error code, or null for the default.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function __construct(
        string $message = '',
        int $status = Response::INTERNAL_SERVER_ERROR,
        array $headers = [],
        $error_code = null
    ) {
        parent::__construct($message, $status);

        $this->headers = $headers;

        if (!is_null($error_code)) {
            $this->error_code = $error_code;
        }
    }

    /**
     * Get the HTTP status the response should carry.
     *
     * A status outside the valid range is reported as a server error, so that a caller can never
     * be sent a status that is not an HTTP status.
     *
     * @return int
     *
     * @since 1.0.0
     */
    public function get_status()
    {
        $status = (int) $this->getCode();

        if ($status < 100 || $status > 599) {
            return Response::INTERNAL_SERVER_ERROR;
        }

        return $status;
    }

    /**
     * Get the headers that should travel with the response.
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
     * Set the headers that should travel with the response.
     *
     * @param array $headers The headers to carry.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function with_headers(array $headers)
    {
        $this->headers = array_merge($this->headers, $headers);

        return $this;
    }

    /**
     * Get the body a caller declared for the response, if any.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public function get_payload()
    {
        return $this->payload;
    }

    /**
     * Declare the body the response should carry instead of the default message envelope.
     *
     * @param mixed $payload The body to carry.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function with_payload($payload)
    {
        $this->payload = $payload;

        return $this;
    }

    /**
     * Get the code identifying the error to a machine reader.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function error_code()
    {
        return $this->error_code;
    }
}
