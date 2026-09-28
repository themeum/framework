<?php

namespace Framework\Tests\Unit\Validation\Rules;

use Framework\Tests\Unit\TestCase;
use Framework\Validation\Validator;

class ConditionalRulesTest extends TestCase
{
    public function test_required_fails_for_nullish_values(): void
    {
        $this->assertTrue(Validator::make(['name' => null], ['name' => 'required'])->fails());
        $this->assertTrue(Validator::make(['name' => ''], ['name' => 'required'])->fails());
        $this->assertTrue(Validator::make(['name' => '   '], ['name' => 'required'])->fails());
        $this->assertTrue(Validator::make(['name' => []], ['name' => 'required'])->fails());
        $this->assertTrue(Validator::make([], ['name' => 'required'])->fails());
    }

    public function test_required_passes_for_present_values(): void
    {
        $this->assertTrue(Validator::make(['name' => 'Jane'], ['name' => 'required'])->passes());
        $this->assertTrue(Validator::make(['count' => 0], ['count' => 'required'])->passes());
        $this->assertTrue(Validator::make(['flag' => false], ['flag' => 'required'])->passes());
    }

    public function test_required_failure_message(): void
    {
        $validator = Validator::make([], ['name' => 'required']);

        $validator->passes();

        $this->assertSame(['The  field is required.'], $validator->errors()['name']);
    }

    public function test_nullable_allows_nullish_values(): void
    {
        $this->assertTrue(Validator::make(['bio' => null], ['bio' => 'nullable|string'])->passes());
        $this->assertTrue(Validator::make(['bio' => ''], ['bio' => 'nullable|string'])->passes());
        $this->assertTrue(Validator::make([], ['bio' => 'nullable|string'])->passes());
    }

    public function test_nullable_does_not_bypass_rules_for_present_values(): void
    {
        $validator = Validator::make(['bio' => 42], ['bio' => 'nullable|string']);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('bio', $validator->errors());
    }

    public function test_prohibited_passes_when_value_is_absent(): void
    {
        $this->assertTrue(Validator::make([], ['legacy_id' => 'prohibited'])->passes());
        $this->assertTrue(Validator::make(['legacy_id' => null], ['legacy_id' => 'prohibited'])->passes());
        $this->assertTrue(Validator::make(['legacy_id' => ''], ['legacy_id' => 'prohibited'])->passes());
    }

    public function test_prohibited_fails_when_value_is_present(): void
    {
        $validator = Validator::make(['legacy_id' => 5], ['legacy_id' => 'prohibited']);

        $this->assertTrue($validator->fails());
        $this->assertSame(['The  field is prohibited.'], $validator->errors()['legacy_id']);
    }
}
