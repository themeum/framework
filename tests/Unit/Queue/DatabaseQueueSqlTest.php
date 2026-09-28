<?php

namespace Framework\Tests\Unit\Queue;

use Framework\Database\Connection\Connection;
use Framework\Queue\DatabaseQueue;
use Framework\Queue\JobRecord;
use Framework\Tests\Support\Cache\FreezesTime;
use Framework\Tests\Support\Database\TestWpdb;
use Framework\Tests\Unit\TestCase;
use RuntimeException;

class DatabaseQueueSqlTest extends TestCase
{
    protected const NOW = 1700000000;

    protected TestWpdb $wpdb;

    protected DatabaseQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;

        $app = $this->bootstrap_application();
        $app->use_prefix('kirki_');

        $wpdb = new TestWpdb();
        $wpdb->insert_id = 7;
        $wpdb->rows_affected = 0;
        $this->wpdb = $wpdb;
        $app->instance(Connection::class, new Connection());

        $this->queue = new class extends DatabaseQueue {
            use FreezesTime;
        };
        $this->queue->freeze(self::NOW);
    }

    public function test_table_names_carry_the_wordpress_and_app_prefixes(): void
    {
        $this->assertSame('wp_kirki_jobs', $this->queue->get_table());
        $this->assertSame('wp_kirki_failed_jobs', $this->queue->get_failed_table());
        $this->assertSame('kirki_jobs', $this->queue->get_table_name());
    }

    public function test_push_binds_the_computed_availability_and_returns_the_insert_id(): void
    {
        $id = $this->queue->push('{"job":"X"}', 'emails', 5, 60);

        $this->assertSame(7, $id);
        $this->assertStringContainsString('INSERT INTO wp_kirki_jobs', $this->last_query());
        $this->assertStringContainsString("VALUES ('emails', 5, '{\\\"job\\\":\\\"X\\\"}', 0, 1700000060, 1700000000)", $this->last_query());
    }

    public function test_claim_is_one_ordered_limited_update_then_a_select_by_token(): void
    {
        $this->queue->claim(10, 'tok');

        [$update, $select] = $this->wpdb->queries;

        $this->assertStringContainsString('UPDATE wp_kirki_jobs', $update);
        $this->assertStringContainsString(
            "SET reserved_at = 1700000000, reserved_by = 'tok', attempts = attempts + 1",
            $update
        );
        $this->assertStringContainsString('WHERE available_at <= 1700000000', $update);
        $this->assertStringContainsString('AND (reserved_at IS NULL OR reserved_at <= 1699999700)', $update);
        $this->assertStringContainsString('ORDER BY priority DESC, available_at ASC, id ASC', $update);
        $this->assertStringContainsString('LIMIT 10', $update);
        $this->assertStringNotContainsString('queue IN', $update);

        $this->assertStringContainsString("WHERE reserved_by = 'tok'", $select);
    }

    public function test_claim_can_be_restricted_to_queues(): void
    {
        $this->queue->claim(5, 'tok', ['emails', 'default']);

        $this->assertStringContainsString("AND queue IN ('emails', 'default')", $this->wpdb->queries[0]);
        $this->assertStringContainsString('LIMIT 5', $this->wpdb->queries[0]);
    }

    public function test_has_due_is_a_single_row_probe(): void
    {
        $this->queue->has_due();

        $this->assertStringContainsString('SELECT 1 FROM wp_kirki_jobs', $this->last_query());
        $this->assertStringContainsString('LIMIT 1', $this->last_query());
    }

    public function test_release_unstarted_refunds_the_attempt(): void
    {
        $this->queue->release_unstarted([3, 4]);

        $this->assertStringContainsString('attempts = IF(attempts > 0, attempts - 1, 0)', $this->last_query());
        $this->assertStringContainsString('WHERE id IN (3, 4)', $this->last_query());
    }

    public function test_release_unstarted_with_nothing_runs_no_query(): void
    {
        $this->queue->release_unstarted([]);

        $this->assertSame([], $this->wpdb->queries);
    }

    public function test_fail_records_the_payload_uuid_and_deletes_the_job(): void
    {
        $record = new JobRecord(9, 'emails', '{"uuid":"abc-123","job":"X"}', 2);

        $this->queue->fail($record, new RuntimeException('nope'));

        [$insert, $delete] = $this->wpdb->queries;
        $this->assertStringContainsString("INSERT INTO wp_kirki_failed_jobs", $insert);
        $this->assertStringContainsString("'abc-123', 'emails'", $insert);
        $this->assertStringContainsString('DELETE FROM wp_kirki_jobs WHERE id = 9', $delete);
    }

    public function test_a_configured_table_name_is_used(): void
    {
        $queue = new class extends DatabaseQueue {
            protected $options = ['table' => 'custom_jobs'];
        };

        $this->assertSame('wp_custom_jobs', $queue->get_table());
    }

    protected function last_query(): string
    {
        return (string) end($this->wpdb->queries);
    }
}
