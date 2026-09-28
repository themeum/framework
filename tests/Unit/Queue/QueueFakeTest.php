<?php

namespace Framework\Tests\Unit\Queue;

use AssertionError;
use Framework\Supports\Facades\Queue;
use Framework\Tests\Support\Queue\Jobs\SendEmail;
use Framework\Tests\Support\Queue\Jobs\ThrowingJob;

class QueueFakeTest extends QueueTestCase
{
    public function test_a_faked_queue_records_dispatches_without_storing_or_spawning(): void
    {
        Queue::fake();

        SendEmail::dispatch(123)->on_queue('emails');

        $this->assertCount(0, $this->queue->rows);
        $this->assertArrayNotHasKey('shutdown', $GLOBALS['framework_test_actions']);
        $this->assertSame(1, Queue::size('emails'));
        $this->assertTrue(Queue::is_faked());
    }

    public function test_assertions_pass_for_what_was_pushed(): void
    {
        Queue::fake();

        SendEmail::dispatch(123);

        Queue::assert_pushed(SendEmail::class);
        Queue::assert_pushed(SendEmail::class, function (SendEmail $job) {
            return $job->user_id === 123;
        });
        Queue::assert_pushed_times(SendEmail::class, 1);
        Queue::assert_not_pushed(ThrowingJob::class);
        Queue::assert_not_pushed(SendEmail::class, function (SendEmail $job) {
            return $job->user_id === 999;
        });

        $this->addToAssertionCount(5);
    }

    public function test_assert_pushed_fails_when_nothing_matches(): void
    {
        Queue::fake();
        SendEmail::dispatch(1);

        $this->expectException(AssertionError::class);

        Queue::assert_pushed(SendEmail::class, function (SendEmail $job) {
            return $job->user_id === 2;
        });
    }

    public function test_assert_pushed_times_fails_on_a_different_count(): void
    {
        Queue::fake();
        SendEmail::dispatch(1);
        SendEmail::dispatch(2);

        $this->expectException(AssertionError::class);
        $this->expectExceptionMessage('pushed 2 times instead of 1 times');

        Queue::assert_pushed_times(SendEmail::class, 1);
    }

    public function test_assert_not_pushed_fails_when_it_was(): void
    {
        Queue::fake();
        SendEmail::dispatch(1);

        $this->expectException(AssertionError::class);

        Queue::assert_not_pushed(SendEmail::class);
    }

    public function test_assert_nothing_pushed(): void
    {
        Queue::fake();
        Queue::assert_nothing_pushed();

        SendEmail::dispatch(1);

        $this->expectException(AssertionError::class);

        Queue::assert_nothing_pushed();
    }
}
