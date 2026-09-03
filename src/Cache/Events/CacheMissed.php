<?php
/**
 * Dispatched when a cache key is read and not found.
 * Carries the store the operation ran against and the cache key it concerned.
 *
 * @package    Framework
 * @subpackage Cache\Events
 * @since      1.0.0
 */
namespace Framework\Cache\Events;

defined('ABSPATH') || exit;

class CacheMissed
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
     * Create a new event instance.
     *
     * @param string $store The name of the store the operation ran against.
     * @param string $key The cache key the operation concerned.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function __construct(string $store, string $key)
    {
        $this->store = $store;
        $this->key = $key;
    }
}
