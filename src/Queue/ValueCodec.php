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

use Closure;
use DateInterval;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Framework\Collections\Collection as BaseCollection;
use Framework\Contracts\SomoyInterface;
use Framework\Database\Query\Collection;
use Framework\Database\Query\Model;
use Framework\Exceptions\QueueException;
use ReflectionClass;
use ReflectionProperty;
use stdClass;

class ValueCodec
{
    /**
     * The key that marks a node as a wrapped value rather than a plain list.
     *
     * @var string
     *
     * @since 3.2.2
     */
    protected const TAG = '@';

    /**
     * The job class the payload belongs to, lower-cased.
     *
     * @var string
     *
     * @since 3.2.2
     */
    protected $root;

    /**
     * The extra classes the job declares as allowed, lower-cased.
     *
     * @var string[]
     *
     * @since 3.2.2
     */
    protected $allowed = [];

    /**
     * The ids of the objects currently being encoded, used to refuse circular references.
     *
     * @var array
     *
     * @since 3.2.2
     */
    protected $stack = [];

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
        $this->root = static::normalize($root);

        foreach ($allowed_classes as $class) {
            if (is_string($class)) {
                $this->allowed[] = static::normalize($class);
            }
        }
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
     * Encode a value into a JSON-safe tree.
     *
     * @param mixed $value The value.
     * @param string $path Where the value sits, for error messages.
     *
     * @return mixed
     *
     * @throws \Framework\Exceptions\QueueException When the value cannot be stored.
     *
     * @since 3.2.2
     */
    public function encode($value, string $path)
    {
        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? $value : [static::TAG => 'float', 'v' => (string) $value];
        }

        if (is_string($value)) {
            return preg_match('//u', $value) ? $value : [static::TAG => 'bin', 'v' => base64_encode($value)];
        }

        if (is_array($value)) {
            return $this->encode_array($value, $path);
        }

        if (is_object($value)) {
            return $this->encode_object($value, $path);
        }

