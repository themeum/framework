<?php

namespace Framework\Tests\Unit\Queue;

use Framework\Queue\Sweeper;
use Framework\Tests\Support\Queue\Jobs\Journal;
use Framework\Tests\Support\Queue\Jobs\SendEmail;
use Framework\Tests\Support\Queue\TestLock;

class SpawnerTest extends QueueTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->enable_queue();
    }

    public function test_a_correctly_signed_request_runs_a_worker(): void
    {
        SendEmail::dispatch(1);

        $this->assertTrue($this->spawner->handle_request($this->signed(self::NOW)));
        $this->assertSame([1], Journal::values('handled'));
    }

    public function test_an_unsigned_request_is_rejected_before_claiming(): void
    {
        SendEmail::dispatch(1);

        $this->assertFalse($this->spawner->handle_request(['action' => 'queue_work']));
        $this->assert_nothing_claimed();
    }

    public function test_a_tampered_signature_is_rejected(): void
    {
        SendEmail::dispatch(1);

        $request = $this->signed(self::NOW);
        $request['sig'] = str_repeat('0', 64);

        $this->assertFalse($this->spawner->handle_request($request));
        $this->assert_nothing_claimed();
    }

    public function test_a_signature_for_another_timestamp_is_rejected(): void
    {
        SendEmail::dispatch(1);

        $request = $this->signed(self::NOW);
        $request['ts'] = self::NOW + 1;

        $this->assertFalse($this->spawner->handle_request($request));
        $this->assert_nothing_claimed();
    }

    public function test_an_expired_signature_is_rejected(): void
    {
        SendEmail::dispatch(1);

        $this->assertFalse($this->spawner->handle_request($this->signed(self::NOW - 61)));
        $this->assertTrue($this->spawner->handle_request($this->signed(self::NOW - 60)));
    }

    public function test_the_sweeper_does_nothing_when_nothing_is_due(): void
    {
        SendEmail::dispatch(1)->delay(3600);

        $this->assertFalse($this->sweeper()->sweep());
        $this->assertSame(0, $this->spawner->spawned);
    }

    public function test_the_sweeper_spawns_once_a_delayed_job_becomes_due(): void
    {
        SendEmail::dispatch(1)->delay(3600);
        $this->travel(3600);

        $this->assertTrue($this->sweeper()->sweep());
        $this->assertSame(1, $this->spawner->spawned);
        $this->assertSame([], TestLock::$held);
    }

    public function test_the_sweeper_skips_a_missing_table(): void
    {
        $this->queue->exists = false;

        $this->assertFalse($this->sweeper()->sweep());
        $this->assertSame(0, $this->spawner->spawned);
    }

    public function test_no_worker_is_spawned_or_run_while_a_chain_holds_the_lock(): void
    {
        SendEmail::dispatch(1);
        $this->spawner->chain_lock()->acquire();

        $this->assertFalse($this->sweeper()->sweep());
        $this->assertFalse($this->spawner->handle_request($this->signed(self::NOW)));
        $this->assertSame(0, $this->spawner->spawned);
        $this->assert_nothing_claimed();
    }

    public function test_a_worker_chains_a_successor_when_work_remains(): void
    {
        $this->queue->with_options(['time_limit' => 2]);
        $this->worker->seconds_per_job = 1.0;

        for ($i = 1; $i <= 5; $i++) {
            SendEmail::dispatch($i);
        }

        $this->spawner->handle_request($this->signed(self::NOW));

        $this->assertSame([1, 2], Journal::values('handled'));
        $this->assertSame(1, $this->spawner->spawned);
        $this->assertSame([], TestLock::$held);
    }

    public function test_the_last_worker_releases_the_lock_without_spawning(): void
    {
        SendEmail::dispatch(1);

        $this->spawner->handle_request($this->signed(self::NOW));

        $this->assertSame(0, $this->spawner->spawned);
        $this->assertSame([], TestLock::$held);
    }

    public function test_undelayed_dispatches_spawn_once_on_shutdown(): void
    {
        SendEmail::dispatch(1);
        SendEmail::dispatch(2);
        SendEmail::dispatch(3);

        $this->assertCount(1, $GLOBALS['framework_test_actions']['shutdown']);
        $this->assertSame(0, $this->spawner->spawned);

        framework_test_do_action('shutdown');

        $this->assertSame(1, $this->spawner->spawned);
    }

    public function test_delayed_dispatches_do_not_spawn_on_shutdown(): void
    {
        SendEmail::dispatch(1)->delay(60);

        $this->assertArrayNotHasKey('shutdown', $GLOBALS['framework_test_actions']);
    }

    protected function signed(int $timestamp): array
    {
        return [
            'action' => 'queue_work',
            'ts' => (string) $timestamp,
            'sig' => $this->spawner->sign($timestamp),
        ];
    }

    protected function sweeper(): Sweeper
    {
        return new Sweeper($this->queue, $this->spawner);
    }

    protected function assert_nothing_claimed(): void
    {
        $this->assertSame([], $this->queue->claims);
        $this->assertSame([], Journal::names());
    }
}
