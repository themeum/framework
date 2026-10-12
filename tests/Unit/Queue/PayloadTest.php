<?php

namespace Framework\Tests\Unit\Queue;

use Framework\Exceptions\QueueException;
use Framework\Queue\JobRecord;
use Framework\Queue\Payload;
use Framework\Tests\Support\Queue\Jobs\DestructorGadget;
use Framework\Tests\Support\Queue\Jobs\Journal;
use Framework\Tests\Support\Queue\Jobs\NotAJob;
use Framework\Tests\Support\Queue\Jobs\SendEmail;
use Framework\Tests\Support\Queue\Jobs\ValueJob;
use Framework\Tests\Support\Queue\Jobs\ValueObject;

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

        $this->assertStringNotContainsString('JobRecord', json_encode($envelope['data']));
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
            'data' => ['@' => 'object', 'c' => 'Framework\\Tests\\Support\\Queue\\Jobs\\Missing', 'p' => []],
        ]), 'does not exist');
    }

    public function test_a_class_that_is_not_a_queued_job_fails_without_running(): void
    {
        $this->assert_row_fails_without_running(json_encode([
            'job' => NotAJob::class,
            'max_tries' => 1,
            'data' => ['@' => 'object', 'c' => NotAJob::class, 'p' => []],
        ]), 'does not implement');
    }

    public function test_data_that_restores_to_a_different_class_fails_without_running(): void
    {
        $this->assert_row_fails_without_running(json_encode([
            'job' => SendEmail::class,
            'max_tries' => 1,
            'data' => ['@' => 'object', 'c' => NotAJob::class, 'p' => []],
        ]), 'does not restore');
    }

    public function test_the_payload_is_json_with_no_php_serialized_data(): void
    {
        $payload = Payload::encode(new SendEmail(1, 'Hi'));

        $this->assertIsArray(json_decode($payload, true)['data']);
        $this->assertStringNotContainsString('O:', json_decode($payload, true)['data']['c'] ?? '');
        $this->assertDoesNotMatchRegularExpression('/"data":"[^"]*[OaCs]:\d+:/', $payload);
    }

    public function test_a_tampered_nested_class_is_never_instantiated(): void
    {
        $envelope = Payload::decode(Payload::encode(new ValueJob(['x' => 1])));
        $envelope['data']['p']['value'] = ['@' => 'object', 'c' => DestructorGadget::class, 'p' => ['command' => 'id']];

        $this->assert_row_fails_without_running(json_encode($envelope), 'is not allowed');
        $this->assertNotContains('gadget destructed', Journal::names());
        $this->assertNotContains('gadget woke', Journal::names());
    }

    public function test_a_tampered_job_class_in_the_data_is_never_instantiated(): void
    {
        $envelope = Payload::decode(Payload::encode(new SendEmail(1)));
        $envelope['data']['c'] = DestructorGadget::class;

        $this->assert_row_fails_without_running(json_encode($envelope), 'does not restore');
        $this->assertNotContains('gadget destructed', Journal::names());
    }

    public function test_a_declared_value_object_round_trips(): void
    {
        $job = new ValueJob(new ValueObject(500, 'USD'));

        $restored = Payload::restore(Payload::decode(Payload::encode($job)));

        $this->assertInstanceOf(ValueObject::class, $restored->value);
        $this->assertSame(500, $restored->value->amount);
        $this->assertSame('USD', $restored->value->currency());
    }

    public function test_a_value_object_the_job_does_not_declare_cannot_be_dispatched(): void
    {
        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('SendEmail->tags[0]');

        Payload::encode(new SendEmail(1, 'Hi', [new ValueObject(1, 'USD')]));
    }

    public function test_a_closure_cannot_be_dispatched(): void
    {
        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('Closure');

        Payload::encode(new ValueJob(function () {
        }));
    }

    public function test_dates_keep_their_class_instant_zone_and_microseconds(): void
    {
        $mutable = new \DateTime('2024-03-05 10:11:12.345678', new \DateTimeZone('Asia/Dhaka'));
        $immutable = new \DateTimeImmutable('2024-03-05 10:11:12', new \DateTimeZone('+02:00'));

        $restored = Payload::restore(Payload::decode(Payload::encode(new ValueJob([$mutable, $immutable]))))->value;

        $this->assertInstanceOf(\DateTime::class, $restored[0]);
        $this->assertInstanceOf(\DateTimeImmutable::class, $restored[1]);
        $this->assertEquals($mutable, $restored[0]);
        $this->assertSame('Asia/Dhaka', $restored[0]->getTimezone()->getName());
        $this->assertSame('+02:00', $restored[1]->getTimezone()->getName());
        $this->assertSame('345678', $restored[0]->format('u'));
    }

    public function test_awkward_values_round_trip_unchanged(): void
    {
        $value = [
            'binary' => "\xff\xfe\x00",
            'float' => 1.5,
            'whole_float' => 2.0,
            'inf' => INF,
            '@' => 'collides',
            5 => 'int key',
            'list' => [1, [2, 3]],
            'empty' => [],
            'null' => null,
            'bool' => false,
        ];

        $restored = Payload::restore(Payload::decode(Payload::encode(new ValueJob($value))))->value;

        $this->assertSame($value, $restored);
    }

    public function test_nan_and_negative_infinity_round_trip(): void
    {
        $restored = Payload::restore(Payload::decode(Payload::encode(new ValueJob([NAN, -INF]))))->value;

        $this->assertNan($restored[0]);
        $this->assertSame(-INF, $restored[1]);
    }

    public function test_a_date_interval_round_trips(): void
    {
        $interval = new \DateInterval('P1Y2M3DT4H5M6S');
        $interval->invert = 1;

        $restored = Payload::restore(Payload::decode(Payload::encode(new ValueJob($interval))))->value;

        $this->assertSame($interval->format('%R %y %m %d %h %i %s'), $restored->format('%R %y %m %d %h %i %s'));
    }

    public function test_a_type_mismatch_on_a_typed_property_fails_the_job_cleanly(): void
    {
        $envelope = Payload::decode(Payload::encode(new ValueJob('x')));
        $envelope['data']['p']['count'] = [1, 2];

        $this->assert_row_fails_without_running(json_encode($envelope), 'Cannot assign');
    }

    public function test_a_stdclass_round_trips(): void
    {
        $restored = Payload::restore(Payload::decode(Payload::encode(new ValueJob((object) ['a' => 1]))))->value;

        $this->assertEquals((object) ['a' => 1], $restored);
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
