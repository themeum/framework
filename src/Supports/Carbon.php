<?php

namespace Framework\Supports;

use Carbon\Carbon as BaseCarbon;
use DateTime;
use DateTimeInterface;
use Exception;
use InvalidArgumentException;
use TypeError;

class Carbon extends BaseCarbon
{
    const BASE_FORMAT = 'Y-m-d H:i:s';

    /**
     * Create a new Carbon instance.
     *
     * On PHP 8.3+, DateTime::getLastErrors() returns false instead of an array
     * when parsing succeeds, which makes the Carbon 1.x constructor fail on its
     * internal error bookkeeping after the instance is already fully initialized.
     * That failure is safe to ignore.
     *
     * @param string|null $time The time string to parse.
     * @param \DateTimeZone|string|null $timezone The timezone of the instance.
     *
     * @return void
     * @since 1.0.0
     */
    public function __construct($time = null, $timezone = null)
    {
        try {
            parent::__construct($time, $timezone);
        } catch (TypeError $exception) {
            if (strpos($exception->getMessage(), 'setLastErrors') === false) {
                throw $exception;
            }
        }
    }

    /**
     * Create a Carbon instance from a specific format.
     *
     * Falls back to a plain DateTime parse when the Carbon 1.x error bookkeeping
     * fails on PHP 8.3+ (see __construct for details).
     *
     * @param string $format The datetime format.
     * @param string $time The time string to parse.
     * @param \DateTimeZone|string|null $tz The timezone of the instance.
     *
     * @return static
     * @since 1.0.0
     * @throws \InvalidArgumentException When the time string does not match the format.
     */
    public static function createFromFormat($format, $time, $tz = null)
    {
        try {
            return parent::createFromFormat($format, $time, $tz);
        } catch (TypeError $exception) {
            if (strpos($exception->getMessage(), 'setLastErrors') === false) {
                throw $exception;
            }

            $date = $tz !== null
                ? DateTime::createFromFormat($format, $time, static::safeCreateDateTimeZone($tz))
                : DateTime::createFromFormat($format, $time);

            if ($date === false) {
                throw new InvalidArgumentException(sprintf('The date "%s" does not match the format "%s".', $time, $format));
            }

            return static::instance($date);
        }
    }

    /**
     * Check if the value is a valid date.
     *
     * @param mixed $value
     *
     * @return bool
     * @since 1.0.0
     */
    public static function isValidDate($value)
    {
        if ($value instanceof DateTimeInterface) {
            return true;
        }

        try {
            static::parse($value);

            return true;
        } catch (Exception $exception) {
            return false;
        }
    }

    /**
     * Determine if the instance represents a valid date.
     *
     * Carbon 1.x does not provide this method; it mirrors the Carbon 2 behavior.
     *
     * @return bool
     * @since 1.0.0
     */
    public function isValid()
    {
        return $this->year !== 0;
    }

    /**
     * Convert the Carbon instance to a base format string.
     *
     * @return string
     * @since 1.0.0
     */
    public function toBaseDateString()
    {
        return $this->format(static::BASE_FORMAT);
    }

    /**
     * Convert the Carbon instance to a SQL safe date string.
     *
     * @return string
     * @since 1.0.0
     */
    public function toSqlDatetimeString()
    {
        return $this->toBaseDateString();
    }

    /**
     * Convert the Carbon instance to a SQL safe date string (snake_case alias).
     *
     * @return string
     * @since 1.0.0
     */
    public function to_sql_datetime_string()
    {
        return $this->toSqlDatetimeString();
    }
}
