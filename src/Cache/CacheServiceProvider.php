<?php
/**
 * Registers the cache manager and the schedule that sweeps expired file entries.
 * Kept separate from the core provider because the cache is the only subsystem that has to hook
 * WordPress at boot in order to maintain its own storage.
 *
 * @package    Framework
 * @subpackage Cache
 * @since      1.0.0
 */
namespace Framework\Cache;

defined('ABSPATH') || exit;

use Framework\ServiceProvider;
use Throwable;

class CacheServiceProvider extends ServiceProvider
{
    /**
     * Register the cache manager.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function register()
    {
        $this->app->singleton(CacheManager::class, function () {
            return new CacheManager();
        });
    }

    /**
     * Wire the schedule that sweeps expired entries.
     *
     * Stale while revalidate refreshes are not registered here; each one registers its own
     * shutdown callback when a stale read actually happens, so a request that never reads a
     * stale key never pays for the hook.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function boot()
    {
        $this->register_garbage_collection();
    }

    /**
     * Schedule and handle the sweep that removes expired entries.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function register_garbage_collection()
    {
        if (!function_exists('add_action') || !function_exists('wp_next_scheduled')) {
            return;
        }

        $hook = $this->app->prefix() . 'cache_gc';

        add_action($hook, function () {
            try {
                $this->app->make(CacheManager::class)->collect_garbage();
            } catch (Throwable $exception) {
                // A sweep that cannot run must never fail the request that triggered it.
            }
        });

        $stores = $this->sweepable_stores();

        if (empty($stores)) {
            $this->unschedule($hook);

            return;
        }

        if (!wp_next_scheduled($hook)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, $this->interval($stores), $hook);
        }
    }

    /**
     * Get the names of the stores that need sweeping.
     *
     * @return array
     *
     * @since 1.0.0
     */
    protected function sweepable_stores()
    {
        try {
            return $this->app->make(CacheManager::class)->sweepable_stores();
        } catch (Throwable $exception) {
            return [];
        }
    }

    /**
     * Get the schedule the sweep should run on.
     *
     * @param array $stores The names of the stores that need sweeping.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function interval(array $stores)
    {
        $manager = $this->app->make(CacheManager::class);

        foreach ($stores as $name) {
            $interval = $manager->store_config($name)['gc'] ?? 'daily';

            if (is_string($interval) && $interval !== '') {
                return $interval;
            }
        }

        return 'daily';
    }

    /**
     * Remove a previously scheduled sweep.
     *
     * @param string $hook The scheduled hook name.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function unschedule(string $hook)
    {
        if (!function_exists('wp_unschedule_hook')) {
            return;
        }

        if (wp_next_scheduled($hook)) {
            wp_unschedule_hook($hook);
        }
    }
}
