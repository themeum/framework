<?php
/**
 * Deletes pending jobs from the queue without running them.
 * Jobs a worker is currently running are left alone.
 *
 * @package    Framework
 * @subpackage Console\Commands
 * @since      3.2.0
 */
namespace Framework\Console\Commands;

defined('ABSPATH') || exit;

use Framework\Console\CommandBase;
use Framework\Console\Synopsis;
use Framework\Queue\DatabaseQueue;

use function Framework\app;

class QueueClearCommand extends CommandBase
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
        $this->summary('Delete the pending jobs on the queue')
            ->description("## EXAMPLES \n\n wp kirki queue:clear\n\n wp kirki queue:clear --queue=emails")
            ->synopsis(
                Synopsis::type('assoc')
                    ->name('queue')
                    ->description('Only clear this queue')
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
        $deleted = app(DatabaseQueue::class)->clear($assoc['queue'] ?? null);

        $this->cli_success(sprintf('Deleted %d pending %s.', $deleted, $deleted === 1 ? 'job' : 'jobs'));
    }
}
