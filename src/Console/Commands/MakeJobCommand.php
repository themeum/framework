<?php
/**
 * Creates a queued job class in the plugin's app/Jobs folder.
 * The generated class implements ShouldQueue and uses Queueable, so it can be dispatched as soon
 * as its handle() method is filled in.
 *
 * @package    Framework
 * @subpackage Console\Commands
 * @since      3.2.0
 */
namespace Framework\Console\Commands;

defined('ABSPATH') || exit;

use Framework\Console\CommandBase;
use Framework\Console\Synopsis;
use Framework\Supports\Facades\File;
use Framework\Supports\Str;

use function Framework\app;
use function Framework\app_path;

class MakeJobCommand extends CommandBase
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
        $this->summary('Create a new queued job class')
            ->description("## EXAMPLES \n\n wp kirki make:job SendAbandonedCartEmail")
            ->synopsis(
                Synopsis::type('positional')
                    ->name('name')
                    ->description('The job class name')
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
        return !empty($args[0]);
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
        $class = Str::pascal((string) $args[0]);
        $namespace = app()->qualify_app_namespace('Jobs');
        $output_file = app_path('Jobs/' . $class . '.php');

        if (File::exists($output_file)) {
            $this->cli_error(sprintf('Job file already exists: %s', $output_file));

            return;
        }

        File::make_dir($output_file);
        File::put($output_file, Str::replace(
            ['{{namespace}}', '{{class_name}}'],
            [$namespace, $class],
            File::get($this->stub_path() . '/job.stub')
        ));

        $this->cli_success(sprintf('Job [%s] created.', $namespace . '\\' . $class));
    }
}
