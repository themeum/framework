<?php
/**
 * Turns a job's property values into a JSON-safe tree and back without PHP object deserialization.
 * Restoring an object never runs its constructor, but the object's destructor still runs when it
 * is released, so the class allowlist checked before any instantiation is the real security
 * boundary: only the job class, a small set of framework types, and the classes a job declares
 * in its allowed_classes property can ever be created from a stored row.
 *
 * @package    Framework
 * @subpackage Queue
 * @since      3.2.2
 */
namespace Framework\Queue;

defined('ABSPATH') || exit;

use Framework\Exceptions\QueueException;
use Framework\Supports\ValueCodec as BaseValueCodec;
use ReflectionClass;

class ValueCodec extends BaseValueCodec
{
    /**
     * The job class the payload belongs to, lower-cased.
     *
     * @var string
     *
     * @since 3.2.2
     */
    protected $root;

    /**
     * Create a codec for one job class.
     *
     * @param string $root The job class.
     * @param array $allowed_classes The extra classes the job allows.
     *
     * @return void
     *
     * @since 3.2.2
     */
    public function __construct(string $root, array $allowed_classes = [])
    {
        parent::__construct($allowed_classes);

        $this->root = static::normalize($root);
    }

    /**
     * Create a codec using the allowed_classes a job class declares, if any.
     *
     * The value is read from the class defaults, never from stored data.
     *
     * @param string $class The job class.
     *
     * @return static
     *
     * @since 3.2.2
     */
    public static function for_job(string $class)
    {
        $allowed = (new ReflectionClass($class))->getDefaultProperties()['allowed_classes'] ?? [];

        return new static($class, is_array($allowed) ? $allowed : []);
    }

    /**
     * Determine whether a class may be created from a stored row.
     *
     * The job class itself and the identifier models are swapped for are allowed on top of the
     * built-in set.
     *
     * @param string $class The class name from the payload.
     *
     * @return bool
     *
     * @since 3.2.2
     */
    protected function is_allowed(string $class)
    {
        if (parent::is_allowed($class)) {
            return true;
        }

        return in_array(static::normalize($class), [$this->root, static::normalize(ModelIdentifier::class)], true);
    }

    /**
     * Build the exception for a value that cannot be stored.
     *
     * @param string $path Where the value sits.
     * @param string $type The type or class of the value.
     *
     * @return QueueException
     *
     * @since 3.2.4
     */
    protected function unsupported_value(string $path, string $type)
    {
        return QueueException::unsupported_value($path, $type);
    }

    /**
     * Build the exception for a stored tree that cannot be restored.
     *
     * @param string $reason Why the tree was rejected.
     *
     * @return QueueException
     *
     * @since 3.2.4
     */
    protected function invalid_payload(string $reason)
    {
        return QueueException::invalid_payload($reason);
    }
}
