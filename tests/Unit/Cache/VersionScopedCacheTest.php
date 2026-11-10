<?php

namespace Framework\Tests\Unit\Cache;

use Framework\Cache\CacheManager;
use Framework\Cache\Events\CacheFlushed;
use Framework\Cache\MemoTable;
use Framework\Cache\Stores\ArrayStore;
use Framework\Container;
use Framework\Tests\Support\Cache\ConfigurableCacheManager;
use Framework\Tests\Support\Cache\FakeWpdb;
use Framework\Tests\Support\Cache\RecordingEventManager;

/**
 * Covers the opt-in version_scoped store setting: a store that holds values whose shape the
 * plugin's own code produces starts cold when the application version changes, so a release can
 * never read an entry written in the shape an earlier release produced.
 */
class VersionScopedCacheTest extends CacheTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->use_prefix('fw_');
    }

    protected function tearDown(): void
    {
        $this->reset_test_wpdb();

        parent::tearDown();
    }

    protected function database_store(array $overrides = []): array
    {
        return array_merge([
            'driver'         => 'database',
            'forever_ttl'    => 315360000,
            'network'        => false,
            'events'         => false,
            'version_scoped' => true,
        ], $overrides);
    }

    protected function request(array $stores): ConfigurableCacheManager
    {
        MemoTable::clear();

        return (new ConfigurableCacheManager())->set_stores($stores);
    }

    protected function options(): array
    {
        return $GLOBALS['framework_test_options'];
    }

    protected function site_options(): array
    {
        return $GLOBALS['framework_test_site_options'];
    }

    public function test_a_value_written_under_an_older_version_is_not_readable_after_an_update()
    {
        $stores = ['database' => $this->database_store()];

        $this->app->use_version('1.0.0');
        $this->request($stores)->store('database')->put('plugins', [['name' => 'Old shape']], 600);

        $this->app->use_version('1.0.1');
        $cache = $this->request($stores)->store('database');

        $this->assertNull($cache->get('plugins'));
        $this->assertSame('1.0.1', $this->options()['fw_cache_app_version_database']);
    }

    public function test_a_store_that_is_not_scoped_keeps_its_values_across_an_update()
    {
        $stores = ['database' => $this->database_store(['version_scoped' => false])];

        $this->app->use_version('1.0.0');
        $this->request($stores)->store('database')->put('plugins', ['kept'], 600);

        $this->app->use_version('1.0.1');
        $cache = $this->request($stores)->store('database');

        $this->assertSame(['kept'], $cache->get('plugins'));
        $this->assertArrayNotHasKey('fw_cache_app_version_database', $this->options());
    }

    public function test_the_built_in_persistent_stores_are_not_scoped_by_default()
    {
        $manager = new CacheManager();

        $this->assertFalse($manager->store_config('database')['version_scoped']);
        $this->assertFalse($manager->store_config('file')['version_scoped']);
        $this->assertArrayNotHasKey('version_scoped', $manager->store_config('array'));
    }

    public function test_a_missing_recorded_version_flushes_once_and_records_the_version()
    {
        $this->app->use_version('1.0.1');

        // Written by a framework release that predates version scoping, so no version is recorded.
        $this->request(['database' => $this->database_store(['version_scoped' => false])])
            ->store('database')
            ->put('plugins', ['old shape'], 600);

        $cache = $this->request(['database' => $this->database_store()])->store('database');

        $this->assertNull($cache->get('plugins'));
        $this->assertSame(2, $this->options()['fw_cache_version']);
        $this->assertSame('1.0.1', $this->options()['fw_cache_app_version_database']);
        $this->assertTrue($GLOBALS['framework_test_option_autoload']['fw_cache_app_version_database']);
    }

    public function test_an_unchanged_version_does_not_flush()
    {
        $stores = ['database' => $this->database_store()];

        $this->app->use_version('1.0.0');
        $this->request($stores)->store('database')->put('plugins', ['current'], 600);

        $version = $this->options()['fw_cache_version'];

        $cache = $this->request($stores)->store('database');

        $this->assertSame(['current'], $cache->get('plugins'));
        $this->assertSame($version, $this->options()['fw_cache_version']);
    }

    public function test_a_downgrade_also_flushes()
    {
        $stores = ['database' => $this->database_store()];

        $this->app->use_version('1.0.1');
        $this->request($stores)->store('database')->put('plugins', ['newer shape'], 600);

        $this->app->use_version('1.0.0');
        $cache = $this->request($stores)->store('database');

        $this->assertNull($cache->get('plugins'));
        $this->assertSame('1.0.0', $this->options()['fw_cache_app_version_database']);
    }

    public function test_a_network_store_records_its_version_as_a_site_option()
    {
        $stores = ['database' => $this->database_store(['network' => true])];

        $this->app->use_version('1.0.0');
        $this->request($stores)->store('database')->put('license', 'old shape', 600);

        $this->assertSame('1.0.0', $this->site_options()['fw_cache_app_version_database']);

        $this->app->use_version('1.0.1');
        $cache = $this->request($stores)->store('database');

        $this->assertNull($cache->get('license'));
        $this->assertSame('1.0.1', $this->site_options()['fw_cache_app_version_database']);
        $this->assertArrayNotHasKey('fw_cache_app_version_database', $this->options());
    }

    public function test_the_flushed_namespace_is_queued_for_and_reclaimed_by_gc()
    {
        $this->app->use_version('1.0.0');

        $manager = $this->request(['database' => $this->database_store()]);
        $manager->store('database');

        $this->assertSame([1], $this->options()['fw_cache_stale_versions']);

        global $wpdb;

        $wpdb = new FakeWpdb();
        $wpdb->option_rows = [
            '_transient_fw_c1_aaaa',
            '_transient_timeout_fw_c1_aaaa',
            '_transient_fw_c2_bbbb',
        ];

        $this->assertSame(2, $manager->collect_garbage('database'));
        $this->assertSame(['_transient_fw_c2_bbbb'], array_values($wpdb->option_rows));
    }

    public function test_a_failed_flush_leaves_the_recorded_version_unchanged()
    {
        $this->app->use_version('1.0.1');
        update_option('fw_cache_app_version_unflushable', '1.0.0');

        $manager = $this->request(['unflushable' => ['driver' => 'unflushable', 'events' => false, 'version_scoped' => true]]);
        $manager->extend('unflushable', function () {
            return new class extends ArrayStore {
                public function flush()
                {
                    return false;
                }
            };
        });

        $manager->store('unflushable');

        $this->assertSame('1.0.0', $this->options()['fw_cache_app_version_unflushable']);
    }

    public function test_resolving_the_store_from_a_flush_listener_does_not_flush_again()
    {
        $this->app->use_version('1.0.0');

        $manager = $this->request(['database' => $this->database_store(['events' => true])]);

        $events = new class extends RecordingEventManager {
            public $on_dispatch;

            public function dispatch($event): array
            {
                parent::dispatch($event);

                ($this->on_dispatch)();

                return [];
            }
        };
        $events->listen_for(CacheFlushed::class);
        $events->on_dispatch = function () use ($manager) {
            $manager->store('database');
        };

        $this->app->instance('event', $events);

        $manager->store('database');

        $this->assertSame(2, $this->options()['fw_cache_version']);
        $this->assertCount(1, $events->dispatched);
    }

    public function test_a_file_store_that_falls_back_is_checked_under_the_fallback_name()
    {
        // No direct filesystem in the test process, so the file store diverts to its fallback.
        $stores = [
            'file' => ['driver' => 'file', 'fallback' => 'database', 'events' => false, 'version_scoped' => true],
            'database' => $this->database_store(['version_scoped' => false]),
        ];

        $this->app->use_version('1.0.0');
        $this->request($stores)->store('file')->put('plugins', ['kept'], 600);

        $this->app->use_version('1.0.1');
        $cache = $this->request($stores)->store('file');

        $this->assertSame(['kept'], $cache->get('plugins'));
        $this->assertArrayNotHasKey('fw_cache_app_version_file', $this->options());
        $this->assertArrayNotHasKey('fw_cache_app_version_database', $this->options());
    }

    public function test_an_application_without_a_version_is_never_flushed()
    {
        $this->set_container_instance(new class extends Container {
            public function config_path($path = '')
            {
                return '/nonexistent/config/' . $path;
            }
        });

        $stores = ['database' => $this->database_store()];

        $this->request($stores)->store('database')->put('plugins', ['kept'], 600);

        $cache = $this->request($stores)->store('database');

        $this->assertSame(['kept'], $cache->get('plugins'));
        $this->assertArrayNotHasKey('cache_app_version_database', $this->options());
    }
}
