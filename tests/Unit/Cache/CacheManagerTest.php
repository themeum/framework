<?php

namespace Framework\Tests\Unit\Cache;

use Framework\Cache\CacheManager;
use Framework\Cache\Repository;
use Framework\Cache\Stores\ArrayStore;
use Framework\Cache\Stores\DatabaseStore;
use Framework\Tests\Support\Cache\ConfigurableCacheManager;
use InvalidArgumentException;

class CacheManagerTest extends CacheTestCase
{
    public function test_the_default_store_is_the_database_store()
    {
        $manager = new CacheManager();

        $this->assertSame('database', $manager->get_default_store());
        $this->assertInstanceOf(DatabaseStore::class, $manager->store()->get_store());
    }

    public function test_a_named_store_is_resolved_and_reused()
    {
        $manager = new CacheManager();

        $array = $manager->store('array');

        $this->assertInstanceOf(ArrayStore::class, $array->get_store());
        $this->assertSame('array', $array->get_name());
        $this->assertSame($array, $manager->store('array'));
    }

    public function test_driver_is_an_alias_of_store()
    {
        $manager = new CacheManager();

        $this->assertSame($manager->store('array'), $manager->driver('array'));
    }

    public function test_writing_to_one_store_does_not_write_to_another()
    {
        $manager = new CacheManager();

        $manager->store('array')->put('key', 'value', 60);

        $this->assertSame('value', $manager->store('array')->get('key'));
        $this->assertNull($manager->store('database')->get('key'));
    }

    public function test_unknown_calls_are_forwarded_to_the_default_store()
    {
        $manager = new ConfigurableCacheManager();
        $manager->set_stores(['database' => ['driver' => 'array', 'events' => false]]);

        $manager->put('key', 'value', 60);

        $this->assertSame('value', $manager->get('key'));
        $this->assertSame('value', $manager->store()->get('key'));
    }

    public function test_a_custom_driver_can_be_registered()
    {
        $manager = new ConfigurableCacheManager();
        $manager->set_stores(['custom' => ['driver' => 'mongo', 'events' => false]]);

        $manager->extend('mongo', function () {
            return new ArrayStore();
        });

        $repository = $manager->store('custom');

        $this->assertInstanceOf(Repository::class, $repository);
        $this->assertInstanceOf(ArrayStore::class, $repository->get_store());

        $repository->put('key', 'value', 60);

        $this->assertSame('value', $repository->get('key'));
        $this->assertSame('value', $repository->remember('key', 60, fn () => 'other'));
    }

    public function test_an_unsupported_driver_is_rejected_by_name()
    {
        $manager = new ConfigurableCacheManager();
        $manager->set_stores(['broken' => ['driver' => 'redis']]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('redis');

        $manager->store('broken');
    }

    public function test_an_unconfigured_store_is_rejected_by_name()
    {
        $manager = new CacheManager();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('nowhere');

        $manager->store('nowhere');
    }

    public function test_store_configuration_falls_back_to_the_built_in_defaults()
    {
        $manager = new CacheManager();

        $config = $manager->store_config('file');

        $this->assertSame('file', $config['driver']);
        $this->assertSame('database', $config['fallback']);
        $this->assertNull($config['path']);
    }

    public function test_file_and_database_stores_are_reported_as_sweepable()
    {
        $manager = new CacheManager();

        $this->assertSame(['database', 'file'], $manager->sweepable_stores());
    }

    public function test_an_array_store_is_never_reported_as_sweepable()
    {
        $manager = new CacheManager();

        $this->assertNotContains('array', $manager->sweepable_stores());
    }

    public function test_a_store_with_garbage_collection_disabled_is_not_swept()
    {
        $manager = new ConfigurableCacheManager();
        $manager->set_stores([
            'file' => ['driver' => 'file', 'gc' => false],
            'database' => ['driver' => 'database', 'gc' => false],
        ]);

        $this->assertSame([], $manager->sweepable_stores());
    }
}
