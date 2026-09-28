<?php
/**
 * Lists the jobs that used up their tries and were moved to the failed jobs table.
 *
 * @package    Framework
 * @subpackage Console\Commands
 * @since      3.2.0
 */
namespace Framework\Console\Commands;

defined('ABSPATH') || exit;

use Framework\Console\CommandBase;
use Framework\Queue\DatabaseQueue;
use Framework\Queue\Payload;

use function Framework\app;

class QueueFailedCommand extends CommandBase
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
        $this->summary('List the failed queue jobs')
            ->description("## EXAMPLES \n\n wp kirki queue:failed");
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
        $rows = app(DatabaseQueue::class)->failed_all();

        if (empty($rows)) {
            $this->cli_line('No failed jobs.');

            return;
        }

        call_user_func(
            '\\WP_CLI\\Utils\\format_items',
            'table',
            array_map(function (array $row) {
                $exception = strtok((string) $row['exception'], "\n");

                return [
                    'ID' => $row['id'],
                    'Queue' => $row['queue'],
                    'Job' => Payload::display_name((string) $row['payload']),
                    'Failed At' => gmdate('Y-m-d H:i:s', (int) $row['failed_at']),
                    'Exception' => $exception === false ? '' : $exception,
                ];
            }, $rows),
            ['ID', 'Queue', 'Job', 'Failed At', 'Exception']
        );
    }
}
