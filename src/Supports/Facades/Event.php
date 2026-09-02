<?php
/**
 * Facade proxy for EventManager dispatch operations.
 * Exposes dispatch, dispatch_if, and dispatch_unless as static methods.
 * Used by the Dispatchable trait and manual event firing.
 *
 * @package    Framework
 * @subpackage Supports\Facades
 * @since      1.0.0
 */
namespace Framework\Supports\Facades;

defined('ABSPATH') || exit;

use Closure;
use Framework\Facade;

/**
 * Facade proxy for EventManager dispatch operations.
 * 
 * @method static void dispatch($event)
 * @method static void dispatch_if(Closure $boolean, $event)
 * @method static void dispatch_unless(Closure $boolean, $event)
 * @method static bool has_listeners(string $event_class)
 * @see    Framework\Managers\EventManager
 */
class Event extends Facade
{
    /**
     * Get the accessor.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public static function get_accessor()
    {
        return 'event';
    }
}
