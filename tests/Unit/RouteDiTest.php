<?php

namespace Framework\Tests\Unit;

use Exception;
use InvalidArgumentException;
use Framework\Contracts\Request as RequestContract;
use Framework\Http\Request;
use Framework\Route;
use WP_REST_Request;

use function Framework\app;

class RouteDiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->reset_route_state();
        Route::set_namespace('framework/v1');
    }

    public function test_group_applies_prefix_and_middleware_to_routes(): void
    {
        Route::group([
            'prefix' => 'products',
            'middleware' => [RouteDiTestMiddleware::class],
        ], function () {
            Route::get('{id}', [RouteDiTestController::class, 'show']);
        });

        $routes = Route::get_routes();

        $this->assertCount(1, $routes);
        $this->assertSame('products/{id}', $routes[0]->endpoint ?? $this->read_route_property($routes[0], 'endpoint'));
        $this->assertSame(
            [RouteDiTestMiddleware::class],
            $this->read_route_property($routes[0], 'middlewares')
        );
    }

    public function test_where_replaces_route_parameter_pattern(): void
    {
        $route = Route::get('products/{id}', [RouteDiTestController::class, 'show'])
            ->where('id', '[0-9]+');

        $formatted = $this->invoke_route_method($route, 'get_formatted_endpoint');

        $this->assertSame('products/(?P<id>[0-9]+)', $formatted);
    }

    public function test_make_resolves_constructor_dependencies_fresh_on_each_call(): void
    {
        $route = Route::get('ping', [RouteDiTestController::class, 'show']);

        $first = $this->invoke_route_method($route, 'make', RouteDiServiceWithDependency::class);
        $second = $this->invoke_route_method($route, 'make', RouteDiServiceWithDependency::class);

        $this->assertInstanceOf(RouteDiServiceWithDependency::class, $first);
        $this->assertInstanceOf(RouteDiLeafService::class, $first->leaf);
        $this->assertNotSame($first, $second);
        $this->assertNotSame($first->leaf, $second->leaf);
    }

    public function test_controller_constructor_and_action_share_container_singleton(): void
    {
        $this->bootstrap_application();
        app()->singleton(RouteDiCart::class, fn () => new RouteDiCart());

        $route = Route::get('cart', [RouteDiCartController::class, 'add']);
        $rest_request = new WP_REST_Request('GET', '/framework/v1/cart');
        $this->invoke_route_method($route, 'resolve_permission_callback', $rest_request);

        $callback = $this->invoke_route_method($route, 'resolve_route');
        $result = $callback($rest_request);

        $this->assertIsArray($result);
        $this->assertSame($result['constructor'], $result['action']);
        $this->assertSame(app()->make(RouteDiCart::class), $result['constructor']);
    }

    public function test_make_resolves_constructor_interface_bound_in_container(): void
    {
        app()->bind(RouteDiGateway::class, fn () => new RouteDiStripeGateway());
        $route = Route::get('pay', [RouteDiPaymentController::class, 'show']);

        $controller = $this->invoke_route_method($route, 'make', RouteDiPaymentController::class);

        $this->assertInstanceOf(RouteDiStripeGateway::class, $controller->gateway);
    }

    public function test_make_resolves_constructor_service_built_by_container_closure(): void
    {
        app()->singleton(RouteDiShippingService::class, fn () => new RouteDiShippingService(['zone' => 'eu']));
        $route = Route::get('shipping', [RouteDiShippingController::class, 'show']);

        $controller = $this->invoke_route_method($route, 'make', RouteDiShippingController::class);

        $this->assertSame(app()->make(RouteDiShippingService::class), $controller->shipping);
        $this->assertSame(['zone' => 'eu'], $controller->shipping->settings);
    }

    public function test_make_uses_default_for_scalar_constructor_parameter(): void
    {
        $route = Route::get('limit', [RouteDiLimitController::class, 'show']);

        $controller = $this->invoke_route_method($route, 'make', RouteDiLimitController::class);

        $this->assertInstanceOf(RouteDiLeafService::class, $controller->leaf);
        $this->assertSame(10, $controller->limit);
    }

    public function test_make_reuses_controller_bound_as_singleton(): void
    {
        app()->singleton(RouteDiLimitController::class);
        $route = Route::get('limit', [RouteDiLimitController::class, 'show']);

        $first = $this->invoke_route_method($route, 'make', RouteDiLimitController::class);
        $second = $this->invoke_route_method($route, 'make', RouteDiLimitController::class);

        $this->assertSame($first, $second);
    }

    public function test_make_does_not_share_unbound_dependency_between_controllers(): void
    {
        $route = Route::get('ping', [RouteDiTestController::class, 'show']);

        $first = $this->invoke_route_method($route, 'make', RouteDiReportController::class);
        $second = $this->invoke_route_method($route, 'make', RouteDiExportController::class);

        $this->assertNotSame($first->reports, $second->reports);
    }

    public function test_make_detects_circular_dependencies(): void
    {
        $route = Route::get('ping', [RouteDiTestController::class, 'show']);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Circular dependency detected');

        $this->invoke_route_method($route, 'make', RouteDiCircularA::class);
    }

    public function test_resolve_method_dependencies_categorizes_parameters(): void
    {
        $route = Route::get('products/{id}', [RouteDiControllerWithDependencies::class, 'update']);

        $dependencies = $this->invoke_route_method(
            $route,
            'resolve_method_dependencies',
            RouteDiControllerWithDependencies::class,
            'update'
        );

        $this->assertCount(1, $dependencies['requests']);
        $this->assertSame(RequestContract::class, $dependencies['requests'][0]['type']);
        $this->assertCount(1, $dependencies['builtins']);
        $this->assertSame('bool', $dependencies['builtins'][0]['type']);
        $this->assertCount(1, $dependencies['abstracts']);
        $this->assertSame(RouteDiDependentService::class, $dependencies['abstracts'][0]['type']);
    }

    public function test_resolve_closure_dependencies_categorizes_parameters(): void
    {
        $route = Route::get('hello/{name}', function (RequestContract $request, string $name, RouteDiDependentService $service) {
            return compact('request', 'name', 'service');
        });

        $dependencies = $this->invoke_route_method(
            $route,
            'resolve_closure_dependencies',
            $this->read_route_property($route, 'action')
        );

        $this->assertCount(1, $dependencies['requests']);
        $this->assertSame(RequestContract::class, $dependencies['requests'][0]['type']);
        $this->assertCount(1, $dependencies['builtins']);
        $this->assertSame('string', $dependencies['builtins'][0]['type']);
        $this->assertSame('name', $dependencies['builtins'][0]['variable']);
        $this->assertCount(1, $dependencies['abstracts']);
        $this->assertSame(RouteDiDependentService::class, $dependencies['abstracts'][0]['type']);
    }

    public function test_resolve_method_dependencies_requires_single_request_parameter(): void
    {
        $route = Route::get('ping', [RouteDiControllerWithMultipleRequests::class, 'handle']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must have only one request dependency');

        $this->invoke_route_method(
            $route,
            'resolve_method_dependencies',
            RouteDiControllerWithMultipleRequests::class,
            'handle'
        );
    }

    public function test_rest_closure_dispatch_injects_route_params(): void
    {
        $this->bootstrap_application();

        $route = Route::get('hello/{name}', function (Request $request, string $name) {
            return 'hi-' . $name;
        });

        $rest_request = new WP_REST_Request('GET', '/framework/v1/hello/sajeeb', ['name' => 'sajeeb']);
        $this->invoke_route_method($route, 'resolve_permission_callback', $rest_request);

        $callback = $this->invoke_route_method($route, 'resolve_route');
        $result = $callback($rest_request);

        $this->assertSame('hi-sajeeb', $result);
    }

    public function test_sort_dependencies_orders_by_parameter_position(): void
    {
        $route = Route::get('ping', [RouteDiTestController::class, 'show']);
        $dependencies = [
            ['resolved' => 'second', 'position' => 1],
            ['resolved' => 'first', 'position' => 0],
        ];

        $sorted = $this->invoke_route_method($route, 'sort_dependencies', $dependencies);

        $this->assertSame(['first', 'second'], array_column($sorted, 'resolved'));
    }

    public function test_update_request_appends_resolved_request_to_dependencies(): void
    {
        $route = Route::get('ping', [RouteDiTestController::class, 'show']);
        $dependencies = [
            ['resolved' => 'service', 'position' => 1],
        ];
        $request = ['resolved' => 'request', 'position' => 0];

        $updated = $this->invoke_route_method($route, 'update_request', $dependencies, $request);

        $this->assertCount(2, $updated);
        $this->assertSame('request', $updated[1]['resolved']);
    }

    private function invoke_route_method(Route $route, string $method, ...$arguments)
    {
        $reflection = new \ReflectionClass(Route::class);
        $method_reflection = $reflection->getMethod($method);
        $method_reflection->setAccessible(true);

        return $method_reflection->invoke($route, ...$arguments);
    }

    private function read_route_property(Route $route, string $property)
    {
        $reflection = new \ReflectionClass(Route::class);
        $property_reflection = $reflection->getProperty($property);
        $property_reflection->setAccessible(true);

        return $property_reflection->getValue($route);
    }
}

