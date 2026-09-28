<?php
/**
 * The only class in the queue that writes SQL: storing, claiming, settling, and inspecting jobs.
 * Timestamps are epoch integers bound from the overridable clock, never MySQL's NOW(), so the
 * claim and retry logic can be driven by a frozen clock in tests and is immune to timezone drift.
 * Claims are a single UPDATE ... ORDER BY ... LIMIT tagged with a per-claim token, which is atomic
 * under InnoDB and works on every MySQL WordPress supports.
 *
 * @package    Framework
 * @subpackage Queue
 * @since      3.2.0
 */
namespace Framework\Queue;

defined('ABSPATH') || exit;

use Framework\Cache\Concerns\InteractsWithTime;
use Framework\Supports\Facades\DB;
use Throwable;

use function Framework\app;
use function Framework\config;
use function Framework\uuid;

class DatabaseQueue
{
    use InteractsWithTime;

    /**
     * Settings that take precedence over config/queue.php.
     *
     * @var array
     *
     * @since 3.2.0
     */
    protected $options = [];

    /**
     * Whether the jobs table has been confirmed to exist during this request.
     *
     * @var bool
     *
     * @since 3.2.0
     */
    protected $table_confirmed = false;

    /**
     * Read a queue setting.
     *
     * @param string $key The setting name within config/queue.php.
     * @param mixed $default The value used when the setting is absent.
     *
     * @return mixed
     *
     * @since 3.2.0
     */
    public function option(string $key, $default = null)
    {
        if (array_key_exists($key, $this->options)) {
            return $this->options[$key];
        }

        return config('queue.' . $key, $default);
    }

    /**
     * Get the jobs table name without the WordPress table prefix.
     *
     * @return string
     *
     * @since 3.2.0
     */
    public function get_table_name()
    {
        return (string) $this->option('table', app()->prefix() . 'jobs');
    }

    /**
     * Get the failed jobs table name without the WordPress table prefix.
     *
     * @return string
     *
     * @since 3.2.0
     */
    public function get_failed_table_name()
    {
        return (string) $this->option('failed_table', app()->prefix() . 'failed_jobs');
    }

    /**
     * Get the full name of the jobs table.
     *
     * @return string
     *
     * @since 3.2.0
     */
    public function get_table()
    {
        return DB::get_table_prefix() . $this->get_table_name();
    }

    /**
     * Get the full name of the failed jobs table.
     *
     * @return string
     *
     * @since 3.2.0
     */
    public function get_failed_table()
    {
        return DB::get_table_prefix() . $this->get_failed_table_name();
    }

    /**
     * Get the current time as a UNIX timestamp.
     *
     * @return int
     *
     * @since 3.2.0
     */
    public function now()
    {
        return (int) $this->current_timestamp();
    }

    /**
     * Determine whether a job with the given delay would be available immediately.
     *
     * @param int|\DateTimeInterface|\DateInterval|null $delay The delay.
     *
     * @return bool
     *
     * @since 3.2.0
     */
    public function is_immediate($delay)
    {
        return $this->available_at($delay) <= $this->now();
    }

    /**
     * Determine whether the jobs table exists.
     *
     * Only a positive answer is remembered, so a table created mid-request is still found.
     *
     * @return bool
     *
     * @since 3.2.0
     */
    public function table_exists()
    {
        if ($this->table_confirmed) {
            return true;
        }

        $rows = DB::select(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s LIMIT 1',
            [$this->get_table()]
        );

        $this->table_confirmed = !empty($rows);

        return $this->table_confirmed;
    }

    /**
     * Store a job.
     *
     * @param string $payload The encoded payload.
     * @param string $queue The queue name.
     * @param int $priority The priority; higher runs first.
     * @param int|\DateTimeInterface|\DateInterval|null $delay The delay before it is available.
     *
     * @return int The new row id.
     *
     * @since 3.2.0
     */
    public function push(string $payload, string $queue, int $priority = 0, $delay = null)
    {
        DB::insert(
            "INSERT INTO {$this->get_table()} (queue, priority, payload, attempts, available_at, created_at)
             VALUES (%s, %d, %s, 0, %d, %d)",
            [$queue, $priority, $payload, $this->available_at($delay), $this->now()]
        );

        return (int) DB::get_db()->insert_id;
    }

