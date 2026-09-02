<?php
/**
 * The cache API every store is driven through.
 * Holds each verb exactly once so that the stores stay primitive and the subtle semantics of
 * lifetimes, envelopes, memoization and stale reads have a single implementation.
 *
 * @package    Framework
 * @subpackage Cache
 * @since      1.0.0
 */
namespace Framework\Cache;

defined('ABSPATH') || exit;

use ArrayAccess;
use Closure;
use Framework\Cache\Concerns\InteractsWithTime;
use Framework\Cache\Events\CacheFlushed;
use Framework\Cache\Events\CacheHit;
use Framework\Cache\Events\CacheMissed;
use Framework\Cache\Events\KeyForgotten;
use Framework\Cache\Events\KeyWritten;
use Framework\Contracts\CacheEntryProvider;
use Framework\Contracts\Store;
use Framework\Supports\Traits\Macroable;
use InvalidArgumentException;
use Throwable;

use function Framework\app;
use function Framework\value;

class Repository implements ArrayAccess
{
    use InteractsWithTime;
    use Macroable;

    /**
     * The store entries are read from and written to.
     *
     * @var Store
     *
     * @since 1.0.0
     */
    protected $store;

    /**
     * The configured name of the store.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected $name;

    /**
     * Whether cache events are dispatched for this store.
     *
     * @var bool
     *
     * @since 1.0.0
     */
    protected $events_enabled;

    /**
     * The lifetime used when none is supplied.
     *
     * @var int
     *
     * @since 1.0.0
     */
    protected $default_cache_time = 3600;

    /**
     * The resolved event manager, or null when none is available.
     *
     * @var mixed
     *
     * @since 1.0.0
     */
    protected $event_manager = false;

    /**
     * Create a new cache repository.
     *
     * @param Store $store The store to drive.
     * @param string $name The configured name of the store.
     * @param bool $events Whether cache events are dispatched for this store.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function __construct(Store $store, string $name = 'default', bool $events = true)
    {
        $this->store = $store;
        $this->name = $name;
        $this->events_enabled = $events;
    }

    /**
     * Read a value from the cache.
     *
     * @param string $key The cache key.
     * @param mixed $default The value returned on a miss; a closure is evaluated only then.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public function get($key, $default = null)
    {
        $key = (string) $key;

        [$found, $cached] = $this->retrieve($key);

        if ($found) {
            $this->fire_event(CacheHit::class, [$this->name, $key, $cached]);

            return $cached;
        }

        $this->fire_event(CacheMissed::class, [$this->name, $key]);

        return value($default);
    }

    /**
     * Read many values from the cache.
     *
     * @param array $keys The cache keys, optionally mapping a key to its own default.
     *
     * @return array Keyed by the requested key, with null or the supplied default for a miss.
     *
     * @since 1.0.0
     */
    public function many(array $keys)
    {
        $values = [];

        foreach ($keys as $key => $default) {
            if (is_int($key)) {
                $values[$default] = $this->get((string) $default);

                continue;
            }

            $values[$key] = $this->get((string) $key, $default);
        }

        return $values;
    }

    /**
     * Read many values from the cache.
     *
     * @param iterable $keys The cache keys.
     * @param mixed $default The value returned for each miss.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function get_multiple($keys, $default = null)
    {
        $values = [];

        foreach ($keys as $key) {
            $values[$key] = $this->get((string) $key, $default);
        }

        return $values;
    }

    /**
     * Determine whether a key is present in the cache.
     *
     * @param string $key The cache key.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function has($key)
    {
        return $this->retrieve((string) $key)[0];
    }

    /**
     * Determine whether a key is absent from the cache.
     *
     * @param string $key The cache key.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function missing($key)
    {
        return !$this->has($key);
    }

    /**
     * Read a value and remove it from the cache.
     *
     * @param string $key The cache key.
     * @param mixed $default The value returned on a miss.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public function pull($key, $default = null)
    {
        $value = $this->get($key, $default);

        $this->forget($key);

        return $value;
    }

    /**
     * Read a value that must be a string.
     *
     * @param string $key The cache key.
     * @param mixed $default The value returned on a miss.
     *
     * @return string
     *
     * @throws InvalidArgumentException When the value is not a string.
     *
     * @since 1.0.0
     */
    public function string($key, $default = null)
    {
        $value = $this->get($key, $default);

        if (!is_string($value)) {
            throw new InvalidArgumentException($this->type_error($key, 'a string', $value));
        }

        return $value;
    }

