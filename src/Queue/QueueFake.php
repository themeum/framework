<?php
/**
 * In-memory stand-in for the queue that records dispatched jobs so a test can assert on them.
 * Nothing is stored and no worker is spawned. Failed assertions throw the built-in AssertionError,
 * which PHPUnit reports as a failure, so the framework does not need PHPUnit at runtime and
 * scoping cannot rename a test-only class it refers to.
 *
 * @package    Framework
 * @subpackage Queue
 * @since      3.2.0
 */
namespace Framework\Queue;

defined('ABSPATH') || exit;

use AssertionError;
use Framework\Contracts\ShouldQueue;

class QueueFake
{
    /**
     * The jobs pushed so far, in order.
     *
     * @var \Framework\Contracts\ShouldQueue[]
     *
     * @since 3.2.0
     */
    protected $jobs = [];

    /**
     * Record a pushed job.
     *
     * @param \Framework\Contracts\ShouldQueue $job The job.
     *
     * @return int A fake row id.
     *
     * @since 3.2.0
     */
    public function push(ShouldQueue $job)
    {
        $this->jobs[] = $job;

        return count($this->jobs);
    }

    /**
     * Get the pushed jobs of a class that pass the callback.
     *
     * @param string $class The job class.
     * @param callable|null $callback Receives the job; return true to keep it.
     *
     * @return \Framework\Contracts\ShouldQueue[]
     *
     * @since 3.2.0
     */
    public function pushed(string $class, ?callable $callback = null)
    {
        return array_values(array_filter($this->jobs, function ($job) use ($class, $callback) {
            return $job instanceof $class && (is_null($callback) || $callback($job));
        }));
    }

    /**
     * Count the recorded jobs, optionally on one queue.
     *
     * @param string|null $queue The queue name.
     *
     * @return int
     *
     * @since 3.2.0
     */
    public function size(?string $queue = null)
    {
        return count(array_filter($this->jobs, function ($job) use ($queue) {
            return is_null($queue) || $job->get_queue() === $queue;
        }));
    }

    /**
     * Forget the recorded jobs, optionally on one queue.
     *
     * @param string|null $queue The queue name.
     *
     * @return int The number of jobs forgotten.
     *
     * @since 3.2.0
     */
    public function clear(?string $queue = null)
    {
        $before = count($this->jobs);

        $this->jobs = array_values(array_filter($this->jobs, function ($job) use ($queue) {
            return !is_null($queue) && $job->get_queue() !== $queue;
        }));

        return $before - count($this->jobs);
    }

    /**
     * Assert that a job of the class was pushed and passes the callback.
     *
     * @param string $class The job class.
     * @param callable|null $callback Receives the job; return true when it matches.
     *
     * @return void
     *
     * @throws \AssertionError When no matching job was pushed.
     *
     * @since 3.2.0
     */
    public function assert_pushed(string $class, ?callable $callback = null)
    {
        $this->assert(
            count($this->pushed($class, $callback)) > 0,
            sprintf('The expected [%s] job was not pushed.', $class)
        );
    }

    /**
     * Assert that a job of the class was pushed exactly the given number of times.
     *
     * @param string $class The job class.
     * @param int $times The expected count.
     *
     * @return void
     *
     * @throws \AssertionError When the count differs.
     *
     * @since 3.2.0
     */
    public function assert_pushed_times(string $class, int $times)
    {
        $count = count($this->pushed($class));

        $this->assert(
            $count === $times,
            sprintf('The expected [%s] job was pushed %d times instead of %d times.', $class, $count, $times)
        );
    }

    /**
     * Assert that no job of the class passing the callback was pushed.
     *
     * @param string $class The job class.
     * @param callable|null $callback Receives the job; return true when it matches.
     *
     * @return void
     *
     * @throws \AssertionError When a matching job was pushed.
     *
     * @since 3.2.0
     */
    public function assert_not_pushed(string $class, ?callable $callback = null)
    {
        $this->assert(
            count($this->pushed($class, $callback)) === 0,
            sprintf('The unexpected [%s] job was pushed.', $class)
        );
    }

    /**
     * Assert that no job was pushed at all.
     *
     * @return void
     *
     * @throws \AssertionError When any job was pushed.
     *
     * @since 3.2.0
     */
    public function assert_nothing_pushed()
    {
        $this->assert(
            empty($this->jobs),
            sprintf('%d unexpected jobs were pushed.', count($this->jobs))
        );
    }

    /**
     * Fail with the message unless the condition holds.
     *
     * @param bool $condition The condition.
     * @param string $message The failure message.
     *
     * @return void
     *
     * @throws \AssertionError When the condition is false.
     *
     * @since 3.2.0
     */
    protected function assert(bool $condition, string $message)
    {
        if (!$condition) {
            throw new AssertionError($message);
        }
    }
}
