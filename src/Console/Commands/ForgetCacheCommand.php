<?php
/**
 * Removes a single key from a cache store.
 * Keys are stored under a derived identifier, so this is the supported way to invalidate one
 * entry from outside the running application.
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

class ForgetCacheCommand extends CommandBase
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
        $key = $args[0] ?? null;

        if (is_null($key) || $key === '') {
            $this->cli_error('A cache key is required, for example: wp cache:forget my-key');

            return;
        }

        try {
            $repository = Cache::store($assoc['store'] ?? null);
        } catch (Throwable $exception) {
            $this->cli_error($exception->getMessage());

            return;
        }

        $repository->forget((string) $key);

        $this->cli_success(sprintf('Removed [%s] from the [%s] cache store.', $key, $repository->get_name()));
    }
}