class RouteDiTestMiddleware
{
    public function handle($request, callable $next)
    {
        return $next($request);
    }
}

class RouteDiTestController
{
    public function show(RequestContract $request)
    {
        return $request;
    }
}

class RouteDiControllerWithDependencies
{
    public function update(RequestContract $request, bool $force, RouteDiDependentService $service)
    {
        return compact('request', 'force', 'service');
    }
}

class RouteDiControllerWithMultipleRequests
{
    public function handle(RequestContract $first, RequestContract $second)
    {
        return compact('first', 'second');
    }
}

class RouteDiLeafService
{
}

class RouteDiServiceWithDependency
{
    public RouteDiLeafService $leaf;

    public function __construct(RouteDiLeafService $leaf)
    {
        $this->leaf = $leaf;
    }
}

class RouteDiDependentService
{
    public string $label = 'wired';
}

class RouteDiCircularA
{
    public function __construct(RouteDiCircularB $dependency)
    {
    }
}

class RouteDiCircularB
{
    public function __construct(RouteDiCircularA $dependency)
    {
    }
}

class RouteDiCart
{
}

class RouteDiCartController
{
    public RouteDiCart $cart;

    public function __construct(RouteDiCart $cart)
    {
        $this->cart = $cart;
    }

    public function add(RequestContract $request, RouteDiCart $cart)
    {
        return ['constructor' => $this->cart, 'action' => $cart];
    }
}

