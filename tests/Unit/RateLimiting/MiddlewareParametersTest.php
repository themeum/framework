<?php

namespace Framework\Tests\Unit\RateLimiting;

use Framework\Contracts\Middleware;
use Framework\Contracts\Request as RequestContract;
use Framework\Exceptions\InvalidMiddlewareException;
use Framework\Route;
use ReflectionClass;

class MiddlewareParametersTest extends RateLimiterTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->reset_route_state();
        $this->reset_middleware_aliases();

        Route::set_namespace('framework/v1');
        RecordingMiddleware::$received = null;
        RecordingMiddleware::$calls = 0;
    }

    protected function tearDown(): void
    {
        $this->reset_middleware_aliases();

        parent::tearDown();
    }

    protected function reset_middleware_aliases(): void
    {
        $reflection = new ReflectionClass(Route::class);
        $property = $reflection->getProperty('middleware_aliases');
        $property->setAccessible(true);
        $property->setValue(null, []);
    }

    protected function parse(string $reference): array
    {
        $reflection = new ReflectionClass(Route::class);
        $method = $reflection->getMethod('parse_middleware');
        $method->setAccessible(true);

        return $method->invoke(null, $reference);
    }

    public function test_a_class_name_carries_no_arguments()
    {
        $this->assertSame([RecordingMiddleware::class, []], $this->parse(RecordingMiddleware::class));
    }

    public function test_an_alias_resolves_to_its_registered_class()
    {
        Route::middleware_alias('record', RecordingMiddleware::class);

        $this->assertSame([RecordingMiddleware::class, []], $this->parse('record'));
    }

    public function test_an_alias_carries_a_single_argument()
    {
        Route::middleware_alias('record', RecordingMiddleware::class);

        $this->assertSame([RecordingMiddleware::class, ['60']], $this->parse('record:60'));
    }

    public function test_an_alias_carries_several_arguments()
    {
        Route::middleware_alias('record', RecordingMiddleware::class);

        $this->assertSame([RecordingMiddleware::class, ['60', '1']], $this->parse('record:60,1'));
    }

    public function test_only_the_first_colon_separates_name_from_arguments()
    {
        Route::middleware_alias('record', RecordingMiddleware::class);

        $this->assertSame([RecordingMiddleware::class, ['a:b', 'c']], $this->parse('record:a:b,c'));
    }

    public function test_an_empty_argument_list_is_no_arguments()
    {
        Route::middleware_alias('record', RecordingMiddleware::class);

        $this->assertSame([RecordingMiddleware::class, []], $this->parse('record:'));
    }

    public function test_an_unresolvable_reference_is_reported()
    {
        $this->expectException(InvalidMiddlewareException::class);

        $this->parse('nope:1');
    }

    public function test_arguments_reach_the_middleware_through_the_pipeline()
    {
        Route::middleware_alias('record', RecordingMiddleware::class);

        $route = Route::get('ping', function () {
            return 'ok';
        })->middleware('record:60,1');

        $this->run_pipeline($route);

        $this->assertSame(['60', '1'], RecordingMiddleware::$received);
        $this->assertSame(1, RecordingMiddleware::$calls);
    }

    public function test_a_middleware_without_arguments_still_runs()
    {
        $route = Route::get('ping', function () {
            return 'ok';
        })->middleware(RecordingMiddleware::class);

        $this->run_pipeline($route);

        $this->assertSame([], RecordingMiddleware::$received);
        $this->assertSame(1, RecordingMiddleware::$calls);
    }

    public function test_group_middleware_carries_its_arguments_to_every_route()
    {
        Route::middleware_alias('record', RecordingMiddleware::class);

        Route::group(['prefix' => 'admin', 'middleware' => 'record:120,1'], function () {
            Route::get('stats', function () {
                return 'ok';
            });
        });

        $routes = Route::get_routes();
        $route = end($routes);

        $this->assertSame(['record:120,1'], $this->read_route_property($route, 'middlewares'));

        $this->run_pipeline($route);

        $this->assertSame(['120', '1'], RecordingMiddleware::$received);
    }

    public function test_group_and_route_middleware_both_run_in_order()
    {
        Route::middleware_alias('record', RecordingMiddleware::class);

        Route::group(['middleware' => 'record:group'], function () {
            Route::get('stats', function () {
                return 'ok';
            })->middleware('record:route');
        });

        $routes = Route::get_routes();
        $route = end($routes);

        $this->assertSame(
            ['record:group', 'record:route'],
            $this->read_route_property($route, 'middlewares')
        );

        $this->run_pipeline($route);

        $this->assertSame(2, RecordingMiddleware::$calls);
    }

    public function test_the_throttle_builder_writes_the_string_form()
    {
        $route = Route::get('ping', function () {
            return 'ok';
        })->throttle(30, 5);

        $this->assertSame(['throttle:30,5'], $this->read_route_property($route, 'middlewares'));
    }

    public function test_the_throttle_builder_accepts_a_limiter_name()
    {
        $route = Route::get('ping', function () {
            return 'ok';
        })->throttle('uploads');

        $this->assertSame(['throttle:uploads'], $this->read_route_property($route, 'middlewares'));
    }

    protected function read_route_property(Route $route, string $property)
    {
        $reflection = new ReflectionClass(Route::class);
        $property_reflection = $reflection->getProperty($property);
        $property_reflection->setAccessible(true);

        return $property_reflection->getValue($route);
    }

    protected function run_pipeline(Route $route): void
    {
        $reflection = new ReflectionClass(Route::class);
        $method = $reflection->getMethod('build_middleware_pipeline');
        $method->setAccessible(true);

        $pipeline = $method->invoke($route, function ($request) {
            return $request;
        });

        $pipeline(new \Framework\Http\Request());
    }
}

class RecordingMiddleware implements Middleware
{
    public static $received = null;

    public static $calls = 0;

    public function handle(RequestContract $request, callable $next, ...$parameters)
    {
        static::$received = $parameters;
        static::$calls++;

        return $next($request);
    }
}
