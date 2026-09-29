<?php

namespace Framework\Tests\Unit\Queue;

use Framework\Application;
use Framework\Console\Commands\MakeJobCommand;
use Framework\Console\Commands\QueueClearCommand;
use Framework\Console\Commands\QueueFlushCommand;
use Framework\Console\Commands\QueueForgetCommand;
use Framework\Console\Commands\QueueRetryCommand;
use Framework\Console\Commands\QueueTableCommand;
use Framework\Console\Commands\QueueWorkCommand;
use Framework\Filesystem\Filesystem;
use Framework\Queue\JobRecord;
use Framework\Tests\Support\Cache\TestFilesystem;
use Framework\Tests\Support\Queue\Jobs\Journal;
use Framework\Tests\Support\Queue\Jobs\SendEmail;
use RuntimeException;

class QueueCommandsTest extends QueueTestCase
{
    protected string $base;

    protected function make_application(): Application
    {
        $this->base = sys_get_temp_dir() . '/framework-queue-commands-' . uniqid();

        mkdir($this->base . '/app', 0777, true);
        mkdir($this->base . '/database/migrations', 0777, true);
        file_put_contents($this->base . '/composer.json', json_encode([
            'autoload' => [
                'psr-4' => [
                    'Acme\\Shop\\' => 'app/',
                    'Acme\\Shop\\Database\\Migrations\\' => 'database/migrations/',
                ],
            ],
        ]));

        $this->reset_container_instance();

        $app = Application::get_instance($this->base);
        $app->use_prefix('shop_');
        $app->instance(Filesystem::class, new TestFilesystem());

        return $app;
    }

    protected function tearDown(): void
    {
        (new TestFilesystem())->delete($this->base, true);

        parent::tearDown();
    }

    public function test_queue_table_writes_two_migrations_without_running_any_sql(): void
    {
        (new QueueTableCommand())->run([], []);

        $jobs = $this->read('database/migrations/CreateJobsTable.php');
        $failed = $this->read('database/migrations/CreateFailedJobsTable.php');

        $this->assertStringContainsString('namespace Acme\\Shop\\Database\\Migrations;', $jobs);
        $this->assertStringContainsString('class CreateJobsTable implements Migration', $jobs);
        $this->assertStringContainsString("Schema::create('shop_jobs'", $jobs);
        $this->assertStringContainsString("\$table->string('reserved_by', 32)->nullable();", $jobs);
        $this->assertStringContainsString("Schema::drop_if_exists('shop_jobs')", $jobs);

        $this->assertStringContainsString('class CreateFailedJobsTable implements Migration', $failed);
        $this->assertStringContainsString("Schema::create('shop_failed_jobs'", $failed);
        $this->assertStringContainsString("app()->prefix() . 'jobs_reserved_at_available_at_priority_index'", $jobs);
        $this->assertStringContainsString("\$table->index('reserved_by', app()->prefix() . 'jobs_reserved_by_index');", $jobs);
        $this->assertStringContainsString("\$table->unique('uuid', app()->prefix() . 'failed_jobs_uuid_unique');", $failed);

        $this->assert_valid_php($jobs);
        $this->assert_valid_php($failed);
        $this->assertContains(
            ['line', '    \\Acme\\Shop\\Database\\Migrations\\CreateJobsTable::class,'],
            \WP_CLI::$messages
        );
    }

