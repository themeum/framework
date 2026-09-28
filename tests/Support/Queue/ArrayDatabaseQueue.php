<?php

namespace Framework\Tests\Support\Queue;

use Framework\Queue\DatabaseQueue;
use Framework\Queue\JobRecord;
use Framework\Tests\Support\Cache\FreezesTime;
use Throwable;

/**
 * The queue's storage kept in arrays, driven by a frozen clock.
 *
 * It mirrors the SQL in DatabaseQueue row for row — the due condition, the stale reclaim, the
 * claim order, the attempt bookkeeping — so the worker can be tested without a database. The SQL
 * itself is covered separately against TestWpdb.
 */
class ArrayDatabaseQueue extends DatabaseQueue
{
    use FreezesTime;

    public array $rows = [];

    public array $failed = [];

    public bool $exists = true;

    public array $claims = [];

    protected int $next_id = 1;

    protected int $next_failed_id = 1;

    public function with_options(array $options): self
    {
        $this->options = array_merge($this->options, $options);

        return $this;
    }

    public function get_table()
    {
        return 'wp_' . $this->get_table_name();
    }

    public function get_failed_table()
    {
        return 'wp_' . $this->get_failed_table_name();
    }

    public function table_exists()
    {
        return $this->exists;
    }

    public function push(string $payload, string $queue, int $priority = 0, $delay = null)
    {
        $id = $this->next_id++;

        $this->rows[$id] = [
            'id' => $id,
            'queue' => $queue,
            'priority' => $priority,
            'payload' => $payload,
            'attempts' => 0,
            'reserved_at' => null,
            'reserved_by' => null,
            'available_at' => $this->available_at($delay),
            'created_at' => $this->now(),
        ];

        return $id;
    }

    public function claim(int $limit, string $token, ?array $queues = null)
    {
        $due = $this->due_rows($queues);

        usort($due, function ($a, $b) {
            return [$b['priority'], $a['available_at'], $a['id']] <=> [$a['priority'], $b['available_at'], $b['id']];
        });

        $claimed = array_slice($due, 0, $limit);
        $this->claims[] = count($claimed);

        foreach ($claimed as $row) {
            $this->rows[$row['id']]['reserved_at'] = $this->now();
            $this->rows[$row['id']]['reserved_by'] = $token;
            $this->rows[$row['id']]['attempts']++;
        }

        return $this->reserved($token);
    }

    public function reserved(string $token)
    {
        $rows = array_values(array_filter($this->rows, function ($row) use ($token) {
            return $row['reserved_by'] === $token;
        }));

        usort($rows, function ($a, $b) {
            return [$b['priority'], $a['available_at'], $a['id']] <=> [$a['priority'], $b['available_at'], $b['id']];
        });

        return array_map([JobRecord::class, 'from_row'], $rows);
    }

    public function delete(int $id)
    {
        unset($this->rows[$id]);
    }

    public function release(int $id, $delay = 0)
    {
        $this->rows[$id]['reserved_at'] = null;
        $this->rows[$id]['reserved_by'] = null;
        $this->rows[$id]['available_at'] = $this->available_at($delay);
    }

    public function release_unstarted(array $ids)
    {
        foreach ($ids as $id) {
            $this->rows[$id]['reserved_at'] = null;
            $this->rows[$id]['reserved_by'] = null;
            $this->rows[$id]['attempts'] = max(0, $this->rows[$id]['attempts'] - 1);
        }
    }

    public function fail(JobRecord $record, Throwable $exception)
    {
        $id = $this->next_failed_id++;
        $envelope = json_decode($record->payload(), true);

        $this->failed[$id] = [
            'id' => $id,
            'uuid' => $envelope['uuid'] ?? 'uuid-' . $id,
            'queue' => $record->queue(),
            'payload' => $record->payload(),
            'exception' => (string) $exception,
            'failed_at' => $this->now(),
        ];

        $this->delete($record->id());
    }

    public function has_due(?array $queues = null)
    {
        return !empty($this->due_rows($queues));
    }

    public function size(?string $queue = null)
    {
        return count($this->waiting_rows($queue));
    }

    public function clear(?string $queue = null)
    {
        $waiting = $this->waiting_rows($queue);

        foreach ($waiting as $row) {
            unset($this->rows[$row['id']]);
        }

        return count($waiting);
    }

    public function failed_all()
    {
        return array_reverse(array_values($this->failed));
    }

    public function failed_find(int $id)
    {
        return $this->failed[$id] ?? null;
    }

    public function forget(int $id)
    {
        $existed = isset($this->failed[$id]);
        unset($this->failed[$id]);

        return $existed;
    }

    public function flush_failed()
    {
        $count = count($this->failed);
        $this->failed = [];

        return $count;
    }

    public function row(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function payload_of(int $id): array
    {
        return json_decode($this->rows[$id]['payload'], true);
    }

    protected function is_claimable(array $row): bool
    {
        return is_null($row['reserved_at']) || $row['reserved_at'] <= $this->stale_before();
    }

    protected function due_rows(?array $queues): array
    {
        return array_values(array_filter($this->rows, function ($row) use ($queues) {
            return $row['available_at'] <= $this->now()
                && $this->is_claimable($row)
                && (empty($queues) || in_array($row['queue'], $queues, true));
        }));
    }

    protected function waiting_rows(?string $queue): array
    {
        return array_values(array_filter($this->rows, function ($row) use ($queue) {
            return $this->is_claimable($row) && (is_null($queue) || $row['queue'] === $queue);
        }));
    }
}
