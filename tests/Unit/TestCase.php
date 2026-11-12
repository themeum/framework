<?php

namespace Framework\Tests\Unit;

use Framework\Application;
use Framework\Container;
use Framework\Database\Connection\Connection;
use Framework\Database\Query\Model;
use Framework\Database\Query\QueryBuilder;
use Framework\Database\Query\QueryCompiler;
use Framework\Database\Query\Relations\Relation;
use Framework\Database\Schema\Structure;
use Framework\Tests\Support\Database\ModelTestWpdb;
use Framework\Facade;
use Framework\Contracts\SomoyInterface;
use Framework\Route;
use Framework\Routing\CurrentRoute;
use Framework\Supports\Somoy;
use Framework\Supports\Str;
use Framework\Tests\Support\Database\TestWpdb;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        $this->reset_container_instance();
        $this->reset_str_macros();
        $this->reset_test_wpdb();
        $this->reset_route_state();
        $this->reset_facade_cache();
        $this->reset_wordpress_hooks();

        parent::tearDown();
    }

    protected function reset_wordpress_hooks(): void
    {
        unset(
            $GLOBALS['framework_test_filters'],
            $GLOBALS['framework_test_did_actions'],
            $GLOBALS['framework_test_remote_requests'],
            $GLOBALS['framework_test_remote_response']
        );
    }

    protected static function framework_path(): string
    {
        return dirname(__DIR__, 2);
    }

    protected function bind_somoy(): void
    {
        $container = new Container();
        $container->instance('app', $container);
        $container->bind(SomoyInterface::class, function () {
            return new Somoy();
        });

        $this->set_container_instance($container);
    }

    protected function bootstrap_application(): Application
    {
        $this->reset_container_instance();

        return Application::get_instance(self::framework_path());
    }

    protected function reset_container_instance(): void
    {
        $this->set_container_instance(null);
    }

    protected function set_container_instance(?Container $container): void
    {
        $reflection = new \ReflectionClass(Container::class);
        $property = $reflection->getProperty('instance');
        $property->setAccessible(true);
        $property->setValue(null, $container);
    }

    protected function reset_str_macros(): void
    {
        $reflection = new \ReflectionClass(Str::class);
        $property = $reflection->getProperty('macros');
        $property->setAccessible(true);
        $property->setValue(null, []);
    }

    protected function make_test_connection(array $config = []): Connection
    {
        global $wpdb;

        $wpdb = new TestWpdb($config);

        return new Connection();
    }

    protected function bootstrap_model_testing(array $wpdb_config = []): ModelTestWpdb
    {
        global $wpdb;

        $wpdb = new ModelTestWpdb($wpdb_config);

        $container = new Container();
        $container->instance('app', $container);
        $container->bind(SomoyInterface::class, function () {
            return new Somoy();
        });
        $container->instance(Connection::class, new Connection());

        $this->set_container_instance($container);

        return $wpdb;
    }

    protected function reset_model_static_state(string $model_class): void
    {
        $model_class::prevent_silently_discarding_attributes(false);
        $model_class::discarded_attribute_callback(null);
        $model_class::unguard(false);

        $reflection = new \ReflectionClass($model_class);

        if ($reflection->hasProperty('guardable_columns')) {
            $guardable_property = $reflection->getProperty('guardable_columns');
            $guardable_property->setAccessible(true);
            $guardable_columns = $guardable_property->getValue();
            unset($guardable_columns[$model_class]);
            $guardable_property->setValue(null, $guardable_columns);
        }
    }

    protected function reset_cast_type_cache(string $model_class): void
    {
        $reflection = new \ReflectionClass($model_class);

        if ($reflection->hasProperty('cast_type_cache')) {
            $property = $reflection->getProperty('cast_type_cache');
            $property->setAccessible(true);
            $property->setValue(null, []);
        }
    }

    protected function reset_relation_join_count(): void
    {
        $reflection = new \ReflectionClass(Relation::class);
        $property = $reflection->getProperty('self_join_count');
        $property->setAccessible(true);
        $property->setValue(null, 0);
    }

    protected function make_existing_model(string $model_class, array $attributes): Model
    {
        $instance = new $model_class();

        return $instance->new_for_hydration($attributes);
    }

    protected function make_query_compiler(array $config = []): QueryCompiler
    {
        return $this->make_test_connection($config)->get_query_compiler();
    }

    protected function make_query_builder(array $config = []): QueryBuilder
    {
        $connection = $this->make_test_connection($config);

        return new QueryBuilder($connection);
    }

    protected function make_structure(string $table, array $config = []): Structure
    {
        return new Structure($table, $this->make_test_connection($config));
    }

    protected function make_alter_structure(string $table, array $config = []): Structure
    {
        return new Structure(
            $table,
            $this->make_test_connection($config),
            Structure::MODE_ALTER
        );
    }

    protected function reset_test_wpdb(): void
    {
        global $wpdb;

        $wpdb = null;
    }

    protected function reset_facade_cache(): void
    {
        $reflection = new \ReflectionClass(Facade::class);
        $property = $reflection->getProperty('resolved_instance');
        $property->setAccessible(true);
        $property->setValue(null, []);
    }

    protected function reset_route_state(): void
    {
        CurrentRoute::reset();

        $reflection = new \ReflectionClass(Route::class);

        $defaults = [
            'namespace' => '',
            'site_namespace' => '',
            'routing_method' => Route::ROUTING_REWRITE_RULES,
            'default_hook_name' => \Framework\Wordpress\Constants\HookNames::TEMPLATE_INCLUDE,
            'routes' => [],
            'named_routes' => [],
            'group_stack' => [],
            'site_router' => null,
            'with_site_route' => false,
        ];

        foreach ($defaults as $property_name => $value) {
            if (!$reflection->hasProperty($property_name)) {
                continue;
            }

            $property = $reflection->getProperty($property_name);
            $property->setAccessible(true);
            $property->setValue(null, $value);
        }
    }
}
