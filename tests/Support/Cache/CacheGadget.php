<?php

namespace Framework\Tests\Support\Cache;

/**
 * Stands in for a gadget class: it records every magic method that runs on it, so a test can prove
 * that a tampered cache entry never created one.
 */
class CacheGadget
{
    public static array $calls = [];

    public $command = '';

    public function __construct()
    {
        static::$calls[] = 'constructed';
    }

    public function __wakeup()
    {
        static::$calls[] = 'woke';
    }

    public function __unserialize(array $data): void
    {
        static::$calls[] = 'unserialized';
    }

    public function __destruct()
    {
        static::$calls[] = 'destructed';
    }
}