        throw QueueException::unsupported_value($path, gettype($value));
    }

    /**
     * Decode a tree produced by encode().
     *
     * @param mixed $node The stored node.
     *
     * @return mixed
     *
     * @throws \Framework\Exceptions\QueueException When the tree is malformed or names a class that is not allowed.
     *
     * @since 3.2.2
     */
    public function decode($node)
    {
        if (!is_array($node)) {
            return $node;
        }

        if (!array_key_exists(static::TAG, $node)) {
            if ($node !== [] && array_keys($node) !== range(0, count($node) - 1)) {
                throw QueueException::invalid_payload('a stored list has unexpected keys.');
            }

            return array_map([$this, 'decode'], $node);
        }

        switch ($node[static::TAG]) {
            case 'array':
                return $this->decode_array($node);
            case 'bin':
                return $this->decode_binary($node);
            case 'float':
                return $this->decode_float($node);
            case 'date':
                return $this->decode_date($node);
            case 'interval':
                return $this->decode_interval($node);
            case 'object':
                return $this->decode_object($node);
        }

        throw QueueException::invalid_payload('a stored value has an unknown type.');
    }

    /**
     * Normalize a class name for comparison.
     *
     * @param string $class The class name.
     *
     * @return string
     *
     * @since 3.2.2
     */
    protected static function normalize(string $class)
    {
        return strtolower(ltrim($class, '\\'));
    }

    /**
     * Determine whether a class may be created from a stored row.
     *
     * @param string $class The class name from the payload.
     *
     * @return bool
     *
     * @since 3.2.2
     */
    protected function is_allowed(string $class)
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', ltrim($class, '\\'))) {
            return false;
        }

        $name = static::normalize($class);

        if ($name === $this->root || in_array($name, $this->allowed, true)) {
            return true;
        }

        if (in_array($name, [static::normalize(ModelIdentifier::class), 'stdclass'], true)) {
            return true;
        }

        return is_a($class, Model::class, true)
            || is_a($class, BaseCollection::class, true)
            || is_a($class, Collection::class, true);
    }

    /**
     * Determine whether a date class may be created from a stored row.
     *
     * @param string $class The class name from the payload.
     *
     * @return bool
     *
     * @since 3.2.2
     */
    protected function is_allowed_date(string $class)
    {
        return $this->is_allowed($class)
            || in_array(static::normalize($class), ['datetime', 'datetimeimmutable'], true)
            || (preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/', ltrim($class, '\\'))
                && is_a($class, SomoyInterface::class, true));
    }

    /**
     * Encode an array, keeping plain lists readable and wrapping the rest as key and value pairs.
     *
     * @param array $value The array.
     * @param string $path Where the array sits.
     *
     * @return array
     *
     * @since 3.2.2
     */
    protected function encode_array(array $value, string $path)
    {
        if ($value === [] || array_keys($value) === range(0, count($value) - 1)) {
            $list = [];

            foreach ($value as $key => $item) {
                $list[] = $this->encode($item, $path . '[' . $key . ']');
            }

            return $list;
        }

        $pairs = [];

        foreach ($value as $key => $item) {
            $pairs[] = [$this->encode($key, $path), $this->encode($item, $path . '[' . $key . ']')];
        }

        return [static::TAG => 'array', 'v' => $pairs];
    }

    /**
     * Encode an object.
     *
     * @param object $value The object.
     * @param string $path Where the object sits.
     *
     * @return array
     *
     * @throws \Framework\Exceptions\QueueException When the object's class is not allowed or it refers to itself.
     *
     * @since 3.2.2
     */
    protected function encode_object($value, string $path)
    {
        if ($value instanceof Closure) {
            throw QueueException::unsupported_value($path, 'Closure');
        }

        if ($value instanceof DateTimeInterface) {
            return $this->encode_date($value, $path);
        }

        if ($value instanceof DateInterval) {
            return [static::TAG => 'interval', 'v' => $value->format('%R|%y|%m|%d|%h|%i|%s|%f')];
        }

        $class = get_class($value);

        if (!$this->is_allowed($class)) {
            throw QueueException::unsupported_value($path, $class);
        }

        $id = spl_object_id($value);

        if (isset($this->stack[$id])) {
            throw QueueException::unsupported_value($path, 'a circular reference to ' . $class);
        }

        $this->stack[$id] = true;

        try {
            $map = [];

            foreach ($this->read_state($value) as $key => $item) {
                $label = preg_replace('/^\0[^\0]*\0/', '', (string) $key);
                $map[(string) $key] = $this->encode($item, $path . '->' . $label);
            }
        } finally {
            unset($this->stack[$id]);
        }

        return [static::TAG => 'object', 'c' => $class, 'p' => $map];
    }

    /**
     * Encode a date with its zone and microseconds.
     *
     * @param \DateTimeInterface $value The date.
     * @param string $path Where the date sits.
     *
     * @return array
     *
     * @throws \Framework\Exceptions\QueueException When the date class is not allowed.
     *
     * @since 3.2.2
     */
    protected function encode_date(DateTimeInterface $value, string $path)
    {
        $class = get_class($value);

        if (!$this->is_allowed_date($class)) {
            throw QueueException::unsupported_value($path, $class);
        }

        return [
            static::TAG => 'date',
            'c' => $class,
            'v' => $value->format('Y-m-d\TH:i:s.u'),
            'z' => $value->getTimezone()->getName(),
        ];
    }

    /**
     * Read an object's state the way PHP serialization keys it.
     *
     * @param object $object The object.
     *
     * @return array
     *
     * @since 3.2.2
     */
    protected function read_state($object)
    {
        if ($object instanceof stdClass) {
            return get_object_vars($object);
        }

        if (method_exists($object, '__serialize')) {
            return $object->__serialize();
        }

        $state = [];

        foreach ($this->get_properties($object) as $key => $property) {
            if ($property->isInitialized($object)) {
                $state[$key] = $property->getValue($object);
            }
        }

        return $state;
    }

    /**
     * Get an object's non-static properties, parents included, keyed as PHP mangles them.
     *
     * @param object $object The object.
     *
     * @return \ReflectionProperty[]
     *
     * @since 3.2.2
     */
    protected function get_properties($object)
    {
        $properties = [];
        $reflection = new ReflectionClass($object);

        do {
            foreach ($reflection->getProperties() as $property) {
                if ($property->isStatic() || $property->getDeclaringClass()->getName() !== $reflection->getName()) {
                    continue;
                }

                $property->setAccessible(true);
                $properties[static::mangle($property)] = $property;
            }
        } while ($reflection = $reflection->getParentClass());

        return $properties;
    }

    /**
     * Get the key PHP's native serialization uses for a property.
     *
     * @param \ReflectionProperty $property The property.
     *
     * @return string
     *
     * @since 3.2.2
     */
    protected static function mangle(ReflectionProperty $property)
    {
        if ($property->isPrivate()) {
            return "\0" . $property->getDeclaringClass()->getName() . "\0" . $property->getName();
        }

        return $property->isProtected() ? "\0*\0" . $property->getName() : $property->getName();
    }

    /**
     * Decode a wrapped keyed array.
     *
     * @param array $node The node.
     *
     * @return array
     *
     * @throws \Framework\Exceptions\QueueException When the node is malformed.
     *
     * @since 3.2.2
     */
    protected function decode_array(array $node)
    {
        if (!isset($node['v']) || !is_array($node['v'])) {
            throw QueueException::invalid_payload('a stored array is malformed.');
        }

        $result = [];

        foreach ($node['v'] as $pair) {
            if (!is_array($pair) || count($pair) !== 2 || !array_key_exists(0, $pair) || !array_key_exists(1, $pair)) {
                throw QueueException::invalid_payload('a stored array entry is malformed.');
            }

            $key = $this->decode($pair[0]);

            if (!is_int($key) && !is_string($key)) {
                throw QueueException::invalid_payload('a stored array key is not an integer or string.');
            }

            $result[$key] = $this->decode($pair[1]);
        }

        return $result;
    }

    /**
     * Decode a wrapped binary string.
     *
     * @param array $node The node.
     *
     * @return string
     *
     * @throws \Framework\Exceptions\QueueException When the node is malformed.
     *
     * @since 3.2.2
     */
    protected function decode_binary(array $node)
    {
        $value = isset($node['v']) && is_string($node['v']) ? base64_decode($node['v'], true) : false;

        if ($value === false) {
            throw QueueException::invalid_payload('a stored binary string is malformed.');
        }

        return $value;
    }

    /**
     * Decode a wrapped non-finite float.
     *
     * @param array $node The node.
     *
     * @return float
     *
     * @throws \Framework\Exceptions\QueueException When the node is malformed.
     *
     * @since 3.2.2
     */
    protected function decode_float(array $node)
    {
        $map = ['INF' => INF, '-INF' => -INF, 'NAN' => NAN];

        if (!isset($node['v']) || !is_string($node['v']) || !isset($map[$node['v']])) {
            throw QueueException::invalid_payload('a stored float is malformed.');
        }

        return $map[$node['v']];
    }

    /**
     * Decode a wrapped date.
     *
     * @param array $node The node.
     *
     * @return \DateTimeInterface
     *
     * @throws \Framework\Exceptions\QueueException When the node is malformed or the class is not allowed.
     *
     * @since 3.2.2
     */
    protected function decode_date(array $node)
    {
        $fields = [$node['c'] ?? null, $node['v'] ?? null, $node['z'] ?? null];

        if ($fields !== array_filter($fields, 'is_string')) {
            throw QueueException::invalid_payload('a stored date is malformed.');
        }

        if (!$this->is_allowed_date($node['c']) || !is_a($node['c'], DateTimeInterface::class, true)) {
            throw QueueException::invalid_payload(sprintf(
                'the class [%s] is not allowed in a queued payload.',
                $node['c']
            ));
        }

        $class = $node['c'];

        return new $class($node['v'], new DateTimeZone($node['z']));
    }

    /**
     * Decode a wrapped date interval.
     *
     * @param array $node The node.
     *
     * @return \DateInterval
     *
     * @throws \Framework\Exceptions\QueueException When the node is malformed.
     *
     * @since 3.2.2
     */
    protected function decode_interval(array $node)
    {
        $parts = isset($node['v']) && is_string($node['v']) ? explode('|', $node['v']) : [];

        if (count($parts) !== 8 || !in_array($parts[0], ['+', '-'], true)) {
            throw QueueException::invalid_payload('a stored date interval is malformed.');
        }

        $interval = new DateInterval('PT0S');
        $interval->invert = $parts[0] === '-' ? 1 : 0;
        $interval->y = (int) $parts[1];
        $interval->m = (int) $parts[2];
        $interval->d = (int) $parts[3];
        $interval->h = (int) $parts[4];
        $interval->i = (int) $parts[5];
        $interval->s = (int) $parts[6];
        $interval->f = (float) $parts[7] / 1000000;

        return $interval;
    }

    /**
     * Decode a wrapped object after checking its class against the allowlist.
     *
     * @param array $node The node.
     *
     * @return object
     *
     * @throws \Framework\Exceptions\QueueException When the node is malformed or the class is not allowed.
     *
     * @since 3.2.2
     */
    protected function decode_object(array $node)
    {
        $class = $node['c'] ?? null;

        if (!is_string($class) || !isset($node['p']) || !is_array($node['p'])) {
            throw QueueException::invalid_payload('a stored object is malformed.');
        }

        if (!$this->is_allowed($class)) {
            throw QueueException::invalid_payload(sprintf(
                'the class [%s] is not allowed in a queued payload.',
                $class
            ));
        }

        $values = [];

        foreach ($node['p'] as $key => $value) {
            $values[$key] = $this->decode($value);
        }

        $object = (new ReflectionClass($class))->newInstanceWithoutConstructor();

        if ($object instanceof stdClass) {
            foreach ($values as $key => $value) {
                $object->{(string) $key} = $value;
            }
        } elseif (method_exists($object, '__unserialize')) {
            $object->__unserialize($values);
        } else {
            foreach ($this->get_properties($object) as $key => $property) {
                if (array_key_exists($key, $values)) {
                    $property->setValue($object, $values[$key]);
                }
            }
        }

        return $object;
    }
}
