<?php
/**
 * Claims due jobs and runs them within a time budget, settling each one afterwards.
 * It knows nothing about how it was started: the loopback endpoint, WP-CLI, and dispatch_sync()
 * all drive the same code, so a job behaves identically whichever way it runs. The budget exists
 * because a web worker must finish well inside max_execution_time; jobs claimed but not started
 * when it runs out are handed back without costing them an attempt.
 *
 * @package    Framework
 * @subpackage Queue
 * @since      3.2.0
 */
namespace Framework\Queue;

defined('ABSPATH') || exit;

use Framework\Contracts\ShouldQueue;
use Framework\Exceptions\QueueException;
use Framework\Queue\Events\JobFailed;
use Framework\Queue\Events\JobProcessed;
use Framework\Queue\Events\JobProcessing;
use Framework\Supports\Facades\Log;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

use function Framework\app;

class Worker
{
    /**
     * The queue storage.
     *
     * @var \Framework\Queue\DatabaseQueue
     *
     * @since 3.2.0
     */
    protected $queue;

    /**
     * The moment the current run started, from microtime(true).
     *
     * @var float
     *
     * @since 3.2.0
     */
    protected $started_at = 0.0;

    /**
     * Create a worker.
     *
     * @param \Framework\Queue\DatabaseQueue $queue The queue storage.
     *
     * @return void
     *
     * @since 3.2.0
     */
    public function __construct(DatabaseQueue $queue)
    {
        $this->queue = $queue;
    }

    /**
     * Process due jobs until the budget, the job limit, or the queue runs out.
     *
     * Options: `budget` (seconds, or null for no limit), `max_jobs` (int or null), and `queues`
     * (queue names to restrict to, or null for every queue).
     *
     * @param array $options The run options.
     *
     * @return bool Whether due jobs remain.
     *
     * @since 3.2.0
     */
    public function run(array $options = [])
    {
        $budget = array_key_exists('budget', $options) ? $options['budget'] : $this->budget();
        $max_jobs = isset($options['max_jobs']) ? max(1, (int) $options['max_jobs']) : null;
        $queues = $options['queues'] ?? null;
        $batch_size = max(1, (int) $this->queue->option('batch_size', 10));

        $this->started_at = microtime(true);
        $processed = 0;

        while (!$this->out_of_time($budget) && (is_null($max_jobs) || $processed < $max_jobs)) {
            $limit = is_null($max_jobs) ? $batch_size : min($batch_size, $max_jobs - $processed);
            $records = array_values($this->queue->claim($limit, $this->token(), $queues));

            if (empty($records)) {
                break;
            }

            foreach ($records as $index => $record) {
                if ($this->out_of_time($budget)) {
                    $this->queue->release_unstarted(array_map(function (JobRecord $unstarted) {
                        return $unstarted->id();
                    }, array_slice($records, $index)));

                    break 2;
                }

                $this->process($record);
                $processed++;
            }
        }

        return $this->queue->has_due($queues);
    }

    /**
     * Get the time budget for a web worker, in seconds.
     *
     * @return float
     *
     * @since 3.2.0
     */
    public function budget()
    {
        $limit = (float) $this->queue->option('time_limit', 20);
        $max_execution_time = (int) ini_get('max_execution_time');

        if ($max_execution_time > 0) {
            $limit = min($limit, floor($max_execution_time * 0.8));
        }

        return max(1.0, $limit);
    }

    /**
     * Run one claimed job and settle it.
     *
     * @param \Framework\Queue\JobRecord $record The claimed record.
     *
     * @return void
     *
     * @since 3.2.0
     */
    public function process(JobRecord $record)
    {
        try {
            $envelope = Payload::decode($record->payload());
        } catch (QueueException $exception) {
            $this->fail_job($record, null, $exception);

            return;
        }

        if ($record->attempts() > max(1, (int) ($envelope['max_tries'] ?? 1))) {
            $this->fail_job(
                $record,
                null,
                QueueException::max_attempts_exceeded(Payload::display_name($record->payload()))
            );

            return;
        }

        try {
            $job = Payload::restore($envelope);
        } catch (QueueException $exception) {
            $this->fail_job($record, null, $exception);

            return;
        }

        $job->set_job_record($record);
        $this->fire(JobProcessing::class, [$record, $job]);

        try {
            $this->call_handle($job);
        } catch (Throwable $exception) {
            $this->handle_exception($record, $job, $envelope, $exception);

            return;
        }

        if ($record->has_failed()) {
            $this->fail_job($record, $job, $record->failure());

            return;
        }

        if ($record->is_released()) {
            $this->queue->release($record->id(), $record->release_delay());

            return;
        }

        $this->queue->delete($record->id());
        $this->fire(JobProcessed::class, [$record, $job]);
    }

