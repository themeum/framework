<?php

namespace Framework\Tests\Unit\Validation\Rules;

use Framework\Tests\Unit\TestCase;
use Framework\Validation\Validator;

class DateRulesTest extends TestCase
{
    public function test_date_accepts_parseable_dates(): void
    {
        $this->assertTrue(Validator::make(['published_at' => '2024-01-15'], ['published_at' => 'date'])->passes());
        $this->assertTrue(Validator::make(['published_at' => '2024-01-15 10:30:00'], ['published_at' => 'date'])->passes());
    }

    public function test_date_rejects_invalid_values(): void
    {
        $this->assertTrue(Validator::make(['published_at' => 'not-a-date'], ['published_at' => 'date'])->fails());
        $this->assertTrue(Validator::make(['published_at' => ['array']], ['published_at' => 'date'])->fails());
    }

    public function test_after_constraint_with_literal_date(): void
    {
        $this->assertTrue(
            Validator::make(['end_date' => '2024-02-01'], ['end_date' => 'date|after:2024-01-01'])->passes()
        );
        $this->assertTrue(
            Validator::make(['end_date' => '2023-12-01'], ['end_date' => 'date|after:2024-01-01'])->fails()
        );
    }

    public function test_after_constraint_resolves_another_field_first(): void
    {
        $validator = Validator::make(
            ['start_date' => '2024-03-01', 'end_date' => '2024-02-01'],
            ['end_date' => 'date|after:start_date']
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('end_date', $validator->errors());

        $validator = Validator::make(
            ['start_date' => '2024-01-01', 'end_date' => '2024-02-01'],
            ['end_date' => 'date|after:start_date']
        );

        $this->assertTrue($validator->passes());
    }

    public function test_after_constraint_fails_for_invalid_reference(): void
    {
        $validator = Validator::make(
            ['end_date' => '2024-02-01'],
            ['end_date' => 'date|after:not-a-date']
        );

        $this->assertTrue($validator->fails());
    }

    public function test_format_constraint_requires_exact_format(): void
    {
        $this->assertTrue(
            Validator::make(['birthday' => '15-01-2024'], ['birthday' => 'date|format:d-m-Y'])->passes()
        );
        $this->assertTrue(
            Validator::make(['birthday' => '2024-01-15'], ['birthday' => 'date|format:d-m-Y'])->fails()
        );
    }

    public function test_constraint_failures_produce_descriptive_messages(): void
    {
        $validator = Validator::make(
            ['end_date' => '2023-12-01'],
            ['end_date' => 'date|after:2024-01-01']
        );

        $validator->passes();

        $this->assertSame(
            ['The  field must be a date after 2024-01-01.'],
            $validator->errors()['end_date']
        );
    }
}
