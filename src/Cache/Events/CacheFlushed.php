<?php
/**
 * Dispatched when every entry of a cache store is removed.
 * Carries only the store name, because a flush concerns no individual key.
 *
 * @package    Framework
 * @subpackage Cache\Events
 * @since      1.0.0
 */
namespace Framework\Cache\Events;

defined('ABSPATH') || exit;

class CacheFlushed
{
    /**
     * The name of the store that was flushed.
     *
     * @var string
     *
     * @since 1.0.0
     */
    public $store;

    /**
     * Create a new event instance.
     *
     * @param string $store The name of the store that was flushed.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function __construct(string $store)
    {
        $this->store = $store;
    }
}
