<?php
/**
 * Counts attempts against a key over a decaying window, backed by the framework cache.
 * Holds the registry of named limiters as well, so that a route can name a limit rather than
 * repeating its numbers.
 *
 * The window is fixed: it opens on the first counted attempt and closes a decay later, which is
 * what the Retry-After and reset headers describe.
 *
 * @package    Framework
 * @subpackage RateLimiting
 * @since      1.0.0
 */
namespace Framework\RateLimiting;

defined('ABSPATH') || exit;

use Closure;
use Framework\Supports\Traits\Macroable;

use function Framework\app;
use function Framework\config;

class RateLimiter
{
    use Macroable;

    /**
     * The number of times acquiring the counter lock is retried before giving up.
     *
     * @var int
     *
     * @since 1.0.0
     */
    public const LOCK_ATTEMPTS = 5;

    /**
     * The number of microseconds waited between attempts to acquire the counter lock.
     *
     * @var int
     *
     * @since 1.0.0
     */
    public const LOCK_WAIT_MICROSECONDS = 2000;

    /**
     * The registered named limiters, keyed by name.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected $limiters = [];

    /**
     * Register a named limiter.
     *
     * The callback receives the incoming request and returns the limit, or an array of limits,
     * that should govern it.
     *
     * @param string $name The limiter name.
     * @param Closure $callback The callback producing the limit or limits.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function for(string $name, Closure $callback)
    {
        $this->limiters[$name] = $callback;

        return $this;
    }

    /**
     * Get the callback registered for a named limiter.
     *
     * @param string $name The limiter name.
     *
     * @return Closure|null
     *
     * @since 1.0.0
     */
    public function limiter(string $name)
    {
        return $this->limiters[$name] ?? null;
    }

    /**
     * Determine whether a named limiter has been registered.
     *
     * @param string $name The limiter name.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function has_limiter(string $name)
    {
        return isset($this->limiters[$name]);
    }

    /**
     * Run a callback only when the key still has attempts left.
     *
     * @param string $key The key identifying what is being limited.
     * @param int $max_attempts The number of attempts allowed within the window.
     * @param Closure $callback The work to run.
     * @param int $decay_seconds The length of the window in seconds.
     *
     * @return mixed The callback result, true when it returned nothing, or false when the key
     *               has no attempts left.
     *
     * @since 1.0.0
     */
    public function attempt(string $key, int $max_attempts, Closure $callback, int $decay_seconds = 60)
    {
        if ($this->too_many_attempts($key, $max_attempts)) {
            return false;
        }

        $this->increment($key, $decay_seconds);

        $result = $callback();

        return is_null($result) ? true : $result;
    }

    /**
     * Determine whether a key has used up its allowance.
     *
     * @param string $key The key identifying what is being limited.
     * @param int $max_attempts The number of attempts allowed within the window.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function too_many_attempts(string $key, int $max_attempts)
    {
        if ($this->attempts($key) < $max_attempts) {
            return false;
        }

        if ($this->available_in($key) <= 0) {
            $this->clear($key);

            return false;
        }

        return true;
    }

    /**
     * Increment a key's attempt count and report the resulting value.
     *
     * Returning the resulting count lets a caller decide in one operation whether a limit is
     * exceeded, rather than reading and then writing. Where the store cannot increment atomically
     * the read and write are serialized behind a cache lock, so concurrent requests cannot lose
     * each other's attempts.
     *
     * @param string $key The key identifying what is being limited.
     * @param int $decay_seconds The length of the window in seconds.
     * @param int $amount The amount to increment by.
     *
     * @return int The attempt count after the increment.
     *
     * @since 1.0.0
     */
    public function increment(string $key, int $decay_seconds = 60, int $amount = 1)
    {
        $key = $this->clean($key);

        return (int) $this->serialized($key, function () use ($key, $decay_seconds, $amount) {
            $cache = $this->cache();
            $timer = $this->timer_key($key);

            if (!$cache->has($timer)) {
                $cache->put($timer, $this->current_timestamp() + $decay_seconds, $decay_seconds);
                $cache->put($key, 0, $decay_seconds);
            }

            $hits = $cache->increment($key, $amount);

            if ($hits === false) {
                $hits = $amount;
                $cache->put($key, $hits, $decay_seconds);
            }

            return $hits;
        });
    }

