<?php
/**
 * Central application kernel that extends the service container and orchestrates plugin bootstrap.
 * Resolves framework paths, registers core and app-defined service providers,
 * and manages the boot lifecycle with before/after callbacks.
 * Acts as the single entry point for binding services, aliases, and application configuration.
 *
 * @package Framework
 *
 * @since 1.0.0
 */

namespace Framework;

defined('ABSPATH') || exit;

use Framework\Container;
use Framework\Contracts\Request as RequestContract;
use Framework\Console\CommandManager;
use Framework\Console\Commands\FreshCommand;
use Framework\Console\Commands\MakeClassCommand;
use Framework\Console\Commands\MakeControllerCommand;
use Framework\Cache\CacheManager;
use Framework\Cache\CacheServiceProvider;
use Framework\Console\Commands\ClearCacheCommand;
use Framework\Console\Commands\ForgetCacheCommand;
use Framework\Console\Commands\GcCacheCommand;
use Framework\Console\Commands\MakeMigrationCommand;
use Framework\Console\Commands\MakeModelCommand;
use Framework\Console\Commands\MakeProviderCommand;
use Framework\Console\Commands\MakeRequestCommand;
use Framework\Console\Commands\MakeSeederCommand;
use Framework\Console\Commands\MigrateCommand;
use Framework\Console\Commands\RollbackCommand;
use Framework\Console\Commands\SeedCommand;
use Framework\Console\Commands\StatusCommand;
use Framework\Database\Connection\DatabaseManager;
use Framework\Database\Schema\SchemaManager;
use Framework\Managers\CookieManager;
use Framework\Managers\SessionManager;
use Framework\Managers\EventManager;
use Framework\Managers\LogManager;
use Framework\Managers\OptionManager;
use Framework\Managers\PolicyManager;
use Framework\ServiceProvider;
use Framework\Filesystem\FileSystemServiceProvider;
use Framework\Http\Request;
use Framework\Filesystem\Path;
use Framework\Http\Client\Request as ClientRequest;
use Framework\CoreServiceProvider;
use Framework\Wordpress\HookServiceProvider;
use Framework\Supports\Facades\Command;
use Framework\Supports\Facades\File;
use Framework\Supports\Str;
use Framework\Supports\Traits\Macroable;
use Exception;
use Framework\Supports\Arr;
use InvalidArgumentException;
use RuntimeException;

class Application extends Container
{
    use Macroable;

    /**
     * Base path
     *
     * @var string|null
     *
     * @since 1.0.0
     */
    protected $base_path;

    /**
     * Bootstrap path
     *
     * @var string|null
     *
     * @since 1.0.0
     */
    protected $bootstrap_path;

    /**
     * Config path
     *
     * @var string|null
     *
     * @since 1.0.0
     */
    protected $config_path;

    /**
     * App path
     *
     * @var string|null
     *
     * @since 1.0.0
     */
    protected $app_path;

    /**
     * Database path
     *
     * @var string|null
     *
     * @since 1.0.0
     */
    protected $database_path;

    /**
     * Resources path
     *
     * @var string|null
     *
     * @since 1.0.0
     */
    protected $resource_path;

    /**
     * View path
     *
     * @var string|null
     *
     * @since 1.0.0
     */
    protected $view_path;

    /**
     * Service providers
     *
     * @var array<string, ServiceProvider>
     *
     * @since 1.0.0
     */
    protected $service_providers = [];

    /**
     * Mark the application booted or not
     *
     * @var bool
     *
     * @since 1.0.0
     */
    protected bool $booted = false;

    /**
     * Booting callbacks
     *
     * @var array<callable>
     *
     * @since 1.0.0
     */
    protected array $booting_callbacks = [];

    /**
     * Booted callbacks
     *
     * @var array<callable>
     *
     * @since 1.0.0
     */
    protected array $booted_callbacks = [];

    /**
     * The application namespace
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected string $namespace = '';

    /**
     * The application version
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected string $version = '1.0.0';

    /**
     * Cached namespaces resolved from composer PSR-4 paths.
     *
     * @var array<string, string>
     *
     * @since 1.0.0
     */
    protected $namespace_paths = [];

