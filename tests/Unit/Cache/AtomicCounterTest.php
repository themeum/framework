<?php

namespace Framework\Tests\Unit\Cache;

use Framework\Tests\Support\Cache\FrozenDatabaseStore;

/**
 * Covers the atomic increment/decrement fix: when a persistent object cache is active, the
 * counter's authoritative value now lives in a dedicated, envelope-free object cache slot,
 * adjusted through wp_cache_incr()/wp_cache_decr(), instead of the non-atomic read-modify-write
 * over the envelope that increment() otherwise performs.
 */
class AtomicCounterTest extends CacheTestCase
{
    protected FrozenDatabaseStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['framework_test_ext_object_cache'] = true;

        $this->store = (new FrozenDatabaseStore('fw_', 315360000))->freeze(time());
    }

    public function test_increment_on_a_fresh_key_seeds_the_counter_and_the_envelope()
    {
        $this->assertSame(1, $this->store->increment('hits'));
        $this->assertSame(1, $this->store->get('hits'));
    }

    public function test_increment_and_decrement_match_the_non_atomic_path_arithmetic()
    {
        $this->store->put('hits', 5, 60);

        $this->assertSame(6, $this->store->increment('hits'));
        $this->assertSame(9, $this->store->increment('hits', 3));
        $this->assertSame(8, $this->store->decrement('hits'));
        $this->assertSame(8, $this->store->get('hits'));
    }

    public function test_increment_refuses_a_non_numeric_existing_value()
    {
        $this->store->put('name', 'taylor', 60);

        $this->assertFalse($this->store->increment('name'));
        $this->assertSame('taylor', $this->store->get('name'));
    }

    public function test_put_clears_the_counter_slot_so_a_later_increment_does_not_drift()
    {
        $this->store->increment('hits');
        $this->store->increment('hits');
        $this->store->increment('hits'); // counter slot now holds 3

        $this->store->put('hits', 100, 60);

        $this->assertSame(101, $this->store->increment('hits'));
    }

    public function test_forever_clears_the_counter_slot()
    {
        $this->store->increment('hits');
        $this->store->increment('hits'); // counter slot now holds 2

        $this->store->forever('hits', 50);

        $this->assertSame(51, $this->store->increment('hits'));
    }

    public function test_forget_clears_the_counter_slot_and_a_later_increment_starts_over()
    {
        $this->store->increment('hits');
        $this->store->increment('hits'); // counter slot now holds 2

        $this->store->forget('hits');

        $this->assertNull($this->store->get('hits'));
        $this->assertSame(1, $this->store->increment('hits'));
    }

    public function test_increment_preserves_the_original_expiry_window()
    {
        $this->store->put('hits', 1, 100);

        $this->store->increment('hits');

        $this->store->travel(99);
        $this->assertSame(2, $this->store->get('hits'));

        $this->store->travel(2);
        $this->assertNull($this->store->get('hits'));
    }

    public function test_increment_adds_its_own_delta_after_losing_the_seed_race()
    {
        // Simulate a concurrent request winning the wp_cache_add() seed for this key's counter
        // slot before this call's wp_cache_incr() and wp_cache_add() attempts run.
        $name = $this->store->storage_key('hits') . '_n';
        $group = 'fw_cache_counters';

        wp_cache_add($name, 10, $group, 0);

        $this->assertSame(11, $this->store->increment('hits'));
        $this->assertSame(11, $this->store->get('hits'));
    }

    public function test_increment_without_an_object_cache_is_unaffected()
    {
        $GLOBALS['framework_test_ext_object_cache'] = false;

        $this->store->put('hits', 5, 60);

        $this->assertSame(6, $this->store->increment('hits'));
        $this->assertSame([], $GLOBALS['framework_test_object_cache']);
    }
}
