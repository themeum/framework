<?php

namespace Framework\Tests\Unit\Queue;

use Framework\Exceptions\QueueException;
use Framework\Queue\JobRecord;
use Framework\Queue\Payload;
use Framework\Tests\Support\Queue\Jobs\Journal;
use Framework\Tests\Support\Queue\Jobs\NotAJob;
use Framework\Tests\Support\Queue\Jobs\SendEmail;

class PayloadTest extends QueueTestCase
{
    public function test_job_properties_survive_the_round_trip(): void
    {
        $job = new SendEmail(42, 'Welcome', ['vip', 'new']);
        $job->on_queue('emails')->with_priority(3);

        $restored = Payload::restore(Payload::decode(Payload::encode($job)));

        $this->assertInstanceOf(SendEmail::class, $restored);
        $this->assertSame(42, $restored->user_id);
        $this->assertSame('Welcome', $restored->subject);
        $this->assertSame(['vip', 'new'], $restored->tags);
        $this->assertSame('emails', $restored->get_queue());
        $this->assertSame(3, $restored->get_priority());
    }

    public function test_the_runtime_record_is_not_persisted(): void
    {
        $job = new SendEmail(1);
        $job->set_job_record(new JobRecord(9, 'default', '{"secret":true}', 4));

        $envelope = Payload::decode(Payload::encode($job));

        $this->assertStringNotContainsString('JobRecord', $envelope['data']);
        $this->assertSame(1, Payload::restore($envelope)->attempts());
        $this->assertSame(4, $job->attempts());
    }

    public function test_a_malformed_envelope_is_rejected(): void
    {
        $this->expectException(QueueException::class);

        Payload::decode('not json');
    }

    public function test_a_class_that_does_not_exist_fails_without_running(): void
    {
        $this->assert_row_fails_without_running(json_encode([
            'job' => 'Framework\\Tests\\Support\\Queue\\Jobs\\Missing',
            'max_tries' => 1,
            'data' => 'O:0:"":0:{}',
        ]), 'does not exist');
    }

    public function test_a_class_that_is_not_a_queued_job_fails_without_running(): void
    {
        $this->assert_row_fails_without_running(json_encode([
            'job' => NotAJob::class,
            'max_tries' => 1,
            'data' => serialize(new NotAJob()),
        ]), 'does not implement');
    }

    public function test_data_that_restores_to_a_different_class_fails_without_running(): void
    {
        $this->assert_row_fails_without_running(json_encode([
            'job' => SendEmail::class,
            'max_tries' => 1,
            'data' => serialize(new NotAJob()),
        ]), 'does not restore');
    }

    public function test_processing_continues_after_an_invalid_payload(): void
    {
        $this->enable_queue();
        $this->queue->push('garbage', 'default');
        SendEmail::dispatch(5);

        $this->worker->run();

        $this->assertCount(1, $this->queue->failed);
        $this->assertSame([5], Journal::values('handled'));
    }

    protected function assert_row_fails_without_running(string $payload, string $reason): void
    {
        $this->queue->push($payload, 'default');

        $this->worker->run();

        $this->assertSame([], Journal::names());
        $this->assertCount(0, $this->queue->rows);
        $this->assertCount(1, $this->queue->failed);
        $this->assertStringContainsString($reason, $this->queue->failed[1]['exception']);
    }
}
