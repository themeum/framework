<?php

namespace Framework\Tests\Unit\RateLimiting;

use Framework\RateLimiting\Limit;
use Framework\RateLimiting\Unlimited;
use Framework\Tests\Support\Cache\FrozenArrayStore;
use Framework\Tests\Support\Cache\FrozenRepository;
use Framework\Tests\Support\RateLimiting\FrozenRateLimiter;

class RateLimiterTest extends RateLimiterTestCase
{
    protected FrozenArrayStore $store;

    protected FrozenRepository $cache;

    protected FrozenRateLimiter $limiter;

    protected function setUp(): void
    {
        parent::setUp();

        $now = time();

        $this->store = (new FrozenArrayStore())->freeze($now);
        $this->cache = (new FrozenRepository($this->store, 'array', false))->freeze($now);
        $this->limiter = (new FrozenRateLimiter())->set_cache($this->cache);
        $this->limiter->freeze($now);
    }

    protected function travel(int $seconds): void
    {
        $this->store->travel($seconds);
        $this->cache->travel($seconds);
        $this->limiter->travel($seconds);
    }

    public function test_attempts_accumulate_within_the_window()
    {
        $this->limiter->increment('key', 60);
        $this->limiter->increment('key', 60);
        $this->limiter->increment('key', 60);

        $this->assertSame(3, $this->limiter->attempts('key'));
    }

    public function test_increment_returns_the_resulting_count()
    {
        $this->assertSame(1, $this->limiter->increment('key', 60));
        $this->assertSame(2, $this->limiter->increment('key', 60));
        $this->assertSame(3, $this->limiter->increment('key', 60));
    }

    public function test_increment_honours_an_explicit_amount()
    {
        $this->limiter->increment('key', 60);

        $this->assertSame(6, $this->limiter->increment('key', 60, 5));
    }

    public function test_distinct_keys_do_not_interfere()
    {
        $this->limiter->increment('one', 60);
        $this->limiter->increment('two', 60);
        $this->limiter->increment('two', 60);

        $this->assertSame(1, $this->limiter->attempts('one'));
        $this->assertSame(2, $this->limiter->attempts('two'));
    }

    public function test_the_window_resets_after_the_decay_elapses()
    {
        $this->limiter->increment('key', 60);
        $this->limiter->increment('key', 60);

        $this->assertTrue($this->limiter->too_many_attempts('key', 2));

        $this->travel(61);

        $this->assertFalse($this->limiter->too_many_attempts('key', 2));
        $this->assertSame(0, $this->limiter->attempts('key'));
    }

    public function test_remaining_reports_the_unused_allowance()
    {
        $this->limiter->increment('key', 60);
        $this->limiter->increment('key', 60);

        $this->assertSame(3, $this->limiter->remaining('key', 5));
        $this->assertSame(3, $this->limiter->retries_left('key', 5));
    }

    public function test_remaining_never_reports_a_negative_allowance()
    {
        $this->limiter->increment('key', 60, 10);

        $this->assertSame(0, $this->limiter->remaining('key', 5));
    }

    public function test_available_in_reports_the_seconds_left_in_the_window()
    {
        $this->limiter->increment('key', 60);

        $this->travel(20);

        $this->assertSame(40, $this->limiter->available_in('key'));
    }

    public function test_available_in_is_zero_for_an_unknown_key()
    {
        $this->assertSame(0, $this->limiter->available_in('never-seen'));
    }

    public function test_clear_restores_the_full_allowance()
    {
        $this->limiter->increment('key', 60);
        $this->limiter->increment('key', 60);

        $this->limiter->clear('key');

        $this->assertSame(0, $this->limiter->attempts('key'));
        $this->assertFalse($this->limiter->too_many_attempts('key', 2));
    }

    public function test_attempt_runs_the_callback_while_attempts_remain()
    {
        $ran = false;

        $result = $this->limiter->attempt('key', 2, function () use (&$ran) {
            $ran = true;

            return 'done';
        });

        $this->assertTrue($ran);
        $this->assertSame('done', $result);
    }

    public function test_attempt_returns_true_when_the_callback_returns_nothing()
    {
        $this->assertTrue($this->limiter->attempt('key', 2, function () {
            return null;
        }));
    }

    public function test_attempt_stops_running_the_callback_once_exhausted()
    {
        $this->limiter->attempt('key', 2, function () {
        });
        $this->limiter->attempt('key', 2, function () {
        });

        $ran = false;

        $result = $this->limiter->attempt('key', 2, function () use (&$ran) {
            $ran = true;
        });

        $this->assertFalse($ran);
        $this->assertFalse($result);
    }

    public function test_named_limiters_are_registered_and_resolved()
    {
        $this->limiter->for('uploads', function () {
            return Limit::per_minute(10);
        });

        $this->assertTrue($this->limiter->has_limiter('uploads'));
        $this->assertNotNull($this->limiter->limiter('uploads'));
        $this->assertNull($this->limiter->limiter('missing'));
    }

    public function test_limit_builders_produce_the_expected_windows()
    {
        $this->assertSame(1, Limit::per_second(5)->decay_seconds);
        $this->assertSame(60, Limit::per_minute(5)->decay_seconds);
        $this->assertSame(180, Limit::per_minutes(3, 5)->decay_seconds);
        $this->assertSame(3600, Limit::per_hour(5)->decay_seconds);
        $this->assertSame(86400, Limit::per_day(5)->decay_seconds);
        $this->assertSame(5, Limit::per_minute(5)->max_attempts);
    }

    public function test_none_produces_an_unlimited_limit()
    {
        $this->assertInstanceOf(Unlimited::class, Limit::none());
    }

    public function test_by_segments_a_limit()
    {
        $limit = Limit::per_minute(5)->by('user:7');

        $this->assertSame('user:7', $limit->key);
    }
}
