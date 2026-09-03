<?php
/**
 * The envelope every cache store persists in place of a bare value.
 * Carries the value together with its originating key and lifetime metadata, so that a miss stays
 * distinguishable from a stored false and a hash collision degrades to a miss.
 *
 * @package    Framework
 * @subpackage Cache
 * @since      1.0.0
 */
namespace Framework\Cache;

defined('ABSPATH') || exit;

class Entry
{
    /**
     * The array key marking a stored value as a cache envelope.
     *
     * @var string
     *
     * @since 1.0.0
     */
    public const MARKER = '__framework_cache';

    /**
     * The envelope format version.
     *
     * An envelope written by a different version is treated as a miss rather than being
     * interpreted, so the format can change without serving wrong values.
     *
     * @var int
     *
     * @since 1.0.0
     */
    public const FORMAT_VERSION = 1;

    /**
     * Build an envelope for the given value.
     *
     * @param string $key The cache key the value was stored under.
     * @param mixed $value The value being stored.
     * @param int $created_at The moment the entry is being written, as a UTC timestamp.
     * @param int|null $expires_at The moment the entry stops being readable, or null to never expire.
     * @param int|null $fresh_until The moment the entry stops being fresh, for stale while revalidate reads.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public static function make(string $key, $value, int $created_at, $expires_at = null, $fresh_until = null)
    {
        return [
            static::MARKER => static::FORMAT_VERSION,
            'key'          => $key,
            'value'        => $value,
            'created_at'   => $created_at,
            'expires_at'   => $expires_at,
            'fresh_until'  => $fresh_until,
        ];
    }

    /**
     * Interpret a raw stored value as an envelope belonging to the given key.
     *
     * Anything that is not a recognised envelope for this exact key is a miss. That covers a
     * value written by something other than the cache, an envelope in an unknown format, and an
     * entry recovered under a colliding hashed key.
     *
     * @param mixed $raw The raw value read back from the store.
     * @param string $key The cache key that was requested.
     *
     * @return array|null
     *
     * @since 1.0.0
     */
    public static function read($raw, string $key)
    {
        if (!is_array($raw) || !isset($raw[static::MARKER])) {
            return null;
        }

        if ($raw[static::MARKER] !== static::FORMAT_VERSION) {
            return null;
        }

        if (!array_key_exists('key', $raw) || $raw['key'] !== $key) {
            return null;
        }

        return $raw;
    }

    /**
     * Determine whether the entry has passed its expiry.
     *
     * @param array $entry The envelope.
     * @param int $now The current UTC timestamp.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public static function has_expired(array $entry, int $now)
    {
        $expires_at = $entry['expires_at'] ?? null;

        if (is_null($expires_at)) {
            return false;
        }

        return $now >= (int) $expires_at;
    }

    /**
     * Determine whether the entry is still within its freshness window.
     *
     * An entry with no freshness boundary is fresh for as long as it has not expired.
     *
     * @param array $entry The envelope.
     * @param int $now The current UTC timestamp.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public static function is_fresh(array $entry, int $now)
    {
        $fresh_until = $entry['fresh_until'] ?? null;

        if (is_null($fresh_until)) {
            return !static::has_expired($entry, $now);
        }

        return $now < (int) $fresh_until;
    }

    /**
     * Get the value carried by the envelope.
     *
     * @param array $entry The envelope.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public static function value(array $entry)
    {
        return $entry['value'] ?? null;
    }
}
