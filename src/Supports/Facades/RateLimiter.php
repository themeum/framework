<?php
/**
 * Facade for the rate limiter.
 * Reads as though the limiter itself were the target, so a route file or provider can register
 * and query limits without resolving anything from the container.
 *
 * @package    Framework
 * @subpackage Supports
 * @since      1.0.0
*/
namespace Framework\Supports\Facades;

defined('ABSPATH') || exit;

use Framework\Facade;

/**
 * The rate limiter class
 *
 * phpcs:disable Generic.Files.LineLength.TooLong
 *
 * @method static \Framework\RateLimiting\RateLimiter for(string $name, \Closure $callback)
 * @method static \Closure|null limiter(string $name)
 * @method static bool has_limiter(string $name)
 * @method static mixed attempt(string $key, int $max_attempts, \Closure $callback, int $decay_seconds = 60)
 * @method static bool too_many_attempts(string $key, int $max_attempts)
 * @method static int increment(string $key, int $decay_seconds = 60, int $amount = 1)
 * @method static int attempts(string $key)
 * @method static int remaining(string $key, int $max_attempts)
 * @method static int retries_left(string $key, int $max_attempts)
 * @method static int available_in(string $key)
 * @method static int available_at(string $key)
 * @method static void clear(string $key)
 * @method static void reset_attempts(string $key)
 * @method static \Framework\Cache\Repository cache()
 *
 * phpcs:enable Generic.Files.LineLength.TooLong
*/
class RateLimiter extends Facade
{
    /**
     * Get the accessor.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public static function get_accessor()
    {
        return 'limiter';
    }
}
