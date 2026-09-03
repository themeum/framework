<?php
/**
 * Facade proxy for CacheManager exposing the cache API statically.
 * Calls not handled by the manager itself are forwarded to the default store's repository.
 * Primary cache entry point for application code.
 *
 * @package    Framework
 * @subpackage Supports\Facades
 * @since      1.0.0
 */
namespace Framework\Supports\Facades;

defined('ABSPATH') || exit;

use Framework\Facade;

// phpcs:disable Generic.Files.LineLength.TooLong

/**
 * Facade proxy for CacheManager exposing the cache API statically.
 *
 * @method static \Framework\Cache\Repository store(string|null $name = null)
 * @method static \Framework\Cache\Repository driver(string|null $name = null)
 * @method static \Framework\Cache\Repository memo(string|null $name = null)
 * @method static \Framework\Cache\Repository repository(\Framework\Contracts\Store $store, string $name = 'default', bool $events = true)
 * @method static \Framework\Cache\CacheManager extend(string $driver, \Closure $callback)
 * @method static string get_default_store()
 * @method static array store_config(string $name)
 * @method static int collect_garbage(string|null $name = null)
 * @method static \Framework\Contracts\Lock lock(string $name, int $seconds = 60, string|null $owner = null)
 * @method static bool uses_external_object_cache()
 * @method static array sweepable_stores()
 * @method static mixed get(string $key, $default = null)
 * @method static array many(array $keys)
 * @method static array get_multiple(iterable $keys, $default = null)
 * @method static bool has(string $key)
 * @method static bool missing(string $key)
 * @method static mixed pull(string $key, $default = null)
 * @method static string string(string $key, $default = null)
 * @method static int integer(string $key, $default = null)
 * @method static float float(string $key, $default = null)
 * @method static bool boolean(string $key, $default = null)
 * @method static array array(string $key, $default = null)
 * @method static bool put(string $key, $value, $ttl = null)
 * @method static bool set(string $key, $value, $ttl = null)
 * @method static bool put_many(array $values, $ttl = null)
 * @method static bool set_multiple(iterable $values, $ttl = null)
 * @method static bool add(string $key, $value, $ttl = null)
 * @method static bool forever(string $key, $value)
 * @method static bool touch(string $key, $ttl)
 * @method static int|false increment(string $key, int $value = 1)
 * @method static int|false decrement(string $key, int $value = 1)
 * @method static mixed remember(string $key, $ttl, \Closure $callback)
 * @method static mixed remember_forever(string $key, \Closure $callback)
 * @method static mixed flexible(string $key, array $ttl, \Closure $callback)
 * @method static bool forget(string $key)
 * @method static bool delete(string $key)
 * @method static bool delete_multiple(iterable $keys)
 * @method static bool flush()
 * @method static bool clear()
 * @method static \Framework\Contracts\Store get_store()
 * @method static string get_name()
 * @method static string get_prefix()
 * @method static int get_default_cache_time()
 * @method static \Framework\Cache\Repository set_default_cache_time(int $seconds)
 * @method static bool supports_tags()
 * @see    \Framework\Cache\CacheManager
 * @see    \Framework\Cache\Repository
 */
class Cache extends Facade
{
    /**
     * Get the accessor.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public static function get_accessor()
    {
        return 'cache';
    }
}
