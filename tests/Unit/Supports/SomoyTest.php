<?php

namespace Framework\Tests\Unit\Supports;

use DateTime;
use DateTimeZone;
use Framework\Contracts\SomoyInterface;
use Framework\Exceptions\InvalidDateFormatException;
use Framework\Supports\Facades\Date;
use Framework\Supports\Somoy;
use Framework\Tests\Unit\TestCase;

class SomoyTest extends TestCase
{
    public function test_now_returns_current_datetime(): void
    {
        $before = time();
        $now = Somoy::now();
        $after = time();

        $this->assertInstanceOf(SomoyInterface::class, $now);
        $this->assertInstanceOf(DateTime::class, $now);
        $this->assertGreaterThanOrEqual($before, $now->get_timestamp());
        $this->assertLessThanOrEqual($after, $now->get_timestamp());
    }

    public function test_parse_accepts_datetime_string(): void
    {
        $date = Somoy::parse('2024-05-01 14:30:00', new DateTimeZone('UTC'));

        $this->assertSame('2024-05-01 14:30:00', $date->to_date_time_string());
        $this->assertSame('UTC', $date->get_timezone()->getName());
    }

    public function test_create_from_format_throws_for_invalid_input(): void
    {
        $this->expectException(InvalidDateFormatException::class);

        Somoy::create_from_format('Y-m-d', 'not-a-date');
    }

    public function test_create_from_format_parses_valid_input(): void
    {
        $date = Somoy::create_from_format('Y-m-d', '2024-05-01', new DateTimeZone('UTC'));

        $this->assertSame('2024-05-01', $date->to_date_string());
    }

    public function test_is_valid_date_accepts_datetime_interface(): void
    {
        $this->assertTrue(Somoy::is_valid_date(new DateTime()));
    }

    public function test_is_valid_date_rejects_invalid_string(): void
    {
        $this->assertFalse(Somoy::is_valid_date('not-a-date'));
    }

    public function test_is_valid_date_rejects_arrays(): void
    {
        $this->assertFalse(Somoy::is_valid_date(['not-a-date']));
    }

    public function test_start_of_day_mutates_in_place(): void
    {
        $date = Somoy::parse('2024-05-01 14:30:45', new DateTimeZone('UTC'));
        $result = $date->start_of_day();

        $this->assertSame($date, $result);
        $this->assertSame('2024-05-01 00:00:00', $date->to_date_time_string());
    }

    public function test_copy_preserves_original_when_mutating(): void
    {
        $date = Somoy::parse('2024-05-01 14:30:45', new DateTimeZone('UTC'));
        $copy = $date->copy()->start_of_day();

        $this->assertSame('2024-05-01 00:00:00', $copy->to_date_time_string());
        $this->assertSame('2024-05-01 14:30:45', $date->to_date_time_string());
    }

    public function test_is_after_compares_dates(): void
    {
        $earlier = Somoy::parse('2024-01-01');
        $later = Somoy::parse('2024-06-01');

        $this->assertTrue($later->is_after($earlier));
        $this->assertFalse($earlier->is_after($later));
    }

    public function test_to_sql_datetime_string_formats_date(): void
    {
        $date = Somoy::parse('2024-05-01 14:30:00', new DateTimeZone('UTC'));

        $this->assertSame('2024-05-01 14:30:00', $date->to_sql_datetime_string());
    }

    public function test_create_from_timestamp_builds_datetime(): void
    {
        $timestamp = (new DateTime('2024-05-01 14:30:00', new DateTimeZone('UTC')))->getTimestamp();
        $date = Somoy::create_from_timestamp($timestamp, new DateTimeZone('UTC'));

        $this->assertSame('2024-05-01 14:30:00', $date->to_date_time_string());
    }

    public function test_to_json_emits_utc_with_trailing_z(): void
    {
        $date = Somoy::parse('2024-05-01 14:30:00', new DateTimeZone('UTC'));
        $json = $date->to_json();

        $this->assertStringEndsWith('Z', $json);
        $this->assertStringStartsWith('2024-05-01T14:30:00', $json);
    }

