<?php
/**
 * Time handling shared by the cache repository and its stores.
 * Every reading of the clock goes through one overridable method so expiry can be exercised in
 * tests without sleeping, and so lifetimes are always computed against the UTC epoch.
 *
 * @package    Framework
 * @subpackage Cache\Concerns
 * @since      1.0.0
 */
namespace Framework\Cache\Concerns;

defined('ABSPATH') || exit;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;

trait InteractsWithTime
{
    /**
     * Get the current moment as a UTC timestamp.
     *
     * WordPress transients compute their own expiry from time(), so this must stay on the same
     * scale. current_time() returns a timezone shifted value and must never be used here.
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
     * Resolve a time to live expressed in any accepted form into a number of seconds.
     *
     * @param mixed $ttl Seconds, a date and time, an interval, or null to never expire.
     *
     * @return int|null The number of seconds, or null when the entry should not expire.
     *
     * @since 1.0.0
     */
    protected function seconds_until($ttl)
    {
        if (is_null($ttl)) {
            return null;
        }

        if ($ttl instanceof DateInterval) {
            $ttl = (new DateTimeImmutable('@' . $this->current_timestamp()))->add($ttl);
        }

        if ($ttl instanceof DateTimeInterface) {
            return $ttl->getTimestamp() - $this->current_timestamp();
        }

        return (int) $ttl;
    }

    /**
     * Turn a number of seconds into an absolute expiry timestamp.
     *
     * @param int|null $seconds The lifetime in seconds, or null to never expire.
     *
     * @return int|null
     *
     * @since 1.0.0
     */
    protected function expiry_from_seconds($seconds)
    {
        if (is_null($seconds)) {
            return null;
        }

        return $this->current_timestamp() + (int) $seconds;
    }
}
