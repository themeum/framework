<?php
/**
 * Dispatched when a value is written to the cache.
 * Carries the store the operation ran against and the cache key it concerned.
 *
 * @package    Framework
 * @subpackage Cache\Events
 * @since      1.0.0
 */
namespace Framework\Cache\Events;

defined('ABSPATH') || exit;

class KeyWritten
{
    /**
     * The name of the store the operation ran against.
     *
     * @var string
     *
     * @since 1.0.0
     */
    public $store;

    /**
     * The cache key the operation concerned.
     *
     * @var string
     *
     * @since 1.0.0
     */
    public $key;

    /**
     * The value the operation concerned.
     *
     * @var mixed
     *
     * @since 1.0.0
     */
    public $value;

    /**
     * Create a new event instance.
     *
     * @param string $store The name of the store the operation ran against.
     * @param string $key The cache key the operation concerned.
     * @param mixed $value The value that was written.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function __construct(string $store, string $key, $value = null)
    {
        $this->store = $store;
        $this->key = $key;
        $this->value = $value;
    }
}
