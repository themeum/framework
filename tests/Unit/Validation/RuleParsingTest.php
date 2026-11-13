<?php

namespace Framework\Tests\Unit\Validation;

use Framework\Tests\Unit\TestCase;
use Framework\Validation\Rule;
use Framework\Validation\Rules\StringRule;
use Framework\Validation\Validator;

class RuleParsingTest extends TestCase
{
    public function test_wildcard_expands_to_one_key_per_item(): void
    {
        $validator = Validator::make(
            ['variants' => [['price' => 1], ['price' => 2], ['price' => 3]]],
            ['variants.*.price' => 'required|numeric']
        );

        $this->assertSame(
            ['variants.0.price', 'variants.1.price', 'variants.2.price'],
            array_keys($validator->get_rules())
        );
    }

    public function test_wildcard_over_empty_or_missing_array_produces_no_keys(): void
    {
        $empty = Validator::make(['variants' => []], ['variants.*.price' => 'required|numeric']);
        $missing = Validator::make([], ['variants.*.price' => 'required|numeric']);

        $this->assertSame([], $empty->get_rules());
        $this->assertSame([], $missing->get_rules());
    }

    public function test_string_rule_instances_are_equal_but_distinct_per_key(): void
    {
        $rules = Validator::make(
            ['variants' => [['sku' => 'a'], ['sku' => 'b']]],
            ['variants.*.sku' => 'required|string|max:50']
        )->get_rules();

        $first = $rules['variants.0.sku'];
        $second = $rules['variants.1.sku'];

        $this->assertCount(count($first), $second);

        foreach ($first as $index => $rule) {
            $this->assertEquals($rule, $second[$index]);
            $this->assertNotSame($rule, $second[$index]);
        }
    }

    public function test_caller_supplied_rule_object_is_distinct_per_key(): void
    {
        $rules = Validator::make(
            ['variants' => [['sku' => 'a'], ['sku' => 'b']]],
            ['variants.*.sku' => [Rule::string()]]
        )->get_rules();

        $first = $rules['variants.0.sku'][0];
        $second = $rules['variants.1.sku'][0];

        $this->assertInstanceOf(StringRule::class, $first);
        $this->assertInstanceOf(StringRule::class, $second);
        $this->assertNotSame($first, $second);

        $first->max(2);

        $this->assertSame(2, $first->get('max'));
        $this->assertNull($second->get('max'));
    }

    public function test_closure_rule_runs_once_per_expanded_key_with_its_value(): void
    {
        $seen = [];

        $validator = Validator::make(
            ['variants' => [['sku' => 'a'], ['sku' => 'b'], ['sku' => 'c']]],
            [
                'variants.*.sku' => [
                    function ($value, $key) use (&$seen) {
                        $seen[$key] = $value;

                        return true;
                    },
                ],
            ]
        );

        $this->assertTrue($validator->passes());
        $this->assertSame(
            ['variants.0.sku' => 'a', 'variants.1.sku' => 'b', 'variants.2.sku' => 'c'],
            $seen
        );
    }

    public function test_conditional_rule_branch_follows_each_validators_own_data(): void
    {
        $rules = [
            'variants.*.sku' => [
                Rule::when(fn ($data) => $data['strict'], 'required|string|max:3', 'string'),
            ],
        ];
        $variants = [['sku' => 'abcd'], ['sku' => 'abcde']];

        $strict = Validator::make(['strict' => true, 'variants' => $variants], $rules);
        $lenient = Validator::make(['strict' => false, 'variants' => $variants], $rules);

        $this->assertTrue($strict->fails());
        $this->assertArrayHasKey('variants.0.sku', $strict->errors());
        $this->assertArrayHasKey('variants.1.sku', $strict->errors());
        $this->assertTrue($lenient->passes());
    }

    public function test_errors_are_reported_per_expanded_key(): void
    {
        $validator = Validator::make(
            ['variants' => [['price' => 5], ['price' => -1], ['price' => 'abc']]],
            ['variants.*.price' => 'required|numeric|min:0']
        );

        $this->assertTrue($validator->fails());

        $errors = $validator->errors();

        $this->assertArrayNotHasKey('variants.0.price', $errors);
        $this->assertNotEmpty($errors['variants.1.price']);
        $this->assertNotEmpty($errors['variants.2.price']);
    }

    public function test_plain_key_keeps_the_caller_supplied_rule_object(): void
    {
        $rule = Rule::string();

        $rules = Validator::make(['name' => 'Widget'], ['name' => [$rule]])->get_rules();

        $this->assertSame($rule, $rules['name'][0]);
    }
}
