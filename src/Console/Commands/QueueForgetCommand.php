<?php
/**
 * Deletes one failed job without retrying it.
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

class QueueForgetCommand extends CommandBase
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
        $this->summary('Delete a failed queue job')
            ->description("## EXAMPLES \n\n wp kirki queue:forget 5")
            ->synopsis(
                Synopsis::type('positional')
                    ->name('id')
                    ->description('The failed job ID')
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
        return !empty($args[0]) && ctype_digit((string) $args[0]);
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
        if (!app(DatabaseQueue::class)->forget((int) $args[0])) {
            $this->cli_error(sprintf('No failed job with ID [%s].', $args[0]));

            return;
        }

        $this->cli_success(sprintf('Failed job [%s] deleted.', $args[0]));
    }
}