    /**
     * The application prefix
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected string $prefix = '';

    /**
     * The application is in development mode
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected string $mode = 'production';

    /**
     * Create a new application instance
     *
     * @param string|null $base_path The base path of the application.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function __construct(?string $base_path = null)
    {
        if ($base_path) {
            $this->set_base_path($base_path);
        }

        $this->register_base_bindings();
        $this->register_base_service_providers();
        $this->register_base_aliases();

        // $this->register_app_defined_providers();
        // $this->register_app_defined_aliases();
    }

    /**
     * Set the base path
     *
     * @param string $path The base path of the application.
     *
     * @return static
     *
     * @since 1.0.0
     */
    public function set_base_path(string $path)
    {
        $this->base_path = rtrim($path, '\/');

        $this->bind_inferred_paths();

        return $this;
    }

    /**
     * Binding the inferred paths
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function bind_inferred_paths()
    {
        $this->instance('path', $this->path());
        $this->instance('path.base', $this->base_path());
        $this->instance('path.bootstrap', $this->bootstrap_path());
        $this->instance('path.config', $this->config_path());
        $this->instance('path.database', $this->database_path());
        $this->instance('path.resource', $this->resource_path());
        $this->instance('path.view', $this->view_path());
    }

    /**
     * Registering the core bindings to the application container.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function register_base_bindings()
    {
        $this->instance('app', $this);
        $this->instance(Container::class, $this);
    }

    /**
     * Registering the core service providers to the application container.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function register_base_service_providers()
    {
        $this->register(new FileSystemServiceProvider($this));
        $this->register(new CacheServiceProvider($this));
        $this->register(new CoreServiceProvider($this));
        $this->register(new HookServiceProvider($this));
    }

    /**
     * Registering the core CLI commands.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function register_base_cli_commands()
    {
        $commands = [
            'make:migration' => MakeMigrationCommand::class,
            'migrate' => MigrateCommand::class,
            'make:model' => MakeModelCommand::class,
            'make:controller' => MakeControllerCommand::class,
            'make:request' => MakeRequestCommand::class,
            'make:seeder' => MakeSeederCommand::class,
            'db:seed' => SeedCommand::class,
            'migrate:fresh' => FreshCommand::class,
            'migrate:rollback' => RollbackCommand::class,
            'migrate:status' => StatusCommand::class,
            'make:provider' => MakeProviderCommand::class,
            'make:class' => MakeClassCommand::class,
            'cache:clear' => ClearCacheCommand::class,
            'cache:forget' => ForgetCacheCommand::class,
            'cache:gc' => GcCacheCommand::class,
        ];

        foreach ($commands as $command => $class) {
            Command::register($command, $class);
        }
    }

    /**
     * Registering the core aliases for future use.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function register_base_aliases()
    {
        foreach (
            [
                'cache' => CacheManager::class,
                'db' => DatabaseManager::class,
                'schema' => SchemaManager::class,
                'option' => OptionManager::class,
                'policy' => PolicyManager::class,
                'event' => EventManager::class,
                'log' => LogManager::class,
                'cookie' => CookieManager::class,
                'session' => SessionManager::class,
                'client-request' => ClientRequest::class,
                'command' => CommandManager::class,
                RequestContract::class => Request::class,
                'request' => Request::class,
            ] as $key => $abstract
        ) {
            $this->alias($key, $abstract);
        }
    }

    /**
     * Register a service provider to the application.
     *
     * @param ServiceProvider|string $provider The service provider class name or instance.
     *
     * @return ServiceProvider
     *
     * @since 1.0.0
     */
    public function register($provider)
    {
        $registered = $this->get_provider($provider);

        if ($registered) {
            return $registered;
        }

        if (is_string($provider)) {
            $provider = $this->resolve_provider($provider);
        }

        $provider->register();

        $this->mark_as_registered($provider);

        if ($this->is_booted()) {
            $this->boot_provider($provider);
        }

        return $provider;
    }

