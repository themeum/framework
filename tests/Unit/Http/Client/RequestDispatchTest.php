<?php

namespace Framework\Tests\Unit\Http\Client;

use Framework\Http\Client\Request;
use Framework\Http\Client\Response;
use Framework\Tests\Unit\TestCase;
use RuntimeException;

class RequestDispatchTest extends TestCase
{
    protected function last_remote_request(): array
    {
        $requests = $GLOBALS['framework_test_remote_requests'] ?? [];

        $this->assertNotEmpty($requests, 'wp_remote_request() was not called.');

        return end($requests);
    }

    public function test_get_with_query_appends_the_query_and_sends_no_body(): void
    {
        $response = (new Request())->get('https://api.example.test/items', ['page' => 2, 'q' => 'tote']);

        [$url, $args] = $this->last_remote_request();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('https://api.example.test/items?page=2&q=tote', $url);
        $this->assertSame('GET', $args['method']);
        $this->assertNull($args['body']);
        $this->assertSame('application/json', $args['headers']['Content-Type']);
    }

    public function test_post_json_encodes_the_body(): void
    {
        (new Request())->timeout(15)->with_headers(['X-Trace' => 'abc'])->post('https://api.example.test/items', ['name' => 'Tote']);

        [$url, $args] = $this->last_remote_request();

        $this->assertSame('https://api.example.test/items', $url);
        $this->assertSame('POST', $args['method']);
        $this->assertSame('{"name":"Tote"}', $args['body']);
        $this->assertSame(15, $args['timeout']);
        $this->assertSame('abc', $args['headers']['X-Trace']);
    }

    public function test_form_body_is_sent_as_an_array(): void
    {
        (new Request())->as_form()->post('https://api.example.test/items', ['name' => 'Tote']);

        [, $args] = $this->last_remote_request();

        $this->assertSame(['name' => 'Tote'], $args['body']);
        $this->assertSame('application/x-www-form-urlencoded', $args['headers']['Content-Type']);
    }

    public function test_multipart_body_sets_the_boundary_header_before_args_are_built(): void
    {
        (new Request())->as_multipart()->post('https://api.example.test/upload', ['name' => 'Tote']);

        [, $args] = $this->last_remote_request();

        $this->assertStringStartsWith('multipart/form-data; boundary=', $args['headers']['Content-Type']);
        $this->assertStringContainsString('name="name"', $args['body']);
    }

    public function test_with_body_sends_the_raw_content(): void
    {
        (new Request())->with_body('<xml/>', 'application/xml')->put('https://api.example.test/doc');

        [, $args] = $this->last_remote_request();

        $this->assertSame('PUT', $args['method']);
        $this->assertSame('<xml/>', $args['body']);
        $this->assertSame('application/xml', $args['headers']['Content-Type']);
    }

    public function test_relative_url_is_joined_to_the_base_url(): void
    {
        (new Request())->base_url('https://api.example.test/v1/')->get('/items');

        [$url] = $this->last_remote_request();

        $this->assertSame('https://api.example.test/v1/items', $url);
    }

    public function test_invalid_method_throws_before_anything_is_sent(): void
    {
        $this->expectException(RuntimeException::class);

        try {
            (new Request())->send('TRACE', 'https://api.example.test/items');
        } finally {
            $this->assertEmpty($GLOBALS['framework_test_remote_requests'] ?? []);
        }
    }
}