    /**
     * Get the number of attempts recorded against a key.
     *
     * @param string $key The key identifying what is being limited.
     *
     * @return int
     *
     * @since 1.0.0
     */
    public function attempts(string $key)
    {
        return (int) $this->cache()->get($this->clean($key), 0);
    }

    /**
     * Get the number of attempts still available to a key.
     *
     * @param string $key The key identifying what is being limited.
     * @param int $max_attempts The number of attempts allowed within the window.
     *
     * @return int
     *
     * @since 1.0.0
     */
    public function remaining(string $key, int $max_attempts)
    {
        return max(0, $max_attempts - $this->attempts($key));
    }

    /**
     * Get the number of attempts still available to a key.
     *
     * @param string $key The key identifying what is being limited.
     * @param int $max_attempts The number of attempts allowed within the window.
     *
     * @return int
     *
     * @since 1.0.0
     */
    public function retries_left(string $key, int $max_attempts)
    {
        return $this->remaining($key, $max_attempts);
    }

    /**
     * Get the number of seconds until a key's allowance resets.
     *
     * @param string $key The key identifying what is being limited.
     *
     * @return int
     *
     * @since 1.0.0
     */
    public function available_in(string $key)
    {
        $reset = $this->cache()->get($this->timer_key($this->clean($key)));

        if (is_null($reset)) {
            return 0;
        }

        return max(0, (int) $reset - $this->current_timestamp());
    }

    /**
     * Get the timestamp at which a key's allowance resets.
     *
     * @param string $key The key identifying what is being limited.
     *
     * @return int
     *
     * @since 1.0.0
     */
    public function available_at(string $key)
    {
        $reset = $this->cache()->get($this->timer_key($this->clean($key)));

        return is_null($reset) ? $this->current_timestamp() : (int) $reset;
    }

    /**
     * Clear every attempt recorded against a key.
     *
     * @param string $key The key identifying what is being limited.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function clear(string $key)
    {
        $key = $this->clean($key);

        $this->cache()->forget($key);
        $this->cache()->forget($this->timer_key($key));
    }

    /**
     * Clear every attempt recorded against a key.
     *
     * @param string $key The key identifying what is being limited.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function reset_attempts(string $key)
    {
        $this->clear($key);
    }

    /**
     * Get the repository the limiter counts in.
     *
     * The store is configurable independently of the application default, so counters can live
     * somewhere other than the page cache.
     *
     * @return \Framework\Cache\Repository
     *
     * @since 1.0.0
     */
    public function cache()
    {
        $store = config('cache.limiter');

        return app('cache')->store($store ?: null);
    }

    /**
     * Run a counter operation with concurrent callers held off where that is needed.
     *
     * A store that increments atomically needs no help. Otherwise the operation runs behind a
     * cache lock, retried briefly because the critical section is short. If the lock still cannot
     * be taken the operation runs anyway rather than failing the request: the result is the same
     * best effort counting the cache offers on its own, never worse.
     *
     * @param string $key The key being counted.
     * @param Closure $callback The counter operation.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    protected function serialized(string $key, Closure $callback)
    {
        $manager = app('cache');

        if ($manager->uses_external_object_cache()) {
            return $callback();
        }

        $lock = $manager->lock('ratelimiter:' . $key, 5);

        for ($attempt = 0; $attempt < static::LOCK_ATTEMPTS; $attempt++) {
            if ($lock->get()) {
                try {
                    return $callback();
                } finally {
                    $lock->release();
                }
            }

            usleep(static::LOCK_WAIT_MICROSECONDS);
        }

        return $callback();
    }

    /**
     * Get the current moment as a timestamp.
     *
     * Seamed so that tests can move the window without waiting for it.
     *
     * @return int
     *
     * @since 1.0.0
     */
    protected function current_timestamp()
    {
        return time();
    }

    /**
     * Get the key holding the moment a window resets.
     *
     * @param string $key The cleaned key identifying what is being limited.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function timer_key(string $key)
    {
        return $key . ':timer';
    }

    /**
     * Normalise a caller supplied key into one the cache can hold.
     *
     * @param string $key The key identifying what is being limited.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function clean(string $key)
    {
        $key = preg_replace('/[^a-zA-Z0-9:_.-]/', '', $key);

        if (strlen($key) > 64) {
            $key = substr($key, 0, 24) . md5($key);
        }

        return 'ratelimit:' . $key;
    }
}
