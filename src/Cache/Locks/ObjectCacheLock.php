<?php
/**
 * A lock backed by the persistent object cache.
 * Relies on wp_cache_add(), which Redis and Memcached implement as a genuine atomic add, so
 * exactly one of several simultaneous callers can create the entry.
 *
 * @package    Framework
 * @subpackage Cache
 * @since      1.0.0
 */
namespace Framework\Cache\Locks;

defined('ABSPATH') || exit;

use Framework\Cache\Lock;

class ObjectCacheLock extends Lock
{
    /**
     * The object cache group the lock entries are stored under.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected $group;

    /**
     * Create an object cache backed lock.
     *
     * @param string $name The lock name.
     * @param int $seconds The number of seconds the lock survives for.
     * @param string $group The object cache group to store the entry under.
     * @param string|null $owner The owner token, or null to generate one.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function __construct(string $name, int $seconds = 60, string $group = 'framework_locks', $owner = null)
    {
        parent::__construct($name, $seconds, $owner);

        $this->group = $group;
    }

    /**
     * Attempt to acquire the lock without waiting.
     *
     * The expiry is handed to the object cache itself, so an abandoned lock disappears without
     * anything having to sweep it.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function acquire()
    {
        return (bool) wp_cache_add($this->name, $this->owner, $this->group, $this->seconds);
    }

    /**
     * Release the lock, but only when this caller still holds it.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function release()
    {
        if (!$this->is_owned_by_current_caller()) {
            return false;
        }

        return (bool) wp_cache_delete($this->name, $this->group);
    }

    /**
     * Determine whether the stored entry still carries this caller's token.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    protected function is_owned_by_current_caller()
    {
        return wp_cache_get($this->name, $this->group) === $this->owner;
    }
}
