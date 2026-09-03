<?php

namespace Framework\Tests\Unit;

use Framework\Contracts\Middleware;
use Framework\Contracts\Request as RequestContract;
use Framework\Exceptions\AuthorizationException;
use Framework\Http\Request;
use Framework\Http\Response;
use Framework\Route;
use Framework\Tests\Support\Models\StubArticle;
use WP_Error;
use WP_REST_Request;

class RouteMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bootstrap_application();
        $this->reset_route_state();
        Route::set_namespace('framework/v1');
        RouteMiddlewareTestCountingMiddleware::$handle_calls = 0;
        RouteMiddlewareSpyRoute::$resolve_model_calls = 0;
    }

    public function test_permission_callback_runs_middleware_once(): void
    {
        $route = Route::get('ping', [RouteMiddlewareTestController::class, 'show'])
            ->middleware([RouteMiddlewareTestCountingMiddleware::class]);

        $rest_request = $this->make_rest_request();
        $permission = $this->invoke_route_method($route, 'resolve_permission_callback', $rest_request);

        $this->assertTrue($permission);
        $this->assertSame(1, RouteMiddlewareTestCountingMiddleware::$handle_calls);

        $callback = $this->invoke_route_method($route, 'resolve_route');
        $callback($rest_request);

        $this->assertSame(1, RouteMiddlewareTestCountingMiddleware::$handle_calls);
    }

    public function test_permission_callback_called_twice_by_wordpress_runs_middleware_once(): void
    {
        $route = Route::get('ping', [RouteMiddlewareTestController::class, 'show'])
            ->middleware([RouteMiddlewareTestCountingMiddleware::class]);

        $rest_request = $this->make_rest_request();

        $first = $this->invoke_route_method($route, 'resolve_permission_callback', $rest_request);
        $second = $this->invoke_route_method($route, 'resolve_permission_callback', $rest_request);

        $this->assertTrue($first);
        $this->assertTrue($second);
        $this->assertSame(1, RouteMiddlewareTestCountingMiddleware::$handle_calls);
    }

    public function test_permission_callback_reruns_for_a_different_request(): void
    {
        $route = Route::get('ping', [RouteMiddlewareTestController::class, 'show'])
            ->middleware([RouteMiddlewareTestCountingMiddleware::class]);

        $this->invoke_route_method($route, 'resolve_permission_callback', $this->make_rest_request());
        $this->invoke_route_method($route, 'resolve_permission_callback', $this->make_rest_request());

        $this->assertSame(2, RouteMiddlewareTestCountingMiddleware::$handle_calls);
    }

    public function test_permission_failure_returns_wp_error(): void
    {
        $route = Route::get('ping', [RouteMiddlewareTestController::class, 'show'])
            ->middleware([RouteMiddlewareTestDenyMiddleware::class]);

        $rest_request = $this->make_rest_request();
        $permission = $this->invoke_route_method($route, 'resolve_permission_callback', $rest_request);

        $this->assertInstanceOf(WP_Error::class, $permission);
        $this->assertSame('rest_forbidden', $permission->get_error_code());
        $this->assertSame('Access denied.', $permission->get_error_message());
        $this->assertSame(Response::FORBIDDEN, $permission->get_error_data()['status']);
        $this->assertNull($this->read_route_property($route, 'resolved_request'));
    }

    public function test_middleware_mutations_reach_controller(): void
    {
        $route = Route::get('ping', [RouteMiddlewareTestController::class, 'show'])
            ->middleware([RouteMiddlewareTestMutatingMiddleware::class]);

        $rest_request = $this->make_rest_request();
        $this->invoke_route_method($route, 'resolve_permission_callback', $rest_request);

        $callback = $this->invoke_route_method($route, 'resolve_route');
        $result = $callback($rest_request);

        $this->assertSame('mutated', $result);
    }

    public function test_middleware_mutations_reach_closure(): void
    {
        $route = Route::get('ping', function (RequestContract $request) {
            return $request->get('middleware_marker');
        })->middleware([RouteMiddlewareTestMutatingMiddleware::class]);

        $rest_request = $this->make_rest_request();
        $this->invoke_route_method($route, 'resolve_permission_callback', $rest_request);

        $callback = $this->invoke_route_method($route, 'resolve_route');
        $result = $callback($rest_request);

        $this->assertSame('mutated', $result);
    }

    public function test_model_binding_runs_in_callback_not_permission(): void
    {
        $wpdb = $this->bootstrap_model_testing(['prefix' => 'wp_']);
        $wpdb->seed('test_articles', [
            ['id' => 1, 'title' => 'First article'],
        ]);

        $route = RouteMiddlewareSpyRoute::spy_get('articles/{article}', [RouteMiddlewareTestModelController::class, 'show'])
            ->middleware([RouteMiddlewareTestCountingMiddleware::class]);

        $rest_request = $this->make_rest_request('GET', '/framework/v1/articles/1', ['article' => 1]);
        $permission = $this->invoke_route_method($route, 'resolve_permission_callback', $rest_request);

        $this->assertTrue($permission);
        $this->assertSame(0, RouteMiddlewareSpyRoute::$resolve_model_calls);

        $callback = $this->invoke_route_method($route, 'resolve_route');
        $callback($rest_request);

        $this->assertSame(1, RouteMiddlewareSpyRoute::$resolve_model_calls);
    }

    public function test_model_binding_not_called_when_permission_fails(): void
    {
        $this->bootstrap_model_testing(['prefix' => 'wp_']);

        $route = RouteMiddlewareSpyRoute::spy_get('articles/{article}', [RouteMiddlewareTestModelController::class, 'show'])
            ->middleware([RouteMiddlewareTestDenyMiddleware::class]);

        $rest_request = $this->make_rest_request('GET', '/framework/v1/articles/1', ['article' => 1]);
        $permission = $this->invoke_route_method($route, 'resolve_permission_callback', $rest_request);

        $this->assertInstanceOf(WP_Error::class, $permission);
        $this->assertSame(0, RouteMiddlewareSpyRoute::$resolve_model_calls);
    }

    public function test_route_without_middleware_still_works(): void
    {
        $route = Route::get('ping', [RouteMiddlewareTestController::class, 'show']);

        $rest_request = $this->make_rest_request();
        $permission = $this->invoke_route_method($route, 'resolve_permission_callback', $rest_request);

        $this->assertTrue($permission);
        $this->assertNotNull($this->read_route_property($route, 'resolved_request'));

        $callback = $this->invoke_route_method($route, 'resolve_route');
        $result = $callback($rest_request);

        $this->assertInstanceOf(RequestContract::class, $result);
    }

    public function test_custom_request_class_is_resolved_for_controller(): void
    {
        $route = Route::get('ping', [RouteMiddlewareTestCustomRequestController::class, 'show']);

        $rest_request = $this->make_rest_request();
        $this->invoke_route_method($route, 'resolve_permission_callback', $rest_request);

        $callback = $this->invoke_route_method($route, 'resolve_route');
        $result = $callback($rest_request);

        $this->assertSame('custom-request', $result);
    }

    public function test_custom_request_class_is_resolved_for_closure(): void
    {
        $route = Route::get('ping', function (RouteMiddlewareTestCustomRequest $request) {
            return $request->get_marker();
        });

        $rest_request = $this->make_rest_request();
        $this->invoke_route_method($route, 'resolve_permission_callback', $rest_request);

        $callback = $this->invoke_route_method($route, 'resolve_route');
        $result = $callback($rest_request);

        $this->assertSame('custom-request', $result);
    }

    protected function make_rest_request(
        string $method = 'GET',
        string $route = '/framework/v1/ping',
        array $params = []
    ): WP_REST_Request {
        return new WP_REST_Request($method, $route, $params);
    }

    protected function invoke_route_method(Route $route, string $method, ...$arguments)
    {
        $reflection = new \ReflectionClass(Route::class);
        $method_reflection = $reflection->getMethod($method);
        $method_reflection->setAccessible(true);

        return $method_reflection->invoke($route, ...$arguments);
    }

    protected function read_route_property(Route $route, string $property)
    {
        $reflection = new \ReflectionClass(Route::class);
        $property_reflection = $reflection->getProperty($property);
        $property_reflection->setAccessible(true);

        return $property_reflection->getValue($route);
    }
}