    /**
     * Get the registered service provider.
     *
     * @param ServiceProvider|string $provider The service provider to get.
     *
     * @return ServiceProvider|null
     *
     * @since 1.0.0
     */
    protected function get_provider($provider)
    {
        $name = is_string($provider) ? $provider : get_class($provider);

        return $this->service_providers[$name] ?? null;
    }

    /**
     * Resolve service provider from provider class name.
     *
     * @param string $provider The service provider class name.
     *
     * @return ServiceProvider
     *
     * @since 1.0.0
     */
    protected function resolve_provider(string $provider)
    {
        return new $provider($this);
    }

    /**
     * Mark the service provider as registered.
     *
     * @param ServiceProvider $provider The service provider to mark as registered.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function mark_as_registered(ServiceProvider $provider)
    {
        $class = get_class($provider);

        $this->service_providers[$class] = $provider;
    }

    /**
     * Boot the service provider.
     *
     * @param ServiceProvider $provider The service provider to boot.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function boot_provider(ServiceProvider $provider)
    {
        if (method_exists($provider, 'boot')) {
            $provider->boot();
        }
    }

    /**
     * Check if the application is booted or not.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function is_booted()
    {
        return $this->booted;
    }

    /**
     * Configure the application.
     *
     * @param string $base_path The base path of the application.
     *
     * @return static
     *
     * @since 1.0.0
     */
    public static function configure(string $base_path)
    {
        $instance = static::get_instance($base_path);

        $instance->register_app_defined_providers()
            ->register_app_defined_aliases();

        return $instance;
    }

    /**
     * Register a route file.
     *
     * @param string $path The route file path.
     *
     * @return self
     *
     * @throws \Exception
     *
     * @since 1.0.0
     */
    public function use_routing(string $path)
    {
        if (!file_exists($path)) {
            throw new Exception("Route file not found: $path");
        }

        include $path;

        return $this;
    }

    /**
     * Use a prefix for the application.
     *
     * @param string $prefix The prefix to use.
     *
     * @return self
     *
     * @since 1.0.0
     */
    public function use_prefix(string $prefix)
    {
        $this->prefix = Str::snake($prefix);

        return $this;
    }

    /**
     * Use a version for the application.
     *
     * @param string $version The version to use.
     *
     * @return self
     *
     * @since 1.0.0
     */
    public function use_version(string $version)
    {
        $this->version = $version;

        return $this;
    }

    /**
     * Get the application prefix.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function prefix()
    {
        return $this->prefix;
    }

    /**
     * Register a bootstrap path.
     *
     * @param string $path The bootstrap path.
     *
     * @return static
     *
     * @since 1.0.0
     */
    public function use_bootstrap_path($path)
    {
        $this->bootstrap_path = $path;

        $this->instance('path.bootstrap', $path);

        return $this;
    }

    /**
     * Register a config path.
     *
     * @param string $path The config path.
     *
     * @return static
     *
     * @since 1.0.0
     */
    public function use_config_path($path)
    {
        $this->config_path = $path;

        $this->instance('path.config', $path);

        return $this;
    }

    /**
     * Register an app path.
     *
     * @param string $path The app path.
     *
     * @return static
     *
     * @since 1.0.0
     */
    public function use_app_path(string $path)
    {
        $this->app_path = $path;

        $this->instance('path', $path);

        return $this;
    }

    /**
     * Register a database path.
     *
     * @param string $path The database path.
     *
     * @return static
     *
     * @since 1.0.0
     */
    public function use_database_path($path)
    {
        $this->database_path = $path;

        $this->instance('path.database', $path);

        return $this;
    }

    /**
     * Register a resource path.
     *
     * @param string $path The resource path.
     *
     * @return static
     *
     * @since 1.0.0
     */
    public function use_resource_path($path)
    {
        $this->resource_path = $path;

        $this->instance('path.resource', $path);

        return $this;
    }

    /**
     * Register a view path.
     *
     * @param string $path The view path.
     *
     * @return static
     *
     * @since 1.0.0
     */
    public function use_view_path($path)
    {
        $this->view_path = $path;

        $this->instance('path.view', $path);

        return $this;
    }

