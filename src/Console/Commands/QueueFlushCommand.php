<?php
/**
 * Deletes every failed job.
 *
 * @package    Framework
 * @subpackage Console\Commands
 * @since      3.2.0
 */
namespace Framework\Console\Commands;

defined('ABSPATH') || exit;

use Framework\Console\CommandBase;
use Framework\Queue\DatabaseQueue;

use function Framework\app;

class QueueFlushCommand extends CommandBase
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
        $this->summary('Delete all failed queue jobs')
            ->description("## EXAMPLES \n\n wp kirki queue:flush");
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
        $deleted = app(DatabaseQueue::class)->flush_failed();

        $this->cli_success(sprintf('Deleted %d failed %s.', $deleted, $deleted === 1 ? 'job' : 'jobs'));
    }
}
