<?php

namespace Framework\Tests\Unit\RateLimiting;

use Framework\Tests\Support\RateLimiting\ConfigurableRequest;

class RequestIpTest extends RateLimiterTestCase
{
    protected function request(array $server, array $proxies = []): ConfigurableRequest
    {
        return (new ConfigurableRequest())->with_server($server)->trust($proxies);
    }

    public function test_the_connecting_address_identifies_the_caller()
    {
        $request = $this->request(['REMOTE_ADDR' => '203.0.113.9']);

        $this->assertSame('203.0.113.9', $request->ip());
    }

    public function test_forwarded_headers_are_ignored_without_a_trusted_proxy()
    {
        $request = $this->request([
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
        ]);

        $this->assertSame('203.0.113.9', $request->ip());
    }

    public function test_a_forged_cloudflare_header_is_ignored_without_a_trusted_proxy()
    {
        $request = $this->request([
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_CF_CONNECTING_IP' => '198.51.100.7',
        ]);

        $this->assertSame('203.0.113.9', $request->ip());
    }

    public function test_a_forwarded_address_is_honoured_behind_a_trusted_proxy()
    {
        $request = $this->request([
            'REMOTE_ADDR' => '192.0.2.10',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
        ], ['192.0.2.10']);

        $this->assertSame('198.51.100.7', $request->ip());
    }

    public function test_a_trusted_proxy_may_be_given_as_a_cidr_range()
    {
        $request = $this->request([
            'REMOTE_ADDR' => '192.0.2.55',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
        ], ['192.0.2.0/24']);

        $this->assertSame('198.51.100.7', $request->ip());
    }

    public function test_an_address_outside_the_trusted_range_is_not_trusted()
    {
        $request = $this->request([
            'REMOTE_ADDR' => '203.0.113.55',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
        ], ['192.0.2.0/24']);

        $this->assertSame('203.0.113.55', $request->ip());
    }

    public function test_the_wildcard_trusts_every_proxy()
    {
        $request = $this->request([
            'REMOTE_ADDR' => '203.0.113.55',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
        ], ['*']);

        $this->assertSame('198.51.100.7', $request->ip());
    }

    public function test_a_chain_of_trusted_proxies_resolves_to_the_client()
    {
        $request = $this->request([
            'REMOTE_ADDR' => '192.0.2.10',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 192.0.2.11, 192.0.2.12',
        ], ['192.0.2.0/24']);

        $this->assertSame('198.51.100.7', $request->ip());
    }

    public function test_an_ipv6_range_is_matched()
    {
        $request = $this->request([
            'REMOTE_ADDR' => '2001:db8::5',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
        ], ['2001:db8::/32']);

        $this->assertSame('198.51.100.7', $request->ip());
    }

    public function test_an_ipv6_address_outside_the_range_is_not_trusted()
    {
        $request = $this->request([
            'REMOTE_ADDR' => '2001:dead::5',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
        ], ['2001:db8::/32']);

        $this->assertSame('2001:dead::5', $request->ip());
    }

    public function test_spoofed_headers_cannot_multiply_allowances()
    {
        $addresses = [];

        foreach (['198.51.100.1', '198.51.100.2', '198.51.100.3'] as $forged) {
            $request = $this->request([
                'REMOTE_ADDR' => '203.0.113.9',
                'HTTP_X_FORWARDED_FOR' => $forged,
            ]);

            $addresses[] = $request->ip();
        }

        $this->assertSame(['203.0.113.9', '203.0.113.9', '203.0.113.9'], $addresses);
        $this->assertCount(1, array_unique($addresses));
    }

    public function test_the_user_identifies_an_authenticated_caller()
    {
        $GLOBALS['framework_test_current_user_id'] = 7;

        $this->assertSame(7, $this->request([])->user_id());
    }

    public function test_a_guest_has_no_user_identifier()
    {
        $GLOBALS['framework_test_current_user_id'] = 0;

        $this->assertNull($this->request([])->user_id());
    }
}
