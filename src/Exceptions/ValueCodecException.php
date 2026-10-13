<?php
/**
 * Exception raised by the value codec when a value cannot be encoded or a stored tree cannot be
 * restored. Kept separate from the queue's exception so the cache can use the codec without its
 * errors naming the queue.
 *
 * @package    Framework
 * @subpackage Exceptions
 * @since      3.2.4
 */
namespace Framework\Exceptions;

defined('ABSPATH') || exit;

use RuntimeException;

class ValueCodecException extends RuntimeException
{
    /**
     * A stored tree is malformed or names a class that is not allowed.
     *
     * @param string $reason Why the tree was rejected.
     *
     * @return static
     *
     * @since 3.2.4
     */
    public static function invalid_payload(string $reason)
    {
        return new static(sprintf('The stored value is invalid: %s', $reason));
    }

    /**
     * A value cannot be stored because of its type or class.
     *
     * @param string $path Where the value sits.
     * @param string $type The type or class of the value.
     *
     * @return static
     *
     * @since 3.2.4
     */
    public static function unsupported_value(string $path, string $type)
    {
        return new static(sprintf(
            'Cannot store %s at [%s]. Store an ID or plain data instead, or list the class in '
            . 'allowed_classes if it is a plain value object.',
            $type,
            $path
        ));
    }
}
