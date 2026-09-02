<?php

namespace Framework\Tests\Unit\Cache;

use Framework\Cache\CacheManager;
use Framework\Cache\Repository;
use Framework\Cache\Stores\MemoizedStore;
use Framework\Tests\Support\Cache\CountingArrayStore;

class MemoTest extends CacheTestCase
{
    protected CountingArrayStore $store;

    protected Repository $cache;

    protected Repository $memo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new CountingArrayStore();
        $this->cache = new Repository($this->store, 'array', false);
        $this->memo = new Repository(new MemoizedStore($this->store, 'array'), 'array', false);
    }

    public function test_repeated_reads_consult_the_store_once()
    {
        $this->cache->put('name', 'taylor', 60);

        $reads_before = $this->store->reads;

        $this->assertSame('taylor', $this->memo->get('name'));
        $this->assertSame('taylor', $this->memo->get('name'));
        $this->assertSame('taylor', $this->memo->get('name'));

        $this->assertSame(1, $this->store->reads - $reads_before);
    }

    public function test_a_miss_is_memoized_too()
    {
        $reads_before = $this->store->reads;

        $this->assertNull($this->memo->get('absent'));
        $this->assertNull($this->memo->get('absent'));

        $this->assertSame(1, $this->store->reads - $reads_before);
    }

    public function test_a_write_through_the_memoized_cache_is_visible_to_the_next_read()
    {
        $this->memo->put('name', 'taylor', 60);

        $this->assertSame('taylor', $this->memo->get('name'));

        $this->memo->put('name', 'tim', 60);

        $this->assertSame('tim', $this->memo->get('name'));
    }

    public function test_a_write_that_bypasses_the_memoized_cache_is_still_observed()
    {
        $this->cache->put('name', 'taylor', 60);

        $this->assertSame('taylor', $this->memo->get('name'));

        $this->cache->put('name', 'tim', 60);

        $this->assertSame(
            'tim',
            $this->memo->get('name'),
            'A memoized read must never return a value a later write in the same request replaced.'
        );
    }

    public function test_a_forget_that_bypasses_the_memoized_cache_is_still_observed()
    {
        $this->cache->put('name', 'taylor', 60);

        $this->assertSame('taylor', $this->memo->get('name'));

        $this->cache->forget('name');

        $this->assertNull($this->memo->get('name'));
    }

    public function test_the_manager_returns_the_same_memoized_repository_each_time()
    {
        $manager = new CacheManager();

        $this->assertSame($manager->memo('array'), $manager->memo('array'));
    }
}