    /**
     * Run a job in the current request without storing it.
     *
     * @param \Framework\Contracts\ShouldQueue $job The job.
     *
     * @return mixed The value handle() returned.
     *
     * @throws \Throwable Whatever handle() threw, after failed() has been called.
     *
     * @since 3.2.0
     */
    public function run_sync(ShouldQueue $job)
    {
        $record = new JobRecord(0, $job->get_queue(), '', 1);
        $job->set_job_record($record);

        try {
            $result = $this->call_handle($job);
        } catch (Throwable $exception) {
            $this->call_failed($job, $exception);

            throw $exception;
        }

        if ($record->has_failed()) {
            $this->call_failed($job, $record->failure());
        }

        return $result;
    }

    /**
     * Decide between retrying and failing a job whose handle() threw.
     *
     * @param \Framework\Queue\JobRecord $record The claimed record.
     * @param \Framework\Contracts\ShouldQueue $job The job.
     * @param array $envelope The decoded envelope.
     * @param \Throwable $exception What handle() threw.
     *
     * @return void
     *
     * @since 3.2.0
     */
    protected function handle_exception(JobRecord $record, ShouldQueue $job, array $envelope, Throwable $exception)
    {
        if (!$record->has_failed() && $record->attempts() < max(1, (int) ($envelope['max_tries'] ?? 1))) {
            $this->queue->release($record->id(), $this->backoff_for($envelope['backoff'] ?? 0, $record->attempts()));

            return;
        }

        $this->fail_job($record, $job, $exception);
    }

    /**
     * Get the retry delay for the attempt that just failed.
     *
     * @param int|array $backoff Seconds, or seconds per attempt with the last value reused.
     * @param int $attempts The attempt that just failed.
     *
     * @return int
     *
     * @since 3.2.0
     */
    protected function backoff_for($backoff, int $attempts)
    {
        if (!is_array($backoff)) {
            return max(0, (int) $backoff);
        }

        $backoff = array_values($backoff);

        if (empty($backoff)) {
            return 0;
        }

        return max(0, (int) $backoff[min(max(1, $attempts), count($backoff)) - 1]);
    }

    /**
     * Record a job as failed and tell everyone who needs to know.
     *
     * @param \Framework\Queue\JobRecord $record The claimed record.
     * @param \Framework\Contracts\ShouldQueue|null $job The job, if it was restored.
     * @param \Throwable $exception Why it failed.
     *
     * @return void
     *
     * @since 3.2.0
     */
    protected function fail_job(JobRecord $record, ?ShouldQueue $job, Throwable $exception)
    {
        $this->queue->fail($record, $exception);

        if ($job) {
            $this->call_failed($job, $exception);
        }

        $this->fire(JobFailed::class, [$record, $job, $exception]);

        try {
            Log::error(sprintf(
                'Queued job [%s] failed on attempt %d: %s',
                Payload::display_name($record->payload()),
                $record->attempts(),
                $exception->getMessage()
            ));
        } catch (Throwable $ignored) {
            // A log that cannot be written must not stop the worker.
        }
    }

    /**
     * Call the job's failed() method, if it has one, without letting it stop the worker.
     *
     * @param \Framework\Contracts\ShouldQueue $job The job.
     * @param \Throwable $exception Why it failed.
     *
     * @return void
     *
     * @since 3.2.0
     */
    protected function call_failed(ShouldQueue $job, Throwable $exception)
    {
        if (!method_exists($job, 'failed')) {
            return;
        }

        try {
            $job->failed($exception);
        } catch (Throwable $ignored) {
            // The job has already failed; its failure hook failing too changes nothing.
        }
    }

    /**
     * Call the job's handle() method with its class-typed parameters resolved from the container.
     *
     * @param \Framework\Contracts\ShouldQueue $job The job.
     *
     * @return mixed
     *
     * @since 3.2.0
     */
    protected function call_handle(ShouldQueue $job)
    {
        $dependencies = [];

        foreach ((new ReflectionMethod($job, 'handle'))->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $dependencies[] = app()->make($type->getName());
                continue;
            }

            $dependencies[] = $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null;
        }

        return $job->handle(...$dependencies);
    }

    /**
     * Dispatch a queue event when anything listens for it.
     *
     * @param string $event_class The event class.
     * @param array $arguments The event's constructor arguments.
     *
     * @return void
     *
     * @since 3.2.0
     */
    protected function fire(string $event_class, array $arguments)
    {
        $events = app('event');

        if ($events->has_listeners($event_class)) {
            $events->dispatch(new $event_class(...$arguments));
        }
    }

    /**
     * Determine whether the run has used up its budget.
     *
     * @param float|null $budget The budget in seconds, or null for no limit.
     *
     * @return bool
     *
     * @since 3.2.0
     */
    protected function out_of_time($budget)
    {
        return !is_null($budget) && $this->elapsed() >= $budget;
    }

    /**
     * Get the seconds elapsed since the run started.
     *
     * @return float
     *
     * @since 3.2.0
     */
    protected function elapsed()
    {
        return microtime(true) - $this->started_at;
    }

    /**
     * Generate a fresh claim token.
     *
     * @return string
     *
     * @since 3.2.0
     */
    protected function token()
    {
        return bin2hex(random_bytes(16));
    }
}
