<?php

namespace Framework\Tests\Unit\Queue;

use Framework\Queue\Events\JobFailed;
use Framework\Queue\Events\JobProcessed;
use Framework\Queue\Events\JobProcessing;
use Framework\Tests\Support\Queue\Jobs\AttemptsJob;
use Framework\Tests\Support\Queue\Jobs\FailedThrowsJob;
use Framework\Tests\Support\Queue\Jobs\InjectedJob;
use Framework\Tests\Support\Queue\Jobs\Journal;
use Framework\Tests\Support\Queue\Jobs\ReleasingJob;
use Framework\Tests\Support\Queue\Jobs\SelfFailingJob;
use Framework\Tests\Support\Queue\Jobs\SendEmail;
use Framework\Tests\Support\Queue\Jobs\ThrowingJob;

class WorkerTest extends QueueTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->enable_queue();
    }

    public function test_jobs_run_highest_priority_first(): void
    {
        SendEmail::dispatch('low')->with_priority(0);
        SendEmail::dispatch('high')->with_priority(10);
        SendEmail::dispatch('middle')->with_priority(5);

        $this->worker->run();

        $this->assertSame(['high', 'middle', 'low'], Journal::values('handled'));
    }

    public function test_equal_priority_runs_in_availability_then_insertion_order(): void
    {
        SendEmail::dispatch('b');
        SendEmail::dispatch('a');
        SendEmail::dispatch('c');
        $this->queue->rows[2]['available_at'] = self::NOW - 10;

        $this->worker->run();

        $this->assertSame(['a', 'b', 'c'], Journal::values('handled'));
    }

    public function test_a_claim_reserves_at_most_the_batch_size_and_one_run_takes_several_batches(): void
    {
        $this->queue->with_options(['batch_size' => 10]);

        for ($i = 1; $i <= 25; $i++) {
            SendEmail::dispatch($i);
        }

        $remaining = $this->worker->run();

        $this->assertSame([10, 10, 5, 0], $this->queue->claims);
        $this->assertCount(25, Journal::values('handled'));
        $this->assertFalse($remaining);
        $this->assertCount(0, $this->queue->rows);
    }

    public function test_an_exhausted_budget_hands_back_unstarted_jobs_without_using_an_attempt(): void
    {
        $this->queue->with_options(['batch_size' => 10, 'time_limit' => 3]);
        $this->worker->seconds_per_job = 1.0;

        for ($i = 1; $i <= 10; $i++) {
            SendEmail::dispatch($i);
        }

        $remaining = $this->worker->run();

        $this->assertTrue($remaining);
        $this->assertSame([1, 2, 3], Journal::values('handled'));
        $this->assertCount(7, $this->queue->rows);

        foreach ($this->queue->rows as $row) {
            $this->assertNull($row['reserved_at']);
            $this->assertNull($row['reserved_by']);
            $this->assertSame(0, $row['attempts']);
        }
    }

    public function test_a_job_limit_stops_the_run(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            SendEmail::dispatch($i);
        }

        $remaining = $this->worker->run(['max_jobs' => 1]);

        $this->assertTrue($remaining);
        $this->assertSame([1], Journal::values('handled'));
        $this->assertSame([1], $this->queue->claims);
    }

    public function test_a_successful_job_is_deleted_and_announced(): void
    {
        $this->events->listen_for(JobProcessing::class)->listen_for(JobProcessed::class);

        SendEmail::dispatch(1);
        $this->worker->run();

        $this->assertCount(0, $this->queue->rows);
        $this->assertSame([JobProcessing::class, JobProcessed::class], $this->events->dispatched_classes());
    }

    public function test_a_throwing_job_is_retried_with_its_backoff_then_failed(): void
    {
        $this->events->listen_for(JobFailed::class);

        ThrowingJob::dispatch();

        $this->worker->run();
        $this->assertSame(1, $this->queue->row(1)['attempts']);
        $this->assertSame(self::NOW + 10, $this->queue->row(1)['available_at']);
        $this->assertNull($this->queue->row(1)['reserved_at']);

        $this->travel(10);
        $this->worker->run();
        $this->assertSame(2, $this->queue->row(1)['attempts']);
        $this->assertSame(self::NOW + 10 + 60, $this->queue->row(1)['available_at']);

        $this->travel(60);
        $this->worker->run();

        $this->assertSame([1, 2, 3], Journal::values('attempt'));
        $this->assertSame(['boom'], Journal::values('failed'));
        $this->assertCount(0, $this->queue->rows);
        $this->assertCount(1, $this->queue->failed);

        $failed = $this->queue->failed[1];
        $this->assertSame('default', $failed['queue']);
        $this->assertSame(self::NOW + 70, $failed['failed_at']);
        $this->assertStringContainsString('boom', $failed['exception']);
        $this->assertSame($this->queue_uuid($failed['payload']), $failed['uuid']);

        $this->assertSame([JobFailed::class], $this->events->dispatched_classes());
        $this->assertCount(1, $this->log->errors);
        $this->assertStringContainsString(ThrowingJob::class, $this->log->errors[0]);
        $this->assertStringContainsString('boom', $this->log->errors[0]);
    }

    public function test_a_single_backoff_value_and_default_tries_come_from_config(): void
    {
        $this->queue->with_options(['tries' => 2, 'backoff' => 15]);

        FailedThrowsJob::dispatch();
        $this->worker->run();

        $this->assertSame(self::NOW + 15, $this->queue->row(1)['available_at']);

        $this->travel(15);
        $this->worker->run();

        $this->assertCount(1, $this->queue->failed);
    }

    public function test_one_failing_job_does_not_stop_the_batch(): void
    {
        SendEmail::dispatch(1);
        FailedThrowsJob::dispatch();
        SendEmail::dispatch(3);

        $this->worker->run();

        $this->assertSame([1, 3], Journal::values('handled'));
        $this->assertCount(1, $this->queue->failed);
    }

    public function test_a_job_can_release_itself(): void
    {
        ReleasingJob::dispatch();

        $this->worker->run();

        $row = $this->queue->row(1);
        $this->assertNotNull($row);
        $this->assertNull($row['reserved_at']);
        $this->assertSame(self::NOW + 30, $row['available_at']);
        $this->assertSame([], Journal::names());
        $this->assertCount(0, $this->queue->failed);
    }

    public function test_a_job_can_fail_itself_with_tries_remaining(): void
    {
        SelfFailingJob::dispatch();

        $this->worker->run();

        $this->assertCount(0, $this->queue->rows);
        $this->assertCount(1, $this->queue->failed);
        $this->assertSame(['gave up'], Journal::values('failed'));
    }

    public function test_attempts_is_visible_inside_handle(): void
    {
        AttemptsJob::dispatch();

        $this->worker->run();
        $this->worker->run();

        $this->assertSame([1, 2], Journal::values('attempts'));
        $this->assertCount(0, $this->queue->rows);
        $this->assertCount(0, $this->queue->failed);
    }

    public function test_handle_receives_container_resolved_dependencies(): void
    {
        InjectedJob::dispatch();

        $this->worker->run();

        $this->assertSame(['default mailer'], Journal::values('injected'));
    }

    protected function queue_uuid(string $payload): string
    {
        return json_decode($payload, true)['uuid'];
    }
}