    /**
     * Reserve up to the given number of due jobs for one worker.
     *
     * The attempt count is incremented as part of the claim, so a job that keeps killing its
     * worker still uses up its tries.
     *
     * @param int $limit The most jobs to reserve.
     * @param string $token The token that marks this claim's rows.
     * @param array|null $queues Restrict the claim to these queue names.
     *
     * @return \Framework\Queue\JobRecord[]
     *
     * @since 3.2.0
     */
    public function claim(int $limit, string $token, ?array $queues = null)
    {
        $now = $this->now();
        $bindings = [$now, $token, $now, $this->stale_before()];

        DB::update(
            "UPDATE {$this->get_table()}
                SET reserved_at = %d, reserved_by = %s, attempts = attempts + 1
              WHERE available_at <= %d
                AND (reserved_at IS NULL OR reserved_at <= %d)"
                . $this->queue_constraint($queues, $bindings) . "
              ORDER BY priority DESC, available_at ASC, id ASC
              LIMIT %d",
            array_merge($bindings, [$limit])
        );

        return $this->reserved($token);
    }

    /**
     * Get the rows reserved under a claim token.
     *
     * @param string $token The claim token.
     *
     * @return \Framework\Queue\JobRecord[]
     *
     * @since 3.2.0
     */
    public function reserved(string $token)
    {
        $rows = DB::select(
            "SELECT id, queue, payload, attempts FROM {$this->get_table()}
              WHERE reserved_by = %s
              ORDER BY priority DESC, available_at ASC, id ASC",
            [$token]
        );

        return array_map([JobRecord::class, 'from_row'], is_array($rows) ? $rows : []);
    }

    /**
     * Delete a finished job.
     *
     * @param int $id The row id.
     *
     * @return void
     *
     * @since 3.2.0
     */
    public function delete(int $id)
    {
        DB::delete("DELETE FROM {$this->get_table()} WHERE id = %d", [$id]);
    }

    /**
     * Put a job back on the queue after a delay; its attempt stays counted.
     *
     * @param int $id The row id.
     * @param int|\DateTimeInterface|\DateInterval|null $delay The delay before it is available.
     *
     * @return void
     *
     * @since 3.2.0
     */
    public function release(int $id, $delay = 0)
    {
        DB::update(
            "UPDATE {$this->get_table()}
                SET reserved_at = NULL, reserved_by = NULL, available_at = %d
              WHERE id = %d",
            [$this->available_at($delay), $id]
        );
    }

