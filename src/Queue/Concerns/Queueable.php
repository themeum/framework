<?php
/**
 * Trait that turns a ShouldQueue class into a dispatchable job, Laravel style.
 * It carries the dispatch options (queue, delay, priority) on the job itself so a job can set its
 * own defaults in its constructor, and it gives handle() access to its own attempt, release, and
 * fail controls. $tries and $backoff are deliberately not declared here: a using class that
 * declared them with a different default would be a fatal error on PHP 7.4, so they are read only
 * when the job class defines them.
 *
 * @package    Framework
 * @subpackage Queue\Concerns
 * @since      3.2.0
 */
namespace Framework\Queue\Concerns;

defined('ABSPATH') || exit;

use Closure;
use Framework\Queue\JobRecord;
use Framework\Queue\PendingDispatch;
use Framework\Queue\QueueManager;
use Framework\Queue\Worker;
use RuntimeException;
use Throwable;

use function Framework\app;

trait Queueable
{
    /**
     * The queue name the job is dispatched to, or null for the default queue.
     *
     * @var string|null
     *
     * @since 3.2.0
     */
    protected $queue;

    /**
     * How long to wait before the job becomes available, or null for no delay.
     *
     * @var int|\DateTimeInterface|\DateInterval|null
     *
     * @since 3.2.0
     */
    protected $delay;

    /**
     * The job's priority; higher runs first.
     *
     * @var int|null
     *
     * @since 3.2.0
     */
    protected $priority;

    /**
     * The record of the run in progress, attached by the worker and never persisted.
     *
     * @var \Framework\Queue\JobRecord|null
     *
     * @since 3.2.0
     */
    protected $job_record;

    /**
     * Dispatch the job to the queue.
     *
     * The row is written when the returned pending dispatch is destroyed, so fluent modifiers
     * chained onto it take effect.
     *
     * @param mixed $arguments The job's constructor arguments.
     *
     * @return \Framework\Queue\PendingDispatch
     *
     * @throws \Framework\Exceptions\QueueException When the queue is not enabled or its table is missing.
     *
     * @since 3.2.0
     */
    public static function dispatch(...$arguments)
    {
        $manager = QueueManager::resolve();
        $manager->ensure_ready();

        return new PendingDispatch(new static(...$arguments), $manager);
    }

    /**
     * Dispatch the job when the condition is true.
     *
     * @param bool|\Closure $boolean The condition.
     * @param mixed $arguments The job's constructor arguments.
     *
     * @return \Framework\Queue\PendingDispatch|null
     *
     * @since 3.2.0
     */
    public static function dispatch_if($boolean, ...$arguments)
    {
        $boolean = $boolean instanceof Closure ? $boolean() : $boolean;

        return $boolean ? static::dispatch(...$arguments) : null;
    }

    /**
     * Dispatch the job unless the condition is true.
     *
     * @param bool|\Closure $boolean The condition.
     * @param mixed $arguments The job's constructor arguments.
     *
     * @return \Framework\Queue\PendingDispatch|null
     *
     * @since 3.2.0
     */
    public static function dispatch_unless($boolean, ...$arguments)
    {
        $boolean = $boolean instanceof Closure ? $boolean() : $boolean;

        return $boolean ? null : static::dispatch(...$arguments);
    }

    /**
     * Run the job immediately in the current request without storing it.
     *
     * @param mixed $arguments The job's constructor arguments.
     *
     * @return mixed The value handle() returned.
     *
     * @throws \Throwable Whatever handle() threw, after failed() has been called.
     *
     * @since 3.2.0
     */
    public static function dispatch_sync(...$arguments)
    {
        return app(Worker::class)->run_sync(new static(...$arguments));
    }

    /**
     * Set the queue name the job is dispatched to.
     *
     * @param string|null $queue The queue name.
     *
     * @return $this
     *
     * @since 3.2.0
     */
    public function on_queue($queue)
    {
        $this->queue = $queue;

        return $this;
    }

    /**
     * Set how long to wait before the job becomes available.
     *
     * @param int|\DateTimeInterface|\DateInterval|null $delay Seconds, or the moment it becomes available.
     *
     * @return $this
     *
     * @since 3.2.0
     */
    public function delay($delay)
    {
        $this->delay = $delay;

        return $this;
    }

    /**
     * Set the job's priority; higher runs first.
     *
     * @param int $priority The priority.
     *
     * @return $this
     *
     * @since 3.2.0
     */
    public function with_priority(int $priority)
    {
        $this->priority = $priority;

        return $this;
    }

    /**
     * Get the queue name the job is dispatched to.
     *
     * @return string
     *
     * @since 3.2.0
     */
    public function get_queue()
    {
        return $this->queue ?? 'default';
    }

    /**
     * Get the delay before the job becomes available.
     *
     * @return int|\DateTimeInterface|\DateInterval|null
     *
     * @since 3.2.0
     */
    public function get_delay()
    {
        return $this->delay;
    }

    /**
     * Get the job's priority.
     *
     * @return int
     *
     * @since 3.2.0
     */
    public function get_priority()
    {
        return (int) ($this->priority ?? 0);
    }

    /**
     * Get the number of times the job may be attempted, when the job class defines it.
     *
     * @return int|null
     *
     * @since 3.2.0
     */
    public function get_tries()
    {
        return property_exists($this, 'tries') ? $this->tries : null;
    }

    /**
     * Get the seconds to wait before retrying, when the job class defines it.
     *
     * @return int|array|null
     *
     * @since 3.2.0
     */
    public function get_backoff()
    {
        return property_exists($this, 'backoff') ? $this->backoff : null;
    }

    /**
     * Get the attempt this run is, counting from one.
     *
     * @return int
     *
     * @since 3.2.0
     */
    public function attempts()
    {
        return $this->job_record ? $this->job_record->attempts() : 1;
    }

    /**
     * Put the job back on the queue once handle() returns, without treating it as a failure.
     *
     * @param int|\DateTimeInterface|\DateInterval $delay How long before it is available again.
     *
     * @return void
     *
     * @since 3.2.0
     */
    public function release($delay = 0)
    {
        if ($this->job_record) {
            $this->job_record->release($delay);
        }
    }

    /**
     * Mark the job as failed once handle() returns, regardless of its remaining tries.
     *
     * @param \Throwable|string|null $exception Why the job failed.
     *
     * @return void
     *
     * @since 3.2.0
     */
    public function fail($exception = null)
    {
        if (!$exception instanceof Throwable) {
            $exception = new RuntimeException($exception ?: sprintf('The job [%s] failed itself.', static::class));
        }

        if ($this->job_record) {
            $this->job_record->fail($exception);
        }
    }

    /**
     * Attach the record of the run in progress.
     *
     * @param \Framework\Queue\JobRecord|null $record The record, or null to detach it.
     *
     * @return $this
     *
     * @since 3.2.0
     */
    public function set_job_record(?JobRecord $record)
    {
        $this->job_record = $record;

        return $this;
    }
}
