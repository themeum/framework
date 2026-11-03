<?php

namespace Framework\Tests\Unit\Http\Client;

use Framework\Http\Client\Response;
use Framework\Tests\Unit\TestCase;
use WP_Error;

class ResponseTest extends TestCase
{
    protected function response_with_status(int $code): Response
    {
        return new Response([
            'headers' => [],
            'body' => '',
            'response' => ['code' => $code, 'message' => ''],
            'cookies' => [],
        ]);
    }

    public function test_wp_error_counts_as_failed(): void
    {
        $response = new Response(new WP_Error('http_request_failed', 'timed out'));

        $this->assertTrue($response->failed());
        $this->assertFalse($response->successful());
        $this->assertSame(0, $response->status());
        $this->assertFalse($response->client_error());
        $this->assertFalse($response->server_error());
    }

    public function test_on_error_runs_for_a_wp_error(): void
    {
        $called = false;

        (new Response(new WP_Error('http_request_failed', 'timed out')))->on_error(function () use (&$called) {
            $called = true;
        });

        $this->assertTrue($called);
    }

    public function test_status_based_results_are_unchanged(): void
    {
        $this->assertFalse($this->response_with_status(200)->failed());
        $this->assertTrue($this->response_with_status(404)->failed());
        $this->assertTrue($this->response_with_status(500)->failed());
    }
}
