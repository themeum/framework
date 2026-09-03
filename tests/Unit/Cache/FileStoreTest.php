<?php

namespace Framework\Tests\Unit\Cache;

use Framework\Cache\Repository;
use Framework\Cache\Stores\ArrayStore;
use Framework\Cache\Stores\FileStore;
use Framework\Tests\Support\Cache\ConfigurableCacheManager;
use Framework\Tests\Support\Cache\FrozenFileStore;
use Framework\Tests\Support\Cache\FrozenRepository;
use Framework\Tests\Support\Cache\TestFilesystem;

class FileStoreTest extends CacheTestCase
{
    protected string $directory;

    protected TestFilesystem $files;

    protected FrozenFileStore $store;

    protected FrozenRepository $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/framework-cache-' . uniqid();
        $this->files = new TestFilesystem();

        $now = time();

        $this->store = (new FrozenFileStore($this->files, 'fw_', $this->directory))->freeze($now);
        $this->cache = (new FrozenRepository($this->store, 'file', false))->freeze($now);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            $this->files->delete($this->directory, true);
        }

        parent::tearDown();
    }

    protected function travel(int $seconds): void
    {
        $this->store->travel($seconds);
        $this->cache->travel($seconds);
    }

    public function test_a_value_round_trips_through_a_file()
    {
        $this->cache->put('settings', ['a' => 1], 60);

        $this->assertSame(['a' => 1], $this->cache->get('settings'));
        $this->assertFileExists($this->store->path('settings'));
    }

    public function test_falsy_values_round_trip()
    {
        $this->cache->put('flag', false, 60);

        $this->assertFalse($this->cache->get('flag'));
        $this->assertTrue($this->cache->has('flag'));
    }

    public function test_the_directory_is_protected_when_it_is_created()
    {
        $this->cache->put('key', 'value', 60);

        $this->assertFileExists($this->directory . '/index.php');
        $this->assertFileExists($this->directory . '/.htaccess');
        $this->assertStringContainsString('denied', file_get_contents($this->directory . '/.htaccess'));
    }

    public function test_an_entry_file_discloses_nothing_when_executed()
    {
        $this->cache->put('secret', 'sensitive value', 60);

        $contents = file_get_contents($this->store->path('secret'));

        $this->assertStringStartsWith(FileStore::GUARD, $contents);
        $this->assertSame('<?php exit; ?>', substr($contents, 0, 14));
        $this->assertStringEndsWith('.php', $this->store->path('secret'));
    }

    public function test_entries_are_spread_across_a_two_level_fan_out()
    {
        $path = $this->store->path('key');
        $hash = $this->store->storage_key('key');

        $this->assertSame(
            $this->directory . '/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash . '.php',
            $path
        );
    }

    public function test_a_key_containing_path_traversal_stays_inside_the_cache_directory()
    {
        $this->cache->put('../../etc/passwd', 'value', 60);

        $this->assertSame('value', $this->cache->get('../../etc/passwd'));
        $this->assertStringStartsWith($this->directory, $this->store->path('../../etc/passwd'));
    }

    public function test_a_write_leaves_no_temporary_file_behind()
    {
        $this->cache->put('key', 'value', 60);

        $this->assertSame([], glob($this->directory . '/*/*/*.tmp*') ?: []);
    }

    public function test_an_expired_entry_is_removed_when_it_is_read()
    {
        $this->cache->put('key', 'value', 60);

        $path = $this->store->path('key');
        $this->assertFileExists($path);

        $this->travel(61);

        $this->assertNull($this->cache->get('key'));
        $this->assertFileDoesNotExist($path);
    }

    public function test_forget_removes_the_entry_file()
    {
        $this->cache->put('key', 'value', 60);

        $this->cache->forget('key');

        $this->assertFileDoesNotExist($this->store->path('key'));
        $this->assertNull($this->cache->get('key'));
    }

    public function test_flush_clears_the_tree_and_restores_the_protection_files()
    {
        $this->cache->put('a', 1, 60);
        $this->cache->put('b', 2, 60);

        $this->assertTrue($this->cache->flush());

        $this->assertNull($this->cache->get('a'));
        $this->assertNull($this->cache->get('b'));
        $this->assertFileExists($this->directory . '/index.php');
        $this->assertFileExists($this->directory . '/.htaccess');
    }

    public function test_the_sweep_removes_expired_entries_and_keeps_live_ones()
    {
        $this->cache->put('short', 'gone', 60);
        $this->cache->put('long', 'kept', 6000);

        $this->travel(61);

        $removed = $this->store->gc();

        $this->assertSame(1, $removed);
        $this->assertFileDoesNotExist($this->store->path('short'));
        $this->assertSame('kept', $this->cache->get('long'));
    }

    public function test_the_sweep_advances_its_cursor()
    {
        $this->cache->put('key', 'value', 60);

        $this->store->gc();

        $this->assertArrayHasKey('fw_cache_gc_cursor', $GLOBALS['framework_test_options']);
    }

    public function test_forever_entries_are_never_swept()
    {
        $this->cache->forever('permanent', 'value');

        $this->travel(60 * 60 * 24 * 365);

        $this->store->gc();

        $this->assertSame('value', $this->cache->get('permanent'));
    }

    public function test_the_store_diverts_to_its_fallback_when_direct_access_is_unavailable()
    {
        $manager = new ConfigurableCacheManager();
        $manager->set_stores([
            'file' => ['driver' => 'file', 'fallback' => 'array', 'events' => false],
            'array' => ['driver' => 'array', 'events' => false],
        ]);

        $repository = $manager->store('file');

        $this->assertInstanceOf(Repository::class, $repository);
        $this->assertInstanceOf(ArrayStore::class, $repository->get_store());

        $repository->put('key', 'value', 60);

        $this->assertSame('value', $repository->get('key'));
    }
}
