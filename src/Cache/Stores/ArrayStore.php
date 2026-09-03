<?php
/**
 * Cache store holding entries in memory for the duration of the current request.
 * Honours lifetimes exactly as the persistent stores do, so a test written against this store
 * predicts the behaviour of the store that will run in production.
 *
 * @package    Framework
 * @subpackage Cache\Stores
 * @since      1.0.0
 */
namespace Framework\Cache\Stores;

defined('ABSPATH') || exit;

use Framework\Cache\Concerns\HashesKeys;
use Framework\Cache\Concerns\InteractsWithTime;
use Framework\Cache\Entry;
use Framework\Contracts\CacheEntryProvider;
use Framework\Contracts\Store;

class ArrayStore implements Store, CacheEntryProvider
{
    use HashesKeys;
    use InteractsWithTime;

    /**
     * The stored entries, keyed by their derived storage identifier.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected $storage = [];

    /**
     * Create a new array store.
     *
     * @param bool $network Whether the store is shared across a network.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function __construct(bool $network = false)
    {
        $this->network = $network;
    }

    /**
     * Derive the storage identifier for a cache key.
     *
     * @param string $key The cache key.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function storage_key(string $key)
    {
        return $this->hash_key($key);
    }

    /**
     * Read the value stored under the given key.
     *
     * @param string $key The cache key.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public function get(string $key)
    {
        $entry = $this->get_entry($key);

        return is_null($entry) ? null : Entry::value($entry);
    }

    /**
     * Read the full stored entry for the given key.
     *
     * @param string $key The cache key.
     *
     * @return array|null
     *
     * @since 1.0.0
     */
    public function get_entry(string $key)
    {
        $storage_key = $this->storage_key($key);

        $entry = Entry::read($this->storage[$storage_key] ?? null, $key);

        if (is_null($entry)) {
            return null;
        }

        if (Entry::has_expired($entry, $this->current_timestamp())) {
            unset($this->storage[$storage_key]);

            return null;
        }

        return $entry;
    }

    /**
     * Read the values stored under the given keys.
     *
     * @param array $keys The cache keys.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function many(array $keys)
    {
        $values = [];

        foreach ($keys as $key) {
            $values[$key] = $this->get($key);
        }

        return $values;
    }

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
    public function put(string $key, $value, int $seconds)
    {
        return $this->put_entry($key, $value, $seconds);
    }

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
    public function put_entry(string $key, $value, int $seconds, $fresh_for = null)
    {
        $now = $this->current_timestamp();

        $this->storage[$this->storage_key($key)] = Entry::make(
            $key,
            $value,
            $now,
            $now + $seconds,
            is_null($fresh_for) ? null : $now + (int) $fresh_for
        );

        return true;
    }

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
    public function put_many(array $values, int $seconds)
    {
        $result = true;

        foreach ($values as $key => $value) {
            $result = $this->put((string) $key, $value, $seconds) && $result;
        }

        return $result;
    }

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
    public function forever(string $key, $value)
    {
        $this->storage[$this->storage_key($key)] = Entry::make(
            $key,
            $value,
            $this->current_timestamp(),
            null
        );

        return true;
    }

    /**
     * Raise the stored integer under the given key.
     *
     * @param string $key The cache key.
     * @param int $value The amount to raise the stored value by.
     *
     * @return int|false
     *
     * @since 1.0.0
     */
    public function increment(string $key, int $value = 1)
    {
        $entry = $this->get_entry($key);

        if (is_null($entry)) {
            return $this->forever($key, $value) ? $value : false;
        }

        $current = Entry::value($entry);

        if (!is_numeric($current)) {
            return false;
        }

        $new = (int) $current + $value;

        $entry['value'] = $new;

        $this->storage[$this->storage_key($key)] = $entry;

        return $new;
    }

    /**
     * Lower the stored integer under the given key.
     *
     * @param string $key The cache key.
     * @param int $value The amount to lower the stored value by.
     *
     * @return int|false
     *
     * @since 1.0.0
     */
    public function decrement(string $key, int $value = 1)
    {
        return $this->increment($key, $value * -1);
    }

    /**
     * Remove the entry stored under the given key.
     *
     * @param string $key The cache key.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function forget(string $key)
    {
        unset($this->storage[$this->storage_key($key)]);

        return true;
    }

    /**
     * Remove every entry belonging to this store.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function flush()
    {
        $this->storage = [];

        return true;
    }

    /**
     * Get the prefix every key of this store is written under.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function get_prefix()
    {
        return '';
    }
}
