<?php
/**
 * Cache store decorator that remembers values already read during the current request.
 * Repeated reads of a key never reach the decorated store, and any write, including one made
 * without going through this decorator, discards the value it had remembered.
 *
 * @package    Framework
 * @subpackage Cache\Stores
 * @since      1.0.0
 */
namespace Framework\Cache\Stores;

defined('ABSPATH') || exit;

use Framework\Cache\Entry;
use Framework\Cache\MemoTable;
use Framework\Contracts\CacheEntryProvider;
use Framework\Contracts\Store;

class MemoizedStore implements Store, CacheEntryProvider
{
    /**
     * The decorated store.
     *
     * @var Store
     *
     * @since 1.0.0
     */
    protected $store;

    /**
     * The name of the decorated store.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected $name;

    /**
     * Create a new memoized store.
     *
     * @param Store $store The store to decorate.
     * @param string $name The name of the decorated store.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function __construct(Store $store, string $name)
    {
        $this->store = $store;
        $this->name = $name;
    }

    /**
     * Get the decorated store.
     *
     * @return Store
     *
     * @since 1.0.0
     */
    public function get_inner_store()
    {
        return $this->store;
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
        if ($this->store instanceof CacheEntryProvider) {
            $entry = $this->get_entry($key);

            return is_null($entry) ? null : Entry::value($entry);
        }

        if (MemoTable::has($this->name, $key)) {
            return MemoTable::get($this->name, $key);
        }

        $value = $this->store->get($key);

        MemoTable::put($this->name, $key, $value);

        return $value;
    }

    /**
     * Read the full stored entry for the given key.
     *
     * Memoized alongside the plain value under a distinct table key, so that reading a value and
     * reading its entry do not overwrite one another. Both are discarded together on a write.
     *
     * @param string $key The cache key.
     *
     * @return array|null
     *
     * @since 1.0.0
     */
    public function get_entry(string $key)
    {
        if (!$this->store instanceof CacheEntryProvider) {
            return null;
        }

        $memo_key = MemoTable::entry_key($key);

        if (MemoTable::has($this->name, $memo_key)) {
            return MemoTable::get($this->name, $memo_key);
        }

        $entry = $this->store->get_entry($key);

        MemoTable::put($this->name, $memo_key, $entry);

        return $entry;
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
        MemoTable::forget($this->name, $key);

        if (!$this->store instanceof CacheEntryProvider) {
            return $this->store->put($key, $value, $seconds);
        }

        return $this->store->put_entry($key, $value, $seconds, $fresh_for);
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
            $values[$key] = $this->get((string) $key);
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
        MemoTable::forget($this->name, $key);

        return $this->store->put($key, $value, $seconds);
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
        foreach (array_keys($values) as $key) {
            MemoTable::forget($this->name, (string) $key);
        }

        return $this->store->put_many($values, $seconds);
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
        MemoTable::forget($this->name, $key);

        return $this->store->increment($key, $value);
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
        MemoTable::forget($this->name, $key);

        return $this->store->decrement($key, $value);
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
        MemoTable::forget($this->name, $key);

        return $this->store->forever($key, $value);
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
        MemoTable::forget($this->name, $key);

        return $this->store->forget($key);
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
        MemoTable::flush($this->name);

        return $this->store->flush();
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
        return $this->store->get_prefix();
    }
}