    /**
     * Join paths.
     *
     * @param string $base_path The base path to join.
     * @param string $path The path to join.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function join_paths($base_path, $path)
    {
        return Path::join($base_path, $path);
    }

    /**
     * Get the path to the app directory.
     *
     * @param string $path The path to join.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function path($path = '')
    {
        return $this->join_paths($this->app_path ?: $this->base_path('app'), $path);
    }

    /**
     * Get the path to the base directory.
     *
     * @param string $path The path to join.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function base_path($path = '')
    {
        return $this->join_paths($this->base_path, $path);
    }

    /**
     * Get the path to the bootstrap directory.
     *
     * @param string $path The path to join.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function bootstrap_path($path = '')
    {
        return $this->join_paths($this->bootstrap_path ?: $this->base_path('bootstrap'), $path);
    }

    /**
     * Get the path to the config directory.
     *
     * @param string $path The path to join.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function config_path($path = '')
    {
        return $this->join_paths($this->config_path ?: $this->base_path('config'), $path);
    }

    /**
     * Get the path to the database directory.
     *
     * @param string $path The path to join.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function database_path($path = '')
    {
        return $this->join_paths($this->database_path ?: $this->base_path('database'), $path);
    }

    /**
     * Get the path to the resources directory.
     *
     * @param string $path The path to join.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function resource_path($path = '')
    {
        return $this->join_paths($this->resource_path ?: $this->base_path('resources'), $path);
    }

    /**
     * Get or set the path to the views directory.
     *
     * @param string $path Optional path to join or absolute path when setting via use_view_path.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function view_path($path = '')
    {
        return $this->join_paths($this->view_path ?: $this->resource_path('views'), $path);
    }

    /**
     * Get the path to the bootstrap providers file.
     *
     * @return string The path to the bootstrap providers file.
     *
     * @since 1.0.0
     */
    public function bootstrap_service_provider_path()
    {
        return $this->bootstrap_path('providers.php');
    }

    /**
     * Get the base URL of the application.
     *
     * @param string $path The path to join.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function base_url($path = '')
    {
        $base_path = $this->base_path();
        $last_segment = basename($base_path);
        $site_base_path = 'wp-content/plugins/' . $last_segment;

        return site_url($site_base_path . $path);
    }

    /**
     * Check if the CLI is available or not.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function is_cli_available()
    {
        return defined('WP_CLI') && \WP_CLI;
    }

    /**
     * Register a booting callback.
     *
     * @param callable $callback The callback to register.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function booting($callback)
    {
        $this->booting_callbacks[] = $callback;
    }

    /**
     * Boot the plugin application.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function boot()
    {
        if ($this->is_booted()) {
            return;
        }

        $this->fire_app_callbacks($this->booting_callbacks);

        array_walk($this->service_providers, function ($provider) {
            $this->boot_provider($provider);
        });

        $this->booted = true;

        $this->fire_app_callbacks($this->booted_callbacks);

        // After booting the application, register the core CLI commands.
        if ($this->is_cli_available()) {
            $this->register_base_cli_commands();
        }
    }

    /**
     * Register a booted callback.
     *
     * @param callable $callback The callback to register.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function booted($callback)
    {
        $this->booted_callbacks[] = $callback;

        if ($this->is_booted()) {
            $callback($this);
        }
    }

    /**
     * Fire the booting callbacks.
     *
     * @param array<callable> $callbacks The callbacks to fire.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function fire_app_callbacks(array $callbacks)
    {
        foreach ($callbacks as $callback) {
            $callback($this);
        }
    }

    /**
     * Register other on demand provided providers.
     *
     * @return $this
     *
     * @throws \InvalidArgumentException
     *
     * @since 1.0.0
     */
    protected function register_app_defined_providers()
    {
        $path = $this->bootstrap_service_provider_path();

        if (!file_exists($path)) {
            return $this;
        }

        $providers = require $path;

        if (empty($providers)) {
            return $this;
        }

        foreach ($providers as $provider) {
            if (!class_exists($provider) || !is_subclass_of($provider, ServiceProvider::class)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Class %s must be a subclass of %s.',
                        $provider,
                        ServiceProvider::class
                    )
                );
            }

