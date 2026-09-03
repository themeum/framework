<?php
/**
 * Resolves the configured cache stores and hands out a repository for each.
 * Forwards any call it does not handle itself to the default store, so the Cache facade reads
 * as though the repository were the facade's target.
 *
 * @package    Framework
 * @subpackage Cache
 * @since      1.0.0
 */
namespace Framework\Cache;

defined('ABSPATH') || exit;

use Closure;
use Framework\Cache\Stores\ArrayStore;
use Framework\Cache\Stores\DatabaseStore;
use Framework\Cache\Stores\FileStore;
use Framework\Cache\Stores\MemoizedStore;
use Framework\Contracts\Store;
use Framework\Filesystem\Filesystem;
use Framework\Supports\Facades\Log;
use Framework\Supports\Traits\Macroable;
use InvalidArgumentException;
use Throwable;

use function Framework\app;
use function Framework\config;

class CacheManager
{
    use Macroable;

    /**
     * The resolved repositories, keyed by store name.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected $stores = [];

    /**
     * The resolved memoized repositories, keyed by store name.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected $memoized = [];

    /**
     * The drivers registered at runtime, keyed by driver name.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected $custom_drivers = [];

    /**
     * Get a repository for the given store.
     *
     * @param string|null $name The store name, or null for the default store.
     *
     * @return Repository
     *
     * @since 1.0.0
     */
    public function store($name = null)
    {
        $name = $name ?: $this->get_default_store();

        if (!isset($this->stores[$name])) {
            $this->stores[$name] = $this->resolve($name);
        }

        return $this->stores[$name];
    }

    /**
     * Get a repository for the given store.
     *
     * @param string|null $name The store name, or null for the default store.
     *
     * @return Repository
     *
     * @since 1.0.0
     */
    public function driver($name = null)
    {
        return $this->store($name);
    }

    /**
     * Get a repository that remembers values already read during this request.
     *
     * The same instance is returned for a given store, because memoization that started over on
     * every call would never accumulate anything.
     *
     * @param string|null $name The store name to decorate, or null for the default store.
     *
     * @return Repository
     *
     * @since 1.0.0
     */
    public function memo($name = null)
    {
        $name = $name ?: $this->get_default_store();

        if (!isset($this->memoized[$name])) {
            $repository = $this->store($name);

            $this->memoized[$name] = $this->repository(
                new MemoizedStore($repository->get_store(), $repository->get_name()),
                $repository->get_name(),
                $this->store_config($name)['events'] ?? true
            );
        }

        return $this->memoized[$name];
    }

    /**
     * Wrap a store in a repository.
     *
     * @param Store $store The store to wrap.
     * @param string $name The configured name of the store.
     * @param bool $events Whether cache events are dispatched for this store.
     *
     * @return Repository
     *
     * @since 1.0.0
     */
    public function repository(Store $store, string $name = 'default', bool $events = true)
    {
        return new Repository($store, $name, $events);
    }

    /**
     * Register a driver that can back a configured store.
     *
     * @param string $driver The driver name used in configuration.
     * @param Closure $callback Receives the store configuration and returns a store.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function extend(string $driver, Closure $callback)
    {
        $this->custom_drivers[$driver] = $callback;

        return $this;
    }

    /**
     * Get the name of the default store.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function get_default_store()
    {
        return (string) (config('cache.default') ?: 'database');
    }

    /**
     * Discard every resolved repository.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function forget_resolved()
    {
        $this->stores = [];
        $this->memoized = [];

        return $this;
    }

    /**
     * Get the configuration for a store, filled in with the built in defaults.
     *
     * Every key is optional, and the cache works correctly with no configuration file present.
     *
     * @param string $name The store name.
     *
     * @return array
     *
     * @throws InvalidArgumentException When the store is not configured.
     *
     * @since 1.0.0
     */
    public function store_config(string $name)
    {
        $defaults = $this->default_configuration();
        $configured = config('cache.stores');
        $configured = is_array($configured) ? $configured : [];

        if (!isset($defaults[$name]) && !isset($configured[$name])) {
            throw new InvalidArgumentException(
                sprintf('Cache store [%s] is not configured. Check the "cache.stores" configuration.', $name)
            );
        }

        return array_merge($defaults[$name] ?? [], $configured[$name] ?? []);
    }

    /**
     * Get the built in configuration for the stores shipped with the framework.
     *
     * @return array
     *
     * @since 1.0.0
     */
    protected function default_configuration()
    {
        $year = defined('YEAR_IN_SECONDS') ? YEAR_IN_SECONDS : 31536000;

        return [
            'database' => [
                'driver'      => 'database',
                'forever_ttl' => 10 * $year,
                'network'     => false,
                'gc'          => 'daily',
                'events'      => true,
            ],
            'file' => [
                'driver'   => 'file',
                'path'     => null,
                'fallback' => 'database',
                'gc'       => 'daily',
                'network'  => false,
                'events'   => true,
            ],
            'array' => [
                'driver'  => 'array',
                'network' => false,
                'events'  => false,
            ],
        ];
    }

