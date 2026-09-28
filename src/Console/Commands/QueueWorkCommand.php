<?php
/**
 * Processes due queue jobs in the WP-CLI process.
 * This is the fallback for hosts where loopback requests are blocked or traffic is too low for
 * WP-Cron to be timely: run it from a system cron. It uses no loopback and no chain lock, and it
 * has no time budget because a CLI process is not bound by max_execution_time.
 *
 * @package    Framework
 * @subpackage Console\Commands
 * @since      3.2.0
 */
namespace Framework\Console\Commands;

defined('ABSPATH') || exit;

use Framework\Console\CommandBase;
use Framework\Console\Synopsis;
use Framework\Queue\Worker;

use function Framework\app;

class QueueWorkCommand extends CommandBase
{
    /**
     * Prepare the command's synopsis and other metadata.
     *
     * @return void
     *
     * @since 3.2.0
     */
    protected function prepare()
    {
        $this->summary('Process the jobs that are due on the queue')
            ->description("## EXAMPLES \n\n wp kirki queue:work\n\n wp kirki queue:work --once --queue=emails")
            ->synopsis(
                Synopsis::type('flag')
                    ->name('once')
                    ->description('Process a single job and stop')
                    ->optional()
            )
            ->synopsis(
                Synopsis::type('assoc')
                    ->name('max-jobs')
                    ->description('Stop after processing this many jobs')
                    ->optional()
            )
            ->synopsis(
                Synopsis::type('assoc')
                    ->name('queue')
                    ->description('Only process these queues, comma separated')
                    ->optional()
            );
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
        $max_jobs = !empty($assoc['once']) ? 1 : ($assoc['max-jobs'] ?? null);
        $queues = isset($assoc['queue'])
            ? array_filter(array_map('trim', explode(',', (string) $assoc['queue'])))
            : null;

        $remaining = app(Worker::class)->run([
            'budget' => null,
            'max_jobs' => is_null($max_jobs) ? null : (int) $max_jobs,
            'queues' => empty($queues) ? null : array_values($queues),
        ]);

        $this->cli_success($remaining ? 'Stopped with jobs still due.' : 'No jobs are due.');
    }
}
