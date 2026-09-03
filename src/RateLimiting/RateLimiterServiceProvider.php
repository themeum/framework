<?php
/**
 * Registers the rate limiter and the throttle middleware alias.
 * Kept separate from the core provider so that the limiter is wired immediately after the cache
 * it counts in, and so the throttle alias exists before any route file is included.
 *
 * @package    Framework
 * @subpackage RateLimiting
 * @since      1.0.0
 */
namespace Framework\RateLimiting;

defined('ABSPATH') || exit;

use Framework\Middlewares\ThrottleRequests;
use Framework\Route;
use Framework\ServiceProvider;

class RateLimiterServiceProvider extends ServiceProvider
{
    /**
     * Register the rate limiter.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function register()
    {
        $this->app->singleton(RateLimiter::class, function () {
            return new RateLimiter();
        });
    }

    /**
     * Register the throttle middleware alias and the filter carrying rate limit headers.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function boot()
    {
        Route::middleware_alias('throttle', ThrottleRequests::class);

        $this->register_response_headers();
    }

    /**
     * Copy the rate limit headers a throttled route recorded onto its outgoing REST response.
     *
     * Middleware on a REST route runs inside the permission callback, where a returned value is
     * discarded, so headers for a permitted request cannot be set from the middleware itself.
     * They are recorded during the middleware pass and attached here instead.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function register_response_headers()
    {
        if (!function_exists('add_filter')) {
            return;
        }

        add_filter('rest_post_dispatch', function ($response) {
            $headers = ThrottleRequests::recorded_headers();

            if (empty($headers) || !is_object($response) || !method_exists($response, 'header')) {
                return $response;
            }

            foreach ($headers as $name => $value) {
                $response->header($name, $value);
            }

            return $response;
        }, 10, 1);
    }
}
