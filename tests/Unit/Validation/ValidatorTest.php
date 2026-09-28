<?php

namespace Framework\Tests\Unit\Validation;

use Framework\Exceptions\ValidationException;
use Framework\Tests\Unit\TestCase;
use Framework\Validation\Rule;
use Framework\Validation\Validator;

class ValidatorTest extends TestCase
{
    public function test_passes_when_all_rules_are_satisfied(): void
    {
        $validator = Validator::make(
            ['name' => 'Widget', 'price' => 10],
            ['name' => 'required|string', 'price' => 'required|numeric']
        );

        $this->assertTrue($validator->passes());
        $this->assertSame([], $validator->errors());
    }

    public function test_fails_and_collects_errors_keyed_by_field(): void
    {
        $validator = Validator::make(
            ['name' => ''],
            ['name' => 'required|string']
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('name', $validator->errors());
        $this->assertContains('The  field is required.', $validator->errors()['name']);
    }

    public function test_validate_throws_validation_exception_on_failure(): void
    {
        $validator = Validator::make(
            ['email' => 'not-an-email'],
            ['email' => 'string|email']
        );

        $this->expectException(ValidationException::class);
        $validator->validate();
    }

    public function test_validate_returns_validated_data_on_success(): void
    {
        $validator = Validator::make(
            ['name' => 'Widget', 'ignored' => 'extra'],
            ['name' => 'required|string']
        );

        $this->assertSame(['name' => 'Widget'], $validator->validate());
    }

    public function test_validated_builds_nested_data_from_dot_notation(): void
    {
        $validator = Validator::make(
            ['user' => ['profile' => ['name' => 'Jane']]],
            ['user.profile.name' => 'required|string']
        );

        $this->assertSame(
            ['user' => ['profile' => ['name' => 'Jane']]],
            $validator->validated()
        );
    }

    public function test_validated_throws_when_validation_failed(): void
    {
        $validator = Validator::make(
            ['name' => 123],
            ['name' => 'string']
        );

        $this->expectException(ValidationException::class);
        $validator->validated();
    }

    public function test_wildcard_rules_expand_to_each_array_item(): void
    {
        $validator = Validator::make(
            [
                'items' => [
                    ['name' => 'Valid'],
                    ['name' => ''],
                ],
            ],
            ['items.*.name' => 'required|string']
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('items.1.name', $validator->errors());
        $this->assertArrayNotHasKey('items.0.name', $validator->errors());
    }

    public function test_validated_merges_wildcard_fields_into_nested_arrays(): void
    {
        $validator = Validator::make(
            [
                'items' => [
                    ['name' => 'First'],
                    ['name' => 'Second'],
                ],
            ],
            [
                'items' => 'array',
                'items.*.name' => 'required|string',
            ]
        );

        $this->assertSame(
            [
                'items' => [
                    ['name' => 'First'],
                    ['name' => 'Second'],
                ],
            ],
            $validator->validated()
        );
    }

    public function test_nullable_skips_remaining_rules_for_nullish_values(): void
    {
        $validator = Validator::make(
            ['nickname' => null],
            ['nickname' => 'nullable|string']
        );

        $this->assertTrue($validator->passes());
    }

    public function test_nullable_still_validates_present_values(): void
    {
        $validator = Validator::make(
            ['nickname' => 123],
            ['nickname' => 'nullable|string']
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('nickname', $validator->errors());
    }

    public function test_missing_required_field_fails(): void
    {
        $validator = Validator::make(
            [],
            ['name' => 'required|string']
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('name', $validator->errors());
    }

    public function test_accepts_fluent_rule_objects(): void
    {
        $validator = Validator::make(
            ['name' => 'ab'],
            ['name' => [Rule::required(), Rule::string()->min(3)]]
        );

        $this->assertTrue($validator->fails());
        $this->assertContains(
            'The  field must be at least 3 characters long.',
            $validator->errors()['name']
        );
    }

    public function test_accepts_array_of_string_rules(): void
    {
        $validator = Validator::make(
            ['status' => 'active'],
            ['status' => ['required', 'in:active,inactive']]
        );

        $this->assertTrue($validator->passes());
    }

    public function test_error_messages_leave_name_placeholder_empty(): void
    {
        $validator = Validator::make(
            ['title' => 42],
            ['title' => 'string']
        );

        $validator->passes();

        $this->assertSame(['The  field must be a string.'], $validator->errors()['title']);
    }
}
