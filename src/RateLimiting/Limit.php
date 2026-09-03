<?php
/**
 * A single rate limit: how many attempts are allowed, over how long, and against what.
 * Limits are values rather than behaviour. A named limiter hands one back, or an array of them,
 * and the throttle middleware decides what to do with what it is given.
 *
 * @package    Framework
 * @subpackage RateLimiting
 * @since      1.0.0
 */
namespace Framework\RateLimiting;

defined('ABSPATH') || exit;

use Closure;

class Limit
{
    /**
     * The number of attempts allowed within the window.
     *
     * @var int
     *
     * @since 1.0.0
     */
    public $max_attempts;

    /**
     * The length of the window in seconds.
     *
     * @var int
     *
     * @since 1.0.0
     */
    public $decay_seconds;

    /**
     * The value the limit is segmented by, or an empty string when it applies as a whole.
     *
     * @var string
     *
     * @since 1.0.0
     */
    public $key = '';

    /**
     * The callback producing the response for a rejected request, if one was declared.
     *
     * @var Closure|null
     *
     * @since 1.0.0
     */
    public $response_callback = null;

    /**
     * Create a limit.
     *
     * @param int $max_attempts The number of attempts allowed within the window.
     * @param int $decay_seconds The length of the window in seconds.
     * @param string $key The value the limit is segmented by.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function __construct(int $max_attempts, int $decay_seconds = 60, string $key = '')
    {
        $this->max_attempts = $max_attempts;
        $this->decay_seconds = max(1, $decay_seconds);
        $this->key = $key;
    }

    /**
     * Create a limit allowing a number of attempts per second.
     *
     * @param int $max_attempts The number of attempts allowed.
     *
     * @return static
     *
     * @since 1.0.0
     */
    public static function per_second(int $max_attempts)
    {
        return new static($max_attempts, 1);
    }

    /**
     * Create a limit allowing a number of attempts per minute.
     *
     * @param int $max_attempts The number of attempts allowed.
     *
     * @return static
     *
     * @since 1.0.0
     */
    public static function per_minute(int $max_attempts)
    {
        return new static($max_attempts, 60);
    }

    /**
     * Create a limit allowing a number of attempts across several minutes.
     *
     * @param int $decay_minutes The length of the window in minutes.
     * @param int $max_attempts The number of attempts allowed.
     *
     * @return static
     *
     * @since 1.0.0
     */
    public static function per_minutes(int $decay_minutes, int $max_attempts)
    {
        return new static($max_attempts, $decay_minutes * 60);
    }

    /**
     * Create a limit allowing a number of attempts per hour.
     *
     * @param int $max_attempts The number of attempts allowed.
     * @param int $decay_hours The length of the window in hours.
     *
     * @return static
     *
     * @since 1.0.0
     */
    public static function per_hour(int $max_attempts, int $decay_hours = 1)
    {
        return new static($max_attempts, $decay_hours * 3600);
    }

    /**
     * Create a limit allowing a number of attempts per day.
     *
     * @param int $max_attempts The number of attempts allowed.
     * @param int $decay_days The length of the window in days.
     *
     * @return static
     *
     * @since 1.0.0
     */
    public static function per_day(int $max_attempts, int $decay_days = 1)
    {
        return new static($max_attempts, $decay_days * 86400);
    }

    /**
     * Create a limit that never rejects.
     *
     * @return static
     *
     * @since 1.0.0
     */
    public static function none()
    {
        return new Unlimited();
    }

    /**
     * Segment the limit by an arbitrary value.
     *
     * @param mixed $key The value to segment by.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function by($key)
    {
        $this->key = (string) $key;

        return $this;
    }

    /**
     * Declare the response returned when the limit is exceeded.
     *
     * The callback receives the request and the rate limit headers, so a custom response can
     * still carry them.
     *
     * @param Closure $callback The callback producing the response.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function response(Closure $callback)
    {
        $this->response_callback = $callback;

        return $this;
    }
}
