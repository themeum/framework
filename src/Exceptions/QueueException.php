<?php
/**
 * Exception raised by the queue when a job cannot be dispatched or restored.
 * Each failure has a named constructor so the message always tells the developer the next step,
 * since most of these surface on a live site where the stack trace is the only clue.
 *
 * @package    Framework
 * @subpackage Exceptions
 * @since      3.2.0
 */
namespace Framework\Exceptions;

defined('ABSPATH') || exit;

use RuntimeException;

class QueueException extends RuntimeException
{
    /**
     * The queue service provider has not been registered.
     *
     * @return static
     *
     * @since 3.2.0
     */
    public static function not_enabled()
    {
        return new static(
            'The queue is not enabled. Add Framework\Queue\QueueServiceProvider to your bootstrap/providers.php.'
        );
    }

    /**
     * The jobs table does not exist yet.
     *
     * @param string $table The full table name that was looked for.
     *
     * @return static
     *
     * @since 3.2.0
     */
    public static function table_missing(string $table)
    {
        return new static(sprintf(
            'The queue table [%s] does not exist. Run `queue:table`, add the migrations to '
            . 'config/migrations.php, then run `migrate`.',
            $table
        ));
    }

    /**
     * A stored payload could not be restored into a job.
     *
     * @param string $reason Why the payload was rejected.
     *
     * @return static
     *
     * @since 3.2.0
     */
    public static function invalid_payload(string $reason)
    {
        return new static(sprintf('The queued job payload is invalid: %s', $reason));
    }

    /**
     * A job holds a value that cannot be stored in the payload.
     *
     * @param string $path Where the value sits, such as the job class and property.
     * @param string $type The type or class of the value.
     *
     * @return static
     *
     * @since 3.2.2
     */
    public static function unsupported_value(string $path, string $type)
    {
        return new static(sprintf(
            'The queued job cannot store %s at [%s]. Store an ID and load it in handle(), or list the class in '
            . 'the job\'s $allowed_classes if it is a plain value object.',
            $type,
            $path
        ));
    }

    /**
     * A job was reclaimed more times than it is allowed to run.
     *
     * @param string $job The job's display name.
     *
     * @return static
     *
     * @since 3.2.0
     */
    public static function max_attempts_exceeded(string $job)
    {
        return new static(sprintf(
            'The job [%s] has been attempted too many times. It may have been interrupted before finishing, '
            . 'for example by a timeout or a fatal error.',
            $job
        ));
    }
}