    /**
     * Read a value that must be an integer.
     *
     * @param string $key The cache key.
     * @param mixed $default The value returned on a miss.
     *
     * @return int
     *
     * @throws InvalidArgumentException When the value is not an integer.
     *
     * @since 1.0.0
     */
    public function integer($key, $default = null)
    {
        $value = $this->get($key, $default);

        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException($this->type_error($key, 'an integer', $value));
        }

        return (int) $value;
    }

    /**
     * Read a value that must be a float.
     *
     * @param string $key The cache key.
     * @param mixed $default The value returned on a miss.
     *
     * @return float
     *
     * @throws InvalidArgumentException When the value is not a float.
     *
     * @since 1.0.0
     */
    public function float($key, $default = null)
    {
        $value = $this->get($key, $default);

        if (filter_var($value, FILTER_VALIDATE_FLOAT) === false) {
            throw new InvalidArgumentException($this->type_error($key, 'a float', $value));
        }

        return (float) $value;
    }

    /**
     * Read a value that must be a boolean.
     *
     * @param string $key The cache key.
     * @param mixed $default The value returned on a miss.
     *
     * @return bool
     *
     * @throws InvalidArgumentException When the value is not a boolean.
     *
     * @since 1.0.0
     */
    public function boolean($key, $default = null)
    {
        $value = $this->get($key, $default);

        if (!is_bool($value)) {
            throw new InvalidArgumentException($this->type_error($key, 'a boolean', $value));
        }

        return $value;
    }

    /**
     * Read a value that must be an array.
     *
     * @param string $key The cache key.
     * @param mixed $default The value returned on a miss.
     *
     * @return array
     *
     * @throws InvalidArgumentException When the value is not an array.
     *
     * @since 1.0.0
     */
    public function array($key, $default = null)
    {
        $value = $this->get($key, $default);

        if (!is_array($value)) {
            throw new InvalidArgumentException($this->type_error($key, 'an array', $value));
        }

        return $value;
    }

    /**
     * Write a value to the cache.
     *
     * A lifetime of zero or less, or one that has already passed, removes the key instead of
     * storing it, and reports failure.
     *
     * @param string $key The cache key.
     * @param mixed $value The value to store.
     * @param mixed $ttl Seconds, a date and time, an interval, or null to never expire.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function put($key, $value, $ttl = null)
    {
        $key = (string) $key;

        if (is_null($ttl)) {
            return $this->forever($key, $value);
        }

        $seconds = $this->seconds_until($ttl);

        if ($seconds <= 0) {
            $this->forget($key);

            return false;
        }

        MemoTable::forget($this->name, $key);

        $result = $this->store->put($key, $value, (int) $seconds);

        if ($result) {
            $this->fire_event(KeyWritten::class, [$this->name, $key, $value]);
        }

        return $result;
    }

    /**
     * Write a value to the cache.
     *
     * @param string $key The cache key.
     * @param mixed $value The value to store.
     * @param mixed $ttl Seconds, a date and time, an interval, or null to never expire.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function set($key, $value, $ttl = null)
    {
        return $this->put($key, $value, $ttl);
    }

    /**
     * Write many values to the cache.
     *
     * @param array $values The key and value pairs to store.
     * @param mixed $ttl Seconds, a date and time, an interval, or null to never expire.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function put_many(array $values, $ttl = null)
    {
        $result = true;

        foreach ($values as $key => $value) {
            $result = $this->put((string) $key, $value, $ttl) && $result;
        }

        return $result;
    }

    /**
     * Write many values to the cache.
     *
     * @param iterable $values The key and value pairs to store.
     * @param mixed $ttl Seconds, a date and time, an interval, or null to never expire.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function set_multiple($values, $ttl = null)
    {
        return $this->put_many(is_array($values) ? $values : iterator_to_array($values), $ttl);
    }

    /**
     * Write a value only when the key is not already present.
     *
     * This is a read followed by a write and is not atomic, because atomic locking is out of
     * scope for this cache.
     *
     * @param string $key The cache key.
     * @param mixed $value The value to store.
     * @param mixed $ttl Seconds, a date and time, an interval, or null to never expire.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function add($key, $value, $ttl = null)
    {
        if ($this->has($key)) {
            return false;
        }

        return $this->put($key, $value, $ttl);
    }

    /**
     * Write a value that does not expire.
     *
     * @param string $key The cache key.
     * @param mixed $value The value to store.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function forever($key, $value)
    {
        $key = (string) $key;

        MemoTable::forget($this->name, $key);

        $result = $this->store->forever($key, $value);

        if ($result) {
            $this->fire_event(KeyWritten::class, [$this->name, $key, $value]);
        }

        return $result;
    }

    /**
     * Extend the lifetime of an entry that is already stored.
     *
     * @param string $key The cache key.
     * @param mixed $ttl Seconds, a date and time, an interval, or null to never expire.
     *
     * @return bool True when the entry existed and its lifetime was changed.
     *
     * @since 1.0.0
     */
    public function touch($key, $ttl)
    {
        $key = (string) $key;

        [$found, $value] = $this->retrieve($key);

        if (!$found) {
            return false;
        }

        return $this->put($key, $value, $ttl);
    }

    /**
     * Raise the stored integer under the given key.
     *
     * Not atomic. Concurrent adjustments may be lost; use the query builder with a raw
     * expression where an exact count matters.
     *
     * @param string $key The cache key.
     * @param int $value The amount to raise the stored value by.
     *
     * @return int|false
     *
     * @since 1.0.0
     */
    public function increment($key, $value = 1)
    {
        $key = (string) $key;

        MemoTable::forget($this->name, $key);

        return $this->store->increment($key, (int) $value);
    }

    /**
     * Lower the stored integer under the given key.
     *
     * Not atomic. Concurrent adjustments may be lost; use the query builder with a raw
     * expression where an exact count matters.
     *
     * @param string $key The cache key.
     * @param int $value The amount to lower the stored value by.
     *
     * @return int|false
     *
     * @since 1.0.0
     */
    public function decrement($key, $value = 1)
    {
        $key = (string) $key;

        MemoTable::forget($this->name, $key);

        return $this->store->decrement($key, (int) $value);
    }

    /**
     * Read a value, computing and storing it when it is absent.
     *
     * @param string $key The cache key.
     * @param mixed $ttl Seconds, a date and time, an interval, or null to never expire.
     * @param Closure $callback Produces the value when the key is absent.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public function remember($key, $ttl, Closure $callback)
    {
        $key = (string) $key;

        [$found, $cached] = $this->retrieve($key);

        if ($found) {
            $this->fire_event(CacheHit::class, [$this->name, $key, $cached]);

            return $cached;
        }

        $this->fire_event(CacheMissed::class, [$this->name, $key]);

        $value = $callback();

        $this->put($key, $value, $ttl);

        return $value;
    }

    /**
     * Read a value, computing and storing it without an expiry when it is absent.
     *
     * @param string $key The cache key.
     * @param Closure $callback Produces the value when the key is absent.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public function remember_forever($key, Closure $callback)
    {
        return $this->remember($key, null, $callback);
    }

    /**
     * Read a value, serving it while stale and refreshing it after the response.
     *
     * Inside the fresh window the stored value is returned untouched. Inside the stale window it
     * is returned immediately and a refresh is registered to run once the response has been
     * sent, where the server allows that. Past the stale window the value is recomputed first.
     *
     * The guard against several requests refreshing at once is best effort, because atomic
     * locking is out of scope for this cache.
     *
     * @param string $key The cache key.
     * @param array $ttl The fresh and stale lifetimes, in seconds.
     * @param Closure $callback Produces the value.
     *
     * @return mixed
     *
     * @throws InvalidArgumentException When the lifetimes are not a fresh and stale pair.
     *
     * @since 1.0.0
     */
    public function flexible($key, array $ttl, Closure $callback)
    {
        $key = (string) $key;

        if (count($ttl) !== 2) {
            throw new InvalidArgumentException(
                sprintf('The flexible lifetime for key [%s] must be a fresh and stale pair.', $key)
            );
        }

        $fresh = (int) $this->seconds_until($ttl[0]);
        $stale = (int) $this->seconds_until($ttl[1]);

        $entry = $this->read_flexible_entry($key);

        if (is_null($entry)) {
            return $this->store_flexible($key, $callback(), $fresh, $stale);
        }

        if (Entry::is_fresh($entry, $this->current_timestamp())) {
            $this->fire_event(CacheHit::class, [$this->name, $key, Entry::value($entry)]);

            return Entry::value($entry);
        }

        $this->schedule_refresh($key, $callback, $fresh, $stale);

        return Entry::value($entry);
    }

    /**
     * Remove a key from the cache.
     *
     * @param string $key The cache key.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function forget($key)
    {
        $key = (string) $key;

        MemoTable::forget($this->name, $key);

        $result = $this->store->forget($key);

        $this->fire_event(KeyForgotten::class, [$this->name, $key]);

        return $result;
    }

    /**
     * Remove a key from the cache.
     *
     * @param string $key The cache key.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function delete($key)
    {
        return $this->forget($key);
    }

    /**
     * Remove many keys from the cache.
     *
     * @param iterable $keys The cache keys.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function delete_multiple($keys)
    {
        $result = true;

        foreach ($keys as $key) {
            $result = $this->forget((string) $key) && $result;
        }

        return $result;
    }

    /**
     * Remove every entry from the store.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function flush()
    {
        MemoTable::flush($this->name);

        $result = $this->store->flush();

        if ($result) {
            $this->fire_event(CacheFlushed::class, [$this->name]);
        }

        return $result;
    }

    /**
     * Remove every entry from the store.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function clear()
    {
        return $this->flush();
    }

    /**
     * Get the store being driven.
     *
     * @return Store
     *
     * @since 1.0.0
     */
    public function get_store()
    {
        return $this->store;
    }

    /**
     * Get the configured name of the store.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function get_name()
    {
        return $this->name;
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

    /**
     * Get the lifetime used when none is supplied.
     *
     * @return int
     *
     * @since 1.0.0
     */
    public function get_default_cache_time()
    {
        return $this->default_cache_time;
    }

    /**
     * Set the lifetime used when none is supplied.
     *
     * @param int $seconds The lifetime in seconds.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function set_default_cache_time($seconds)
    {
        $this->default_cache_time = (int) $seconds;

        return $this;
    }

    /**
     * Determine whether this cache supports tagging.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function supports_tags()
    {
        return false;
    }

    /**
     * Determine whether a key is present in the cache.
     *
     * @param mixed $offset The cache key.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function offsetExists($offset): bool
    {
        return $this->has((string) $offset);
    }

    /**
     * Read a value from the cache.
     *
     * @param mixed $offset The cache key.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->get((string) $offset);
    }

    /**
     * Write a value to the cache for the default lifetime.
     *
     * @param mixed $offset The cache key.
     * @param mixed $value The value to store.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function offsetSet($offset, $value): void
    {
        $this->put((string) $offset, $value, $this->default_cache_time);
    }

    /**
     * Remove a key from the cache.
     *
     * @param mixed $offset The cache key.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function offsetUnset($offset): void
    {
        $this->forget((string) $offset);
    }

    /**
     * Read a key, reporting whether it was present as well as its value.
     *
     * Going through the entry when the store exposes one is what keeps a stored null or false
     * distinguishable from a miss.
     *
     * @param string $key The cache key.
     *
     * @return array A pair of whether the key was found and the value stored under it.
     *
     * @since 1.0.0
     */
    protected function retrieve(string $key)
    {
        if ($this->store instanceof CacheEntryProvider) {
            $entry = $this->store->get_entry($key);

            return is_null($entry) ? [false, null] : [true, Entry::value($entry)];
        }

        $value = $this->store->get($key);

        return is_null($value) ? [false, null] : [true, $value];
    }

    /**
     * Read the entry behind a stale while revalidate key.
     *
     * @param string $key The cache key.
     *
     * @return array|null
     *
     * @since 1.0.0
     */
    protected function read_flexible_entry(string $key)
    {
        if ($this->store instanceof CacheEntryProvider) {
            return $this->store->get_entry($key);
        }

        $value = $this->store->get($key);

        if (is_null($value)) {
            return null;
        }

        $fresh_until = $this->store->get($key . ':fresh_until');

        return Entry::make(
            $key,
            $value,
            $this->current_timestamp(),
            null,
            is_null($fresh_until) ? null : (int) $fresh_until
        );
    }

    /**
     * Store a value with an explicit freshness boundary.
     *
     * @param string $key The cache key.
     * @param mixed $value The value to store.
     * @param int $fresh The number of seconds the value stays fresh.
     * @param int $stale The number of seconds the value may be served while stale.
     *
     * @return mixed The stored value.
     *
     * @since 1.0.0
     */
    protected function store_flexible(string $key, $value, int $fresh, int $stale)
    {
        MemoTable::forget($this->name, $key);

        if ($this->store instanceof CacheEntryProvider) {
            $this->store->put_entry($key, $value, $stale, $fresh);
        } else {
            $this->store->put($key, $value, $stale);
            $this->store->put($key . ':fresh_until', $this->current_timestamp() + $fresh, $stale);
        }

        $this->fire_event(KeyWritten::class, [$this->name, $key, $value]);

        return $value;
    }

    /**
     * Register a refresh to run once the response has been sent.
     *
     * @param string $key The cache key.
     * @param Closure $callback Produces the value.
     * @param int $fresh The number of seconds the value stays fresh.
     * @param int $stale The number of seconds the value may be served while stale.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function schedule_refresh(string $key, Closure $callback, int $fresh, int $stale)
    {
        if (!$this->claim_refresh($key, $stale)) {
            return;
        }

        if (!function_exists('add_action')) {
            $this->store_flexible($key, $callback(), $fresh, $stale);

            return;
        }

        add_action('shutdown', function () use ($key, $callback, $fresh, $stale) {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }

            $this->store_flexible($key, $callback(), $fresh, $stale);

            $this->store->forget($this->refresh_marker($key));
        }, PHP_INT_MAX);
    }

    /**
     * Claim the right to refresh a stale key.
     *
     * Best effort only. Without atomic locking two requests can both claim the same refresh, so
     * this narrows a stampede rather than preventing one.
     *
     * @param string $key The cache key.
     * @param int $stale The number of seconds the value may be served while stale.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    protected function claim_refresh(string $key, int $stale)
    {
        $marker = $this->refresh_marker($key);

        if (!is_null($this->store->get($marker))) {
            return false;
        }

        return (bool) $this->store->put($marker, 1, max(1, min($stale, 60)));
    }

    /**
     * Get the key used to mark a refresh as already claimed.
     *
     * @param string $key The cache key.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function refresh_marker(string $key)
    {
        return $key . ':refreshing';
    }

    /**
     * Dispatch a cache event, building it only when something is listening.
     *
     * @param string $class The event class name.
     * @param array $arguments The event constructor arguments.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function fire_event(string $class, array $arguments)
    {
        if (!$this->events_enabled) {
            return;
        }

        $events = $this->event_manager();

        if (is_null($events) || !$events->has_listeners($class)) {
            return;
        }

        $events->dispatch(new $class(...$arguments));
    }

    /**
     * Resolve the event manager, or null when the container has none bound.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    protected function event_manager()
    {
        if ($this->event_manager === false) {
            try {
                $this->event_manager = app('event');
            } catch (Throwable $exception) {
                $this->event_manager = null;
            }
        }

        return $this->event_manager;
    }

    /**
     * Build the message reported when a typed read receives the wrong type.
     *
     * @param string $key The cache key.
     * @param string $expected A description of the expected type.
     * @param mixed $value The value that was read.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function type_error(string $key, string $expected, $value)
    {
        return sprintf('Cache value for key [%s] must be %s, %s given.', $key, $expected, gettype($value));
    }
}