            $this->register(new $provider($this));
        }

        return $this;
    }

    /**
     * Register tha aliases defined at the application level.
     * Generally it will come from the bootstrap/aliases.php file.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    protected function register_app_defined_aliases()
    {
        $aliases_path = $this->bootstrap_path('aliases.php');

        if (!file_exists($aliases_path)) {
            return $this;
        }

        $aliases = require $aliases_path;

        if (empty($aliases)) {
            return $this;
        }

        foreach ($aliases as $alias => $abstracts) {
            foreach ($abstracts as $abstract) {
                $this->alias($alias, $abstract);
            }
        }

        return $this;
    }

    /**
     * Get the application namespace.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function get_namespace()
    {
        if (!empty($this->namespace)) {
            return $this->namespace;
        }

        return $this->namespace = $this->parse_namespace_from_composer();
    }

    /**
     * Parse the namespace from the composer.json file.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function parse_namespace_from_composer()
    {
        return $this->namespace = $this->get_namespace_for_path('app');
    }

    /**
     * Qualify a namespace under the application PSR-4 root.
     *
     * @param string $suffix The suffix to qualify.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function qualify_app_namespace($suffix = '')
    {
        $namespace = rtrim($this->get_namespace(), '\\');

        if ($suffix === '') {
            return $namespace;
        }

        $suffix = str_replace('/', '\\', $suffix);

        return $namespace . '\\' . ltrim($suffix, '\\');
    }

    /**
     * Get the migrations namespace from the host composer.json PSR-4 map.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function get_migrations_namespace()
    {
        return rtrim($this->get_namespace_for_path('database/migrations'), '\\');
    }

    /**
     * Get the seeders namespace from composer or derive it from migrations.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function get_seeders_namespace()
    {
        try {
            return rtrim($this->get_namespace_for_path('database/seeders'), '\\');
        } catch (RuntimeException $exception) {
            return preg_replace('/\\Migrations\\?$/', '\\Seeders', rtrim($this->get_migrations_namespace(), '\\'));
        }
    }

    /**
     * Resolve a PSR-4 namespace for a path relative to the application base.
     *
     * @param string $relative_path The relative path to the namespace.
     *
     * @return string
     *
     * @throws \RuntimeException
     *
     * @since 1.0.0
     */
    public function get_namespace_for_path($relative_path)
    {
        if (isset($this->namespace_paths[$relative_path])) {
            return $this->namespace_paths[$relative_path];
        }

        $composer = File::json(
            $this->base_path('composer.json')
        );

        if (empty($composer['autoload']['psr-4'])) {
            throw new RuntimeException('The composer must have a PSR-4 autoload configuration.');
        }

        $resolved_path = $this->base_path($relative_path);
        $target = realpath($resolved_path);

        if ($target === false) {
            File::make_dir($this->base_path($relative_path));
            $target = realpath($resolved_path);
        }

        foreach ($composer['autoload']['psr-4'] as $namespace => $paths) {
            $paths = Arr::wrap($paths);

            foreach ($paths as $path) {
                $resolved = realpath($this->base_path($path));

                if ($resolved !== false && $resolved === $target) {
                    return $this->namespace_paths[$relative_path] = $namespace;
                }
            }
        }

        throw new RuntimeException(sprintf('Unable to detect namespace for path [%s].', $relative_path));
    }

    /**
     * Set the application mode.
     *
     * @param string $mode The mode to set.
     *
     * @return static
     *
     * @since 1.0.0
     */
    public function use_app_mode($mode = 'production')
    {
        $this->mode = in_array(strtolower($mode), ['dev', 'development'], true) ? 'development' : 'production';

        return $this;
    }

    /**
     * Check if the application is in development mode.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function is_dev_mode()
    {
        return $this->mode === 'development';
    }

    /**
     * Get the application version.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function version()
    {
        return $this->version;
    }
}
