<?php
/**
 * Cache store backed by the WordPress transient API.
 * Chosen so that a site running a persistent object cache is served from memory without any
 * configuration, at the cost of the mitigations documented on each method below.
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
use Framework\Supports\Facades\DB;
use Throwable;

class DatabaseStore implements Store, CacheEntryProvider
{
    use HashesKeys;
    use InteractsWithTime;

    /**
     * The number of hexadecimal characters kept from a hashed key.
     *
     * A transient name may be at most 172 characters, because option_name is varchar(191) and
     * the companion timeout row adds a 19 character prefix. Keeping the hash short leaves ample
     * room for the application prefix and the namespace version.
     *
     * @var int
     *
     * @since 1.0.0
     */
    public const HASH_LENGTH = 32;

    /**
     * The number of superseded namespace versions a single gc() run may sweep.
     *
     * @var int
     *
     * @since 1.0.0
     */
    public const GC_VERSIONS_PER_RUN = 3;

    /**
     * The number of option rows a single gc() run may delete per namespace version.
     *
     * @var int
     *
     * @since 1.0.0
     */
    public const GC_ROWS_PER_VERSION = 500;

    /**
     * The prefix every key of this store is written under.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected $prefix;

    /**
     * The lifetime given to entries that never logically expire.
     *
     * @var int
     *
     * @since 1.0.0
     */
    protected $forever_ttl;

    /**
     * The resolved namespace version.
     *
     * @var int|null
     *
     * @since 1.0.0
     */
    protected $resolved_version = null;

    /**
     * Create a new transient backed store.
     *
     * @param string $prefix The prefix every key is written under.
     * @param int $forever_ttl The lifetime given to entries that never logically expire.
     * @param bool $network Whether the store is shared across a network.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function __construct(string $prefix, int $forever_ttl = 0, bool $network = false)
    {
        $this->prefix = $prefix;
        $this->forever_ttl = $forever_ttl;
        $this->network = $network;
    }

    /**
     * Derive the transient name a cache key is stored under.
     *
     * Hashing is unconditional. WordPress removes the strict SQL modes, so a transient name past
     * the limit is silently truncated into a collision rather than raising an error, and the
     * overflow depends on the data rather than the code, so it escapes testing.
     *
     * @param string $key The cache key.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function storage_key(string $key)
    {
        return $this->get_prefix() . $this->hash_key($key, static::HASH_LENGTH);
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
        return $this->prefix . 'c' . $this->version() . '_';
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
        $entry = Entry::read($this->read_transient($this->storage_key($key)), $key);

        if (is_null($entry)) {
            return null;
        }

        if (Entry::has_expired($entry, $this->current_timestamp())) {
            $this->forget($key);

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
        $this->clear_counter($key);

        $now = $this->current_timestamp();

        $entry = Entry::make(
            $key,
            $value,
            $now,
            $now + $seconds,
            is_null($fresh_for) ? null : $now + (int) $fresh_for
        );

        return $this->write_transient($this->storage_key($key), $entry, $seconds);
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
     * The envelope records that the entry never expires, while the transient is given a long but
     * finite lifetime. WordPress marks an expiry free transient as an autoloaded option and
     * skips its own autoload size guard when doing so, which would load the value on every
     * request. Configuring the lifetime as zero restores literal never expiry.
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
        $this->clear_counter($key);

        $entry = Entry::make($key, $value, $this->current_timestamp(), null);

        return $this->write_transient($this->storage_key($key), $entry, $this->forever_ttl);
    }

    /**
     * Raise the stored integer under the given key.
     *
     * A persistent object cache turns this into a genuinely atomic operation, backed by the
     * cache backend's own increment primitive. Without one, this is a read followed by a write
     * and is not atomic; concurrent adjustments may be lost. Use the query builder with a raw
     * expression where an exact count matters regardless of which path runs.
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
        if ($this->uses_external_object_cache()) {
            return $this->increment_atomic($key, $value);
        }

        return $this->increment_read_modify_write($key, $value);
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
     * Raise the stored integer through a read-modify-write over the envelope.
     *
     * @param string $key The cache key.
     * @param int $value The amount to raise the stored value by.
     *
     * @return int|false
     *
     * @since 1.0.0
     */
    protected function increment_read_modify_write(string $key, int $value)
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

        $expires_at = $entry['expires_at'] ?? null;

        $seconds = is_null($expires_at)
            ? $this->forever_ttl
            : max(1, (int) $expires_at - $this->current_timestamp());

        return $this->write_transient($this->storage_key($key), $entry, $seconds) ? $new : false;
    }

    /**
     * Raise the stored integer atomically through the active persistent object cache.
     *
     * The envelope only exists to distinguish a miss from a stored falsy value and to guard
     * against hash collisions, so it cannot itself be the target of an atomic increment: the
     * whole serialized array sits in the object cache slot, not a bare integer. Instead the
     * authoritative value lives in a dedicated, envelope-free counter slot, adjusted through the
     * backend's own atomic increment, and is mirrored back into the envelope afterwards purely so
     * get() and put() keep reading correct data. put(), forever() and forget() clear that mirror
     * slot so the two representations can never drift apart.
     *
     * @param string $key The cache key.
     * @param int $value The amount to raise the stored value by.
     *
     * @return int|false
     *
     * @since 1.0.0
     */
    protected function increment_atomic(string $key, int $value)
    {
        $name = $this->counter_key($key);
        $group = $this->counter_group();

        $result = wp_cache_incr($name, $value, $group);

        if ($result !== false) {
            $this->sync_counter_envelope($key, (int) $result);

            return (int) $result;
        }

        // Nothing in the counter slot yet. An envelope entry may already exist for this key
        // holding a non-numeric value, in which case this is refused exactly as the
        // read-modify-write path refuses it, rather than silently starting a fresh counter.
        $entry = $this->get_entry($key);
        $seed = $value;

        if (!is_null($entry)) {
            $current = Entry::value($entry);

            if (!is_numeric($current)) {
                return false;
            }

            $seed = (int) $current + $value;
        }

        if (wp_cache_add($name, $seed, $group, $this->forever_ttl)) {
            $this->sync_counter_envelope($key, $seed);

            return $seed;
        }

        // Lost the race to seed the slot to a concurrent caller; add this call's own delta on
        // top of whichever value won.
        $result = wp_cache_incr($name, $value, $group);

        if ($result === false) {
            return false;
        }

        $this->sync_counter_envelope($key, (int) $result);

        return (int) $result;
    }

    /**
     * Mirror an atomically updated counter value back into the envelope.
     *
     * This write is not itself atomic; the counter slot that fed it is the only source of truth
     * for the arithmetic, so a lost or out of order mirror write can only make a plain get()
     * transiently stale until the next increment or decrement corrects it, never corrupt the
     * count itself.
     *
     * @param string $key The cache key.
     * @param int $value The counter's current value.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function sync_counter_envelope(string $key, int $value)
    {
        $entry = $this->get_entry($key);
        $now = $this->current_timestamp();

        if (is_null($entry)) {
            $entry = Entry::make($key, $value, $now, null);
            $seconds = $this->forever_ttl;
        } else {
            $entry['value'] = $value;

            $expires_at = $entry['expires_at'] ?? null;
            $seconds = is_null($expires_at) ? $this->forever_ttl : max(1, (int) $expires_at - $now);
        }

        $this->write_transient($this->storage_key($key), $entry, $seconds);
    }

    /**
     * Get the object cache key backing the atomic counter slot for a cache key.
     *
     * @param string $key The cache key.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function counter_key(string $key)
    {
        return $this->storage_key($key) . '_n';
    }

    /**
     * Get the object cache group the atomic counter slots are stored under.
     *
     * A dedicated group keeps these raw integers separate from the "transient" group WordPress
     * itself manages, so nothing outside increment()/decrement() ever reads or writes them.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function counter_group()
    {
        return $this->prefix . 'cache_counters';
    }

    /**
     * Clear the atomic counter slot for a cache key, if one exists.
     *
     * Called from every write path other than increment()/decrement() themselves, so a put(),
     * forever() or forget() can never leave a stale counter mirror behind.
     *
     * @param string $key The cache key.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function clear_counter(string $key)
    {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($this->counter_key($key), $this->counter_group());
        }
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
        $this->clear_counter($key);

        $name = $this->storage_key($key);

        return (bool) ($this->network ? delete_site_transient($name) : delete_transient($name));
    }

    /**
     * Remove every entry belonging to this store.
     *
     * Performed by advancing a namespace version, so that every previously written key becomes
     * unreachable in one write. The rows a superseded version leaves behind are not deleted here:
     * a DELETE that could touch an unbounded number of rows has no place inside the request that
     * called flush(), and a version a persistent object cache is currently serving has nothing to
     * delete until that cache evicts it anyway. The version is instead queued for gc() to reclaim
     * in bounded slices, retried on every run until nothing is left.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function flush()
    {
        $previous = $this->version();

        $this->write_option($this->version_option(), $previous + 1);

        $this->resolved_version = $previous + 1;

        $this->queue_stale_version($previous);

        return true;
    }

    /**
     * Determine whether a persistent object cache is serving transients.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function uses_external_object_cache()
    {
        return function_exists('wp_using_ext_object_cache') ? (bool) wp_using_ext_object_cache() : false;
    }

    /**
     * Reclaim the option rows left behind by superseded namespace versions.
     *
     * A bounded number of versions and rows per version are swept each run, so a large backlog
     * cannot time out a single call. A version is dropped from the backlog only once a run finds
     * fewer rows than its own limit for it, meaning nothing was left; a failed or still-partial
     * run leaves it queued so the next run retries it, rather than leaking the rows forever.
     *
     * @return int The number of rows removed.
     *
     * @since 1.0.0
     */
    public function gc()
    {
        if ($this->uses_external_object_cache()) {
            return 0;
        }

        $queue = $this->stale_versions();

        if (empty($queue)) {
            return 0;
        }

        $removed = 0;
        $remaining = [];

        foreach ($queue as $index => $version) {
            if ($index >= static::GC_VERSIONS_PER_RUN) {
                $remaining[] = $version;

                continue;
            }

            $deleted = $this->sweep_version_batch((int) $version, static::GC_ROWS_PER_VERSION);
            $removed += $deleted;

            if ($deleted >= static::GC_ROWS_PER_VERSION) {
                $remaining[] = $version;
            }
        }

        $this->write_option($this->stale_versions_option(), $remaining);

        return $removed;
    }

    /**
     * Record a superseded namespace version for gc() to reclaim.
     *
     * @param int $version The namespace version that was just superseded.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function queue_stale_version(int $version)
    {
        $queue = $this->stale_versions();

        if (in_array($version, $queue, true)) {
            return;
        }

        $queue[] = $version;

        $this->write_option($this->stale_versions_option(), $queue);
    }

    /**
     * Get the namespace versions still awaiting cleanup.
     *
     * @return array
     *
     * @since 1.0.0
     */
    protected function stale_versions()
    {
        $queue = $this->read_option($this->stale_versions_option(), []);

        return is_array($queue) ? array_values(array_map('intval', $queue)) : [];
    }

    /**
     * Get the option name holding the backlog of superseded namespace versions.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function stale_versions_option()
    {
        return $this->prefix . 'cache_stale_versions';
    }

    /**
     * Delete a bounded number of option rows belonging to a superseded namespace version.
     *
     * Site scoped stores and network scoped stores on an installation that is not genuine
     * Multisite both keep their rows in the options table, under the "_transient_" and
     * "_site_transient_" prefixes respectively. A network scoped store on real Multisite instead
     * keeps them in the network's site meta table, because that is where set_site_transient()
     * itself writes once a network exists to share them across.
     *
     * @param int $version The namespace version to remove.
     * @param int $limit The maximum number of rows to delete in this call.
     *
     * @return int The number of rows removed.
     *
     * @since 1.0.0
     */
    protected function sweep_version_batch(int $version, int $limit)
    {
        $db = DB::get_db();

        if (!is_object($db) || !method_exists($db, 'esc_like')) {
            return 0;
        }

        $namespace = $this->prefix . 'c' . $version . '_';

        try {
            if (!$this->network) {
                return $this->delete_option_rows_batch(
                    $db,
                    '_transient_' . $namespace,
                    '_transient_timeout_' . $namespace,
                    $limit
                );
            }

            if (function_exists('is_multisite') && is_multisite() && isset($db->sitemeta)) {
                return $this->delete_sitemeta_rows_batch(
                    $db,
                    '_site_transient_' . $namespace,
                    '_site_transient_timeout_' . $namespace,
                    $limit
                );
            }

            return $this->delete_option_rows_batch(
                $db,
                '_site_transient_' . $namespace,
                '_site_transient_timeout_' . $namespace,
                $limit
            );
        } catch (Throwable $exception) {
            // Left in the backlog; the next gc() run tries again.
            return 0;
        }
    }

    /**
     * Delete a bounded number of rows from the site options table.
     *
     * @param mixed $db The database connection.
     * @param string $value_prefix The option name prefix identifying the value rows.
     * @param string $timeout_prefix The option name prefix identifying the timeout rows.
     * @param int $limit The maximum number of rows to delete.
     *
     * @return int
     *
     * @since 1.0.0
     */
    protected function delete_option_rows_batch($db, string $value_prefix, string $timeout_prefix, int $limit)
    {
        return (int) DB::affecting_statement(
            "DELETE FROM {$db->options} WHERE (option_name LIKE %s OR option_name LIKE %s) LIMIT %d",
            [
                $db->esc_like($value_prefix) . '%',
                $db->esc_like($timeout_prefix) . '%',
                $limit,
            ]
        );
    }

    /**
     * Delete a bounded number of rows from the network's site meta table.
     *
     * @param mixed $db The database connection.
     * @param string $value_prefix The meta key prefix identifying the value rows.
     * @param string $timeout_prefix The meta key prefix identifying the timeout rows.
     * @param int $limit The maximum number of rows to delete.
     *
     * @return int
     *
     * @since 1.0.0
     */
    protected function delete_sitemeta_rows_batch($db, string $value_prefix, string $timeout_prefix, int $limit)
    {
        $network_id = function_exists('get_current_network_id') ? (int) get_current_network_id() : 1;

        return (int) DB::affecting_statement(
            "DELETE FROM {$db->sitemeta} WHERE site_id = %d AND (meta_key LIKE %s OR meta_key LIKE %s) LIMIT %d",
            [
                $network_id,
                $db->esc_like($value_prefix) . '%',
                $db->esc_like($timeout_prefix) . '%',
                $limit,
            ]
        );
    }

    /**
     * Get the current namespace version.
     *
     * @return int
     *
     * @since 1.0.0
     */
    protected function version()
    {
        if (is_null($this->resolved_version)) {
            $this->resolved_version = max(1, (int) $this->read_option($this->version_option(), 1));
        }

        return $this->resolved_version;
    }

    /**
     * Get the option name holding the namespace version.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function version_option()
    {
        return $this->prefix . 'cache_version';
    }

    /**
     * Read a transient, honouring the network scope of this store.
     *
     * @param string $name The transient name.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    protected function read_transient(string $name)
    {
        return $this->network ? get_site_transient($name) : get_transient($name);
    }

    /**
     * Write a transient, honouring the network scope of this store.
     *
     * @param string $name The transient name.
     * @param mixed $value The value to store.
     * @param int $seconds The lifetime in seconds.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    protected function write_transient(string $name, $value, int $seconds)
    {
        if ($this->network) {
            return (bool) set_site_transient($name, $value, $seconds);
        }

        return (bool) set_transient($name, $value, $seconds);
    }

    /**
     * Read an option, honouring the network scope of this store.
     *
     * @param string $name The option name.
     * @param mixed $default The value returned when the option is absent.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    protected function read_option(string $name, $default = null)
    {
        return $this->network ? get_site_option($name, $default) : get_option($name, $default);
    }

    /**
     * Write an option, honouring the network scope of this store.
     *
     * @param string $name The option name.
     * @param mixed $value The value to store.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    protected function write_option(string $name, $value)
    {
        if ($this->network) {
            return (bool) update_site_option($name, $value);
        }

        return (bool) update_option($name, $value, false);
    }
}
