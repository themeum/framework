<?php

namespace Framework\Tests\Unit\Validation\Rules;

use Framework\Tests\Unit\TestCase;
use Framework\Validation\Validator;

class StringRulesTest extends TestCase
{
    public function test_string_accepts_strings_only(): void
    {
        $this->assertTrue(Validator::make(['name' => 'Widget'], ['name' => 'string'])->passes());
        $this->assertTrue(Validator::make(['name' => 42], ['name' => 'string'])->fails());
    }

    public function test_min_and_max_length_constraints(): void
    {
        $this->assertTrue(Validator::make(['name' => 'abcd'], ['name' => 'string|min:3|max:5'])->passes());
        $this->assertTrue(Validator::make(['name' => 'ab'], ['name' => 'string|min:3'])->fails());
        $this->assertTrue(Validator::make(['name' => 'abcdef'], ['name' => 'string|max:5'])->fails());
    }

    public function test_exact_length_constraints(): void
    {
        $this->assertTrue(Validator::make(['code' => 'abcde'], ['code' => 'string|length:5'])->passes());
        $this->assertTrue(Validator::make(['code' => 'abcde'], ['code' => 'string|size:5'])->passes());
        $this->assertTrue(Validator::make(['code' => 'abcde'], ['code' => 'string|exactly:5'])->passes());
        $this->assertTrue(Validator::make(['code' => 'abc'], ['code' => 'string|length:5'])->fails());
    }

    public function test_between_constraint(): void
    {
        $this->assertTrue(Validator::make(['name' => 'abc'], ['name' => 'string|between:2,5'])->passes());
        $this->assertTrue(Validator::make(['name' => 'a'], ['name' => 'string|between:2,5'])->fails());
        $this->assertTrue(Validator::make(['name' => 'abcdef'], ['name' => 'string|between:2,5'])->fails());
    }

    public function test_email_constraint(): void
    {
        $this->assertTrue(Validator::make(['email' => 'jane@example.com'], ['email' => 'string|email'])->passes());
        $this->assertTrue(Validator::make(['email' => 'not-an-email'], ['email' => 'string|email'])->fails());
    }

    public function test_url_constraint(): void
    {
        $this->assertTrue(Validator::make(['site' => 'https://example.com'], ['site' => 'string|url'])->passes());
        $this->assertTrue(Validator::make(['site' => 'not-a-url'], ['site' => 'string|url'])->fails());
    }

    public function test_ip_constraint(): void
    {
        $this->assertTrue(Validator::make(['ip' => '192.168.1.1'], ['ip' => 'string|ip'])->passes());
        $this->assertTrue(Validator::make(['ip' => '999.999.999.999'], ['ip' => 'string|ip'])->fails());
    }

    public function test_alpha_constraints(): void
    {
        $this->assertTrue(Validator::make(['value' => 'abc'], ['value' => 'string|alpha'])->passes());
        $this->assertTrue(Validator::make(['value' => 'abc1'], ['value' => 'string|alpha'])->fails());

        $this->assertTrue(Validator::make(['value' => 'abc123'], ['value' => 'string|alpha_num'])->passes());
        $this->assertTrue(Validator::make(['value' => 'abc-123'], ['value' => 'string|alpha_num'])->fails());

        $this->assertTrue(Validator::make(['value' => 'abc-123_x'], ['value' => 'string|alpha_dash'])->passes());
        $this->assertTrue(Validator::make(['value' => 'abc 123'], ['value' => 'string|alpha_dash'])->fails());
    }

    public function test_starts_with_and_ends_with_constraints(): void
    {
        $this->assertTrue(Validator::make(['sku' => 'PRD-100'], ['sku' => 'string|starts_with:PRD'])->passes());
        $this->assertTrue(Validator::make(['sku' => 'ITM-100'], ['sku' => 'string|starts_with:PRD'])->fails());

        $this->assertTrue(Validator::make(['file' => 'report.pdf'], ['file' => 'string|ends_with:.pdf'])->passes());
        $this->assertTrue(Validator::make(['file' => 'report.doc'], ['file' => 'string|ends_with:.pdf'])->fails());
    }

    public function test_doesnt_start_with_and_doesnt_end_with_constraints(): void
    {
        $this->assertTrue(Validator::make(['sku' => 'ITM-100'], ['sku' => 'string|doesnt_start_with:PRD'])->passes());
        $this->assertTrue(Validator::make(['sku' => 'PRD-100'], ['sku' => 'string|doesnt_start_with:PRD'])->fails());

        $this->assertTrue(Validator::make(['file' => 'report.doc'], ['file' => 'string|doesnt_end_with:.pdf'])->passes());
        $this->assertTrue(Validator::make(['file' => 'report.pdf'], ['file' => 'string|doesnt_end_with:.pdf'])->fails());
    }

    public function test_constraint_failures_produce_descriptive_messages(): void
    {
        $validator = Validator::make(['name' => 'ab'], ['name' => 'string|min:3']);

        $validator->passes();

        $this->assertSame(
            ['The  field must be at least 3 characters long.'],
            $validator->errors()['name']
        );
    }
}
