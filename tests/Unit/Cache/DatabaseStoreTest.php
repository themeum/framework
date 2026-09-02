<?php

namespace Framework\Tests\Unit\Cache;

use Framework\Cache\Entry;
use Framework\Tests\Support\Cache\FrozenDatabaseStore;
use Framework\Tests\Support\Cache\FrozenRepository;

class DatabaseStoreTest extends CacheTestCase
{
    protected FrozenDatabaseStore $store;

    protected FrozenRepository $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $now = time();

        $this->store = (new FrozenDatabaseStore('fw_', 315360000))->freeze($now);
        $this->cache = (new FrozenRepository($this->store, 'database', false))->freeze($now);
    }

    public function test_a_value_round_trips_through_a_transient()
    {
        $this->cache->put('settings', ['a' => 1], 60);

        $this->assertSame(['a' => 1], $this->cache->get('settings'));
    }

    public function test_a_key_far_beyond_the_transient_limit_is_stored_and_read_back()
    {
        $key = 'rest_response_' . str_repeat('a', 400);

        $this->cache->put($key, 'value', 60);

        $this->assertSame('value', $this->cache->get($key));
        $this->assertLessThanOrEqual(172, strlen($this->store->storage_key($key)));
    }

    public function test_two_long_keys_sharing_a_prefix_are_stored_independently()
    {
        $prefix = 'https://example.com/wp-json/v1/products?' . str_repeat('filter=x&', 30);

        $first = $prefix . 'page=3';
        $second = $prefix . 'page=4';

        $this->cache->put($first, 'page three', 60);
        $this->cache->put($second, 'page four', 60);

        $this->assertSame('page three', $this->cache->get($first));
        $this->assertSame('page four', $this->cache->get($second));
        $this->assertNotSame($this->store->storage_key($first), $this->store->storage_key($second));
    }

    public function test_a_key_containing_path_unsafe_characters_round_trips()
    {
        $key = '../../etc/passwd';

        $this->cache->put($key, 'value', 60);

        $this->assertSame('value', $this->cache->get($key));
    }

    public function test_an_entry_recovered_under_a_colliding_identifier_reads_as_a_miss()
    {
        $this->cache->put('wanted', 'right value', 60);

        $storage_key = $this->store->storage_key('wanted');

        set_transient($storage_key, Entry::make('a-different-key', 'wrong value', time(), time() + 60));

        $this->assertNull($this->cache->get('wanted'));
    }

    public function test_a_value_written_by_something_other_than_the_cache_reads_as_a_miss()
    {
        set_transient($this->store->storage_key('foreign'), 'raw value', 60);

        $this->assertNull($this->cache->get('foreign'));
    }

    public function test_forever_writes_a_transient_that_wordpress_will_not_autoload()
    {
        $this->cache->forever('catalog', ['big']);

        $stored = $GLOBALS['framework_test_transients'][$this->store->storage_key('catalog')];

        $this->assertGreaterThan(0, $stored['lifetime']);
        $this->assertSame(['big'], $this->cache->get('catalog'));
    }

    public function test_a_zero_forever_ttl_restores_literal_never_expiry()
    {
        $store = (new FrozenDatabaseStore('fw_', 0))->freeze(time());
        $cache = (new FrozenRepository($store, 'database', false))->freeze(time());

        $cache->forever('token', 'value');

        $stored = $GLOBALS['framework_test_transients'][$store->storage_key('token')];

        $this->assertSame(0, $stored['lifetime']);
    }

    public function test_forever_entries_do_not_expire()
    {
        $this->cache->forever('key', 'value');

        $this->store->travel(60 * 60 * 24 * 365 * 5);
        $this->cache->travel(60 * 60 * 24 * 365 * 5);

        $this->assertSame('value', $this->cache->get('key'));
    }

    public function test_flush_advances_the_namespace_version_and_hides_every_entry()
    {
        $this->cache->put('a', 1, 60);
        $this->cache->forever('b', 2);

        $before = $this->store->get_prefix();

        $this->assertTrue($this->cache->flush());

        $this->assertNotSame($before, $this->store->get_prefix());
        $this->assertFalse($this->cache->has('a'));
        $this->assertFalse($this->cache->has('b'));
        $this->assertSame(2, (int) get_option('fw_cache_version'));
    }

    public function test_flush_still_hides_entries_when_an_object_cache_is_active()
    {
        $this->cache->put('a', 1, 60);

        $GLOBALS['framework_test_ext_object_cache'] = true;

        $this->assertTrue($this->store->uses_external_object_cache());
        $this->assertTrue($this->cache->flush());
        $this->assertFalse($this->cache->has('a'));
    }

    public function test_entries_expire_on_their_lifetime()
    {
        $this->cache->put('key', 'value', 60);

        $this->store->travel(61);
        $this->cache->travel(61);

        $this->assertNull($this->cache->get('key'));
    }
}
