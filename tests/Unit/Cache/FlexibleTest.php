<?php

namespace Framework\Tests\Unit\Cache;

use Framework\Tests\Support\Cache\FrozenArrayStore;
use Framework\Tests\Support\Cache\FrozenRepository;
use InvalidArgumentException;

class FlexibleTest extends CacheTestCase
{
    protected FrozenArrayStore $store;

    protected FrozenRepository $cache;

    protected int $calls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $now = time();

        $this->store = (new FrozenArrayStore())->freeze($now);
        $this->cache = (new FrozenRepository($this->store, 'array', false))->freeze($now);

        $this->calls = 0;
        $GLOBALS['framework_test_actions'] = [];
    }

    protected function travel(int $seconds): void
    {
        $this->store->travel($seconds);
        $this->cache->travel($seconds);
    }

    protected function counting_callback(): callable
    {
        return function () {
            $this->calls++;

            return 'value ' . $this->calls;
        };
    }

    public function test_the_first_read_computes_and_stores_the_value()
    {
        $this->assertSame('value 1', $this->cache->flexible('key', [5, 10], $this->counting_callback()));
        $this->assertSame(1, $this->calls);
    }

    public function test_a_read_inside_the_fresh_window_does_not_recompute()
    {
        $this->cache->flexible('key', [5, 10], $this->counting_callback());

        $this->travel(3);

        $this->assertSame('value 1', $this->cache->flexible('key', [5, 10], $this->counting_callback()));
        $this->assertSame(1, $this->calls);
    }

    public function test_a_read_inside_the_stale_window_serves_the_stale_value_and_defers_the_refresh()
    {
        $this->cache->flexible('key', [5, 10], $this->counting_callback());

        $this->travel(7);

        $this->assertSame('value 1', $this->cache->flexible('key', [5, 10], $this->counting_callback()));
        $this->assertSame(1, $this->calls, 'The refresh must not run before the response is sent.');

        $this->assertSame(1, framework_test_do_action('shutdown'));
        $this->assertSame(2, $this->calls);
        $this->assertSame('value 2', $this->cache->get('key'));
    }

    public function test_a_read_past_the_stale_window_recomputes_before_returning()
    {
        $this->cache->flexible('key', [5, 10], $this->counting_callback());

        $this->travel(11);

        $this->assertSame('value 2', $this->cache->flexible('key', [5, 10], $this->counting_callback()));
        $this->assertSame(2, $this->calls);
    }

    public function test_concurrent_stale_reads_register_only_one_refresh()
    {
        $this->cache->flexible('key', [5, 10], $this->counting_callback());

        $this->travel(7);

        $this->cache->flexible('key', [5, 10], $this->counting_callback());
        $this->cache->flexible('key', [5, 10], $this->counting_callback());

        $this->assertSame(1, framework_test_do_action('shutdown'));
    }

    public function test_a_lifetime_that_is_not_a_pair_is_rejected()
    {
        $this->expectException(InvalidArgumentException::class);

        $this->cache->flexible('key', [5], $this->counting_callback());
    }
}
