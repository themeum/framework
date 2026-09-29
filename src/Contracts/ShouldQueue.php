<?php
/**
 * Marker contract for a job that runs in the background queue instead of inline.
 * The queue refuses to restore a stored payload whose class does not implement it, so this
 * interface is also what stops a tampered row from choosing an arbitrary class to run.
 *
 * @package    Framework
 * @subpackage Contracts
 * @since      3.2.0
 */
namespace Framework\Contracts;

defined('ABSPATH') || exit;

/**
 * Contract for a queued job.
 *
 * The interface stays a marker so that implementing it is never a breaking change, but the queue
 * relies on the Queueable trait's methods and on a job-defined handle(). The tags below describe
 * that runtime contract for static analysis; failed() is left out because it is optional.
 *
 * @method mixed handle(mixed ...$dependencies)
 * @method $this on_queue(string|null $queue)
 * @method $this delay(int|\DateTimeInterface|\DateInterval|null $delay)
 * @method $this with_priority(int $priority)
 * @method string get_queue()
 * @method int|\DateTimeInterface|\DateInterval|null get_delay()
 * @method int get_priority()
 * @method int|null get_tries()
 * @method int|array|null get_backoff()
 * @method int attempts()
 * @method void release(int|\DateTimeInterface|\DateInterval $delay = 0)
 * @method void fail(\Throwable|string|null $exception = null)
 * @method $this set_job_record(\Framework\Queue\JobRecord|null $record)
 *
 * @since 3.2.0
 */
interface ShouldQueue
{
    //
}
