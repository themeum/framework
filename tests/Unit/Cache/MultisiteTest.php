<?php

namespace Framework\Tests\Unit\Cache;

use Framework\Cache\Repository;
use Framework\Cache\Stores\DatabaseStore;
use Framework\Cache\Stores\MemoizedStore;

class MultisiteTest extends CacheTestCase
{
    protected function switch_to_blog(int $id): void
    {
        $GLOBALS['framework_test_blog_id'] = $id;
    }

    protected function make_cache(bool $network = false): Repository
    {
        return new Repository(new DatabaseStore('fw_', 315360000, $network), 'database', false);
    }

    public function test_an_entry_written_on_one_site_is_not_readable_on_another()
    {
        $cache = $this->make_cache();

        $cache->put('settings', 'site one', 60);

        $this->switch_to_blog(2);

        $this->assertNull($cache->get('settings'));

        $this->switch_to_blog(1);

        $this->assertSame('site one', $cache->get('settings'));
    }

    public function test_each_site_keeps_its_own_value_for_the_same_key()
    {
        $cache = $this->make_cache();

        $cache->put('settings', 'site one', 60);

        $this->switch_to_blog(2);
        $cache->put('settings', 'site two', 60);

        $this->assertSame('site two', $cache->get('settings'));

        $this->switch_to_blog(1);

        $this->assertSame('site one', $cache->get('settings'));
    }

    public function test_a_memoized_read_does_not_leak_across_a_site_switch()
    {
        $store = new DatabaseStore('fw_', 315360000);

        $memo = new Repository(new MemoizedStore($store, 'database'), 'database', false);
        $plain = new Repository($store, 'database', false);

        $plain->put('settings', 'site one', 60);

        $this->assertSame('site one', $memo->get('settings'));

        $this->switch_to_blog(2);

        $this->assertNull(
            $memo->get('settings'),
            'A memoized value from another site must never be returned after switching sites.'
        );
    }

    public function test_a_network_store_is_shared_by_every_site()
    {
        $cache = $this->make_cache(true);

        $cache->put('license', 'active', 60);

        $this->switch_to_blog(2);

        $this->assertSame('active', $cache->get('license'));
    }

    public function test_a_network_store_writes_site_transients()
    {
        $cache = $this->make_cache(true);

        $cache->put('license', 'active', 60);

        $this->assertNotEmpty($GLOBALS['framework_test_site_transients']);
        $this->assertEmpty($GLOBALS['framework_test_transients']);
    }
}
