<?php

namespace Framework\Tests\Unit\Cache;

use Framework\Cache\Events\CacheFlushed;
use Framework\Cache\Events\CacheHit;
use Framework\Cache\Events\CacheMissed;
use Framework\Cache\Events\KeyForgotten;
use Framework\Cache\Events\KeyWritten;
use Framework\Cache\Repository;
use Framework\Cache\Stores\ArrayStore;
use Framework\Tests\Support\Cache\RecordingEventManager;

class CacheEventsTest extends CacheTestCase
{
    protected RecordingEventManager $events;

    protected function setUp(): void
    {
        parent::setUp();

        $this->events = new RecordingEventManager();

        $this->app->instance('event', $this->events);
    }

    protected function make_cache(bool $enabled = true): Repository
    {
        return new Repository(new ArrayStore(), 'array', $enabled);
    }

    public function test_a_miss_is_reported_with_the_store_and_key()
    {
        $this->events->listen_for(CacheMissed::class);

        $this->make_cache()->get('absent');

        $this->assertSame([CacheMissed::class], $this->events->dispatched_classes());
        $this->assertSame('array', $this->events->dispatched[0]->store);
        $this->assertSame('absent', $this->events->dispatched[0]->key);
    }

    public function test_a_hit_is_reported_with_the_value()
    {
        $cache = $this->make_cache();
        $cache->put('key', 'value', 60);

        $this->events->listen_for(CacheHit::class);

        $cache->get('key');

        $this->assertSame([CacheHit::class], $this->events->dispatched_classes());
        $this->assertSame('value', $this->events->dispatched[0]->value);
    }

    public function test_writes_forgets_and_flushes_are_reported()
    {
        $this->events->listen_for(KeyWritten::class)
            ->listen_for(KeyForgotten::class)
            ->listen_for(CacheFlushed::class);

        $cache = $this->make_cache();

        $cache->put('key', 'value', 60);
        $cache->forget('key');
        $cache->flush();

        $this->assertSame(
            [KeyWritten::class, KeyForgotten::class, CacheFlushed::class],
            $this->events->dispatched_classes()
        );
    }

    public function test_nothing_is_dispatched_when_no_listener_is_registered()
    {
        $cache = $this->make_cache();

        $cache->put('key', 'value', 60);
        $cache->get('key');
        $cache->get('absent');
        $cache->flush();

        $this->assertSame([], $this->events->dispatched);
    }

    public function test_nothing_is_dispatched_when_events_are_disabled_for_the_store()
    {
        $this->events->listen_for(CacheMissed::class)->listen_for(KeyWritten::class);

        $cache = $this->make_cache(false);

        $cache->put('key', 'value', 60);
        $cache->get('absent');

        $this->assertSame([], $this->events->dispatched);
    }
}
