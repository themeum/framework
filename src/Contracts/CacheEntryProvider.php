<?php
/**
 * Optional contract for cache stores that can expose an entry's stored metadata.
 * Only required by stale while revalidate reads, which need to know when an entry was written
 * and when it stops being fresh; stores that do not implement it still work everywhere else.
 *
 * @package    Framework
 * @subpackage Contracts
 * @since      1.0.0
 */
namespace Framework\Contracts;

defined('ABSPATH') || exit;

interface CacheEntryProvider
{
    /**
     * Read the full stored entry, metadata included, for the given key.
     *
     * @param string $key The cache key.
     *
     * @return array|null The entry, or null when the key is missing or expired.
     *
     * @since 1.0.0
     */
    public function get_entry(string $key);

    /**
     * Store a value together with an explicit freshness boundary.
     *
     * @param string $key The cache key.
     * @param mixed $value The value to store.
     * @param int $seconds The number of seconds the entry should live for.
     * @param int|null $fresh_for The number of seconds the entry should be considered fresh.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function put_entry(string $key, $value, int $seconds, $fresh_for = null);
}
