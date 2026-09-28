<?php

namespace Framework\Tests\Unit\Queue;

use Framework\Tests\Support\Queue\Jobs\AttemptsJob;
use Framework\Tests\Support\Queue\Jobs\Journal;
use Framework\Tests\Support\Queue\Jobs\SendEmail;

class StaleRecoveryTest extends QueueTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->enable_queue();
        $this->queue->with_options(['retry_after' => 300]);
    }

    public function test_a_live_reservation_is_not_claimed_again(): void
    {
        SendEmail::dispatch(1);
        $this->queue->claim(10, 'crashed-worker');

        $this->travel(299);
        $this->worker->run();

        $this->assertSame([], Journal::values('handled'));
        $this->assertSame('crashed-worker', $this->queue->row(1)['reserved_by']);
    }

    public function test_an_abandoned_reservation_is_reclaimed_and_counts_an_attempt(): void
    {
        AttemptsJob::dispatch();
        $this->queue->claim(10, 'crashed-worker');

        $this->travel(300);
        $this->assertTrue($this->queue->has_due());

        $this->worker->run();

        $this->assertSame([2], Journal::values('attempts'));
        $this->assertCount(0, $this->queue->rows);
    }

    public function test_a_single_try_job_whose_worker_crashed_is_failed_not_rerun(): void
    {
        SendEmail::dispatch(1);
        $this->queue->claim(10, 'crashed-worker');

        $this->travel(300);
        $this->worker->run();

        $this->assertSame([], Journal::values('handled'));
        $this->assertCount(1, $this->queue->failed);
    }

    public function test_a_crash_loop_ends_in_the_failed_jobs_table_without_running_again(): void
    {
        AttemptsJob::dispatch();

        $this->queue->claim(10, 'crash-1');
        $this->travel(300);
        $this->queue->claim(10, 'crash-2');
        $this->travel(300);

        $this->worker->run();

        $this->assertSame([], Journal::values('attempts'));
        $this->assertCount(0, $this->queue->rows);
        $this->assertCount(1, $this->queue->failed);
        $this->assertStringContainsString('attempted too many times', $this->queue->failed[1]['exception']);
    }
}
