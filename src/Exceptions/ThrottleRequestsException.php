<?php
/**
 * Exception thrown when a request exceeds its rate limit.
 * Carries the standard rate limit headers alongside the 429 status so that a caller learns both
 * that it was throttled and when it may try again.
 *
 * @package    Framework
 * @subpackage Exceptions
 * @since      1.0.0
 */
namespace Framework\Exceptions;

defined('ABSPATH') || exit;

use Framework\Http\Response;

class ThrottleRequestsException extends HttpException
{
    /**
     * The code identifying the error to a machine reader.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected $error_code = 'rest_too_many_requests';

    /**
     * Create a rate limit exception.
     *
     * @param string $message The message shown to the caller.
     * @param array $headers The rate limit headers the response should carry.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function __construct(string $message = 'Too Many Requests.', array $headers = [])
    {
        parent::__construct($message, Response::TOO_MANY_REQUESTS, $headers, 'rest_too_many_requests');
    }
}