class RouteMiddlewareSpyRoute extends Route
{
    public static $resolve_model_calls = 0;

    public static function spy_get(string $endpoint, $action)
    {
        $instance = new static();
        $instance->method = 'get';
        $instance->endpoint = $endpoint;
        $instance->action = $action;

        static::$routes[] = $instance;

        return $instance;
    }

    protected function resolve_model($model, $value)
    {
        static::$resolve_model_calls++;

        return parent::resolve_model($model, $value);
    }
}

class RouteMiddlewareTestCountingMiddleware implements Middleware
{
    public static $handle_calls = 0;

    public function handle($request, callable $next)
    {
        static::$handle_calls++;

        return $next($request);
    }
}

class RouteMiddlewareTestDenyMiddleware implements Middleware
{
    public function handle($request, callable $next)
    {
        throw new AuthorizationException('Access denied.', Response::FORBIDDEN);
    }
}

class RouteMiddlewareTestMutatingMiddleware implements Middleware
{
    public function handle($request, callable $next)
    {
        $request->middleware_marker = 'mutated';

        return $next($request);
    }
}

class RouteMiddlewareTestController
{
    public function show(RequestContract $request)
    {
        return $request->get('middleware_marker', $request);
    }
}

class RouteMiddlewareTestModelController
{
    public function show(RequestContract $request, StubArticle $article)
    {
        return $article;
    }
}

class RouteMiddlewareTestCustomRequest extends Request
{
    public function get_marker()
    {
        return 'custom-request';
    }
}

class RouteMiddlewareTestCustomRequestController
{
    public function show(RouteMiddlewareTestCustomRequest $request)
    {
        return $request->get_marker();
    }
}
