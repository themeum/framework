<?php
/**
 * Contract for an atomic, self expiring lock.
 * Describes only the operations a lock backend must provide; the cache manager decides which
 * backend a site can actually support.
 *
 * @package    Framework
 * @subpackage Contracts
 * @since      1.0.0
 */
namespace Framework\Contracts;

defined('ABSPATH') || exit;

use Closure;

interface Lock
{
    /**
     * Attempt to acquire the lock.
     *
     * Acquisition must be atomic and must never block: a caller that loses the race is told so
     * immediately rather than waiting for the holder to finish.
     *
     * When a callback is given, it is run only if the lock was acquired, and the lock is released
     * afterwards even when the callback raises.
     *
     * @param Closure|null $callback Optional work to run while holding the lock.
     *
     * @return mixed The callback result when one is given and the lock was acquired, false when
     *               the lock was not acquired, otherwise true.
     *
     * @since 1.0.0
     */
    public function get(?Closure $callback = null);

    /**
     * Release the lock.
     *
     * A release must only take effect for the caller that currently holds the lock, so that a
     * caller whose lock already expired cannot release a lock since acquired by someone else.
     *
     * @return bool True when this caller held the lock and it was released.
     *
     * @since 1.0.0
     */
    public function release();

    /**
     * Get the token identifying this caller's hold on the lock.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function owner();
}
