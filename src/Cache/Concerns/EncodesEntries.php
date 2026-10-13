<?php
/**
 * Envelope encoding shared by the persistent cache stores.
 * Stores the envelope as JSON with only its value passed through the value codec, so no stored
 * entry is ever handed to PHP object deserialization. The envelope is checked against the
 * requested key before the value is decoded, so an entry for another key never creates an object.
 *
 * @package    Framework
 * @subpackage Cache\Concerns
 * @since      3.2.4
 */
namespace Framework\Cache\Concerns;

defined('ABSPATH') || exit;

use Framework\Cache\Entry;
use Framework\Supports\ValueCodec;
use Throwable;

trait EncodesEntries
{
    /**
     * The codec values are encoded with.
     *
     * @var ValueCodec
     *
     * @since 3.2.4
     */
    protected $codec;

    /**
     * Encode an envelope as the JSON string that is stored.
     *
     * @param array $entry The envelope.
     * @param string $key The cache key, named in the error when the value cannot be stored.
     *
     * @return string|false
     *
     * @throws \Framework\Exceptions\ValueCodecException When the value holds something that cannot be stored.
     *
     * @since 3.2.4
     */
    protected function encode_entry(array $entry, string $key)
    {
        $entry['value'] = $this->codec->encode($entry['value'] ?? null, $key);

        $json = wp_json_encode($entry, JSON_PRESERVE_ZERO_FRACTION);

        return is_string($json) ? $json : false;
    }

    /**
     * Decode a stored JSON string into the envelope for the given key.
     *
     * @param mixed $raw The stored value.
     * @param string $key The cache key that was requested.
     *
     * @return array|null
     *
     * @since 3.2.4
     */
    protected function decode_entry($raw, string $key)
    {
        if (!is_string($raw)) {
            return null;
        }

        return $this->restore_entry(json_decode($raw, true), $key);
    }

    /**
     * Restore the value of an already parsed envelope for the given key.
     *
     * Any failure while restoring is a miss rather than an error, because a cache must never be
     * the reason a request fails. That includes a type mismatch on a typed property of an allowed
     * class whose shape has changed since the entry was written.
     *
     * @param mixed $envelope The parsed envelope.
     * @param string $key The cache key that was requested.
     *
     * @return array|null
     *
     * @since 3.2.4
     */
    protected function restore_entry($envelope, string $key)
    {
        $entry = Entry::read($envelope, $key);

        if (is_null($entry)) {
            return null;
        }

        try {
            $entry['value'] = $this->codec->decode($entry['value'] ?? null);
        } catch (Throwable $exception) {
            return null;
        }

        return $entry;
    }
}
