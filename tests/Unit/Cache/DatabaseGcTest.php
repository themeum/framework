<?php

namespace Framework\Tests\Unit\Cache;

use Framework\Cache\Stores\DatabaseStore;
use Framework\Tests\Support\Cache\FakeWpdb;
use Framework\Tests\Support\Cache\FrozenDatabaseStore;
use Framework\Tests\Support\Cache\FrozenRepository;

/**
 * Covers the network flush cleanup fix: flush() no longer sweeps synchronously and never runs
 * for network stores at all, orphaning every entry a flush left behind forever. It now queues
 * the superseded namespace version and gc() drains the backlog in bounded slices, targeting the
 * options table or the network's site meta table depending on scope.
 */
class DatabaseGcTest extends CacheTestCase
{
    protected function tearDown(): void
    {
        $this->reset_test_wpdb();

        parent::tearDown();
    }

    protected function fake_wpdb(): FakeWpdb
    {
        global $wpdb;

        $wpdb = new FakeWpdb();

        return $wpdb;
    }

    public function test_flush_does_not_sweep_synchronously()
    {
        $store = (new FrozenDatabaseStore('fw_', 315360000))->freeze(time());
        $cache = (new FrozenRepository($store, 'database', false))->freeze(time());

        $cache->put('a', 1, 60);

        // No $wpdb is configured at all; a synchronous sweep attempt would throw or no-op badly.
        $this->assertTrue($cache->flush());
        $this->assertSame([1], $this->stale_versions_option_value('fw_'));
    }

    public function test_gc_reclaims_a_site_scoped_flush()
    {
        $wpdb = $this->fake_wpdb();
        $wpdb->option_rows = [
            '_transient_fw_c1_aaaa',
            '_transient_timeout_fw_c1_aaaa',
            '_transient_fw_c2_bbbb', // belongs to the current namespace, must survive
        ];

        $store = new DatabaseStore('fw_', 315360000, false);

        update_option('fw_cache_version', 2);
        update_option('fw_cache_stale_versions', [1]);

        $removed = $store->gc();

        $this->assertSame(2, $removed);
        $this->assertSame(['_transient_fw_c2_bbbb'], array_values($wpdb->option_rows));
        $this->assertSame([], get_option('fw_cache_stale_versions'));
    }

    public function test_gc_reclaims_a_network_flush_when_multisite_is_not_active()
    {
        $wpdb = $this->fake_wpdb();
        $wpdb->option_rows = [
            '_site_transient_fw_c1_aaaa',
            '_site_transient_timeout_fw_c1_aaaa',
        ];

        $store = new DatabaseStore('fw_', 315360000, true);

        update_site_option('fw_cache_version', 2);
        update_site_option('fw_cache_stale_versions', [1]);

        $removed = $store->gc();

        $this->assertSame(2, $removed);
        $this->assertSame([], $wpdb->option_rows);
    }

    public function test_gc_reclaims_a_network_flush_on_real_multisite_from_sitemeta()
    {
        $GLOBALS['framework_test_multisite'] = true;
        $GLOBALS['framework_test_network_id'] = 1;

        $wpdb = $this->fake_wpdb();
        $wpdb->sitemeta_rows = [
            ['site_id' => 1, 'meta_key' => '_site_transient_fw_c1_aaaa'],
            ['site_id' => 1, 'meta_key' => '_site_transient_timeout_fw_c1_aaaa'],
            ['site_id' => 2, 'meta_key' => '_site_transient_fw_c1_aaaa'], // a different network, must survive
        ];

        $store = new DatabaseStore('fw_', 315360000, true);

        update_site_option('fw_cache_version', 2);
        update_site_option('fw_cache_stale_versions', [1]);

        $removed = $store->gc();

        $this->assertSame(2, $removed);
        $this->assertCount(1, $wpdb->sitemeta_rows);
        $this->assertSame(2, array_values($wpdb->sitemeta_rows)[0]['site_id']);

        // Confirm nothing was written to wp_options for the multisite case.
        $this->assertArrayNotHasKey('fw_cache_stale_versions', $GLOBALS['framework_test_options']);
    }

    public function test_gc_requeues_a_version_that_still_has_rows_left()
    {
        $wpdb = $this->fake_wpdb();

        $wpdb->option_rows = array_map(
            static fn ($i) => '_transient_fw_c1_' . $i,
            range(1, DatabaseStore::GC_ROWS_PER_VERSION + 50)
        );

        $store = new DatabaseStore('fw_', 315360000, false);

        update_option('fw_cache_version', 2);
        update_option('fw_cache_stale_versions', [1]);

        $first_run = $store->gc();

        $this->assertSame(DatabaseStore::GC_ROWS_PER_VERSION, $first_run);
        $this->assertSame([1], get_option('fw_cache_stale_versions'));

        $second_run = $store->gc();

        $this->assertSame(50, $second_run);
        $this->assertSame([], get_option('fw_cache_stale_versions'));
    }

    public function test_gc_only_sweeps_a_bounded_number_of_versions_per_run()
    {
        $wpdb = $this->fake_wpdb();
        $wpdb->option_rows = [
            '_transient_fw_c1_a',
            '_transient_fw_c2_a',
            '_transient_fw_c3_a',
            '_transient_fw_c4_a',
            '_transient_fw_c5_a',
        ];

        $store = new DatabaseStore('fw_', 315360000, false);

        update_option('fw_cache_version', 6);
        update_option('fw_cache_stale_versions', [1, 2, 3, 4, 5]);

        $removed = $store->gc();

        $this->assertSame(DatabaseStore::GC_VERSIONS_PER_RUN, $removed);
        $this->assertSame([4, 5], get_option('fw_cache_stale_versions'));
    }

    public function test_gc_does_nothing_while_an_external_object_cache_is_active()
    {
        $GLOBALS['framework_test_ext_object_cache'] = true;

        $store = new DatabaseStore('fw_', 315360000, false);

        update_option('fw_cache_stale_versions', [1]);

        $this->assertSame(0, $store->gc());
        $this->assertSame([1], get_option('fw_cache_stale_versions'));
    }

    protected function stale_versions_option_value(string $prefix): array
    {
        return get_option($prefix . 'cache_stale_versions', []);
    }
}
