<?php

namespace Framework\Tests\Unit\Validation\Rules;

use Framework\Tests\Unit\TestCase;
use Framework\Validation\Rule;
use Framework\Validation\Validator;

class ComparisonRulesTest extends TestCase
{
    public function test_in_accepts_listed_values(): void
    {
        $this->assertTrue(Validator::make(['status' => 'active'], ['status' => 'in:active,inactive'])->passes());
        $this->assertTrue(Validator::make(['status' => 'deleted'], ['status' => 'in:active,inactive'])->fails());
    }

    public function test_in_accepts_fluent_rule_with_array(): void
    {
        $validator = Validator::make(
            ['status' => 'archived'],
            ['status' => [Rule::in(['active', 'inactive'])]]
        );

        $this->assertTrue($validator->fails());
        $this->assertSame(
            ['The  field must be in the list of "active, inactive".'],
            $validator->errors()['status']
        );
    }

    public function test_not_in_rejects_listed_values(): void
    {
        $this->assertTrue(Validator::make(['username' => 'admin'], ['username' => 'not_in:admin,root'])->fails());
        $this->assertTrue(Validator::make(['username' => 'jane'], ['username' => 'not_in:admin,root'])->passes());
    }

    public function test_not_in_failure_message_lists_disallowed_values(): void
    {
        $validator = Validator::make(['username' => 'root'], ['username' => 'not_in:admin,root']);

        $validator->passes();

        $this->assertSame(
            ['The  field must not be in the list of "admin, root".'],
            $validator->errors()['username']
        );
    }

    public function test_same_as_passes_for_identical_values(): void
    {
        $validator = Validator::make(
            ['password' => 'secret', 'password_confirmation' => 'secret'],
            ['password_confirmation' => 'same_as:password']
        );

        $this->assertTrue($validator->passes());
    }

    public function test_same_as_fails_for_different_values(): void
    {
        $validator = Validator::make(
            ['password' => 'secret', 'password_confirmation' => 'other'],
            ['password_confirmation' => 'same_as:password']
        );

        $this->assertTrue($validator->fails());
        $this->assertSame(
            ['The  field must match the password field.'],
            $validator->errors()['password_confirmation']
        );
    }

    public function test_same_as_uses_strict_comparison(): void
    {
        $validator = Validator::make(
            ['count' => 1, 'count_confirmation' => '1'],
            ['count_confirmation' => 'same_as:count']
        );

        $this->assertTrue($validator->fails());
    }

    public function test_same_as_resolves_dot_notation_fields(): void
    {
        $validator = Validator::make(
            ['user' => ['email' => 'jane@example.com'], 'email_confirmation' => 'jane@example.com'],
            ['email_confirmation' => 'same_as:user.email']
        );

        $this->assertTrue($validator->passes());
    }
}
