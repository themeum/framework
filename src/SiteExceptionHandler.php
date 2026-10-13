<?php
/**
 * Translates thrown exceptions into WordPress-native site route error responses.
 * Maps authorization, not-found, and validation failures to HTTP status pages.
 *
 * @package Framework
 * @since   1.0.0
 */
namespace Framework;

defined('ABSPATH') || exit;

use Framework\Exceptions\AuthorizationException;
use Framework\Exceptions\HttpException;
use Framework\Exceptions\ModelNotFoundException;
use Framework\Exceptions\ValidationException;
use Framework\Http\Response;
use Framework\Supports\Facades\Log;
use Exception;

class SiteExceptionHandler
{
    /**
     * Handle an exception for a site route request.
     *
     * @param Exception $exception The exception to handle.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public static function handle(Exception $exception)
    {
        if ($exception instanceof HttpException) {
            static::send_headers($exception->get_headers());

            static::fail($exception->get_status(), $exception->getMessage() ?: 'Request failed');
        }

        if ($exception instanceof AuthorizationException) {
            static::fail(Response::FORBIDDEN, $exception->getMessage() ?: 'Forbidden');
        }

        if ($exception instanceof ModelNotFoundException) {
            static::fail(Response::NOT_FOUND, $exception->getMessage() ?: 'Not Found');
        }

        if ($exception instanceof ValidationException) {
            static::fail(
                Response::UNPROCESSABLE_ENTITY,
                $exception->getMessage() ?: 'Validation failed'
            );
        }

        $status = (int) $exception->getCode();

        if ($status < 100 || $status > 599) {
            $status = Response::INTERNAL_SERVER_ERROR;
        }

        Log::error($exception->getMessage());

        $message = $exception->getMessage();

        if ($status >= Response::INTERNAL_SERVER_ERROR && !static::is_debug()) {
            $message = '';
        }

        static::fail($status, $message ?: 'Internal Server Error');
    }

    /**
     * Whether internal error details may be shown to the visitor.
     *
     * A server error's message can carry SQL, file paths, or other internals, so it only reaches
     * the page while WP_DEBUG is on; it is always logged regardless.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    protected static function is_debug()
    {
        return defined('WP_DEBUG') && WP_DEBUG;
    }

    /**
     * Emit the headers an HTTP exception asked to travel with the response.
     *
     * Headers must be written before wp_die() sends the body, so this runs ahead of the failure
     * page rather than alongside it.
     *
     * @param array $headers The headers to send.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected static function send_headers(array $headers)
    {
        if (headers_sent()) {
            return;
        }

        foreach ($headers as $name => $value) {
            header(sprintf('%s: %s', $name, $value), true);
        }
    }

    /**
     * Stop the request with a WordPress error page at the given HTTP status.
     *
     * @param int $status HTTP status code.
     * @param string $message Error message.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected static function fail(int $status, string $message)
    {
        status_header($status);
        nocache_headers();
        wp_die(esc_html($message), esc_html($message), ['response' => absint($status)]);
    }
}
