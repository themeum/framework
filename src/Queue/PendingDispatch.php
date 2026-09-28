<?php
/**
 * The object Job::dispatch() returns, which writes the job to the queue when it is destroyed.
 * Writing on destruction is what lets `Job::dispatch($id)->delay(60)->on_queue('emails')` read
 * as one statement. The checks that can fail (queue enabled, table present) already ran in
 * dispatch(), so the destructor only has to insert.
 *
 * @package    Framework
 * @subpackage Queue
 * @since      3.2.0
 */
namespace Framework\Queue;

defined('ABSPATH') || exit;

use Framework\Contracts\ShouldQueue;

class PendingDispatch
{
    /**
     * The job being dispatched.
     *
     * @var \Framework\Contracts\ShouldQueue
     *
     * @since 3.2.0
     */
    protected $job;

    /**
     * The queue manager the job is pushed to.
     *
     * @var \Framework\Queue\QueueManager
     *
     * @since 3.2.0
     */
    protected $manager;

    /**
     * Create a pending dispatch.
     *
     * @param \Framework\Contracts\ShouldQueue $job The job being dispatched.
     * @param \Framework\Queue\QueueManager $manager The queue manager.
     *
     * @return void
     *
     * @since 3.2.0
     */
    public function __construct(ShouldQueue $job, QueueManager $manager)
    {
        $this->job = $job;
        $this->manager = $manager;
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
        $this->job->delay($delay);

        return $this;
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
        $this->job->on_queue($queue);

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
        $this->job->with_priority($priority);

        return $this;
    }

    /**
     * Get the job being dispatched.
     *
     * @return \Framework\Contracts\ShouldQueue
     *
     * @since 3.2.0
     */
    public function get_job()
    {
        return $this->job;
    }

    /**
     * Push the job to the queue.
     *
     * @return void
     *
     * @since 3.2.0
     */
    public function __destruct()
    {
        $this->manager->push($this->job);
    }
}
