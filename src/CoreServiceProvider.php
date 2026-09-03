<?php
/**
 * Registers the framework's essential services during application bootstrap.
 * Binds database, schema, migration, discovery, and manager components into the container.
 * Boots listener and policy discovery caching.
 *
 * @package Framework
 * @since   1.0.0
 */
namespace Framework;

defined('ABSPATH') || exit;

use Framework\Console\CommandManager;
use Framework\Database\Connection\Connection;
use Framework\Database\Connection\DatabaseManager;
use Framework\Database\Migrations\Migrator;
use Framework\Database\Schema\SchemaManager;
use Framework\Discovery\ListenerDiscovery;
use Framework\Discovery\PolicyDiscovery;
use Framework\Contracts\SomoyInterface;
use Framework\Contracts\SessionHandler;
use Framework\Managers\CookieManager;
use Framework\Managers\EventManager;
use Framework\Managers\LogManager;
use Framework\Managers\PolicyManager;
use Framework\Managers\SessionManager;
use Framework\Session\Handlers\ArraySessionHandler;
use Framework\Session\Handlers\TransientSessionHandler;
use Framework\ServiceProvider;
use InvalidArgumentException;
use Framework\Http\Response;
use Framework\Supports\MessagesBag;
use Framework\Supports\Somoy;
use Framework\View\SectionManager;
use Framework\View\TemplateEngine;
use Framework\View\ViewContext;

use function Framework\config;

class CoreServiceProvider extends ServiceProvider
{
    /**
     * Register the hooks to the application.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function register()
    {
        $this->register_database_services();
        $this->register_discoveries();
        $this->register_managers();
        $this->register_migrations();
        $this->register_messages();

        $this->app->singleton(Response::class);
        $this->app->singleton(TemplateEngine::class);
        $this->app->singleton(ViewContext::class);
        $this->app->singleton(SectionManager::class);

        if (class_exists(\Faker\Factory::class)) {
            $this->app->singleton(\Faker\Factory::class, function () {
                return \Faker\Factory::create();
            });
        }
    }

    /**
     * Boot the service provider.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function boot()
    {
        $this->app->make(PolicyDiscovery::class)
            ->discover()
            ->cache();

        $this->app->make(ListenerDiscovery::class)
            ->discover()
            ->cache();

        $this->notice_session_durability();
    }

    /**
     * Register the messages.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function register_messages()
    {
        $this->app->singleton(MessagesBag::class, function () {
            return new MessagesBag();
        });
    }

    /**
     * Register the managers.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function register_managers()
    {
        $this->app->singleton(DatabaseManager::class);
        $this->app->singleton(SchemaManager::class);
        $this->app->singleton(LogManager::class);
        $this->app->singleton(EventManager::class);
        $this->app->singleton(PolicyManager::class);
        $this->app->singleton(CookieManager::class);
        $this->register_session_services();
        $this->app->bind(SomoyInterface::class, function () {
            return new Somoy();
        });
        $this->app->singleton(CommandManager::class);
    }

    /**
     * Register the session store and its storage driver.
     *
     * The driver named by configuration is bound with no silent substitution:
     * an unknown driver name is an error rather than a fallback.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function register_session_services()
    {
        $this->app->singleton(SessionHandler::class, function () {
            $driver = config('session.driver', 'database');

            if ($driver === 'database') {
                return new TransientSessionHandler();
            }

            if ($driver === 'array') {
                return new ArraySessionHandler();
            }

            throw new InvalidArgumentException(
                sprintf('Unsupported session driver [%s]. Use "database" or "array".', (string) $driver)
            );
        });

        $this->app->singleton(SessionManager::class, function () {
            return new SessionManager($this->app->make(SessionHandler::class));
        });
    }

    /**
     * Warn when session durability depends on an external object cache.
     *
     * Transients are served from the object cache when one is installed, so a
     * cache flush discards every live session.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function notice_session_durability()
    {
        if (config('session.driver', 'database') !== 'database') {
            return;
        }

        $handler = $this->app->make(SessionHandler::class);

        if (!$handler instanceof TransientSessionHandler || !$handler->is_external_object_cache()) {
            return;
        }

        $this->app->make(LogManager::class)->info(
            'Sessions use the database driver while an external object cache is active, '
            . 'so session durability depends on that cache. A cache flush discards live sessions.'
        );
    }

    /**
     * Register the discoveries.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function register_discoveries()
    {
        $this->app->singleton(ListenerDiscovery::class);
        $this->app->singleton(PolicyDiscovery::class);
    }

    /**
     * Register the database singletons.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function register_database_services()
    {
        $this->app->singleton(Connection::class);
        $this->app->singleton(Migrator::class);
    }

    /**
     * Register the migrations tags.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function register_migrations()
    {
        $migrations_path = $this->app->config_path('migrations.php');

        if (!file_exists($migrations_path)) {
            return;
        }

        $migrations = include $migrations_path;

        if (empty($migrations)) {
            return;
        }

        $this->app->tag($migrations, 'app.migrations');
    }
}
