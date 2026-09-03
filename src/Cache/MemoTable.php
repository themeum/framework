<?php
/**
 * The per request table backing memoized cache reads.
 * Shared by every cache instance for a given store, so that a write made through the ordinary
 * cache is observed by a later memoized read within the same request.
 *
 * @package    Framework
 * @subpackage Cache
 * @since      1.0.0
 */
namespace Framework\Cache;

defined('ABSPATH') || exit;

class MemoTable
{
    /**
     * The memoized values, grouped by scope and then by cache key.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected static $entries = [];

    /**
     * The prefix distinguishing a memoized entry from a memoized value.
     *
     * A key has two representations in the table, because a caller may ask for the value or for
     * the entry carrying its metadata. Both are invalidated together.
     *
     * @var string
     *
     * @since 1.0.0
     */
    public const ENTRY_PREFIX = 'entry:';

    /**
     * Build the scope a store's memoized values live under.
     *
     * The current site forms part of the scope so that switching sites during a request cannot
     * surface a value memoized for a different site.
     *
     * @param string $store The store name.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public static function scope(string $store)
    {
        $site = function_exists('get_current_blog_id') ? (string) get_current_blog_id() : '0';

        return $store . '|' . $site;
    }

    /**
     * Determine whether a value has been memoized for the given key.
     *
     * @param string $store The store name.
     * @param string $key The cache key.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public static function has(string $store, string $key)
    {
        return array_key_exists($key, static::$entries[static::scope($store)] ?? []);
    }

    /**
     * Read the memoized value for the given key.
     *
     * @param string $store The store name.
     * @param string $key The cache key.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public static function get(string $store, string $key)
    {
        return static::$entries[static::scope($store)][$key] ?? null;
    }

    /**
     * Memoize a value for the given key.
     *
     * Misses are memoized as well as hits, so that a repeatedly absent key is only looked up
     * once per request.
     *
     * @param string $store The store name.
     * @param string $key The cache key.
     * @param mixed $value The value to memoize.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public static function put(string $store, string $key, $value)
    {
        static::$entries[static::scope($store)][$key] = $value;
    }

    /**
     * Discard the memoized value for the given key.
     *
     * @param string $store The store name.
     * @param string $key The cache key.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public static function forget(string $store, string $key)
    {
        $scope = static::scope($store);

        unset(static::$entries[$scope][$key], static::$entries[$scope][static::ENTRY_PREFIX . $key]);
    }

    /**
     * Get the table key a memoized entry is held under.
     *
     * @param string $key The cache key.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public static function entry_key(string $key)
    {
        return static::ENTRY_PREFIX . $key;
    }

    /**
     * Discard every memoized value for a store.
     *
     * @param string $store The store name.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public static function flush(string $store)
    {
        unset(static::$entries[static::scope($store)]);
    }

    /**
     * Discard every memoized value for every store and site.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public static function clear()
    {
        static::$entries = [];
    }
}
