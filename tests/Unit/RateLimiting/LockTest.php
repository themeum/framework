<?php

namespace Framework\Tests\Unit\RateLimiting;

use Framework\Cache\Locks\DatabaseLock;
use Framework\Cache\Locks\ObjectCacheLock;
use Framework\Tests\Support\RateLimiting\LockWpdb;
use RuntimeException;

class LockTest extends RateLimiterTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wpdb'] = new LockWpdb();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);

        parent::tearDown();
    }

    public function test_only_one_of_two_object_cache_callers_acquires_the_lock()
    {
        $GLOBALS['framework_test_ext_object_cache'] = true;

        $first = new ObjectCacheLock('counter', 10);
        $second = new ObjectCacheLock('counter', 10);

        $this->assertTrue($first->get());
        $this->assertFalse($second->get());
    }

    public function test_an_object_cache_lock_can_be_reacquired_after_release()
    {
        $GLOBALS['framework_test_ext_object_cache'] = true;

        $first = new ObjectCacheLock('counter', 10);
        $first->get();
        $this->assertTrue($first->release());

        $second = new ObjectCacheLock('counter', 10);

        $this->assertTrue($second->get());
    }

    public function test_an_object_cache_lock_is_not_released_by_a_stale_holder()
    {
        $GLOBALS['framework_test_ext_object_cache'] = true;

        $first = new ObjectCacheLock('counter', 10);
        $first->get();

        wp_cache_delete('counter', 'framework_locks');

        $second = new ObjectCacheLock('counter', 10);
        $second->get();

        $this->assertFalse($first->release());
        $this->assertSame($second->owner(), wp_cache_get('counter', 'framework_locks'));
    }

    public function test_only_one_of_two_database_callers_acquires_the_lock()
    {
        $first = new DatabaseLock('counter', 10);
        $second = new DatabaseLock('counter', 10);

        $this->assertTrue($first->get());
        $this->assertFalse($second->get());
    }

    public function test_a_database_lock_can_be_reacquired_after_release()
    {
        $first = new DatabaseLock('counter', 10);
        $first->get();

        $this->assertTrue($first->release());

        $second = new DatabaseLock('counter', 10);

        $this->assertTrue($second->get());
    }

    public function test_an_abandoned_database_lock_becomes_available_once_expired()
    {
        $abandoned = new DatabaseLock('counter', 10);
        $abandoned->get();

        $this->expire_rows();

        $successor = new DatabaseLock('counter', 10);

        $this->assertTrue($successor->get());
    }

    public function test_a_stale_database_holder_cannot_release_a_reacquired_lock()
    {
        $abandoned = new DatabaseLock('counter', 10);
        $abandoned->get();

        $this->expire_rows();

        $successor = new DatabaseLock('counter', 10);
        $successor->get();

        $this->assertFalse($abandoned->release());
        $this->assertNotEmpty($GLOBALS['wpdb']->rows);
    }

    public function test_a_database_lock_releases_after_the_callback_raises()
    {
        $lock = new DatabaseLock('counter', 10);

        try {
            $lock->get(function () {
                throw new RuntimeException('failed');
            });

            $this->fail('The exception should have propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('failed', $exception->getMessage());
        }

        $successor = new DatabaseLock('counter', 10);

        $this->assertTrue($successor->get());
    }

    public function test_the_callback_does_not_run_when_the_lock_is_unavailable()
    {
        $holder = new DatabaseLock('counter', 10);
        $holder->get();

        $ran = false;

        $result = (new DatabaseLock('counter', 10))->get(function () use (&$ran) {
            $ran = true;
        });

        $this->assertFalse($ran);
        $this->assertFalse($result);
    }

    public function test_the_callback_result_is_returned_and_the_lock_released()
    {
        $lock = new DatabaseLock('counter', 10);

        $this->assertSame('done', $lock->get(function () {
            return 'done';
        }));

        $this->assertTrue((new DatabaseLock('counter', 10))->get());
    }

    public function test_the_sweep_removes_expired_lock_rows_only()
    {
        (new DatabaseLock('expired', 10))->get();
        $this->expire_rows();

        (new DatabaseLock('live', 600))->get();

        $removed = DatabaseLock::gc('framework_');

        $this->assertSame(1, $removed);
        $this->assertCount(1, $GLOBALS['wpdb']->rows);
    }

    public function test_a_lock_lifetime_is_always_at_least_one_second()
    {
        $lock = new DatabaseLock('counter', 0);
        $lock->get();

        $value = reset($GLOBALS['wpdb']->rows);
        $expiry = (int) substr($value, strrpos($value, '|') + 1);

        $this->assertGreaterThan(time(), $expiry);
    }

    protected function expire_rows(): void
    {
        foreach ($GLOBALS['wpdb']->rows as $name => $value) {
            $owner = substr($value, 0, strrpos($value, '|'));

            $GLOBALS['wpdb']->rows[$name] = $owner . '|' . (time() - 5);
        }
    }
}
