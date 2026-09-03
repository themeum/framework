<?php
/**
 * Removes every entry from a cache store.
 * Operates on the default store unless another is named, and reports what was cleared.
 * The transient backed store clears in constant time by advancing its namespace version.
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

class ClearCacheCommand extends CommandBase
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
        $name = $assoc['store'] ?? null;

        try {
            $repository = Cache::store($name);
        } catch (Throwable $exception) {
            $this->cli_error($exception->getMessage());

            return;
        }

        if ($repository->flush()) {
            $this->cli_success(sprintf('Cleared the [%s] cache store.', $repository->get_name()));

            return;
        }

        $this->cli_warning(sprintf('The [%s] cache store reported nothing was cleared.', $repository->get_name()));
    }
}
