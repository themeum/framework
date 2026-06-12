<?php

namespace Framework\Wordpress;

use Framework\ServiceProvider;
use Framework\Wordpress\Hooks\Actions\RegisterRestApi;

class HookServiceProvider extends ServiceProvider
{
    protected static $defaults = [
        'actions' => [RegisterRestApi::class],
        'filters' => [],
    ];

    /**
     * Register the hooks to the application.
     *
     * @return void
     */
    public function register()
    {
        $hooks = $this->hooks();

        $this->app->tag($hooks['actions'], 'hook.actions');
        $this->app->tag($hooks['filters'], 'hook.filters');
    }

    /**
     * Get the hooks from the configuration file.
     *
     * @return array
     * @since 1.0.0
     */
    protected function hooks()
    {
        $hooks_path = $this->app->config_path('hooks.php');

        if (!file_exists($hooks_path)) {
            return static::$defaults;
        }

        $hooks = include $hooks_path;

        if (empty($hooks)) {
            return static::$defaults;
        }

        if (!in_array(RegisterRestApi::class, $hooks['actions'], true)) {
            array_unshift($hooks['actions'], RegisterRestApi::class);
        }

        return [
            'actions' => $hooks['actions'] ?? [],
            'filters' => $hooks['filters'] ?? [],
        ];
    }

    /**
     * Add the action hooks on after the application booted.
     *
     * @return void
     */
    public function boot()
    {
        $actions = $this->app->tagged('hook.actions');

        foreach ($actions as $action) {
            add_action(
                $action->get_name(),
                [$action, 'handle'],
                $action->get_priority(),
                $action->get_args_count()
            );
        }

        $filters = $this->app->tagged('hook.filters');

        foreach ($filters as $filter) {
            add_filter(
                $filter->get_name(),
                [$filter, 'handle'],
                $filter->get_priority(),
                $filter->get_args_count()
            );
        }
    }
}