    public function test_date_facade_forwards_to_somoy(): void
    {
        $date = Date::parse('2024-05-01 14:30:00', new DateTimeZone('UTC'));

        $this->assertInstanceOf(Somoy::class, $date);
        $this->assertSame('2024-05-01 14:30:00', $date->to_date_time_string());
    }

    public function test_today_yesterday_and_tomorrow(): void
    {
        $today = Somoy::today(new DateTimeZone('UTC'));
        $yesterday = Somoy::yesterday(new DateTimeZone('UTC'));
        $tomorrow = Somoy::tomorrow(new DateTimeZone('UTC'));

        $this->assertSame('00:00:00', $today->to_time_string());
        $this->assertTrue($yesterday->is_before($today));
        $this->assertTrue($tomorrow->is_after($today));
        $this->assertTrue($today->is_same_day($yesterday->copy()->add_day()));
    }

    public function test_diff_for_humans_reports_seconds_ago(): void
    {
        $now = Somoy::parse('2024-05-01 14:30:45', new DateTimeZone('UTC'));
        $date = $now->copy()->sub_seconds(1);

        $this->assertSame('1 second ago', $date->diff_for_humans($now));
    }

    public function test_diff_for_humans_reports_plural_minutes_ago(): void
    {
        $now = Somoy::parse('2024-05-01 14:30:45', new DateTimeZone('UTC'));
        $date = $now->copy()->sub_minutes(5);

        $this->assertSame('5 minutes ago', $date->diff_for_humans($now));
    }

    public function test_diff_for_humans_reports_future_dates(): void
    {
        $now = Somoy::parse('2024-05-01 14:30:45', new DateTimeZone('UTC'));
        $date = $now->copy()->add_hours(3);

        $this->assertSame('3 hours from now', $date->diff_for_humans($now));
    }

    public function test_diff_for_humans_defaults_to_now(): void
    {
        $date = Somoy::now()->sub_minutes(10);

        $this->assertSame('10 minutes ago', $date->diff_for_humans());
    }

    public function test_diff_for_humans_reports_days_under_a_week(): void
    {
        $now = Somoy::parse('2024-05-10 00:00:00', new DateTimeZone('UTC'));
        $date = $now->copy()->sub_days(3);

        $this->assertSame('3 days ago', $date->diff_for_humans($now));
    }

    public function test_diff_for_humans_reports_weeks_from_leftover_days(): void
    {
        $now = Somoy::parse('2024-05-11 00:00:00', new DateTimeZone('UTC'));
        $date = $now->copy()->sub_days(10);

        $this->assertSame('1 week ago', $date->diff_for_humans($now));
    }

    public function test_diff_for_humans_uses_calendar_exact_months(): void
    {
        // Jan (31 days) + Feb 2023 (28 days, not a leap year) = 59 days, which
        // a flat 30-day-per-month approximation would floor down to 1 month.
        $earlier = Somoy::parse('2023-01-01 00:00:00', new DateTimeZone('UTC'));
        $later = Somoy::parse('2023-03-01 00:00:00', new DateTimeZone('UTC'));

        $this->assertSame('2 months ago', $earlier->diff_for_humans($later));
    }

    public function test_diff_for_humans_reports_just_now_for_the_same_moment(): void
    {
        $now = Somoy::parse('2024-05-01 14:30:45', new DateTimeZone('UTC'));

        $this->assertSame('just now', $now->diff_for_humans($now->copy()));
    }

    public function test_diff_for_humans_supports_short_units(): void
    {
        $now = Somoy::parse('2024-05-01 14:30:45', new DateTimeZone('UTC'));
        $date = $now->copy()->sub_minutes(5);

        $this->assertSame('5min ago', $date->diff_for_humans($now, null, true));
    }

