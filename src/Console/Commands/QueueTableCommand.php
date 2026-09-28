<?php
/**
 * Generates the migrations for the queue's jobs and failed jobs tables.
 * It writes migration classes only and never runs DDL, so the tables arrive through the plugin's
 * own migration order. It is available before the queue provider is registered so the tables can
 * exist by the time the queue is switched on.
 *
 * @package    Framework
 * @subpackage Console\Commands
 * @since      3.2.0
 */
namespace Framework\Console\Commands;

defined('ABSPATH') || exit;

use Framework\Console\CommandBase;
use Framework\Queue\DatabaseQueue;
use Framework\Supports\Facades\File;
use Framework\Supports\Str;

use function Framework\app;
use function Framework\database_path;

class QueueTableCommand extends CommandBase
{
    /**
     * The migrations to generate, keyed by class name, with their stub and table getter.
     *
     * @var array
     *
     * @since 3.2.0
     */
    protected $migrations = [
        'CreateJobsTable' => ['queue-jobs-table.stub', 'get_table_name'],
        'CreateFailedJobsTable' => ['queue-failed-jobs-table.stub', 'get_failed_table_name'],
    ];

    /**
     * Prepare the command's synopsis and other metadata.
     *
     * @return void
     *
     * @since 3.2.0
     */
    protected function prepare()
    {
        $this->summary('Create the migrations for the queue tables')
            ->description("## EXAMPLES \n\n wp kirki queue:table");
    }

    /**
     * Run the command.
     *
     * @param mixed $args The positional arguments.
     * @param mixed $assoc The associative arguments.
     *
     * @return void
     *
     * @since 3.2.0
     */
    public function run($args, $assoc)
    {
        $directory = database_path('migrations');
        $existing = [];

        foreach (array_keys($this->migrations) as $class) {
            if (File::exists($this->output_file($directory, $class))) {
                $existing[] = $this->output_file($directory, $class);
            }
        }

        if (!empty($existing)) {
            $this->cli_error(sprintf('Migration files already exist: %s', implode(', ', $existing)));

            return;
        }

        $queue = app(DatabaseQueue::class);
        $namespace = app()->get_migrations_namespace();

        foreach ($this->migrations as $class => [$stub, $table_getter]) {
            $output_file = $this->output_file($directory, $class);

            File::make_dir($output_file);
            File::put($output_file, Str::replace(
                ['{{migrations_namespace}}', '{{class_name}}', '{{table}}'],
                [$namespace, $class, $queue->$table_getter()],
                File::get($this->stub_path() . '/' . $stub)
            ));

            $this->cli_success(sprintf('Migration [%s] created.', $class));
        }

        $this->cli_line('Add these to config/migrations.php, then run `migrate`:');

        foreach (array_keys($this->migrations) as $class) {
            $this->cli_line(sprintf('    \\%s\\%s::class,', $namespace, $class));
        }
    }

    /**
     * Get the path a migration class is written to.
     *
     * @param string $directory The migrations directory.
     * @param string $class The migration class name.
     *
     * @return string
     *
     * @since 3.2.0
     */
    protected function output_file(string $directory, string $class)
    {
        return sprintf('%s/%s.php', rtrim($directory, '/'), $class);
    }
}
