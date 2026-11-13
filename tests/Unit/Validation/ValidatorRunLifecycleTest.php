<?php

namespace Framework\Tests\Unit\Validation;

use Framework\Tests\Unit\TestCase;
use Framework\Validation\Validator;

class ValidatorRunLifecycleTest extends TestCase
{
    protected int $calls = 0;

    protected function counting_rule(): \Closure
    {
        return function () {
            $this->calls++;

            return true;
        };
    }

    public function test_validate_runs_each_key_once(): void
    {
        $validator = Validator::make(
            ['name' => 'Widget', 'sku' => 'W-1'],
            ['name' => [$this->counting_rule()], 'sku' => [$this->counting_rule()]]
        );

        $this->assertSame(['name' => 'Widget', 'sku' => 'W-1'], $validator->validate());
        $this->assertSame(2, $this->calls);
    }

    public function test_validated_after_passes_does_not_run_the_rules_again(): void
    {
        $validator = Validator::make(['name' => 'Widget'], ['name' => [$this->counting_rule()]]);

        $this->assertTrue($validator->passes());
        $this->assertSame(['name' => 'Widget'], $validator->validated());
        $this->assertSame(1, $this->calls);
    }

    public function test_repeat_calls_do_not_duplicate_errors(): void
    {
        $validator = Validator::make(['name' => ''], ['name' => 'required']);

        $this->assertTrue($validator->fails());
        $after_fails = $validator->errors();

        $this->assertFalse($validator->passes());
        $this->assertSame($after_fails, $validator->errors());
        $this->assertCount(1, $validator->errors()['name']);
    }

    public function test_sometimes_adding_a_failing_rule_after_a_run_validates_again(): void
    {
        $validator = Validator::make(['name' => 'Widget'], ['name' => 'string']);

        $this->assertTrue($validator->passes());

        $validator->sometimes('name', [fn () => 'Name is reserved.'], fn () => true);

        $this->assertFalse($validator->passes());
        $this->assertSame(['Name is reserved.'], $validator->errors()['name']);
    }

    public function test_sometimes_with_a_false_callback_keeps_the_stored_outcome(): void
    {
        $validator = Validator::make(['name' => 'Widget'], ['name' => [$this->counting_rule()]]);

        $this->assertTrue($validator->passes());

        $validator->sometimes('name', [fn () => 'Name is reserved.'], fn () => false);

        $this->assertTrue($validator->passes());
        $this->assertSame(1, $this->calls);
    }

    public function test_set_value_after_a_failed_run_validates_again(): void
    {
        $validator = Validator::make(['name' => ''], ['name' => 'required']);

        $this->assertTrue($validator->fails());

        $validator->set_value('name', 'Widget');

        $this->assertTrue($validator->passes());
        $this->assertSame([], $validator->errors());
    }
}
