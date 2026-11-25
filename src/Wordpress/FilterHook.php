<?php
/**
 * Apply and register WordPress filters under names that say what the call does.
 * The fire-side counterpart to BaseHook: `apply` returns the filtered value,
 * unlike ActionHook::fire(), so a filter call with no assignment looks wrong.
 * Each method is a pure pass-through to the matching WordPress core function.
 *
 * @package    Framework
 * @subpackage Wordpress
 * @since      3.3.5
 */
namespace Framework\Wordpress;

defined('ABSPATH') || exit;

class FilterHook
{
    /**
     * Apply a filter to a value and return the filtered result.
     *
     * @param string $hook_name The filter to apply.
     * @param mixed  $value     The value to filter.
     * @param mixed  $args      Extra arguments passed to each callback.
     *
     * @return mixed
     *
     * @since 3.3.5
     */
    public static function apply(string $hook_name, $value, ...$args)
    {
        return apply_filters($hook_name, $value, ...$args);
    }

    /**
     * Register a callback on a filter.
     *
     * @param string   $hook_name     The filter to listen on.
     * @param callable $callback      The callback that returns the filtered value.
     * @param int      $priority      Lower numbers run earlier.
     * @param int      $accepted_args The number of arguments the callback accepts.
     *
     * @return true
     *
     * @since 3.3.5
     */
    public static function add(string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1)
    {
        return add_filter($hook_name, $callback, $priority, $accepted_args);
    }
}
