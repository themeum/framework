<?php
/**
 * Base for the atomic locks the cache hands out.
 * Holds the name, lifetime and owner token every backend needs, and implements the callback
 * form of acquisition once so that each backend only has to say how it acquires and releases.
 *
 * @package    Framework
 * @subpackage Cache
 * @since      1.0.0
 */
namespace Framework\Cache;

defined('ABSPATH') || exit;

use Closure;
use Framework\Contracts\Lock as LockContract;

abstract class Lock implements LockContract
{
    /**
     * The name identifying the lock.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected $name;

    /**
     * The number of seconds the lock survives before it may be acquired again.
     *
     * @var int
     *
     * @since 1.0.0
     */
    protected $seconds;

    /**
     * The token identifying this caller's hold on the lock.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected $owner;

    /**
     * Create a lock.
     *
     * The lifetime is mandatory and floored at one second: a lock without an expiry turns a
     * request that dies mid hold into a key that can never be locked again.
     *
     * @param string $name The lock name.
     * @param int $seconds The number of seconds the lock survives for.
     * @param string|null $owner The owner token, or null to generate one.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function __construct(string $name, int $seconds = 60, $owner = null)
    {
        $this->name = $name;
        $this->seconds = max(1, $seconds);
        $this->owner = $owner ?: static::generate_owner();
    }

    /**
     * Attempt to acquire the lock without waiting.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    abstract public function acquire();

    /**
     * Attempt to acquire the lock, optionally running work while holding it.
     *
     * @param Closure|null $callback Optional work to run while holding the lock.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public function get(?Closure $callback = null)
    {
        $acquired = $this->acquire();

        if (is_null($callback)) {
            return $acquired;
        }

        if (!$acquired) {
            return false;
        }

        try {
            return $callback();
        } finally {
            $this->release();
        }
    }

    /**
     * Get the token identifying this caller's hold on the lock.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function owner()
    {
        return $this->owner;
    }

    /**
     * Get the name identifying the lock.
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
     * Generate a token identifying a caller's hold on a lock.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected static function generate_owner()
    {
        if (function_exists('wp_generate_password')) {
            return wp_generate_password(20, false);
        }

        return (string) uniqid('lock', true);
    }
}
