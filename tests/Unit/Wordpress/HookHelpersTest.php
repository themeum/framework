<?php

namespace Framework\Tests\Unit\Wordpress;

use Framework\Tests\Unit\TestCase;
use Framework\Wordpress\ActionHook;
use Framework\Wordpress\FilterHook;

class HookHelpersTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['framework_test_actions']['framework_test_action_hook']);

        parent::tearDown();
    }

    public function test_filter_apply_returns_the_value_from_a_callback_added_with_add(): void
    {
        FilterHook::add('framework_test_filter_hook', function ($value, $suffix) {
            return $value . $suffix;
        }, 10, 2);

        $this->assertSame('price-filtered', FilterHook::apply('framework_test_filter_hook', 'price', '-filtered'));
    }

    public function test_action_fire_runs_a_callback_added_with_add_with_the_passed_arguments(): void
    {
        $received = null;

        ActionHook::add('framework_test_action_hook', function (...$args) use (&$received) {
            $received = $args;
        }, 10, 2);

        ActionHook::fire('framework_test_action_hook', 'order', 42);

        $this->assertSame(['order', 42], $received);
    }
}