interface RouteDiGateway
{
}

class RouteDiStripeGateway implements RouteDiGateway
{
}

class RouteDiPaymentController
{
    public RouteDiGateway $gateway;

    public function __construct(RouteDiGateway $gateway)
    {
        $this->gateway = $gateway;
    }

    public function show(RequestContract $request)
    {
        return $request;
    }
}

class RouteDiShippingService
{
    public array $settings;

    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }
}

class RouteDiShippingController
{
    public RouteDiShippingService $shipping;

    public function __construct(RouteDiShippingService $shipping)
    {
        $this->shipping = $shipping;
    }

    public function show(RequestContract $request)
    {
        return $request;
    }
}

class RouteDiLimitController
{
    public RouteDiLeafService $leaf;

    public int $limit;

    public function __construct(RouteDiLeafService $leaf, int $limit = 10)
    {
        $this->leaf = $leaf;
        $this->limit = $limit;
    }

    public function show(RequestContract $request)
    {
        return $request;
    }
}

class RouteDiReportService
{
    public RouteDiLeafService $leaf;

    public function __construct(RouteDiLeafService $leaf)
    {
        $this->leaf = $leaf;
    }
}

class RouteDiReportController
{
    public RouteDiReportService $reports;

    public function __construct(RouteDiReportService $reports)
    {
        $this->reports = $reports;
    }
}

class RouteDiExportController
{
    public RouteDiReportService $reports;

    public function __construct(RouteDiReportService $reports)
    {
        $this->reports = $reports;
    }
}
