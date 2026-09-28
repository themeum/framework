<?php
/**
 * Puts failed jobs back on the queue with their attempts reset.
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

class QueueRetryCommand extends CommandBase
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
        $this->summary('Retry failed queue jobs')
            ->description("## EXAMPLES \n\n wp kirki queue:retry 5\n\n wp kirki queue:retry all")
            ->synopsis(
                Synopsis::type('positional')
                    ->name('id')
                    ->description('The failed job ID, or "all"')
            );
    }

    /**
     * Check if the command passed the validation.
     *
     * @param mixed $args The positional arguments.
     * @param mixed $assoc The associative arguments.
     *
     * @return bool
     *
     * @since 3.2.0
     */
    protected function passed($args, $assoc)
    {
        return !empty($args[0]) && ($args[0] === 'all' || ctype_digit((string) $args[0]));
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
        $retried = app(DatabaseQueue::class)->retry($args[0] === 'all' ? 'all' : (int) $args[0]);

        if ($retried === 0) {
            $this->cli_warning('No matching failed jobs.');

            return;
        }

        $this->cli_success(sprintf(
            'Pushed %d failed %s back onto the queue.',
            $retried,
            $retried === 1 ? 'job' : 'jobs'
        ));
    }
}
