<?php
/**
 * A limit that never rejects.
 * Returned by Limit::none(). The throttle middleware recognises it and skips counting entirely,
 * so an unlimited route writes no counter state at all.
 *
 * @package    Framework
 * @subpackage RateLimiting
 * @since      1.0.0
 */
namespace Framework\RateLimiting;

defined('ABSPATH') || exit;

class Unlimited extends Limit
{
    /**
     * Create an unlimited limit.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function __construct()
    {
        parent::__construct(PHP_INT_MAX, 60);
    }
}
