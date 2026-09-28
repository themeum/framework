<?php

namespace Framework\Tests\Unit\Queue;

use DateInterval;
use DateTimeImmutable;
use Framework\Exceptions\QueueException;
use Framework\Queue\Events\JobQueued;
use Framework\Tests\Support\Queue\Jobs\DefaultsJob;
use Framework\Tests\Support\Queue\Jobs\Journal;
use Framework\Tests\Support\Queue\Jobs\SendEmail;
use Framework\Tests\Support\Queue\Jobs\ThrowingJob;
use RuntimeException;

class DispatchTest extends QueueTestCase
{
    public function test_dispatch_stores_one_pending_row_with_defaults(): void
    {
        $this->enable_queue();

        SendEmail::dispatch(42);

        $this->assertCount(1, $this->queue->rows);

        $row = $this->queue->row(1);
        $this->assertSame('default', $row['queue']);
        $this->assertSame(0, $row['priority']);
        $this->assertSame(0, $row['attempts']);
        $this->assertNull($row['reserved_at']);
        $this->assertSame(self::NOW, $row['available_at']);
        $this->assertSame(SendEmail::class, $this->queue->payload_of(1)['job']);
    }

    public function test_the_row_is_written_only_when_the_pending_dispatch_is_destroyed(): void
    {
        $this->enable_queue();

        $pending = SendEmail::dispatch(42)->delay(10);
        $this->assertCount(0, $this->queue->rows);

        unset($pending);
        $this->assertCount(1, $this->queue->rows);
    }

    public function test_delay_in_seconds(): void
    {
        $this->enable_queue();

        SendEmail::dispatch(1)->delay(3600);

        $this->assertSame(self::NOW + 3600, $this->queue->row(1)['available_at']);
    }

    public function test_delay_until_a_date(): void
    {
        $this->enable_queue();

        SendEmail::dispatch(1)->delay(new DateTimeImmutable('@' . (self::NOW + 120)));

        $this->assertSame(self::NOW + 120, $this->queue->row(1)['available_at']);
    }

    public function test_delay_by_an_interval(): void
    {
        $this->enable_queue();

        SendEmail::dispatch(1)->delay(new DateInterval('PT5M'));

        $this->assertSame(self::NOW + 300, $this->queue->row(1)['available_at']);
    }

    public function test_a_past_date_is_available_now(): void
    {
        $this->enable_queue();

        SendEmail::dispatch(1)->delay(new DateTimeImmutable('@' . (self::NOW - 500)));

        $this->assertSame(self::NOW, $this->queue->row(1)['available_at']);
    }

    public function test_queue_name_and_priority(): void
    {
        $this->enable_queue();

        SendEmail::dispatch(1)->on_queue('emails')->with_priority(10);

        $this->assertSame('emails', $this->queue->row(1)['queue']);
        $this->assertSame(10, $this->queue->row(1)['priority']);
    }

    public function test_conditional_dispatch(): void
    {
        $this->enable_queue();

        SendEmail::dispatch_if(false, 1);
        SendEmail::dispatch_unless(true, 2);
        $this->assertCount(0, $this->queue->rows);

        SendEmail::dispatch_if(function () {
            return true;
        }, 3);
        SendEmail::dispatch_unless(false, 4);
        $this->assertCount(2, $this->queue->rows);
    }

    public function test_dispatch_sync_runs_immediately_without_storing_or_needing_the_provider(): void
    {
        $result = SendEmail::dispatch_sync(7);

        $this->assertSame('sent:7', $result);
        $this->assertSame([7], Journal::values('handled'));
        $this->assertCount(0, $this->queue->rows);
    }

    public function test_dispatch_sync_calls_failed_then_rethrows(): void
    {
        try {
            ThrowingJob::dispatch_sync();
            $this->fail('The exception was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $this->assertSame(['boom'], Journal::values('failed'));
    }

    public function test_a_job_can_set_its_own_defaults_and_the_dispatch_can_override_them(): void
    {
        $this->enable_queue();

        DefaultsJob::dispatch();
        DefaultsJob::dispatch()->on_queue('other')->with_priority(1)->delay(0);

        $this->assertSame('emails', $this->queue->row(1)['queue']);
        $this->assertSame(5, $this->queue->row(1)['priority']);
        $this->assertSame(self::NOW + 60, $this->queue->row(1)['available_at']);

        $this->assertSame('other', $this->queue->row(2)['queue']);
        $this->assertSame(1, $this->queue->row(2)['priority']);
        $this->assertSame(self::NOW, $this->queue->row(2)['available_at']);
    }

    public function test_dispatch_without_the_provider_names_the_provider(): void
    {
        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('QueueServiceProvider');

        SendEmail::dispatch(1);
    }

    public function test_dispatch_with_a_missing_table_says_how_to_create_it(): void
    {
        $this->enable_queue();
        $this->queue->exists = false;

        try {
            SendEmail::dispatch(1);
            $this->fail('No exception was thrown.');
        } catch (QueueException $exception) {
            $this->assertStringContainsString('wp_jobs', $exception->getMessage());
            $this->assertStringContainsString('queue:table', $exception->getMessage());
        }

        $this->assertCount(0, $this->queue->rows);
    }

    public function test_the_payload_records_the_jobs_tries_or_the_configured_default(): void
    {
        $this->enable_queue();
        $this->queue->with_options(['tries' => 4, 'backoff' => 20]);

        ThrowingJob::dispatch();
        SendEmail::dispatch(1);

        $this->assertSame(3, $this->queue->payload_of(1)['max_tries']);
        $this->assertSame([10, 60], $this->queue->payload_of(1)['backoff']);
        $this->assertSame(4, $this->queue->payload_of(2)['max_tries']);
        $this->assertSame(20, $this->queue->payload_of(2)['backoff']);
    }

    public function test_job_queued_is_dispatched_when_listened_for(): void
    {
        $this->enable_queue();
        $this->events->listen_for(JobQueued::class);

        SendEmail::dispatch(1);

        $this->assertSame([JobQueued::class], $this->events->dispatched_classes());
        $this->assertSame(1, $this->events->dispatched[0]->id);
        $this->assertInstanceOf(SendEmail::class, $this->events->dispatched[0]->job);
    }
}
