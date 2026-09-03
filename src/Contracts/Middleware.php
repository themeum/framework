<?php
/**
 * Contract for middleware that can intercept and authorize API requests.
 * Middleware classes implementing this interface should perform authorization
 * or filtering logic before a route is executed.
 *
 * @package    Framework
 * @subpackage Contracts
 * @since      1.0.0
 */
namespace Framework\Contracts;

defined('ABSPATH') || exit;

interface Middleware
{
    /**
     * Handle an incoming request and return a boolean indicating access.
     *
     * A middleware declared with arguments after a colon, such as 'throttle:60,1', receives those
     * arguments as trailing parameters. An implementation opts in by declaring them, for example
     * handle(Request $request, callable $next, ...$parameters); one that takes no arguments needs
     * no change, which is why every middleware written before arguments existed still works.
     *
     * @param Request $request The incoming request instance.
     * @param callable $next The next middleware in the chain.
     *
     * @return mixed The result of the next middleware or a redirect response.
     *
     * @since 1.0.0
     */
    public function handle(Request $request, callable $next);
}
