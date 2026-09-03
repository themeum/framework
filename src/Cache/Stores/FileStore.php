<?php
/**
 * Cache store writing entries beneath the WordPress uploads directory.
 * Entries are written atomically and are protected from direct web access by an unguessable
 * path, an execution guard, and the usual directory hardening files.
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
use Framework\Exceptions\StoreUnavailableException;
use Framework\Filesystem\Filesystem;
use Throwable;

class FileStore implements Store, CacheEntryProvider
{
    use HashesKeys;
    use InteractsWithTime;

    /**
     * The bytes every entry file opens with.
     *
     * A request for the file is answered with nothing when the server executes PHP, because exit
     * runs before the payload is reached. Its length is fixed so the payload can be sliced off
     * without parsing.
     *
     * @var string
     *
     * @since 1.0.0
     */
    public const GUARD = "<?php exit; ?>\n";

    /**
     * The number of shard directories a single sweep may visit.
     *
     * @var int
     *
     * @since 1.0.0
     */
    public const SWEEP_SHARDS = 16;

    /**
     * The number of entry files a single sweep may inspect.
     *
     * @var int
     *
     * @since 1.0.0
     */
    public const SWEEP_FILES = 200;

    /**
     * The filesystem.
     *
     * @var Filesystem
     *
     * @since 1.0.0
     */
    protected $files;

    /**
     * The prefix identifying this application's cache directory and options.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected $prefix;

    /**
     * The configured directory, or null to derive one beneath the uploads directory.
     *
     * @var string|null
     *
     * @since 1.0.0
     */
    protected $configured_path;

    /**
     * The resolved cache directory.
     *
     * @var string|null
     *
     * @since 1.0.0
     */
    protected $resolved_directory = null;

    /**
     * Create a new file backed store.
     *
     * @param Filesystem $files The filesystem.
     * @param string $prefix The prefix identifying this application's directory and options.
     * @param string|null $path The configured directory, or null to derive one.
     * @param bool $network Whether the store is shared across a network.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function __construct(Filesystem $files, string $prefix, $path = null, bool $network = false)
    {
        $this->files = $files;
        $this->prefix = $prefix;
        $this->configured_path = $path;
        $this->network = $network;
    }

    /**
     * Fail unless the current host can serve this store without remote transfers.
     *
     * WordPress may select an FTP or SSH transport, which would turn every cache read into a
     * network round trip, or fail to select one at all. Either case must divert to another store
     * rather than degrade or break the request.
     *
     * @return void
     *
     * @throws StoreUnavailableException When direct filesystem access is not available.
     *
     * @since 1.0.0
     */
    public static function guard_supported()
    {
        if (!function_exists('get_filesystem_method')) {
            $include = ABSPATH . 'wp-admin/includes/file.php';

            if (!is_readable($include)) {
                throw new StoreUnavailableException(
                    'The file cache store cannot determine the filesystem method on this host.'
                );
            }

            require_once $include;
        }

        $method = get_filesystem_method();

        if ($method !== 'direct') {
            throw new StoreUnavailableException(
                sprintf('The file cache store requires direct filesystem access, got [%s].', (string) $method)
            );
        }
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
     * Get the absolute path an entry is written to.
     *
     * @param string $key The cache key.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function path(string $key)
    {
        $hash = $this->storage_key($key);

        return $this->directory() . '/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash . '.php';
    }

    /**
     * Get the directory entries are written beneath.
     *
     * The name carries a salted suffix so that an entry's location cannot be derived from its
     * key alone by anyone who does not already hold the site's secrets.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function directory()
    {
        if (!is_null($this->resolved_directory)) {
            return $this->resolved_directory;
        }

        if (!is_null($this->configured_path)) {
            return $this->resolved_directory = rtrim($this->configured_path, '/\\');
        }

        $uploads = function_exists('wp_upload_dir') ? wp_upload_dir() : [];
        $base = $uploads['basedir'] ?? (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/uploads' : ABSPATH . 'uploads');

        $suffix = substr(hash_hmac('sha256', 'cache-directory', $this->key_salt()), 0, 12);

        return $this->resolved_directory = rtrim($base, '/\\') . '/' . $this->prefix . 'cache-' . $suffix;
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
        $entry = Entry::read($this->read_payload($this->path($key)), $key);

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
        $now = $this->current_timestamp();

        return $this->write_payload($this->path($key), Entry::make(
            $key,
            $value,
            $now,
            $now + $seconds,
            is_null($fresh_for) ? null : $now + (int) $fresh_for
        ));
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
        return $this->write_payload(
            $this->path($key),
            Entry::make($key, $value, $this->current_timestamp(), null)
        );
    }

    /**
     * Raise the stored integer under the given key.
     *
     * This is a read followed by a write and is therefore not atomic. Concurrent adjustments may
     * be lost. Use the query builder with a raw expression where an exact count matters.
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

        return $this->write_payload($this->path($key), $entry) ? $new : false;
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
        $path = $this->path($key);

        if (!$this->files->is_file($path)) {
            return true;
        }

        return (bool) $this->files->delete($path, false);
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
        $directory = $this->directory();

        if ($this->files->is_directory($directory)) {
            $this->files->delete($directory, true);
        }

        $this->ensure_directory();

        return true;
    }

    /**
     * Remove expired entries from a bounded slice of the cache directory.
     *
     * A full scan would time out on a large cache, so each run visits a rotating window of shard
     * directories and stops after a fixed number of files. Successive runs advance the cursor.
     * Listing the files inside a bucket goes through the filesystem's bounded scan rather than
     * glob(), which would read and sort every entry in a bucket before the per-run file cap ever
     * gets a chance to apply, spiking memory on a bucket that has accumulated many entries.
     *
     * @return int The number of entries removed.
     *
     * @since 1.0.0
     */
    public function gc()
    {
        $directory = $this->directory();

        if (!$this->files->is_directory($directory)) {
            return 0;
        }

        $shards = $this->files->glob($directory . '/*');
        $shards = is_array($shards) ? array_values(array_filter($shards, [$this->files, 'is_directory'])) : [];

        if (empty($shards)) {
            return 0;
        }

        $cursor = (int) get_option($this->cursor_option(), 0);
        $now = $this->current_timestamp();
        $removed = 0;
        $inspected = 0;

        for ($offset = 0; $offset < static::SWEEP_SHARDS; $offset++) {
            $shard = $shards[($cursor + $offset) % count($shards)];

            $buckets = $this->files->glob($shard . '/*');
            $buckets = is_array($buckets) ? array_values(array_filter($buckets, [$this->files, 'is_directory'])) : [];

            foreach ($buckets as $bucket) {
                $budget = static::SWEEP_FILES - $inspected;

                if ($budget <= 0) {
                    break 2;
                }

                $files = $this->files->scan_directory($bucket, $budget, 'php');

                foreach ($files as $file) {
                    $inspected++;

                    if ($this->payload_has_expired($file, $now)) {
                        $this->files->delete($file, false);

                        $removed++;
                    }
                }
            }
        }

        update_option($this->cursor_option(), ($cursor + static::SWEEP_SHARDS) % max(1, count($shards)), false);

        return $removed;
    }

    /**
     * Determine whether the entry file at the given path has expired.
     *
     * @param string $path The absolute path of the entry file.
     * @param int $now The current UTC timestamp.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    protected function payload_has_expired(string $path, int $now)
    {
        $payload = $this->read_payload($path);

        if (!is_array($payload) || !isset($payload[Entry::MARKER])) {
            return true;
        }

        return Entry::has_expired($payload, $now);
    }

    /**
     * Get the option name holding the sweep cursor.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function cursor_option()
    {
        return $this->prefix . 'cache_gc_cursor';
    }

    /**
     * Read and unserialize the payload stored at the given path.
     *
     * A missing entry is the ordinary case for a cache, so it resolves to null rather than
     * raising. The filesystem is checked first, and the read is still guarded because the file
     * may be removed between the two calls.
     *
     * @param string $path The absolute path of the entry file.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    protected function read_payload(string $path)
    {
        if (!$this->files->is_file($path)) {
            return null;
        }

        try {
            $contents = $this->files->get($path);
        } catch (Throwable $exception) {
            return null;
        }

        if (!is_string($contents) || strlen($contents) <= strlen(static::GUARD)) {
            return null;
        }

        $payload = @unserialize(substr($contents, strlen(static::GUARD)));

        return $payload === false ? null : $payload;
    }

    /**
     * Write a payload to the given path without ever leaving a partial file behind.
     *
     * The payload is written to a temporary name and then moved into place, which the direct
     * filesystem transport performs with rename().
     *
     * @param string $path The absolute path of the entry file.
     * @param array $entry The envelope to store.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    protected function write_payload(string $path, array $entry)
    {
        $this->ensure_directory();

        $this->files->make_dir($this->files->dirname($path));

        $temporary = $path . '.' . uniqid('tmp', true);

        if (!$this->files->put($temporary, static::GUARD . serialize($entry))) {
            return false;
        }

        if (!$this->files->move($temporary, $path)) {
            $this->files->delete($temporary, false);

            return false;
        }

        return true;
    }

    /**
     * Create the cache directory and the files protecting it from direct web access.
     *
     * Each guard covers a different server configuration, which is why all of them are written:
     * the index silences directory listing, the htaccess file is honoured by Apache and ignored
     * by nginx, and the entry files themselves carry an execution guard. The unguessable
     * directory name is the layer that holds regardless of server configuration.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function ensure_directory()
    {
        $directory = $this->directory();

        if ($this->files->is_directory($directory)) {
            return;
        }

        $this->files->make_dir($directory);

        $this->files->put($directory . '/index.php', "<?php\n// Silence is golden.\n");
        $this->files->put($directory . '/.htaccess', "Require all denied\n");
    }
}
