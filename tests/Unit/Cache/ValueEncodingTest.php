<?php

namespace Framework\Tests\Unit\Cache;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Framework\Cache\Entry;
use Framework\Cache\Repository;
use Framework\Cache\Stores\ArrayStore;
use Framework\Cache\Stores\DatabaseStore;
use Framework\Cache\Stores\FileStore;
use Framework\Database\Query\Collection;
use Framework\Exceptions\ValueCodecException;
use Framework\Supports\Somoy;
use Framework\Supports\ValueCodec;
use Framework\Tests\Support\Cache\CacheGadget;
use Framework\Tests\Support\Cache\Money;
use Framework\Tests\Support\Cache\TestFilesystem;
use Framework\Tests\Support\Models\QueuedArticle;
use Framework\Tests\Support\Models\QueuedComment;
use stdClass;

class ValueEncodingTest extends CacheTestCase
{
    protected string $directory;

    protected TestFilesystem $files;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/framework-cache-' . uniqid();
        $this->files = new TestFilesystem();

        CacheGadget::$calls = [];
        Money::$constructed = 0;
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            $this->files->delete($this->directory, true);
        }

        parent::tearDown();
    }

    public function drivers(): array
    {
        return [
            'file' => ['file'],
            'database' => ['database'],
        ];
    }

    protected function store(string $driver, array $allowed_classes = [])
    {
        $codec = new ValueCodec($allowed_classes);

        if ($driver === 'file') {
            return new FileStore($this->files, 'fw_', $this->directory, false, $codec);
        }

        return new DatabaseStore('fw_', 315360000, false, $codec);
    }

    protected function cache($store): Repository
    {
        return new Repository($store, 'test', false);
    }

    protected function write_raw($store, string $key, string $contents): void
    {
        if ($store instanceof FileStore) {
            $this->files->make_dir(dirname($store->path($key)));
            $this->files->put($store->path($key), FileStore::GUARD . $contents);

            return;
        }

        set_transient($store->storage_key($key), $contents, 60);
    }

    protected function raw($store, string $key)
    {
        if ($store instanceof FileStore) {
            return file_get_contents($store->path($key));
        }

        return $GLOBALS['framework_test_transients'][$store->storage_key($key)]['value'] ?? null;
    }

    protected function gadget_envelope(string $key): string
    {
        return json_encode([
            Entry::MARKER => Entry::FORMAT_VERSION,
            'key' => $key,
            'value' => ['@' => 'object', 'c' => CacheGadget::class, 'p' => ['command' => 'id']],
            'created_at' => time(),
            'expires_at' => null,
            'fresh_until' => null,
        ]);
    }

    /** @dataProvider drivers */
    public function test_scalars_binary_strings_and_keyed_arrays_round_trip(string $driver)
    {
        $cache = $this->cache($this->store($driver));
        $value = [
            'int' => 42,
            'float' => 1.0,
            'inf' => INF,
            'negative_inf' => -INF,
            'bool' => true,
            'null' => null,
            'utf8' => 'héllo',
            'binary' => "\xff\xfe\x00",
            'list' => [1, 'two', [3]],
            10 => 'numeric key',
            '@' => 'reserved looking key',
        ];

        $cache->put('settings', $value, 60);

        $this->assertSame($value, $cache->get('settings'));
    }

    /** @dataProvider drivers */
    public function test_a_stored_false_is_still_present(string $driver)
    {
        $cache = $this->cache($this->store($driver));

        $cache->put('flag', false, 60);

        $this->assertFalse($cache->get('flag', 'default'));
        $this->assertTrue($cache->has('flag'));
    }

    /** @dataProvider drivers */
    public function test_a_model_with_a_loaded_relation_round_trips(string $driver)
    {
        $cache = $this->cache($this->store($driver));

        $comment = new QueuedComment();
        $comment->set_raw_attributes(['id' => 7, 'article_id' => 1, 'body' => 'First'], true);

        $article = new QueuedArticle();
        $article->set_raw_attributes(['id' => 1, 'title' => 'Hello', 'body' => 'World'], true);
        $article->set_raw_attributes(['id' => 1, 'title' => 'Edited', 'body' => 'World']);
        $article->set_relation('comments', new Collection([$comment]));

        $cache->put('article', $article, 60);
        $restored = $cache->get('article');

        $this->assertInstanceOf(QueuedArticle::class, $restored);
        $this->assertEquals($article, $restored);
        $this->assertInstanceOf(Collection::class, $restored->get_relation('comments'));
        $this->assertInstanceOf(QueuedComment::class, $restored->get_relation('comments')->first());
    }

    /** @dataProvider drivers */
    public function test_dates_round_trip_with_their_class_instant_and_zone(string $driver)
    {
        $cache = $this->cache($this->store($driver));
        $zone = new DateTimeZone('Asia/Dhaka');
        $dates = [
            new DateTime('2026-10-13 09:30:15.123456', $zone),
            new DateTimeImmutable('2026-10-13 09:30:15.123456', $zone),
            new Somoy('2026-10-13 09:30:15.123456', $zone),
        ];

        $cache->put('dates', $dates, 60);
        $restored = $cache->get('dates');

        foreach ($dates as $index => $date) {
            $this->assertSame(get_class($date), get_class($restored[$index]));
            $this->assertSame($date->format('Y-m-d H:i:s.u'), $restored[$index]->format('Y-m-d H:i:s.u'));
            $this->assertSame('Asia/Dhaka', $restored[$index]->getTimezone()->getName());
        }
    }

    /** @dataProvider drivers */
    public function test_a_std_class_round_trips(string $driver)
    {
        $cache = $this->cache($this->store($driver));
        $object = new stdClass();
        $object->name = 'value';

        $cache->put('object', $object, 60);

        $this->assertEquals($object, $cache->get('object'));
    }

    /** @dataProvider drivers */
    public function test_a_configured_value_object_round_trips_without_its_constructor(string $driver)
    {
        $cache = $this->cache($this->store($driver, [Money::class]));

        $cache->put('price', new Money(500, 'BDT'), 60);
        $restored = $cache->get('price');

        $this->assertInstanceOf(Money::class, $restored);
        $this->assertSame(500, $restored->amount);
        $this->assertSame('BDT', $restored->currency());
        $this->assertSame(1, Money::$constructed);
    }

    /** @dataProvider drivers */
    public function test_storing_a_disallowed_object_throws_and_keeps_the_previous_entry(string $driver)
    {
        $cache = $this->cache($this->store($driver));
        $cache->put('profile', 'previous', 60);

        try {
            $cache->put('profile', ['nested' => new Money(1, 'USD')], 60);
            $this->fail('Storing a disallowed object should throw.');
        } catch (ValueCodecException $exception) {
            $this->assertStringContainsString(Money::class, $exception->getMessage());
            $this->assertStringContainsString('profile', $exception->getMessage());
        }

        $this->assertSame('previous', $cache->get('profile'));
    }

    /** @dataProvider drivers */
    public function test_remember_propagates_the_exception_and_caches_nothing(string $driver)
    {
        $cache = $this->cache($this->store($driver));

        try {
            $cache->remember('computed', 60, function () {
                return new Money(1, 'USD');
            });
            $this->fail('Remembering a disallowed object should throw.');
        } catch (ValueCodecException $exception) {
            $this->assertNull($cache->get('computed'));
        }
    }

    public function test_the_array_driver_still_accepts_any_object()
    {
        $cache = new Repository(new ArrayStore(false), 'array', false);
        $money = new Money(1, 'USD');

        $cache->put('money', $money, 60);

        $this->assertSame($money, $cache->get('money'));
    }

    /** @dataProvider drivers */
    public function test_stored_data_is_json_not_php_serialized(string $driver)
    {
        $store = $this->store($driver);
        $this->cache($store)->put('settings', ['a' => new stdClass()], 60);

        $raw = $this->raw($store, 'settings');

        $this->assertIsString($raw);

        if ($store instanceof FileStore) {
            $this->assertStringStartsWith(FileStore::GUARD, $raw);
            $raw = substr($raw, strlen(FileStore::GUARD));
        }

        $this->assertIsArray(json_decode($raw, true));
        $this->assertDoesNotMatchRegularExpression('/[Oa]:\d+:/', $raw);
    }

    /** @dataProvider drivers */
    public function test_a_tampered_entry_naming_a_disallowed_class_is_a_miss_and_never_instantiated(string $driver)
    {
        $store = $this->store($driver);
        $this->write_raw($store, 'session', $this->gadget_envelope('session'));

        $this->assertSame('default', $this->cache($store)->get('session', 'default'));
        gc_collect_cycles();
        $this->assertSame([], CacheGadget::$calls);
    }

    /** @dataProvider drivers */
    public function test_a_corrupt_entry_is_a_miss(string $driver)
    {
        $store = $this->store($driver);
        $this->write_raw($store, 'broken', '{"not json');

        $this->assertSame('default', $this->cache($store)->get('broken', 'default'));
    }

    public function test_an_old_serialized_file_is_a_miss_and_is_swept()
    {
        $store = $this->store('file');
        $gadget = new CacheGadget();
        $this->write_raw($store, 'legacy', serialize(Entry::make('legacy', $gadget, time(), time() + 600)));
        unset($gadget);
        CacheGadget::$calls = [];

        $this->assertNull($this->cache($store)->get('legacy'));
        $this->assertSame([], CacheGadget::$calls);

        $this->assertSame(1, $store->gc());
        $this->assertFileDoesNotExist($store->path('legacy'));
    }

    public function test_an_old_array_transient_is_a_miss_and_is_replaced_as_json()
    {
        $store = $this->store('database');
        set_transient($store->storage_key('legacy'), Entry::make('legacy', 'old', time(), time() + 600), 600);
        $cache = $this->cache($store);

        $this->assertNull($cache->get('legacy'));

        $cache->put('legacy', 'new', 60);

        $this->assertIsString($this->raw($store, 'legacy'));
        $this->assertSame('new', $cache->get('legacy'));
    }

    public function test_the_atomic_counter_mirror_is_stored_as_json()
    {
        $GLOBALS['framework_test_ext_object_cache'] = true;
        $store = $this->store('database');

        $this->assertSame(3, $store->increment('hits', 3));
        $this->assertIsString($this->raw($store, 'hits'));
        $this->assertSame(3, $store->get('hits'));
    }
}
