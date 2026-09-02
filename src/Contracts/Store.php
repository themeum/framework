<?php
/**
 * Contract for cache storage drivers.
 * Describes only the primitive operations a backend must provide; every higher level verb is
 * built on top of these by the cache repository.
 *
 * @package    Framework
 * @subpackage Contracts
 * @since      1.0.0
 */
namespace Framework\Contracts;

defined('ABSPATH') || exit;

interface Store
{
    /**
     * Read the value stored under the given key.
     *
     * A missing, expired, or unrecognised entry must resolve to null rather than raising an
     * error, so that a cache miss is never an exceptional condition.
     *
     * @param string $key The cache key.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public function get(string $key);

    /**
     * Read the values stored under the given keys.
     *
     * @param array $keys The cache keys.
     *
     * @return array Keyed by the requested key, with null for every miss.
     *
     * @since 1.0.0
     */
    public function many(array $keys);

    /**
     * Store a value under the given key for a number of seconds.
     *
     * @param string $key The cache key.
     * @param mixed $value The value to store.
     * @param int $seconds The number of seconds the entry should live for.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function put(string $key, $value, int $seconds);

    /**
     * Store many values for a number of seconds.
     *
     * @param array $values The key and value pairs to store.
     * @param int $seconds The number of seconds the entries should live for.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function put_many(array $values, int $seconds);

    /**
     * Raise the stored integer under the given key.
     *
     * Implementations that cannot perform this atomically must document that concurrent
     * adjustments may be lost.
     *
     * @param string $key The cache key.
     * @param int $value The amount to raise the stored value by.
     *
     * @return int|false The new value, or false when the stored value is not numeric.
     *
     * @since 1.0.0
     */
    public function increment(string $key, int $value = 1);

    /**
     * Lower the stored integer under the given key.
     *
     * @param string $key The cache key.
     * @param int $value The amount to lower the stored value by.
     *
     * @return int|false The new value, or false when the stored value is not numeric.
     *
     * @since 1.0.0
     */
    public function decrement(string $key, int $value = 1);

    /**
     * Store a value that does not expire.
     *
     * @param string $key The cache key.
     * @param mixed $value The value to store.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function forever(string $key, $value);

    /**
     * Remove the entry stored under the given key.
     *
     * @param string $key The cache key.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function forget(string $key);

    /**
     * Remove every entry belonging to this store.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function flush();

    /**
     * Get the prefix every key of this store is written under.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function get_prefix();
}
