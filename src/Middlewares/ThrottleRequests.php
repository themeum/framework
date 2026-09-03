<?php
/**
 * Rejects a request once it has exceeded the rate limit governing its route.
 * Accepts either an inline limit, written as a maximum and a decay in minutes, or the name of a
 * limiter registered through the rate limiter.
 *
 * A rejection is thrown rather than returned, because middleware on a REST route runs inside the
 * permission callback where a returned response would be discarded.
 *
 * @package    Framework
 * @subpackage Middlewares
 * @since      1.0.0
 */
namespace Framework\Middlewares;

defined('ABSPATH') || exit;

use Framework\Contracts\Middleware;
use Framework\Contracts\Request;
use Framework\Exceptions\ThrottleRequestsException;
use Framework\RateLimiting\Limit;
use Framework\RateLimiting\RateLimiter;
use Framework\RateLimiting\Unlimited;
use InvalidArgumentException;

use function Framework\app;

class ThrottleRequests implements Middleware
{
    /**
     * The rate limit headers recorded for the request currently being handled.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected static $headers = [];

    /**
     * Handle the incoming request, rejecting it when it has exceeded its limit.
     *
     * @param Request $request The incoming request instance.
     * @param callable $next The next middleware callback.
     * @param mixed $parameters The limit declared on the route, spread as trailing arguments.
     *
     * @return mixed The result of the next middleware.
     *
     * @throws ThrottleRequestsException When the request has exceeded its limit.
     *
     * @since 1.0.0
     */
    public function handle(Request $request, callable $next, ...$parameters)
    {
        foreach ($this->resolve_limits($request, $parameters) as $limit) {
            if ($limit instanceof Unlimited) {
                continue;
            }

            $this->enforce($request, $limit);
        }

        return $next($request);
    }

    /**
     * Get the rate limit headers recorded for the request currently being handled.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public static function recorded_headers()
    {
        return static::$headers;
    }

    /**
     * Discard any recorded rate limit headers.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public static function forget_headers()
    {
        static::$headers = [];
    }

    /**
     * Count the request against a limit and reject it when the limit is exceeded.
     *
     * @param Request $request The incoming request instance.
     * @param Limit $limit The limit to enforce.
     *
     * @return void
     *
     * @throws ThrottleRequestsException When the request has exceeded its limit.
     *
     * @since 1.0.0
     */
    protected function enforce(Request $request, Limit $limit)
    {
        $limiter = $this->limiter();
        $key = $this->resolve_key($request, $limit);

        if ($limiter->too_many_attempts($key, $limit->max_attempts)) {
            throw $this->rejection($request, $limit, $key);
        }

        $hits = $limiter->increment($key, $limit->decay_seconds);

        if ($hits > $limit->max_attempts) {
            throw $this->rejection($request, $limit, $key);
        }

        $this->record_headers([
            'X-RateLimit-Limit' => (string) $limit->max_attempts,
            'X-RateLimit-Remaining' => (string) max(0, $limit->max_attempts - $hits),
        ]);
    }

    /**
     * Build the exception rejecting a request that has exceeded its limit.
     *
     * @param Request $request The incoming request instance.
     * @param Limit $limit The limit that was exceeded.
     * @param string $key The key the limit was counted against.
     *
     * @return ThrottleRequestsException
     *
     * @since 1.0.0
     */
    protected function rejection(Request $request, Limit $limit, string $key)
    {
        $limiter = $this->limiter();
        $retry_after = $limiter->available_in($key);

        $headers = [
            'Retry-After' => (string) $retry_after,
            'X-RateLimit-Limit' => (string) $limit->max_attempts,
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset' => (string) $limiter->available_at($key),
        ];

        $this->record_headers($headers);

        if ($limit->response_callback) {
            $response = call_user_func($limit->response_callback, $request, $headers);

            if ($response instanceof ThrottleRequestsException) {
                return $response;
            }

            if (!is_null($response)) {
                return (new ThrottleRequestsException('Too Many Requests.', $headers))
                    ->with_payload($response);
            }
        }

        return new ThrottleRequestsException('Too Many Requests.', $headers);
    }

    /**
     * Resolve the limits governing the request from the parameters declared on the route.
     *
     * A numeric first parameter is an inline limit; anything else names a registered limiter.
     *
     * @param Request $request The incoming request instance.
     * @param array $parameters The parameters declared on the route.
     *
     * @return array
     *
     * @throws InvalidArgumentException When a named limiter has not been registered.
     *
     * @since 1.0.0
     */
    protected function resolve_limits(Request $request, array $parameters)
    {
        $first = $parameters[0] ?? 60;

        if (is_numeric($first)) {
            $decay_minutes = isset($parameters[1]) ? (int) $parameters[1] : 1;

            return [Limit::per_minutes(max(1, $decay_minutes), (int) $first)];
        }

        $callback = $this->limiter()->limiter((string) $first);

        if (is_null($callback)) {
            throw new InvalidArgumentException(
                sprintf('Rate limiter [%s] is not registered.', $first)
            );
        }

        $limits = $callback($request);

        if (is_null($limits)) {
            return [];
        }

        return is_array($limits) ? $limits : [$limits];
    }

    /**
     * Build the key a limit is counted against.
     *
     * A limit that declares its own segmenting value uses it. Otherwise the authenticated user
     * identifies the caller, falling back to the client address for guests. The route is folded
     * in so that separate routes do not share an allowance.
     *
     * @param Request $request The incoming request instance.
     * @param Limit $limit The limit being enforced.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function resolve_key(Request $request, Limit $limit)
    {
        if ($limit->key !== '') {
            return $this->route_signature($request) . '|' . sha1($limit->key);
        }

        $caller = method_exists($request, 'user_id') ? $request->user_id() : null;

        if (is_null($caller)) {
            $caller = method_exists($request, 'ip') ? $request->ip() : null;
        }

        return $this->route_signature($request) . '|' . sha1((string) $caller);
    }

    /**
     * Build a stable identifier for the route being requested.
     *
     * @param Request $request The incoming request instance.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function route_signature(Request $request)
    {
        $method = method_exists($request, 'method') ? (string) $request->method() : '';
        $path = method_exists($request, 'get_route') ? (string) $request->get_route() : '';

        if ($method === '' && $path === '') {
            return sha1('global');
        }

        return sha1($method . ' ' . $path);
    }

    /**
     * Record the rate limit headers so they can be attached to the outgoing response.
     *
     * @param array $headers The headers to record.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function record_headers(array $headers)
    {
        static::$headers = array_merge(static::$headers, $headers);
    }

    /**
     * Get the rate limiter.
     *
     * @return RateLimiter
     *
     * @since 1.0.0
     */
    protected function limiter()
    {
        return app(RateLimiter::class);
    }
}
