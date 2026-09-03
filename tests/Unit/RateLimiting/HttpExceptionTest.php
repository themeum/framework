<?php

namespace Framework\Tests\Unit\RateLimiting;

use Framework\ApiExceptionHandler;
use Framework\Contracts\Middleware;
use Framework\Contracts\Request as RequestContract;
use Framework\Exceptions\AuthorizationException;
use Framework\Exceptions\HttpException;
use Framework\Exceptions\ThrottleRequestsException;
use Framework\Exceptions\ValidationException;
use Framework\Http\Response;
use Framework\Route;
use Framework\SiteExceptionHandler;
use RuntimeException;
use ReflectionClass;
use WP_Error;
use WP_REST_Request;

class HttpExceptionTest extends RateLimiterTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->reset_route_state();
        Route::set_namespace('framework/v1');
    }

    public function test_the_declared_status_and_headers_survive()
    {
        $exception = new HttpException('Nope', 418, ['X-Teapot' => 'yes'], 'teapot');

        $this->assertSame(418, $exception->get_status());
        $this->assertSame(['X-Teapot' => 'yes'], $exception->get_headers());
        $this->assertSame('teapot', $exception->error_code());
    }

    public function test_an_out_of_range_status_falls_back_to_a_server_error()
    {
        $this->assertSame(Response::INTERNAL_SERVER_ERROR, (new HttpException('x', 4))->get_status());
        $this->assertSame(Response::INTERNAL_SERVER_ERROR, (new HttpException('x', 900))->get_status());
    }

    public function test_a_throttle_exception_defaults_to_too_many_requests()
    {
        $exception = new ThrottleRequestsException();

        $this->assertSame(Response::TOO_MANY_REQUESTS, $exception->get_status());
        $this->assertSame('rest_too_many_requests', $exception->error_code());
    }

    public function test_the_api_handler_renders_the_status_and_headers()
    {
        $response = ApiExceptionHandler::get_response(
            new ThrottleRequestsException('Too Many Requests.', ['Retry-After' => '42'])
        );

        $this->assertSame(Response::TOO_MANY_REQUESTS, $response->get_status());
        $this->assertSame('42', $response->get_headers()['Retry-After']);
        $this->assertSame('rest_too_many_requests', $response->get_content(true)['code']);
        $this->assertFalse($response->get_content(true)['success']);
    }

    public function test_the_api_handler_renders_a_declared_payload()
    {
        $exception = (new ThrottleRequestsException('Too Many Requests.'))
            ->with_payload(['message' => 'Slow down']);

        $response = ApiExceptionHandler::get_response($exception);

        $this->assertSame('Slow down', $response->get_content(true)['message']);
        $this->assertFalse($response->get_content(true)['success']);
    }

    public function test_existing_error_mappings_are_untouched()
    {
        $validation = ApiExceptionHandler::get_response(
            ValidationException::with_errors(['name' => 'required'], 'Invalid')
        );

        $this->assertSame(Response::UNPROCESSABLE_ENTITY, $validation->get_status());
    }

    public function test_middleware_rejection_becomes_a_wp_error_carrying_the_status()
    {
        Route::middleware_alias('deny', RejectingMiddleware::class);

        $route = Route::get('ping', function () {
            return 'ok';
        })->middleware('deny');

        $permission = $this->invoke_route_method(
            $route,
            'resolve_permission_callback',
            new WP_REST_Request('GET', '/framework/v1/ping')
        );

        $this->assertInstanceOf(WP_Error::class, $permission);
        $this->assertSame('rest_too_many_requests', $permission->get_error_code());
        $this->assertSame(429, $permission->get_error_data()['status']);
        $this->assertSame('7', $permission->get_error_data()['headers']['Retry-After']);
    }

    public function test_an_authorization_failure_still_maps_to_forbidden()
    {
        Route::middleware_alias('forbid', ForbiddingMiddleware::class);

        $route = Route::get('ping', function () {
            return 'ok';
        })->middleware('forbid');

        $permission = $this->invoke_route_method(
            $route,
            'resolve_permission_callback',
            new WP_REST_Request('GET', '/framework/v1/ping')
        );

        $this->assertInstanceOf(WP_Error::class, $permission);
        $this->assertSame('rest_forbidden', $permission->get_error_code());
    }

    public function test_the_site_handler_maps_a_throttle_rejection_to_429()
    {
        try {
            SiteExceptionHandler::handle(
                new ThrottleRequestsException('Too Many Requests.', ['Retry-After' => '30'])
            );

            $this->fail('The handler should have stopped the request.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Too Many Requests.', $exception->getMessage());
            $this->assertSame(429, $GLOBALS['framework_test_status_header']);
        }
    }

    public function test_the_site_handler_still_maps_authorization_to_forbidden()
    {
        try {
            SiteExceptionHandler::handle(new AuthorizationException('Nope', Response::FORBIDDEN));

            $this->fail('The handler should have stopped the request.');
        } catch (RuntimeException $exception) {
            $this->assertSame(Response::FORBIDDEN, $GLOBALS['framework_test_status_header']);
        }
    }

    protected function invoke_route_method(Route $route, string $method, ...$arguments)
    {
        $reflection = new ReflectionClass(Route::class);
        $method_reflection = $reflection->getMethod($method);
        $method_reflection->setAccessible(true);

        return $method_reflection->invoke($route, ...$arguments);
    }
}

class RejectingMiddleware implements Middleware
{
    public function handle(RequestContract $request, callable $next, ...$parameters)
    {
        throw new ThrottleRequestsException('Too Many Requests.', ['Retry-After' => '7']);
    }
}

class ForbiddingMiddleware implements Middleware
{
    public function handle(RequestContract $request, callable $next, ...$parameters)
    {
        throw new AuthorizationException('Nope', Response::FORBIDDEN);
    }
}
