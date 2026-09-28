<?php
/**
 * One claimed row from the jobs table, plus what the running job decided about itself.
 * A job calls release() or fail() on itself from inside handle(); those calls only record the
 * decision here, and the worker acts on it once handle() has returned.
 *
 * @package    Framework
 * @subpackage Queue
 * @since      3.2.0
 */
namespace Framework\Queue;

defined('ABSPATH') || exit;

use Throwable;

class JobRecord
{
    /**
     * The row id, or zero for a job run synchronously that was never stored.
     *
     * @var int
     *
     * @since 3.2.0
     */
    protected $id;

    /**
     * The queue name the row was stored under.
     *
     * @var string
     *
     * @since 3.2.0
     */
    protected $queue;

    /**
     * The raw JSON payload.
     *
     * @var string
     *
     * @since 3.2.0
     */
    protected $payload;

    /**
     * The attempt this run is, counting from one.
     *
     * @var int
     *
     * @since 3.2.0
     */
    protected $attempts;

    /**
     * The delay the job asked to be released with, or null when it did not release itself.
     *
     * @var int|\DateTimeInterface|\DateInterval|null
     *
     * @since 3.2.0
     */
    protected $release_delay;

    /**
     * Whether the job released itself.
     *
     * @var bool
     *
     * @since 3.2.0
     */
    protected $released = false;

    /**
     * The exception the job failed itself with, or null.
     *
     * @var \Throwable|null
     *
     * @since 3.2.0
     */
    protected $failure;

    /**
     * Whether the job failed itself.
     *
     * @var bool
     *
     * @since 3.2.0
     */
    protected $failed = false;

    /**
     * Create a record.
     *
     * @param int $id The row id.
     * @param string $queue The queue name.
     * @param string $payload The raw JSON payload.
     * @param int $attempts The attempt this run is.
     *
     * @return void
     *
     * @since 3.2.0
     */
    public function __construct(int $id, string $queue, string $payload, int $attempts)
    {
        $this->id = $id;
        $this->queue = $queue;
        $this->payload = $payload;
        $this->attempts = $attempts;
    }

    /**
     * Create a record from a row of the jobs table.
     *
     * @param array $row The row as an associative array.
     *
     * @return static
     *
     * @since 3.2.0
     */
    public static function from_row(array $row)
    {
        return new static(
            (int) $row['id'],
            (string) $row['queue'],
            (string) $row['payload'],
            (int) $row['attempts']
        );
    }

    /**
     * Get the row id.
     *
     * @return int
     *
     * @since 3.2.0
     */
    public function id()
    {
        return $this->id;
    }

    /**
     * Get the queue name.
     *
     * @return string
     *
     * @since 3.2.0
     */
    public function queue()
    {
        return $this->queue;
    }

    /**
     * Get the raw JSON payload.
     *
     * @return string
     *
     * @since 3.2.0
     */
    public function payload()
    {
        return $this->payload;
    }

    /**
     * Get the attempt this run is.
     *
     * @return int
     *
     * @since 3.2.0
     */
    public function attempts()
    {
        return $this->attempts;
    }

    /**
     * Record that the job asked to go back on the queue.
     *
     * @param int|\DateTimeInterface|\DateInterval $delay How long before it is available again.
     *
     * @return void
     *
     * @since 3.2.0
     */
    public function release($delay = 0)
    {
        $this->released = true;
        $this->release_delay = $delay;
    }

    /**
     * Determine whether the job released itself.
     *
     * @return bool
     *
     * @since 3.2.0
     */
    public function is_released()
    {
        return $this->released;
    }

    /**
     * Get the delay the job asked to be released with.
     *
     * @return int|\DateTimeInterface|\DateInterval|null
     *
     * @since 3.2.0
     */
    public function release_delay()
    {
        return $this->release_delay;
    }

    /**
     * Record that the job failed itself.
     *
     * @param \Throwable $exception Why the job failed.
     *
     * @return void
     *
     * @since 3.2.0
     */
    public function fail(Throwable $exception)
    {
        $this->failed = true;
        $this->failure = $exception;
    }

    /**
     * Determine whether the job failed itself.
     *
     * @return bool
     *
     * @since 3.2.0
     */
    public function has_failed()
    {
        return $this->failed;
    }

    /**
     * Get the exception the job failed itself with.
     *
     * @return \Throwable|null
     *
     * @since 3.2.0
     */
    public function failure()
    {
        return $this->failure;
    }
}
