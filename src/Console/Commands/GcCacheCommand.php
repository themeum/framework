<?php
/**
 * Sweeps expired entries from the cache stores that maintain their own storage.
 * Runs the same bounded sweep the schedule performs, so it can be exercised without waiting for
 * the next scheduled run.
 *
 * @package    Framework
 * @subpackage Console\Commands
 * @since      1.0.0
 */
namespace Framework\Console\Commands;

defined('ABSPATH') || exit;

use Framework\Console\CommandBase;
use Framework\Supports\Facades\Cache;
use Throwable;

class GcCacheCommand extends CommandBase
{
    /**
     * Run the command
     *
     * @param mixed $args The positional arguments.
     * @param mixed $assoc The associative arguments.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function run($args, $assoc)
    {
        try {
            $removed = Cache::collect_garbage($assoc['store'] ?? null);
        } catch (Throwable $exception) {
            $this->cli_error($exception->getMessage());

            return;
        }

        $this->cli_success(sprintf('Removed %d expired cache %s.', $removed, $removed === 1 ? 'entry' : 'entries'));
    }
}
