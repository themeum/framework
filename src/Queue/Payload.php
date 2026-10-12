<?php
/**
 * Encodes a job into the JSON envelope stored in the jobs table, and restores it again.
 * The job's properties are stored as a JSON value tree and rebuilt without PHP object deserialization.
 * The class named in the envelope is checked against ShouldQueue and against the class in the
 * data before anything is instantiated, and every nested class is checked against the allowlist
 * in ValueCodec, so a tampered row cannot make the worker create an arbitrary object.
 *
 * @package    Framework
 * @subpackage Queue
 * @since      3.2.0
 */
namespace Framework\Queue;

defined('ABSPATH') || exit;

use Framework\Contracts\ShouldQueue;
use Framework\Exceptions\QueueException;
use ReflectionException;
use TypeError;

use function Framework\uuid;

class Payload
{
    /**
     * Encode a job into its stored envelope.
     *
     * @param \Framework\Contracts\ShouldQueue $job The job to encode.
     * @param int $default_tries The tries used when the job does not define $tries.
     * @param int|array $default_backoff The backoff used when the job does not define $backoff.
     *
     * @return string
     *
     * @throws \Framework\Exceptions\QueueException When the job holds a value that cannot be stored.
     *
     * @since 3.2.0
     */
    public static function encode(ShouldQueue $job, int $default_tries = 1, $default_backoff = 0)
    {
        $clone = clone $job;
        $clone->set_job_record(null);

        $tries = $job->get_tries();
        $backoff = $job->get_backoff();

        $class = get_class($job);
        $data = ValueCodec::for_job($class)->encode($clone, $class);

        $payload = wp_json_encode([
            'uuid' => (string) uuid(),
            'display_name' => $class,
            'job' => $class,
            'max_tries' => max(1, (int) ($tries ?? $default_tries)),
            'backoff' => $backoff ?? $default_backoff,
            'priority' => $job->get_priority(),
            'data' => $data,
        ], JSON_PRESERVE_ZERO_FRACTION);

        if (!is_string($payload)) {
            throw QueueException::invalid_payload(sprintf('the job [%s] could not be encoded as JSON.', $class));
        }

        return $payload;
    }

    /**
     * Decode a stored envelope without restoring the job.
     *
     * @param string $payload The raw JSON payload.
     *
     * @return array
     *
     * @throws \Framework\Exceptions\QueueException When the envelope is malformed.
     *
     * @since 3.2.0
     */
    public static function decode(string $payload)
    {
        $envelope = json_decode($payload, true);

        if (!is_array($envelope) || !isset($envelope['job'], $envelope['data'])) {
            throw QueueException::invalid_payload('the envelope is not valid JSON or is missing its job data.');
        }

        return $envelope;
    }

    /**
     * Restore the job instance from a decoded envelope.
     *
     * @param array $envelope The decoded envelope.
     *
     * @return \Framework\Contracts\ShouldQueue
     *
     * @throws \Framework\Exceptions\QueueException When the class is unknown or not a queued job.
     *
     * @since 3.2.0
     */
    public static function restore(array $envelope)
    {
        $class = (string) $envelope['job'];

        if (!class_exists($class)) {
            throw QueueException::invalid_payload(sprintf('the class [%s] does not exist.', $class));
        }

        if (!is_subclass_of($class, ShouldQueue::class)) {
            throw QueueException::invalid_payload(sprintf(
                'the class [%s] does not implement %s.',
                $class,
                ShouldQueue::class
            ));
        }

        $data = $envelope['data'];

        if (!is_array($data) || ($data['c'] ?? null) !== $class) {
            throw QueueException::invalid_payload(sprintf('the data does not restore to [%s].', $class));
        }

        try {
            $job = ValueCodec::for_job($class)->decode($data);
        } catch (TypeError | ReflectionException $exception) {
            throw QueueException::invalid_payload($exception->getMessage());
        }

        if (!$job instanceof $class) {
            throw QueueException::invalid_payload(sprintf('the data does not restore to [%s].', $class));
        }

        return $job;
    }

    /**
     * Get the display name recorded in an envelope, falling back to the raw payload's class.
     *
     * @param string $payload The raw JSON payload.
     *
     * @return string
     *
     * @since 3.2.0
     */
    public static function display_name(string $payload)
    {
        $envelope = json_decode($payload, true);

        return is_array($envelope) ? (string) ($envelope['display_name'] ?? $envelope['job'] ?? 'unknown') : 'unknown';
    }
}
