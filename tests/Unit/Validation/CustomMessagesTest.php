<?php

namespace Framework\Tests\Unit\Validation;

use Framework\Supports\MessagesBag;
use Framework\Tests\Support\Validation\CountingMessagesBag;
use Framework\Tests\Unit\TestCase;
use Framework\Validation\Rule;
use Framework\Validation\Validator;

use function Framework\app;

class CustomMessagesTest extends TestCase
{
    protected const DEFAULT_MAX = 'The  field must be at most 3 characters long.';

    protected function errors_for(array $data, array $rules, array $messages = []): array
    {
        $validator = Validator::make($data, $rules, $messages);
        $validator->passes();

        return $validator->errors();
    }

    public function test_flat_custom_message_is_reported(): void
    {
        $errors = $this->errors_for(['name' => ''], ['name' => 'required'], ['name.required' => 'Flat']);

        $this->assertSame(['name' => ['Flat']], $errors);
    }

    public function test_nested_custom_message_is_reported(): void
    {
        $errors = $this->errors_for(['name' => ''], ['name' => 'required'], ['name' => ['required' => 'Nested']]);

        $this->assertSame(['name' => ['Nested']], $errors);
    }

    public function test_constraint_custom_message_replaces_placeholders(): void
    {
        $errors = $this->errors_for(
            ['name' => 'abcdef'],
            ['name' => 'string|max:3'],
            ['name.max' => 'At most {max}']
        );

        $this->assertSame(['name' => ['At most 3']], $errors);
    }

    public function test_closure_custom_message_receives_name_and_value(): void
    {
        $errors = $this->errors_for(
            ['name' => 5],
            ['name' => 'string'],
            ['name.string' => fn ($name, $value) => "Closure {$name}=" . $value]
        );

        $this->assertSame(['name' => ['Closure name=5']], $errors);
    }

    public function test_custom_message_for_one_expanded_key_applies_to_that_key_only(): void
    {
        $errors = $this->errors_for(
            ['v' => [['s' => 'abcdef'], ['s' => 'abcdefg']]],
            ['v.*.s' => 'string|max:3'],
            ['v.1.s.max' => 'Second only']
        );

        $this->assertSame(['v.0.s' => [static::DEFAULT_MAX], 'v.1.s' => ['Second only']], $errors);
    }

    public function test_custom_message_for_another_field_leaves_the_default(): void
    {
        $errors = $this->errors_for(['name' => 'abcdef'], ['name' => 'string|max:3'], ['other.max' => 'Other']);

        $this->assertSame(['name' => [static::DEFAULT_MAX]], $errors);
    }

    public function test_shared_rule_object_does_not_keep_custom_messages_across_validators(): void
    {
        $rule = Rule::string()->max(3);

        $with_custom = $this->errors_for(['name' => 'abcdef'], ['name' => [$rule]], ['name.max' => 'Custom A']);
        $without_custom = $this->errors_for(['name' => 'abcdef'], ['name' => [$rule]]);

        $this->assertSame(['name' => ['Custom A']], $with_custom);
        $this->assertSame(['name' => [static::DEFAULT_MAX]], $without_custom);
    }

    public function test_default_message_is_reported_without_custom_messages(): void
    {
        $errors = $this->errors_for(['name' => ''], ['name' => 'required']);

        $this->assertSame(['name' => ['The  field is required.']], $errors);
    }

    public function test_passing_payload_without_custom_messages_makes_no_lookups(): void
    {
        $bag = new CountingMessagesBag();
        app()->instance(MessagesBag::class, $bag);

        $variants = array_fill(0, 50, ['sku' => 'SKU-1', 'price' => 10, 'stock' => 3]);

        $validator = Validator::make(
            ['variants' => $variants],
            [
                'variants' => 'required|array|min:1',
                'variants.*.sku' => 'required|string|max:50',
                'variants.*.price' => 'required|numeric|min:0',
                'variants.*.stock' => 'nullable|integer|min:0',
            ]
        );

        $bag->lookups = 0;

        $this->assertTrue($validator->passes());
        $this->assertSame(0, $bag->lookups);
    }
}
