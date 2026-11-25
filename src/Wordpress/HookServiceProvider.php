<?php
/**
 * Loads action and filter hook classes from config/hooks.php and tags them for container discovery.
 * Ensures RegisterRestApi is always registered even when config is empty.
 * Boots hook instances onto WordPress during application boot.
 *
 * @package    Framework
 * @subpackage Wordpress
 * @since      1.0.0
 */
namespace Framework\Wordpress;

defined('ABSPATH') || exit;

use Framework\ServiceProvider;
use Framework\Wordpress\Hooks\Actions\FlushQueuedCookies;
use Framework\Wordpress\Hooks\Actions\InvalidateSessionOnLogout;
use Framework\Wordpress\Hooks\Actions\MigrateSessionOnLogin;
use Framework\Wordpress\Hooks\Actions\SaveSession;
use Framework\Wordpress\Hooks\Actions\VersionUpdate;
use Framework\Wordpress\Hooks\Actions\RegisterRestApi;
use Framework\Wordpress\Hooks\Actions\RegisterSiteRoutes;
use Framework\Wordpress\Hooks\Filters\FlushRestCookies;

class HookServiceProvider extends ServiceProvider
{
    /**
     * The defaults.
     *
     * @var array<string, array<string>>
     *
     * @since 1.0.0
     */
    protected static $defaults = [
        'actions' => [
            RegisterRestApi::class,
            RegisterSiteRoutes::class,
            VersionUpdate::class,
            FlushQueuedCookies::class,
            SaveSession::class,
            MigrateSessionOnLogin::class,
            InvalidateSessionOnLogout::class,
        ],
        'filters' => [FlushRestCookies::class],
    ];

    /**
     * Register the hooks to the application.
     *
     * @return void
     *
     * @since 1.0.0
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
     *
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

        return [
            'actions' => $this->merge_defaults($hooks['actions'] ?? [], 'actions'),
            'filters' => $this->merge_defaults($hooks['filters'] ?? [], 'filters'),
        ];
    }

    /**
     * Merge the framework default hooks into the configured ones.
     *
     * Defaults are prepended so the framework hooks register before application hooks,
     * and are skipped when the application already lists them.
     *
     * @param array $configured The hooks defined by the application.
     * @param string $type The hook group, either actions or filters.
     *
     * @return array
     *
     * @since 1.0.0
     */
    protected function merge_defaults(array $configured, string $type)
    {
        $missing = [];

        foreach (static::$defaults[$type] as $hook) {
            if (!in_array($hook, $configured, true)) {
                $missing[] = $hook;
            }
        }

        if (empty($missing)) {
            return $configured;
        }

        return array_merge($missing, $configured);
    }

    /**
     * Add the action hooks on after the application booted.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function boot()
    {
        $actions = $this->app->tagged('hook.actions');

        foreach ($actions as $action) {
            ActionHook::add(
                $action->get_name(),
                [$action, 'handle'],
                $action->get_priority(),
                $action->get_args_count(),
            );
        }

        $filters = $this->app->tagged('hook.filters');

        foreach ($filters as $filter) {
            FilterHook::add(
                $filter->get_name(),
                [$filter, 'handle'],
                $filter->get_priority(),
                $filter->get_args_count(),
            );
        }
    }
}
