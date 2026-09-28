<?php

namespace Framework\Tests\Support\Queue\Jobs;

/**
 * Where the sample jobs record what happened to them, since they run far from the test.
 */
class Journal
{
    public static array $entries = [];

    public static function write(string $entry, $value = null): void
    {
        static::$entries[] = [$entry, $value];
    }

    public static function names(): array
    {
        return array_column(static::$entries, 0);
    }

    public static function values(string $entry): array
    {
        return array_column(array_filter(static::$entries, function ($item) use ($entry) {
            return $item[0] === $entry;
        }), 1);
    }
}
