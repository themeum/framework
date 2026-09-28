<?php
/**
 * Facade for the background job queue.
 * Jobs are normally dispatched with Job::dispatch(); this facade covers pushing prepared job
 * instances, inspecting the queue, and faking it in tests.
 *
 * @package    Framework
 * @subpackage Supports\Facades
 * @since      3.2.0
 */
namespace Framework\Supports\Facades;

defined('ABSPATH') || exit;

use Framework\Facade;

// phpcs:disable Generic.Files.LineLength.TooLong

/**
 * Facade proxy for the queue manager.
 *
 * @method static int push(\Framework\Contracts\ShouldQueue $job)
 * @method static int later(int|\DateTimeInterface|\DateInterval $delay, \Framework\Contracts\ShouldQueue $job)
 * @method static int size(?string $queue = null)
 * @method static int clear(?string $queue = null)
 * @method static \Framework\Queue\QueueFake fake()
 * @method static bool is_faked()
 * @method static void assert_pushed(string $class, ?callable $callback = null)
 * @method static void assert_pushed_times(string $class, int $times)
 * @method static void assert_not_pushed(string $class, ?callable $callback = null)
 * @method static void assert_nothing_pushed()
 * @see    \Framework\Queue\QueueManager
 */
class Queue extends Facade
{
    /**
     * Get the accessor.
     *
     * @return string
     *
     * @since 3.2.0
     */
    public static function get_accessor()
    {
        return 'queue';
    }
}
