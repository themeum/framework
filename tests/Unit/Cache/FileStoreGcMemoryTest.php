<?php

namespace Framework\Tests\Unit\Cache;

use Framework\Cache\Entry;
use Framework\Cache\Stores\FileStore;
use Framework\Tests\Support\Cache\FrozenFileStore;
use Framework\Tests\Support\Cache\TestFilesystem;

/**
 * Covers the FileStore::gc() memory-spike fix. The previous implementation globbed an entire
 * bucket's file listing into memory before its per-run SWEEP_FILES cap ever applied, so a bucket
 * that had accumulated many entries could still spike memory regardless of the cap. Listing now
 * goes through Filesystem::scan_directory(), which reads entries one at a time and stops as soon
 * as the cap is reached, so the amount of memory used to discover files is itself bounded.
 */
class FileStoreGcMemoryTest extends CacheTestCase
{
    protected string $directory;

    protected TestFilesystem $files;

    protected FrozenFileStore $store;

    protected int $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/framework-cache-gc-' . uniqid();
        $this->files = new TestFilesystem();
        $this->now = time();
        $this->store = (new FrozenFileStore($this->files, 'fw_', $this->directory))->freeze($this->now);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            $this->files->delete($this->directory, true);
        }

        parent::tearDown();
    }

    /**
     * Write raw entry files directly into one bucket, bypassing the store's own key hashing, so
     * a single bucket can be made to hold far more files than a normal write pattern would.
     */
    protected function seed_bucket(string $bucket, int $count, bool $expired): void
    {
        $this->files->make_dir($bucket);

        $expires_at = $expired ? $this->now - 10 : $this->now + 6000;

        for ($i = 0; $i < $count; $i++) {
            $entry = Entry::make('key-' . $i, 'value', $this->now, $expires_at);
            $path = $bucket . '/' . sprintf('%05d', $i) . '.php';

            $this->files->put($path, FileStore::GUARD . serialize($entry));
        }
    }

    public function test_scan_directory_never_returns_more_than_the_requested_limit()
    {
        $bucket = $this->directory . '/aa/bb';
        $this->seed_bucket($bucket, 5000, false);

        $files = $this->files->scan_directory($bucket, 200, 'php');

        $this->assertCount(200, $files);
    }

    public function test_scan_directory_only_returns_the_requested_extension()
    {
        $bucket = $this->directory . '/aa/bb';
        $this->files->make_dir($bucket);
        $this->files->put($bucket . '/index.txt', 'not a cache entry');
        $this->files->put($bucket . '/entry.php', FileStore::GUARD . serialize(
            Entry::make('key', 'value', $this->now, $this->now + 60)
        ));

        $files = $this->files->scan_directory($bucket, 0, 'php');

        $this->assertCount(1, $files);
        $this->assertStringEndsWith('entry.php', $files[0]);
    }

    public function test_gc_stays_bounded_and_still_removes_expired_entries_in_an_oversized_bucket()
    {
        $bucket = $this->directory . '/aa/bb';
        $this->seed_bucket($bucket, FileStore::SWEEP_FILES + 500, true);

        $removed = $this->store->gc();

        $this->assertSame(FileStore::SWEEP_FILES, $removed);

        $remaining = $this->files->glob($bucket . '/*.php');
        $this->assertCount(500, $remaining);
    }

    public function test_gc_keeps_live_entries_in_an_oversized_bucket()
    {
        $bucket = $this->directory . '/aa/bb';
        $this->seed_bucket($bucket, FileStore::SWEEP_FILES + 100, false);

        $removed = $this->store->gc();

        $this->assertSame(0, $removed);

        $remaining = $this->files->glob($bucket . '/*.php');
        $this->assertCount(FileStore::SWEEP_FILES + 100, $remaining);
    }
}
