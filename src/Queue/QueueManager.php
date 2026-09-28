<?php
/**
 * Entry point behind the Queue facade: pushes jobs, reports on the queue, and swaps in a fake.
 * It is bound only by QueueServiceProvider, which is how Job::dispatch() tells whether the
 * consuming plugin opted in. The first undelayed push in a request registers one shutdown
 * callback that starts a worker, so "dispatch now" does not wait for the next cron tick.
 *
 * @package    Framework
 * @subpackage Queue
 * @since      3.2.0
 */
namespace Framework\Queue;

defined('ABSPATH') || exit;

use Framework\Contracts\ShouldQueue;
use Framework\Exceptions\QueueException;
use Framework\Queue\Events\JobQueued;

use function Framework\app;

class QueueManager
{
    /**
     * The queue storage.
     *
     * @var \Framework\Queue\DatabaseQueue
     *
     * @since 3.2.0
     */
    protected $database;

    /**
     * The loopback spawner.
     *
     * @var \Framework\Queue\Spawner
     *
     * @since 3.2.0
     */
    protected $spawner;

    /**
     * The fake that records pushes, when the queue is faked.
     *
     * @var \Framework\Queue\QueueFake|null
     *
     * @since 3.2.0
     */
    protected $fake;

    /**
     * Whether the shutdown spawn has been registered for this request.
     *
     * @var bool
     *
     * @since 3.2.0
     */
    protected $spawn_scheduled = false;

    /**
     * Create the queue manager.
     *
     * @param \Framework\Queue\DatabaseQueue $database The queue storage.
     * @param \Framework\Queue\Spawner $spawner The loopback spawner.
     *
     * @return void
     *
     * @since 3.2.0
     */
    public function __construct(DatabaseQueue $database, Spawner $spawner)
    {
        $this->database = $database;
        $this->spawner = $spawner;
    }

    /**
     * Get the bound queue manager.
     *
     * @return static
     *
     * @throws \Framework\Exceptions\QueueException When the queue service provider is not registered.
     *
     * @since 3.2.0
     */
    public static function resolve()
    {
        if (!app()->bound(QueueManager::class)) {
            throw QueueException::not_enabled();
        }

        return app(QueueManager::class);
    }

    /**
     * Make sure a job can be stored.
     *
     * @return void
     *
     * @throws \Framework\Exceptions\QueueException When the jobs table does not exist.
     *
     * @since 3.2.0
     */
    public function ensure_ready()
    {
        if ($this->fake) {
            return;
        }

        if (!$this->database->table_exists()) {
            throw QueueException::table_missing($this->database->get_table());
        }
    }

    /**
     * Push a job onto the queue.
     *
     * @param \Framework\Contracts\ShouldQueue $job The job.
     *
     * @return int The stored row id.
     *
     * @since 3.2.0
     */
    public function push(ShouldQueue $job)
    {
        if ($this->fake) {
            return $this->fake->push($job);
        }

        $id = $this->database->push(
            Payload::encode(
                $job,
                (int) $this->database->option('tries', 1),
                $this->database->option('backoff', 0)
            ),
            $job->get_queue(),
            $job->get_priority(),
            $job->get_delay()
        );

        $events = app('event');

        if ($events->has_listeners(JobQueued::class)) {
            $events->dispatch(new JobQueued($id, $job));
        }

        if ($this->database->is_immediate($job->get_delay())) {
            $this->schedule_spawn();
        }

        return $id;
    }

    /**
     * Push a job onto the queue after a delay.
     *
     * @param int|\DateTimeInterface|\DateInterval $delay Seconds, or the moment it becomes available.
     * @param \Framework\Contracts\ShouldQueue $job The job.
     *
     * @return int The stored row id.
     *
     * @since 3.2.0
     */
    public function later($delay, ShouldQueue $job)
    {
        $job->delay($delay);

        return $this->push($job);
    }

    /**
     * Count the jobs not currently being worked on, optionally on one queue.
     *
     * @param string|null $queue The queue name.
     *
     * @return int
     *
     * @since 3.2.0
     */
    public function size(?string $queue = null)
    {
        return $this->fake ? $this->fake->size($queue) : $this->database->size($queue);
    }

    /**
     * Delete the jobs not currently being worked on, optionally on one queue.
     *
     * @param string|null $queue The queue name.
     *
     * @return int The number of jobs deleted.
     *
     * @since 3.2.0
     */
    public function clear(?string $queue = null)
    {
        return $this->fake ? $this->fake->clear($queue) : $this->database->clear($queue);
    }

    /**
     * Replace the queue with a recorder for the rest of the request.
     *
     * Also binds this manager, so a test can fake the queue without registering the provider.
     *
     * @return \Framework\Queue\QueueFake
     *
     * @since 3.2.0
     */
    public function fake()
    {
        $this->fake = new QueueFake();

        app()->instance(QueueManager::class, $this);

        return $this->fake;
    }

    /**
     * Determine whether the queue is faked.
     *
     * @return bool
     *
     * @since 3.2.0
     */
    public function is_faked()
    {
        return !is_null($this->fake);
    }

    /**
     * Assert that a job of the class was pushed and passes the callback.
     *
     * @param string $class The job class.
     * @param callable|null $callback Receives the job; return true when it matches.
     *
     * @return void
     *
     * @since 3.2.0
     */
    public function assert_pushed(string $class, ?callable $callback = null)
    {
        $this->faked()->assert_pushed($class, $callback);
    }

    /**
     * Assert that a job of the class was pushed exactly the given number of times.
     *
     * @param string $class The job class.
     * @param int $times The expected count.
     *
     * @return void
     *
     * @since 3.2.0
     */
    public function assert_pushed_times(string $class, int $times)
    {
        $this->faked()->assert_pushed_times($class, $times);
    }

    /**
     * Assert that no job of the class passing the callback was pushed.
     *
     * @param string $class The job class.
     * @param callable|null $callback Receives the job; return true when it matches.
     *
     * @return void
     *
     * @since 3.2.0
     */
    public function assert_not_pushed(string $class, ?callable $callback = null)
    {
        $this->faked()->assert_not_pushed($class, $callback);
    }

    /**
     * Assert that no job was pushed at all.
     *
     * @return void
     *
     * @since 3.2.0
     */
    public function assert_nothing_pushed()
    {
        $this->faked()->assert_nothing_pushed();
    }

    /**
     * Get the fake, failing loudly when the queue was not faked first.
     *
     * @return \Framework\Queue\QueueFake
     *
     * @throws \Framework\Exceptions\QueueException When Queue::fake() was not called.
     *
     * @since 3.2.0
     */
    protected function faked()
    {
        if (!$this->fake) {
            throw new QueueException('Call Queue::fake() before making queue assertions.');
        }

        return $this->fake;
    }

    /**
     * Start a worker when the current request finishes, at most once per request.
     *
     * @return void
     *
     * @since 3.2.0
     */
    protected function schedule_spawn()
    {
        if ($this->spawn_scheduled || !function_exists('add_action')) {
            return;
        }

        $this->spawn_scheduled = true;

        add_action('shutdown', function () {
            $this->spawner->spawn_if_idle();
        }, PHP_INT_MAX);
    }
}
