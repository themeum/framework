<?php

namespace Framework\Tests\Unit\Cache;

use DateInterval;
use Framework\Supports\Somoy;
use Framework\Tests\Support\Cache\FrozenArrayStore;
use Framework\Tests\Support\Cache\FrozenRepository;
use InvalidArgumentException;

class RepositoryTest extends CacheTestCase
{
    protected FrozenArrayStore $store;

    protected FrozenRepository $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $now = time();

        $this->store = (new FrozenArrayStore())->freeze($now);
        $this->cache = (new FrozenRepository($this->store, 'array', false))->freeze($now);
    }

    protected function travel(int $seconds): void
    {
        $this->store->travel($seconds);
        $this->cache->travel($seconds);
    }

    public function test_a_false_value_round_trips_and_is_not_a_miss()
    {
        $this->cache->put('flag', false, 60);

        $this->assertFalse($this->cache->get('flag'));
        $this->assertTrue($this->cache->has('flag'));
        $this->assertFalse($this->cache->missing('flag'));
    }

    public function test_null_zero_and_empty_string_round_trip()
    {
        $this->cache->put('nothing', null, 60);
        $this->cache->put('zero', 0, 60);
        $this->cache->put('blank', '', 60);

        $this->assertNull($this->cache->get('nothing'));
        $this->assertTrue($this->cache->has('nothing'));
        $this->assertSame(0, $this->cache->get('zero'));
        $this->assertSame('', $this->cache->get('blank'));
    }

    public function test_remember_does_not_reinvoke_its_callback_for_a_cached_null()
    {
        $calls = 0;

        $callback = function () use (&$calls) {
            $calls++;

            return null;
        };

        $this->assertNull($this->cache->remember('lookup', 60, $callback));
        $this->assertNull($this->cache->remember('lookup', 60, $callback));

        $this->assertSame(1, $calls);
    }

    public function test_a_missing_key_returns_the_default()
    {
        $this->assertNull($this->cache->get('absent'));
        $this->assertSame('fallback', $this->cache->get('absent', 'fallback'));
    }

    public function test_a_closure_default_is_evaluated_only_on_a_miss()
    {
        $calls = 0;

        $default = function () use (&$calls) {
            $calls++;

            return 'computed';
        };

        $this->assertSame('computed', $this->cache->get('absent', $default));
        $this->assertSame(1, $calls);

        $this->cache->put('present', 'stored', 60);

        $this->assertSame('stored', $this->cache->get('present', $default));
        $this->assertSame(1, $calls);
    }

    public function test_a_lifetime_in_seconds_expires()
    {
        $this->cache->put('key', 'value', 60);

        $this->travel(59);
        $this->assertSame('value', $this->cache->get('key'));

        $this->travel(2);
        $this->assertNull($this->cache->get('key'));
    }

    public function test_a_date_time_lifetime_is_accepted()
    {
        $this->cache->put('key', 'value', Somoy::now()->add_seconds(600));

        $this->travel(599);
        $this->assertSame('value', $this->cache->get('key'));

        $this->travel(2);
        $this->assertNull($this->cache->get('key'));
    }

    public function test_a_date_interval_lifetime_is_accepted()
    {
        $this->cache->put('key', 'value', new DateInterval('PT10M'));

        $this->travel(599);
        $this->assertSame('value', $this->cache->get('key'));

        $this->travel(2);
        $this->assertNull($this->cache->get('key'));
    }

    public function test_a_null_lifetime_never_expires()
    {
        $this->cache->put('key', 'value', null);

        $this->travel(60 * 60 * 24 * 365 * 20);

        $this->assertSame('value', $this->cache->get('key'));
    }

    public function test_a_zero_lifetime_removes_the_key_and_reports_failure()
    {
        $this->cache->put('key', 'value', 60);

        $this->assertFalse($this->cache->put('key', 'value', 0));
        $this->assertFalse($this->cache->has('key'));
    }

    public function test_a_negative_lifetime_removes_the_key_and_reports_failure()
    {
        $this->cache->put('key', 'value', 60);

        $this->assertFalse($this->cache->put('key', 'value', -5));
        $this->assertFalse($this->cache->has('key'));
    }

    public function test_a_past_date_removes_the_key_and_reports_failure()
    {
        $this->cache->put('key', 'value', 60);

        $this->assertFalse($this->cache->put('key', 'value', Somoy::now()->sub_seconds(10)));
        $this->assertFalse($this->cache->has('key'));
    }

    public function test_add_only_writes_when_the_key_is_absent()
    {
        $this->assertTrue($this->cache->add('key', 'first', 60));
        $this->assertFalse($this->cache->add('key', 'second', 60));
        $this->assertSame('first', $this->cache->get('key'));
    }

    public function test_pull_reads_and_removes()
    {
        $this->cache->put('key', 'value', 60);

        $this->assertSame('value', $this->cache->pull('key'));
        $this->assertFalse($this->cache->has('key'));
    }

    public function test_touch_extends_an_existing_entry_and_reports_a_missing_one()
    {
        $this->cache->put('key', 'value', 60);

        $this->travel(50);
        $this->assertTrue($this->cache->touch('key', 600));

        $this->travel(100);
        $this->assertSame('value', $this->cache->get('key'));

        $this->assertFalse($this->cache->touch('absent', 60));
    }

    public function test_many_and_put_many_round_trip_with_defaults_for_misses()
    {
        $this->cache->put_many(['a' => 1, 'b' => 2], 60);

        $this->assertSame(['a' => 1, 'b' => 2], $this->cache->many(['a', 'b']));
        $this->assertSame(['a' => 1, 'missing' => null], $this->cache->many(['a', 'missing']));
        $this->assertSame(['missing' => 'default'], $this->cache->many(['missing' => 'default']));
    }

    public function test_counters_adjust_and_reject_non_numeric_values()
    {
        $this->cache->put('hits', 5, 60);

        $this->assertSame(6, $this->cache->increment('hits'));
        $this->assertSame(9, $this->cache->increment('hits', 3));
        $this->assertSame(8, $this->cache->decrement('hits'));

        $this->cache->put('name', 'taylor', 60);
        $this->assertFalse($this->cache->increment('name'));
        $this->assertSame('taylor', $this->cache->get('name'));
    }

    public function test_typed_readers_return_their_type_or_throw()
    {
        $this->cache->put('text', 'hello', 60);
        $this->cache->put('number', 42, 60);
        $this->cache->put('flag', true, 60);
        $this->cache->put('list', ['a'], 60);

        $this->assertSame('hello', $this->cache->string('text'));
        $this->assertSame(42, $this->cache->integer('number'));
        $this->assertSame(42.0, $this->cache->float('number'));
        $this->assertTrue($this->cache->boolean('flag'));
        $this->assertSame(['a'], $this->cache->array('list'));

        $this->expectException(InvalidArgumentException::class);
        $this->cache->string('number');
    }

    public function test_array_access_reads_writes_and_removes()
    {
        $this->cache['key'] = 'value';

        $this->assertTrue(isset($this->cache['key']));
        $this->assertSame('value', $this->cache['key']);

        unset($this->cache['key']);

        $this->assertFalse(isset($this->cache['key']));
    }

    public function test_flush_removes_everything()
    {
        $this->cache->put('a', 1, 60);
        $this->cache->forever('b', 2);

        $this->assertTrue($this->cache->flush());
        $this->assertFalse($this->cache->has('a'));
        $this->assertFalse($this->cache->has('b'));
    }

    public function test_tagging_is_reported_as_unsupported()
    {
        $this->assertFalse($this->cache->supports_tags());
    }
}
