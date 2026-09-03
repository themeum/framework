<?php

namespace Framework\Tests\Unit\RateLimiting;

use Framework\Exceptions\ThrottleRequestsException;
use Framework\Http\Request;
use Framework\Http\Response;
use Framework\Middlewares\ThrottleRequests;
use Framework\RateLimiting\Limit;
use Framework\RateLimiting\RateLimiter;
use Framework\Tests\Support\Cache\FrozenArrayStore;
use Framework\Tests\Support\Cache\FrozenRepository;
use Framework\Tests\Support\RateLimiting\FrozenRateLimiter;
use InvalidArgumentException;

class ThrottleRequestsTest extends RateLimiterTestCase
{
    protected FrozenRateLimiter $limiter;

    protected function setUp(): void
    {
        parent::setUp();

        $now = time();

        $store = (new FrozenArrayStore())->freeze($now);
        $cache = (new FrozenRepository($store, 'array', false))->freeze($now);

        $this->limiter = (new FrozenRateLimiter())->set_cache($cache);
        $this->limiter->freeze($now);

        $this->app->instance(RateLimiter::class, $this->limiter);
    }

    protected function pass(array $parameters, ?Request $request = null)
    {
        $request = $request ?: new Request();

        return (new ThrottleRequests())->handle($request, function ($request) {
            return 'reached';
        }, ...$parameters);
    }

    public function test_a_request_within_its_limit_reaches_the_route()
    {
        $this->assertSame('reached', $this->pass(['3', '1']));
    }

    public function test_a_request_beyond_its_limit_is_rejected()
    {
        $this->pass(['2', '1']);
        $this->pass(['2', '1']);

        $this->expectException(ThrottleRequestsException::class);

        $this->pass(['2', '1']);
    }

    public function test_a_rejection_carries_the_rate_limit_headers()
    {
        $this->pass(['1', '1']);

        try {
            $this->pass(['1', '1']);

            $this->fail('The request should have been rejected.');
        } catch (ThrottleRequestsException $exception) {
            $headers = $exception->get_headers();

            $this->assertSame(Response::TOO_MANY_REQUESTS, $exception->get_status());
            $this->assertSame('rest_too_many_requests', $exception->error_code());
            $this->assertSame('1', $headers['X-RateLimit-Limit']);
            $this->assertSame('0', $headers['X-RateLimit-Remaining']);
            $this->assertArrayHasKey('Retry-After', $headers);
            $this->assertArrayHasKey('X-RateLimit-Reset', $headers);
            $this->assertGreaterThan(0, (int) $headers['Retry-After']);
        }
    }

    public function test_a_permitted_request_records_the_allowance_headers()
    {
        $this->pass(['5', '1']);

        $headers = ThrottleRequests::recorded_headers();

        $this->assertSame('5', $headers['X-RateLimit-Limit']);
        $this->assertSame('4', $headers['X-RateLimit-Remaining']);
    }

    public function test_the_remaining_allowance_counts_down()
    {
        $this->pass(['3', '1']);
        $this->pass(['3', '1']);

        $this->assertSame('1', ThrottleRequests::recorded_headers()['X-RateLimit-Remaining']);
    }

    public function test_the_allowance_returns_after_the_window_passes()
    {
        $this->pass(['1', '1']);

        $this->limiter->travel(61);
        $this->limiter->cache()->travel(61);
        $this->limiter->cache()->get_store()->travel(61);

        $this->assertSame('reached', $this->pass(['1', '1']));
    }

    public function test_a_named_limiter_governs_the_route()
    {
        $this->limiter->for('uploads', function () {
            return Limit::per_minute(1);
        });

        $this->assertSame('reached', $this->pass(['uploads']));

        $this->expectException(ThrottleRequestsException::class);

        $this->pass(['uploads']);
    }

    public function test_an_unregistered_limiter_name_is_reported()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Rate limiter [missing] is not registered.');

        $this->pass(['missing']);
    }

    public function test_an_unlimited_limit_never_rejects()
    {
        $this->limiter->for('open', function () {
            return Limit::none();
        });

        for ($index = 0; $index < 25; $index++) {
            $this->assertSame('reached', $this->pass(['open']));
        }

        $this->assertSame([], ThrottleRequests::recorded_headers());
    }

    public function test_several_limits_are_all_evaluated()
    {
        $this->limiter->for('mixed', function () {
            return [
                Limit::per_minute(5)->by('minute'),
                Limit::per_day(2)->by('day'),
            ];
        });

        $this->pass(['mixed']);
        $this->pass(['mixed']);

        $this->expectException(ThrottleRequestsException::class);

        $this->pass(['mixed']);
    }

    public function test_the_first_exceeded_limit_governs_the_retry_time()
    {
        $this->limiter->for('mixed', function () {
            return [
                Limit::per_minute(5)->by('minute'),
                Limit::per_day(1)->by('day'),
            ];
        });

        $this->pass(['mixed']);

        try {
            $this->pass(['mixed']);

            $this->fail('The request should have been rejected.');
        } catch (ThrottleRequestsException $exception) {
            $this->assertGreaterThan(60, (int) $exception->get_headers()['Retry-After']);
        }
    }

    public function test_a_segmented_limit_gives_each_caller_its_own_allowance()
    {
        $this->limiter->for('per-user', function ($request) {
            return Limit::per_minute(1)->by($request->throttle_identity);
        });

        $first = new Request();
        $first->throttle_identity = 'user:1';

        $second = new Request();
        $second->throttle_identity = 'user:2';

        $this->assertSame('reached', $this->pass(['per-user'], $first));
        $this->assertSame('reached', $this->pass(['per-user'], $second));

        $this->expectException(ThrottleRequestsException::class);

        $this->pass(['per-user'], $first);
    }

    public function test_a_custom_response_is_used_for_the_rejection()
    {
        $this->limiter->for('custom', function () {
            return Limit::per_minute(1)->response(function ($request, array $headers) {
                return ['message' => 'Slow down', 'limit' => $headers['X-RateLimit-Limit']];
            });
        });

        $this->pass(['custom']);

        try {
            $this->pass(['custom']);

            $this->fail('The request should have been rejected.');
        } catch (ThrottleRequestsException $exception) {
            $this->assertSame(
                ['message' => 'Slow down', 'limit' => '1'],
                $exception->get_payload()
            );
            $this->assertArrayHasKey('Retry-After', $exception->get_headers());
        }
    }

    public function test_the_decay_argument_is_expressed_in_minutes()
    {
        $this->pass(['1', '2']);

        try {
            $this->pass(['1', '2']);

            $this->fail('The request should have been rejected.');
        } catch (ThrottleRequestsException $exception) {
            $this->assertGreaterThan(60, (int) $exception->get_headers()['Retry-After']);
        }
    }
}