    /**
     * Build the repository for a configured store.
     *
     * @param string $name The store name.
     *
     * @return Repository
     *
     * @throws InvalidArgumentException When the configured driver is not registered.
     *
     * @since 1.0.0
     */
    protected function resolve(string $name)
    {
        $config = $this->store_config($name);
        $driver = $config['driver'] ?? $name;

        if (isset($this->custom_drivers[$driver])) {
            return $this->repository($this->custom_drivers[$driver]($config, $name), $name, $config['events'] ?? true);
        }

        if ($driver === 'database') {
            return $this->repository($this->create_database_store($config), $name, $config['events'] ?? true);
        }

        if ($driver === 'array') {
            return $this->repository($this->create_array_store($config), $name, $config['events'] ?? true);
        }

        if ($driver === 'file') {
            return $this->create_file_repository($name, $config);
        }

        throw new InvalidArgumentException(
            sprintf('Unsupported cache driver [%s] for store [%s].', (string) $driver, $name)
        );
    }

    /**
     * Build a transient backed store.
     *
     * @param array $config The store configuration.
     *
     * @return DatabaseStore
     *
     * @since 1.0.0
     */
    protected function create_database_store(array $config)
    {
        return new DatabaseStore(
            $this->prefix(),
            (int) ($config['forever_ttl'] ?? 0),
            (bool) ($config['network'] ?? false)
        );
    }

    /**
     * Build an in memory store.
     *
     * @param array $config The store configuration.
     *
     * @return ArrayStore
     *
     * @since 1.0.0
     */
    protected function create_array_store(array $config)
    {
        return new ArrayStore((bool) ($config['network'] ?? false));
    }

    /**
     * Build a repository for the file store, diverting to another store where it cannot run.
     *
     * WordPress may select a remote filesystem transport, which would turn every cache read into
     * a network round trip, or fail to select one at all. Neither may be allowed to fail a
     * request, so the configured fallback takes over and the condition is reported instead.
     *
     * @param string $name The store name.
     * @param array $config The store configuration.
     *
     * @return Repository
     *
     * @since 1.0.0
     */
    protected function create_file_repository(string $name, array $config)
    {
        try {
            FileStore::guard_supported();

            $store = new FileStore(
                app(Filesystem::class),
                $this->prefix(),
                $config['path'] ?? null,
                (bool) ($config['network'] ?? false)
            );

            return $this->repository($store, $name, $config['events'] ?? true);
        } catch (Throwable $exception) {
            $this->report_file_store_unavailable($name, $exception);

            return $this->store((string) ($config['fallback'] ?? 'database'));
        }
    }

    /**
     * Report that the file store diverted to its fallback.
     *
     * @param string $name The store name.
     * @param Throwable $exception The reason the store could not be built.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function report_file_store_unavailable(string $name, Throwable $exception)
    {
        $message = sprintf(
            'Cache store [%s] fell back to another store: %s',
            $name,
            $exception->getMessage()
        );

        try {
            Log::warning($message);
        } catch (Throwable $ignored) {
            // A cache must never be the reason a request fails, logging included.
        }

        $app = app();

        if (function_exists('_doing_it_wrong') && method_exists($app, 'is_dev_mode') && $app->is_dev_mode()) {
            _doing_it_wrong(__METHOD__, esc_html($message), '1.0.0');
        }
    }

    /**
     * Get the application prefix used to namespace cache storage.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function prefix()
    {
        $app = app();

        return method_exists($app, 'prefix') ? (string) $app->prefix() : '';
    }

    /**
     * Run the sweep that removes expired entries from the stores that need one.
     *
     * Only stores that maintain their own storage need this: the file store for entries that
     * have not been read since expiring, and the database store for rows a flush() left behind.
     * The in memory store ends with the request and needs neither.
     *
     * @param string|null $name The store to sweep, or null for every store that supports it.
     *
     * @return int The number of entries removed.
     *
     * @since 1.0.0
     */
    public function collect_garbage($name = null)
    {
        $names = is_null($name) ? $this->sweepable_stores() : [$name];
        $removed = 0;

        foreach ($names as $store_name) {
            $store = $this->store($store_name)->get_store();

            if (method_exists($store, 'gc')) {
                $removed += (int) $store->gc();
            }
        }

        return $removed;
    }

    /**
     * Get the names of the configured stores that maintain their own storage.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function sweepable_stores()
    {
        $configured = config('cache.stores');
        $configured = is_array($configured) ? $configured : [];

        $names = array_unique(array_merge(array_keys($this->default_configuration()), array_keys($configured)));

        return array_values(array_filter($names, function ($store_name) {
            $config = $this->store_config($store_name);
            $driver = $config['driver'] ?? $store_name;

            if (!in_array($driver, ['file', 'database'], true)) {
                return false;
            }

            return ($config['gc'] ?? 'daily') !== false;
        }));
    }

    /**
     * Forward any other call to the default store's repository.
     *
     * @param string $method The method being called.
     * @param array $arguments The arguments passed to the method.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public function __call($method, $arguments)
    {
        return $this->store()->{$method}(...$arguments);
    }
}