    public function test_diff_for_humans_joins_multiple_parts(): void
    {
        $later = Somoy::parse('2023-03-01 00:00:00', new DateTimeZone('UTC'));
        $earlier = $later->copy()->sub_years(1)->sub_months(2);

        $this->assertSame('1 year 2 months ago', $earlier->diff_for_humans($later, null, false, 2));
    }

    public function test_diff_for_humans_supports_absolute_syntax(): void
    {
        $now = Somoy::parse('2024-05-01 14:30:45', new DateTimeZone('UTC'));
        $date = $now->copy()->sub_hours(3);

        $this->assertSame('3 hours', $date->diff_for_humans($now, Somoy::DIFF_ABSOLUTE));
    }

    public function test_diff_for_humans_supports_relative_to_other_syntax(): void
    {
        $now = Somoy::parse('2024-05-01 14:30:45', new DateTimeZone('UTC'));
        $earlier = $now->copy()->sub_hours(3);
        $later = $now->copy()->add_hours(3);

        $this->assertSame('3 hours before', $earlier->diff_for_humans($now, Somoy::DIFF_RELATIVE_TO_OTHER));
        $this->assertSame('3 hours after', $later->diff_for_humans($now, Somoy::DIFF_RELATIVE_TO_OTHER));
    }

    public function test_diff_for_humans_reports_one_day_words(): void
    {
        $now = Somoy::parse('2024-05-02 00:00:00', new DateTimeZone('UTC'));
        $yesterday = $now->copy()->sub_days(1);
        $tomorrow = $now->copy()->add_days(1);

        $this->assertSame('yesterday', $yesterday->diff_for_humans($now));
        $this->assertSame('tomorrow', $tomorrow->diff_for_humans($now));
    }

    public function test_diff_for_humans_reports_two_day_words(): void
    {
        $now = Somoy::parse('2024-05-03 00:00:00', new DateTimeZone('UTC'));
        $before_yesterday = $now->copy()->sub_days(2);
        $after_tomorrow = $now->copy()->add_days(2);

        $this->assertSame('before yesterday', $before_yesterday->diff_for_humans($now));
        $this->assertSame('after tomorrow', $after_tomorrow->diff_for_humans($now));
    }

    public function test_diff_for_humans_can_disable_day_words(): void
    {
        $now = Somoy::parse('2024-05-02 00:00:00', new DateTimeZone('UTC'));
        $date = $now->copy()->sub_days(1);
        $options = Somoy::NO_ZERO_DIFF | Somoy::JUST_NOW;

        $this->assertSame('1 day ago', $date->diff_for_humans($now, null, false, 1, $options));
    }

    public function test_diff_for_humans_reports_zero_diff_without_just_now(): void
    {
        $now = Somoy::parse('2024-05-01 14:30:45', new DateTimeZone('UTC'));

        $this->assertSame('1 second ago', $now->diff_for_humans($now->copy(), null, false, 1, Somoy::NO_ZERO_DIFF));
    }

    public function test_diff_for_humans_reports_zero_seconds_with_no_zero_diff_options(): void
    {
        $now = Somoy::parse('2024-05-01 14:30:45', new DateTimeZone('UTC'));

        $this->assertSame('0 seconds', $now->diff_for_humans($now->copy(), Somoy::DIFF_ABSOLUTE, false, 1, 0));
    }

    public function test_diff_for_humans_skips_zero_units_by_default(): void
    {
        $now = Somoy::parse('2024-05-01 14:30:45', new DateTimeZone('UTC'));
        $date = $now->copy()->sub_hours(2)->sub_seconds(15);

        $this->assertSame('2 hours 15 seconds ago', $date->diff_for_humans($now, null, false, 3));
    }

    public function test_diff_for_humans_sequential_parts_only_stops_at_first_zero(): void
    {
        $now = Somoy::parse('2024-05-01 14:30:45', new DateTimeZone('UTC'));
        $date = $now->copy()->sub_hours(2)->sub_seconds(15);
        $options = Somoy::SEQUENTIAL_PARTS_ONLY;

        $this->assertSame('2 hours ago', $date->diff_for_humans($now, null, false, 3, $options));
    }
}