    /**
     * Hand back claimed jobs the worker never started, refunding the attempt the claim took.
     *
     * @param int[] $ids The row ids.
     *
     * @return void
     *
     * @since 3.2.0
     */
    public function release_unstarted(array $ids)
    {
        $ids = array_values(array_map('intval', $ids));

        if (empty($ids)) {
            return;
        }

        DB::update(
            "UPDATE {$this->get_table()}
                SET reserved_at = NULL, reserved_by = NULL, attempts = IF(attempts > 0, attempts - 1, 0)
              WHERE id IN (" . implode(', ', array_fill(0, count($ids), '%d')) . ')',
            $ids
        );
    }

    /**
     * Move a job to the failed jobs table.
     *
     * @param \Framework\Queue\JobRecord $record The claimed record.
     * @param \Throwable $exception Why it failed.
     *
     * @return void
     *
     * @since 3.2.0
     */
    public function fail(JobRecord $record, Throwable $exception)
    {
        $envelope = json_decode($record->payload(), true);

        DB::insert(
            "INSERT INTO {$this->get_failed_table()} (uuid, queue, payload, exception, failed_at)
             VALUES (%s, %s, %s, %s, %d)",
            [
                is_array($envelope) && !empty($envelope['uuid']) ? (string) $envelope['uuid'] : (string) uuid(),
                $record->queue(),
                $record->payload(),
                (string) $exception,
                $this->now(),
            ]
        );

        $this->delete($record->id());
    }

    /**
     * Determine whether any job is due, optionally within the given queues.
     *
     * @param array|null $queues Restrict the check to these queue names.
     *
     * @return bool
     *
     * @since 3.2.0
     */
    public function has_due(?array $queues = null)
    {
        $bindings = [$this->now(), $this->stale_before()];

        $rows = DB::select(
            "SELECT 1 FROM {$this->get_table()}
              WHERE available_at <= %d
                AND (reserved_at IS NULL OR reserved_at <= %d)"
                . $this->queue_constraint($queues, $bindings) . '
              LIMIT 1',
            $bindings
        );

        return !empty($rows);
    }

    /**
     * Count the jobs not currently being worked on, optionally on one queue.
     *
     * @param string|null $queue The queue name.
     *
     * @return int
     *
     * @since 3.2.0
     */
    public function size(?string $queue = null)
    {
        $bindings = [$this->stale_before()];

        $rows = DB::select(
            "SELECT COUNT(*) AS aggregate FROM {$this->get_table()}
              WHERE (reserved_at IS NULL OR reserved_at <= %d)"
                . $this->queue_constraint(is_null($queue) ? null : [$queue], $bindings),
            $bindings
        );

        return (int) ($rows[0]['aggregate'] ?? 0);
    }

    /**
     * Delete the jobs not currently being worked on, optionally on one queue.
     *
     * @param string|null $queue The queue name.
     *
     * @return int The number of jobs deleted.
     *
     * @since 3.2.0
     */
    public function clear(?string $queue = null)
    {
        $bindings = [$this->stale_before()];

        return (int) DB::delete(
            "DELETE FROM {$this->get_table()}
              WHERE (reserved_at IS NULL OR reserved_at <= %d)"
                . $this->queue_constraint(is_null($queue) ? null : [$queue], $bindings),
            $bindings
        );
    }

    /**
     * Get every failed job, newest first.
     *
     * @return array
     *
     * @since 3.2.0
     */
    public function failed_all()
    {
        $rows = DB::select(
            "SELECT id, uuid, queue, payload, exception, failed_at FROM {$this->get_failed_table()} ORDER BY id DESC"
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Find one failed job.
     *
     * @param int $id The failed job's id.
     *
     * @return array|null
     *
     * @since 3.2.0
     */
    public function failed_find(int $id)
    {
        $rows = DB::select(
            "SELECT id, uuid, queue, payload, exception, failed_at FROM {$this->get_failed_table()} WHERE id = %d",
            [$id]
        );

        return empty($rows) ? null : $rows[0];
    }

    /**
     * Move failed jobs back onto the queue with their attempts reset.
     *
     * @param int|string $id A failed job's id, or "all".
     *
     * @return int The number of jobs moved back.
     *
     * @since 3.2.0
     */
    public function retry($id)
    {
        if ($id === 'all') {
            $failed = $this->failed_all();
        } else {
            $failed = array_filter([$this->failed_find((int) $id)]);
        }

        foreach ($failed as $row) {
            $envelope = json_decode((string) $row['payload'], true);

            $this->push(
                (string) $row['payload'],
                (string) $row['queue'],
                is_array($envelope) ? (int) ($envelope['priority'] ?? 0) : 0
            );

            $this->forget((int) $row['id']);
        }

        return count($failed);
    }

    /**
     * Delete one failed job.
     *
     * @param int $id The failed job's id.
     *
     * @return bool Whether a row was deleted.
     *
     * @since 3.2.0
     */
    public function forget(int $id)
    {
        return (int) DB::delete("DELETE FROM {$this->get_failed_table()} WHERE id = %d", [$id]) > 0;
    }

    /**
     * Delete every failed job.
     *
     * @return int The number of rows deleted.
     *
     * @since 3.2.0
     */
    public function flush_failed()
    {
        return (int) DB::delete("DELETE FROM {$this->get_failed_table()}");
    }

    /**
     * Get the reservation time before which a reservation is considered abandoned.
     *
     * @return int
     *
     * @since 3.2.0
     */
    protected function stale_before()
    {
        return $this->now() - (int) $this->option('retry_after', 300);
    }

    /**
     * Get the moment a job with the given delay becomes available.
     *
     * @param int|\DateTimeInterface|\DateInterval|null $delay The delay.
     *
     * @return int
     *
     * @since 3.2.0
     */
    protected function available_at($delay)
    {
        $seconds = $this->seconds_until($delay);

        return $this->now() + max(0, (int) $seconds);
    }

    /**
     * Build the queue-name constraint and append its bindings.
     *
     * @param array|null $queues The queue names, or null for every queue.
     * @param array $bindings The bindings to append to.
     *
     * @return string
     *
     * @since 3.2.0
     */
    protected function queue_constraint(?array $queues, array &$bindings)
    {
        if (empty($queues)) {
            return '';
        }

        $queues = array_values(array_map('strval', $queues));
        array_push($bindings, ...$queues);

        return ' AND queue IN (' . implode(', ', array_fill(0, count($queues), '%s')) . ')';
    }
}
