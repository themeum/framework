<?php
/**
 * Opts a plugin into the background queue.
 * Unlike the framework's core providers this one is not registered automatically: a plugin that
 * never queues anything should not pay for an every-minute cron event, an AJAX endpoint, or a
 * query against a table it never created. Adding it to bootstrap/providers.php is the switch.
 *
 * @package    Framework
 * @subpackage Queue
 * @since      3.2.0
 */
namespace Framework\Queue;

defined('ABSPATH') || exit;

use Framework\Console\Commands\QueueClearCommand;
use Framework\Console\Commands\QueueFailedCommand;
use Framework\Console\Commands\QueueFlushCommand;
use Framework\Console\Commands\QueueForgetCommand;
use Framework\Console\Commands\QueueRetryCommand;
use Framework\Console\Commands\QueueWorkCommand;
use Framework\Http\Superglobals;
use Framework\ServiceProvider;
use Framework\Supports\Facades\Command;
use Throwable;

class QueueServiceProvider extends ServiceProvider
{
    /**
     * Bind the queue services.
     *
     * @return void
     *
     * @since 3.2.0
     */
    public function register()
    {
        $this->app->singleton(DatabaseQueue::class);
        $this->app->singleton(Worker::class);
        $this->app->singleton(Spawner::class);
        $this->app->singleton(QueueManager::class);
    }

    /**
     * Wire the sweep schedule, the worker endpoint, and the queue commands.
     *
     * @return void
     *
     * @since 3.2.0
     */
    public function boot()
    {
        if (function_exists('add_action')) {
            $this->register_sweep();
            $this->register_worker_endpoint();
        }

        if ($this->app->is_cli_available()) {
            $this->register_commands();
        }
    }

    /**
     * Add the every-minute schedule and the recurring sweep event.
     *
     * @return void
     *
     * @since 3.2.0
     */
    protected function register_sweep()
    {
        $schedule = $this->app->prefix() . 'queue_every_minute';
        $hook = $this->app->prefix() . 'queue_sweep';

        add_filter('cron_schedules', function ($schedules) use ($schedule) {
            $schedules[$schedule] = [
                'interval' => MINUTE_IN_SECONDS,
                'display' => __('Every minute (queue sweep)'),
            ];

            return $schedules;
        });

        add_action($hook, function () {
            try {
                $this->app->make(Sweeper::class)->sweep();
            } catch (Throwable $exception) {
                // A sweep that cannot run must never fail the request that triggered it.
            }
        });

        if (function_exists('wp_next_scheduled') && !wp_next_scheduled($hook)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, $schedule, $hook);
        }
    }

    /**
     * Register the AJAX action the loopback request is sent to.
     *
     * Both the logged-in and logged-out variants are hooked because which one fires depends on
     * the cookies the request carries; the signature, not the session, is the credential.
     *
     * @return void
     *
     * @since 3.2.0
     */
    protected function register_worker_endpoint()
    {
        $callback = function () {
            $accepted = $this->app->make(Spawner::class)->handle_request(Superglobals::post());

            wp_die('', '', ['response' => $accepted ? 200 : 403]);
        };

        add_action('wp_ajax_nopriv_' . Spawner::action(), $callback);
        add_action('wp_ajax_' . Spawner::action(), $callback);
    }

    /**
     * Register the queue commands that need the queue to be enabled.
     *
     * @return void
     *
     * @since 3.2.0
     */
    protected function register_commands()
    {
        $commands = [
            'queue:work' => QueueWorkCommand::class,
            'queue:failed' => QueueFailedCommand::class,
            'queue:retry' => QueueRetryCommand::class,
            'queue:forget' => QueueForgetCommand::class,
            'queue:flush' => QueueFlushCommand::class,
            'queue:clear' => QueueClearCommand::class,
        ];

        foreach ($commands as $command => $class) {
            Command::register($command, $class);
        }
    }
}
