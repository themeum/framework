<?php
/**
 * Fire and register WordPress actions under names that say what the call does.
 * The fire-side counterpart to BaseHook: `fire` returns nothing, unlike
 * FilterHook::apply(), so a call reads differently from a filter at a glance.
 * Each method is a pure pass-through to the matching WordPress core function.
 *
 * @package    Framework
 * @subpackage Wordpress
 * @since      3.3.5
 */
namespace Framework\Wordpress;

defined('ABSPATH') || exit;

class ActionHook
{
    /**
     * Fire an action, running every callback registered on it.
     *
     * @param string $hook_name The action to fire.
     * @param mixed  $args      The arguments passed to each callback.
     *
     * @return void
     *
     * @since 3.3.5
     */
    public static function fire(string $hook_name, ...$args)
    {
        do_action($hook_name, ...$args);
    }

    /**
     * Register a callback on an action.
     *
     * @param string   $hook_name     The action to listen on.
     * @param callable $callback      The callback to run when the action fires.
     * @param int      $priority      Lower numbers run earlier.
     * @param int      $accepted_args The number of arguments the callback accepts.
     *
     * @return true
     *
     * @since 3.3.5
     */
    public static function add(string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1)
    {
        return add_action($hook_name, $callback, $priority, $accepted_args);
    }
}
