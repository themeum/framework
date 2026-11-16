<?php

namespace Framework\Tests\Unit\Http;

use Framework\Http\Request;
use Framework\Tests\Support\RateLimiting\ConfigurableRequest;
use Framework\Tests\Unit\TestCase;
use InvalidArgumentException;
use stdClass;
use WP_REST_Request;

class RequestHeaderTest extends TestCase
{
    public function test_header_names_match_across_case_and_separators(): void
    {
        $request = (new Request())->set_header('X-Tenant', 'acme');

        $this->assertSame('acme', $request->header('x_tenant'));
        $this->assertSame('acme', $request->header('x-tenant'));
        $this->assertTrue($request->has_header('x_tenant'));
    }

    public function test_set_header_adds_a_new_header(): void
    {
        $request = (new Request())->set_header('X-Trace', 'abc');

        $this->assertSame('abc', $request->get_header('X-Trace'));
    }

    public function test_set_header_replaces_existing_values(): void
    {
        $request = (new Request())->set_header('X-Trace', 'a');
        $request->set_header('X-Trace', 'b');

        $this->assertSame('b', $request->header('X-Trace'));
        $this->assertSame(['b'], $request->get_headers()['x_trace']);
    }

    public function test_set_header_casts_scalars_to_strings(): void
    {
        $request = (new Request())
            ->set_header('X-User-Id', 42)
            ->set_header('X-Ratio', 1.5)
            ->set_header('X-Yes', true)
            ->set_header('X-No', false);

        $this->assertSame('42', $request->header('X-User-Id'));
        $this->assertSame('1.5', $request->header('X-Ratio'));
        $this->assertSame('1', $request->header('X-Yes'));
        $this->assertSame('', $request->header('X-No'));
        $this->assertTrue($request->has_header('X-No'));
    }

    public function test_set_header_stores_array_values_in_order(): void
    {
        $request = (new Request())->set_header('Accept', ['text/html', 'application/json']);

        $this->assertSame(['text/html', 'application/json'], $request->get_headers()['accept']);
        $this->assertSame('text/html', $request->header('Accept'));
    }

    public function test_set_header_returns_the_same_request(): void
    {
        $request = new Request();
        $result = $request->set_header('X-One', '1')->set_header('X-Two', '2');

        $this->assertSame($request, $result);
        $this->assertTrue($request->has_header('X-One'));
        $this->assertTrue($request->has_header('X-Two'));
    }

    public function test_setting_null_removes_an_existing_header(): void
    {
        $request = (new Request())->set_header('X-Debug', '1');
        $request->set_header('X-Debug', null);

        $this->assertFalse($request->has_header('X-Debug'));
    }

    public function test_setting_null_on_an_absent_header_is_harmless(): void
    {
        $request = (new Request())->set_header('X-Debug', null);

        $this->assertFalse($request->has_header('X-Debug'));
    }

    public function test_object_value_is_rejected_and_header_kept(): void
    {
        $request = (new Request())->set_header('X-Trace', 'a');

        try {
            $request->set_header('X-Trace', new stdClass());
            $this->fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(['a'], $request->get_headers()['x_trace']);
        }
    }

    public function test_nested_array_value_is_rejected_and_header_kept(): void
    {
        $request = (new Request())->set_header('X-Trace', 'a');

        try {
            $request->set_header('X-Trace', ['b', ['c']]);
            $this->fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(['a'], $request->get_headers()['x_trace']);
        }
    }

    public function test_with_headers_merges_and_keeps_unrelated_headers(): void
    {
        $request = (new Request())
            ->set_header('Accept', 'text/html')
            ->set_header('X-Trace', 'a');

        $result = $request->with_headers(['X-Trace' => 'b', 'X-Tenant' => 'acme']);

        $this->assertSame($request, $result);
        $this->assertSame('text/html', $request->header('Accept'));
        $this->assertSame('b', $request->header('X-Trace'));
        $this->assertSame('acme', $request->header('X-Tenant'));
    }

    public function test_with_headers_null_entry_removes_header(): void
    {
        $request = (new Request())->set_header('X-Debug', '1');
        $request->with_headers(['X-Debug' => null]);

        $this->assertFalse($request->has_header('X-Debug'));
    }

    public function test_with_headers_rejects_invalid_values(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Request())->with_headers(['X-Bad' => new stdClass()]);
    }

    public function test_remove_header_removes_an_existing_header(): void
    {
        $request = (new Request())->set_header('Authorization', 'Bearer x');
        $result = $request->remove_header('authorization');

        $this->assertSame($request, $result);
        $this->assertFalse($request->has_header('Authorization'));
        $this->assertSame('none', $request->header('Authorization', 'none'));
    }

    public function test_remove_header_on_an_absent_header_is_harmless(): void
    {
        $request = (new Request())->remove_header('X-Missing');

        $this->assertFalse($request->has_header('X-Missing'));
    }

    public function test_has_header_reports_presence(): void
    {
        $request = (new Request())->make_from_http([], [], [], [
            'REQUEST_METHOD' => 'GET',
            'CONTENT_TYPE' => 'application/json',
        ]);

        $this->assertTrue($request->has_header('content-type'));
        $this->assertFalse($request->has_header('X-Missing'));
    }

    public function test_fresh_request_accepts_headers(): void
    {
        $request = new Request();

        $this->assertSame([], $request->get_headers());

        $request->set_header('X-Tenant', 'acme');

        $this->assertSame('acme', $request->header('X-Tenant'));
    }

    public function test_request_from_http_input_keeps_existing_headers(): void
    {
        $request = (new Request())->make_from_http([], [], [], [
            'REQUEST_METHOD' => 'GET',
            'HTTP_ACCEPT' => 'text/html',
        ]);

        $request->set_header('X-Tenant', 'acme');

        $this->assertSame('text/html', $request->header('Accept'));
        $this->assertSame('acme', $request->header('X-Tenant'));
    }

    public function test_request_from_rest_request_accepts_mutation(): void
    {
        $rest_request = new WP_REST_Request('GET', '/test', [], ['accept' => ['text/html']]);
        $request = Request::from_wp_rest_request($rest_request);

        $request->set_header('X-Tenant', 'acme');

        $this->assertSame('text/html', $request->header('Accept'));
        $this->assertSame('acme', $request->header('X-Tenant'));
        $this->assertSame(['accept' => ['text/html']], $rest_request->get_headers());
    }

    public function test_setting_forwarded_for_does_not_change_the_client_address(): void
    {
        $request = (new ConfigurableRequest())->with_server(['REMOTE_ADDR' => '10.0.0.5']);

        $request->set_header('X-Forwarded-For', '1.2.3.4');

        $this->assertSame('10.0.0.5', $request->ip());
    }

    public function test_setting_forwarded_for_with_a_trusted_proxy_has_no_effect(): void
    {
        $request = (new ConfigurableRequest())
            ->with_server(['REMOTE_ADDR' => '10.0.0.5'])
            ->trust(['10.0.0.5']);

        $request->set_header('X-Forwarded-For', '1.2.3.4');
        $request->set_header('CF-Connecting-IP', '1.2.3.4');

        $this->assertSame('10.0.0.5', $request->ip());
    }
}