    public function test_queue_table_refuses_to_overwrite(): void
    {
        (new QueueTableCommand())->run([], []);
        file_put_contents($this->base . '/database/migrations/CreateJobsTable.php', 'edited');

        try {
            (new QueueTableCommand())->run([], []);
            $this->fail('The command did not stop.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('CreateJobsTable.php', $exception->getMessage());
        }

        $this->assertSame('edited', $this->read('database/migrations/CreateJobsTable.php'));
    }

    public function test_make_job_writes_a_dispatchable_job_class(): void
    {
        (new MakeJobCommand())->run(['send_abandoned_cart_email'], []);

        $job = $this->read('app/Jobs/SendAbandonedCartEmail.php');

        $this->assertStringContainsString('namespace Acme\\Shop\\Jobs;', $job);
        $this->assertStringContainsString('class SendAbandonedCartEmail implements ShouldQueue', $job);
        $this->assertStringContainsString('use Queueable;', $job);
        $this->assertStringContainsString('use SerializesModels;', $job);
        $this->assertStringContainsString('public function handle()', $job);
        $this->assert_valid_php($job);

        $this->expectException(RuntimeException::class);

        (new MakeJobCommand())->run(['SendAbandonedCartEmail'], []);
    }

    public function test_queue_work_once_processes_a_single_job(): void
    {
        $this->enable_queue();
        SendEmail::dispatch(1);
        SendEmail::dispatch(2);
        SendEmail::dispatch(3);

        (new QueueWorkCommand())->run([], ['once' => true]);

        $this->assertSame([1], Journal::values('handled'));
        $this->assertCount(2, $this->queue->rows);
    }

    public function test_queue_work_can_be_restricted_to_queues(): void
    {
        $this->enable_queue();
        SendEmail::dispatch('a');
        SendEmail::dispatch('b')->on_queue('emails');
        SendEmail::dispatch('c')->on_queue('reports');

        (new QueueWorkCommand())->run([], ['queue' => 'emails, reports']);

        $this->assertSame(['b', 'c'], Journal::values('handled'));
        $this->assertCount(1, $this->queue->rows);
    }

    public function test_queue_retry_all_moves_failed_jobs_back_with_attempts_reset(): void
    {
        $this->enable_queue();
        SendEmail::dispatch(1)->with_priority(7);
        SendEmail::dispatch(2)->on_queue('emails');
        $this->fail_row(1);
        $this->fail_row(2);

        (new QueueRetryCommand())->run(['all'], []);

        $this->assertSame([], $this->queue->failed);
        $this->assertCount(2, $this->queue->rows);

        $rows = array_values($this->queue->rows);
        $this->assertSame([0, 0], array_column($rows, 'attempts'));
        $this->assertEqualsCanonicalizing(['default', 'emails'], array_column($rows, 'queue'));
        $this->assertContains(7, array_column($rows, 'priority'));
    }

    public function test_queue_retry_one_leaves_the_others(): void
    {
        $this->enable_queue();
        SendEmail::dispatch(1);
        SendEmail::dispatch(2);
        $this->fail_row(1);
        $this->fail_row(2);

        (new QueueRetryCommand())->run(['2'], []);

        $this->assertSame([1], array_keys($this->queue->failed));
        $this->assertCount(1, $this->queue->rows);
    }

    public function test_queue_forget_and_flush(): void
    {
        $this->enable_queue();
        SendEmail::dispatch(1);
        SendEmail::dispatch(2);
        SendEmail::dispatch(3);
        $this->fail_row(1);
        $this->fail_row(2);
        $this->fail_row(3);

        (new QueueForgetCommand())->run(['1'], []);
        $this->assertSame([2, 3], array_keys($this->queue->failed));

        (new QueueFlushCommand())->run([], []);
        $this->assertSame([], $this->queue->failed);

        $this->expectException(RuntimeException::class);

        (new QueueForgetCommand())->run(['99'], []);
    }

    public function test_queue_clear_deletes_pending_jobs_optionally_by_queue(): void
    {
        $this->enable_queue();
        SendEmail::dispatch(1);
        SendEmail::dispatch(2)->on_queue('emails');
        SendEmail::dispatch(3)->on_queue('emails');

        (new QueueClearCommand())->run([], ['queue' => 'emails']);
        $this->assertCount(1, $this->queue->rows);

        (new QueueClearCommand())->run([], []);
        $this->assertCount(0, $this->queue->rows);
    }

    protected function fail_row(int $id): void
    {
        $this->queue->fail(JobRecord::from_row($this->queue->row($id)), new RuntimeException('failed'));
    }

    protected function read(string $relative): string
    {
        return (string) file_get_contents($this->base . '/' . $relative);
    }

    protected function assert_valid_php(string $code): void
    {
        token_get_all($code, TOKEN_PARSE);

        $this->addToAssertionCount(1);
    }
}
