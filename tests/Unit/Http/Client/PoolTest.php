<?php

namespace Framework\Tests\Unit\Http\Client;

use Framework\Http\Client\PooledRequest;
use Framework\Http\Client\Request;
use Framework\Http\Client\Response;
use Framework\Tests\Support\Http\RecordingRequest;
use Framework\Tests\Unit\TestCase;
use InvalidArgumentException;
use RuntimeException;
use WP_Error;
use WpOrg\Requests\Exception as RequestsException;
use WpOrg\Requests\Response as RequestsResponse;

class PoolTest extends TestCase
{
    protected function tearDown(): void
    {
        unset(
            $GLOBALS['framework_test_block_external'],
            $GLOBALS['framework_test_accessible_hosts'],
            $GLOBALS['framework_test_unsafe_urls']
        );

        parent::tearDown();
    }

    protected function sent_urls(RecordingRequest $client): array
    {
        $urls = [];

        foreach ($client->pool->batches as $batch) {
            foreach ($batch as $request) {
                $urls[] = $request['url'];
            }
        }

        return $urls;
    }

    protected function batch_sizes(RecordingRequest $client): array
    {
        return array_map('count', $client->pool->batches);
    }

    protected function debug_actions(): array
    {
        return array_values(array_filter($GLOBALS['framework_test_did_actions'] ?? [], function ($action) {
            return $action[0] === 'http_api_debug';
        }));
    }

    public function test_responses_keep_insertion_order_with_keyed_and_unkeyed_requests(): void
    {
        $client = new RecordingRequest();

        $responses = $client->pool(function ($pool) {
            $pool->as('a')->get('https://a.test/1');
            $pool->get('https://a.test/2');
            $pool->as('b')->get('https://a.test/3');
            $pool->get('https://a.test/4');
        });

        $this->assertSame(['a', 0, 'b', 1], array_keys($responses));
        $this->assertContainsOnlyInstancesOf(Response::class, $responses);
        $this->assertSame('https://a.test/1', $responses['a']->body());
        $this->assertSame('https://a.test/2', $responses[0]->body());
        $this->assertSame('https://a.test/3', $responses['b']->body());
        $this->assertSame('https://a.test/4', $responses[1]->body());
    }

    public function test_unkeyed_request_follows_php_append_rules_after_an_integer_key(): void
    {
        $responses = (new RecordingRequest())->pool(function ($pool) {
            $pool->as(5)->get('https://a.test/5');
            $pool->get('https://a.test/6');
        });

        $this->assertSame([5, 6], array_keys($responses));
        $this->assertSame('https://a.test/6', $responses[6]->body());
    }

    public function test_verb_methods_return_the_pooled_request_and_send_nothing_until_the_callback_returns(): void
    {
        $client = new RecordingRequest();
        $seen = [];

        $client->pool(function ($pool) use ($client, &$seen) {
            $seen[] = $pool->as('a')->timeout(15)->get('https://a.test/1');
            $seen[] = $pool->post('https://a.test/2', ['x' => 1]);
            $seen[] = count($client->pool->batches);
        });

        $this->assertInstanceOf(PooledRequest::class, $seen[0]);
        $this->assertInstanceOf(PooledRequest::class, $seen[1]);
        $this->assertSame(0, $seen[2]);
        $this->assertSame(['https://a.test/1', 'https://a.test/2'], $this->sent_urls($client));
    }

    public function test_duplicate_key_throws_and_nothing_is_sent(): void
    {
        $client = new RecordingRequest();

        try {
            $client->pool(function ($pool) {
                $pool->as('a')->get('https://a.test/1');
                $pool->as('a')->get('https://a.test/2');
            });
            $this->fail('A duplicate key must throw.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('[a]', $exception->getMessage());
        }

        $this->assertSame([], $client->pool->batches);
    }

    public function test_explicit_key_colliding_with_an_unkeyed_index_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new RecordingRequest())->pool(function ($pool) {
            $pool->get('https://a.test/1');
            $pool->as(0)->get('https://a.test/2');
        });
    }

    public function test_request_without_a_verb_throws_before_anything_is_sent(): void
    {
        $client = new RecordingRequest();

        try {
            $client->pool(function ($pool) {
                $pool->as('a')->get('https://a.test/1');
                $pool->as('forgotten')->timeout(5);
            });
            $this->fail('A pooled request with no verb must throw.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('[forgotten]', $exception->getMessage());
        }

        $this->assertSame([], $client->pool->batches);
    }

    public function test_failures_are_isolated_per_request(): void
    {
        add_filter('pre_http_request', function ($pre, $args, $url) {
            return $url === 'https://a.test/wp-error' ? new WP_Error('http_request_failed', 'stubbed') : $pre;
        }, 10, 3);

        $client = new RecordingRequest();
        $client->responder = function (array $request) {
            switch ($request['url']) {
                case 'https://a.test/500':
                    return new RequestsResponse(500, 'boom');
                case 'https://a.test/exception':
                    return new RequestsException('cURL error 28: timed out');
                default:
                    return new RequestsResponse(200, $request['url']);
            }
        };

        $responses = $client->pool(function ($pool) {
            $pool->as('ok')->get('https://a.test/ok');
            $pool->as('wp-error')->get('https://a.test/wp-error');
            $pool->as('500')->get('https://a.test/500');
            $pool->as('exception')->get('https://a.test/exception');
            $pool->as('ok2')->get('https://a.test/ok2');
        });

        $this->assertTrue($responses['ok']->successful());
        $this->assertSame('https://a.test/ok', $responses['ok']->body());
        $this->assertTrue($responses['ok2']->successful());
        $this->assertSame('https://a.test/ok2', $responses['ok2']->body());

        $this->assertFalse($responses['wp-error']->successful());
        $this->assertTrue($responses['wp-error']->failed());

        $this->assertSame(500, $responses['500']->status());
        $this->assertFalse($responses['500']->successful());
        $this->assertTrue($responses['500']->failed());

        $this->assertFalse($responses['exception']->successful());
        $this->assertTrue($responses['exception']->failed());
        $this->assertSame(0, $responses['exception']->status());
    }

    public function test_transport_exception_becomes_a_wp_error_with_the_message(): void
    {
        $captured = null;

        add_filter('http_response', function ($response) {
            return $response;
        });
        add_action('http_api_debug', function ($response) use (&$captured) {
            $captured = $response;
        });

        $client = new RecordingRequest();
        $client->responder = function () {
            return new RequestsException('cURL error 6: could not resolve host');
        };

        $client->pool(function ($pool) {
            $pool->get('https://a.test/1');
        });

        $this->assertInstanceOf(WP_Error::class, $captured);
        $this->assertSame('http_request_failed', $captured->get_error_code());
        $this->assertSame('cURL error 6: could not resolve host', $captured->get_error_message());
    }

    public function test_pre_http_request_receives_the_final_url_and_args_and_the_request_is_not_sent(): void
    {
        $calls = [];
        $filtered_responses = 0;

        add_filter('pre_http_request', function ($pre, $args, $url) use (&$calls) {
            $calls[] = [$url, $args];

            return strpos($url, 'stubbed') !== false
                ? ['headers' => [], 'body' => 'from-filter', 'response' => ['code' => 201, 'message' => 'Created']]
                : $pre;
        }, 10, 3);
        add_filter('http_response', function ($response) use (&$filtered_responses) {
            $filtered_responses++;

            return $response;
        });

        $client = new RecordingRequest();

        $responses = $client->pool(function ($pool) {
            $pool->as('stubbed')->with_headers(['X-Custom' => 'yes'])->get('https://a.test/stubbed', ['page' => 2]);
            $pool->as('sent')->get('https://a.test/sent');
        });

        [$url, $args] = $calls[0];

        $this->assertSame('https://a.test/stubbed?page=2', $url);
        $this->assertSame('yes', $args['headers']['X-Custom']);
        $this->assertSame(['https://a.test/sent'], $this->sent_urls($client));
        $this->assertSame('from-filter', $responses['stubbed']->body());
        $this->assertSame(201, $responses['stubbed']->status());

        $debugged_urls = array_map(function ($action) {
            return $action[1][4];
        }, $this->debug_actions());

        $this->assertSame(['https://a.test/sent'], $debugged_urls);
        $this->assertSame(1, $filtered_responses);
    }

    public function test_http_request_args_changes_every_pooled_request(): void
    {
        add_filter('http_request_args', function ($args, $url) {
            $args['headers']['X-Added'] = 'for ' . $url;

            return $args;
        }, 10, 2);

        $client = new RecordingRequest();

        $client->pool(function ($pool) {
            $pool->get('https://a.test/1');
            $pool->post('https://a.test/2', ['x' => 1]);
        });

        $batch = $client->pool->batches[0];

        $this->assertSame('for https://a.test/1', $batch[0]['headers']['X-Added']);
        $this->assertSame('for https://a.test/2', $batch[1]['headers']['X-Added']);
    }

    public function test_blocked_external_host_fails_only_that_request(): void
    {
        $GLOBALS['framework_test_block_external'] = true;
        $GLOBALS['framework_test_accessible_hosts'] = ['allowed.test'];

        $client = new RecordingRequest();

        $responses = $client->pool(function ($pool) {
            $pool->as('allowed')->get('https://allowed.test/a.jpg');
            $pool->as('blocked')->get('https://blocked.test/b.jpg');
        });

        $this->assertSame(['https://allowed.test/a.jpg'], $this->sent_urls($client));
        $this->assertTrue($responses['allowed']->successful());
        $this->assertTrue($responses['blocked']->failed());

        $blocked_debug = $this->debug_actions()[0][1];

        $this->assertInstanceOf(WP_Error::class, $blocked_debug[0]);
        $this->assertSame('http_request_not_executed', $blocked_debug[0]->get_error_code());
    }

    public function test_unsafe_url_with_reject_unsafe_urls_fails_only_that_request(): void
    {
        $GLOBALS['framework_test_unsafe_urls'] = ['https://unsafe.test/x'];

        $client = new RecordingRequest();

        $responses = $client->pool(function ($pool) {
            $pool->as('unsafe')->with_options(['reject_unsafe_urls' => true])->get('https://unsafe.test/x');
            $pool->as('unchecked')->get('https://unsafe.test/x');
            $pool->as('safe')->with_options(['reject_unsafe_urls' => true])->get('https://safe.test/y');
        });

        $this->assertTrue($responses['unsafe']->failed());
        $this->assertTrue($responses['unchecked']->successful());
        $this->assertTrue($responses['safe']->successful());
        $this->assertSame(['https://unsafe.test/x', 'https://safe.test/y'], $this->sent_urls($client));
    }

    public function test_fluent_options_build_the_same_url_and_args_as_a_single_request(): void
    {
        $build = function (Request $request) {
            return $request
                ->with_headers(['X-Trace' => 'abc'])
                ->with_token('secret')
                ->timeout(15)
                ->accept_json();
        };

        $build(new Request())->post('https://a.test/items?ignored=1', ['name' => 'Tote']);
        $build(new Request())->get('https://a.test/items', ['page' => 2]);

        $single = $GLOBALS['framework_test_remote_requests'];
        $pooled = [];

        (new RecordingRequest())->pool(function ($pool) use ($build, &$pooled) {
            $pooled[] = $build($pool->as('post'))->post('https://a.test/items?ignored=1', ['name' => 'Tote'])->get_recorded();
            $pooled[] = $build($pool->as('get'))->get('https://a.test/items', ['page' => 2])->get_recorded();
        });

        $this->assertEquals($single, $pooled);
        $this->assertSame('https://a.test/items?page=2', $pooled[1][0]);
        $this->assertSame('{"name":"Tote"}', $pooled[0][1]['body']);
    }

    public function test_pooled_requests_do_not_inherit_the_parent_request_settings(): void
    {
        $client = (new RecordingRequest())->base_url('https://parent.test')->with_token('parent');
        $recorded = null;

        $client->pool(function ($pool) use (&$recorded) {
            $recorded = $pool->get('https://a.test/1')->get_recorded();
        });

        $this->assertArrayNotHasKey('Authorization', $recorded[1]['headers']);
    }

    public function test_concurrency_splits_network_requests_into_batches_in_order(): void
    {
        $client = new RecordingRequest();

        $client->pool(function ($pool) {
            for ($i = 0; $i < 25; $i++) {
                $pool->get('https://a.test/' . $i);
            }
        }, ['concurrency' => 10]);

        $this->assertSame([10, 10, 5], $this->batch_sizes($client));
        $this->assertSame(array_map(function ($i) {
            return 'https://a.test/' . $i;
        }, range(0, 24)), $this->sent_urls($client));
    }

    public function test_short_circuited_requests_do_not_take_a_batch_slot(): void
    {
        add_filter('pre_http_request', function ($pre, $args, $url) {
            return in_array($url, ['https://a.test/3', 'https://a.test/7'], true)
                ? ['headers' => [], 'body' => '', 'response' => ['code' => 200, 'message' => 'OK']]
                : $pre;
        }, 10, 3);

        $client = new RecordingRequest();

        $responses = $client->pool(function ($pool) {
            for ($i = 0; $i < 12; $i++) {
                $pool->get('https://a.test/' . $i);
            }
        }, ['concurrency' => 10]);

        $this->assertSame([10], $this->batch_sizes($client));
        $this->assertCount(12, $responses);
    }

    public function test_default_concurrency_is_ten(): void
    {
        $client = new RecordingRequest();

        $client->pool(function ($pool) {
            for ($i = 0; $i < 11; $i++) {
                $pool->get('https://a.test/' . $i);
            }
        });

        $this->assertSame([10, 1], $this->batch_sizes($client));
    }

    /**
     * @dataProvider invalid_concurrency_provider
     */
    public function test_invalid_concurrency_throws_before_the_callback_runs($concurrency): void
    {
        $called = false;

        try {
            (new RecordingRequest())->pool(function () use (&$called) {
                $called = true;
            }, ['concurrency' => $concurrency]);
            $this->fail('An invalid concurrency must throw.');
        } catch (InvalidArgumentException $exception) {
            $this->assertFalse($called);
        }
    }

    public function invalid_concurrency_provider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'numeric string' => ['10'],
            'float' => [2.5],
        ];
    }

    public function test_empty_pool_returns_an_empty_array_and_sends_nothing(): void
    {
        $client = new RecordingRequest();

        $this->assertSame([], $client->pool(function () {
        }));
        $this->assertSame([], $client->pool->batches);
    }

    public function test_debug_action_fires_once_per_sent_request_and_response_filter_wins(): void
    {
        add_filter('http_response', function ($response, $args, $url) {
            $response['body'] = 'filtered ' . $url;

            return $response;
        }, 10, 3);

        $responses = (new RecordingRequest())->pool(function ($pool) {
            $pool->as('a')->timeout(7)->get('https://a.test/1');
            $pool->as('b')->get('https://a.test/2');
        });

        $debug = $this->debug_actions();

        $this->assertCount(2, $debug);
        [$response, $context, $class, $args, $url] = $debug[0][1];
        $this->assertSame('response', $context);
        $this->assertSame('WpOrg\Requests\Requests', $class);
        $this->assertSame(7, $args['timeout']);
        $this->assertSame('https://a.test/1', $url);
        $this->assertArrayHasKey('http_response', $response);
        $this->assertSame('filtered https://a.test/1', $responses['a']->body());
        $this->assertSame('filtered https://a.test/2', $responses['b']->body());
    }

    public function test_non_blocking_request_gets_the_wordpress_empty_response(): void
    {
        $responses = (new RecordingRequest())->pool(function ($pool) {
            $pool->with_options(['blocking' => false])->get('https://a.test/1');
        });

        $this->assertSame('', $responses[0]->body());
        $this->assertSame(0, $responses[0]->status());
    }

    public function test_requests_options_follow_wordpress(): void
    {
        $client = new RecordingRequest();

        $client->pool(function ($pool) {
            $pool->as('get')->timeout(9)->get('https://a.test/1');
            $pool->as('post')->without_verifying()->with_options(['limit_response_size' => 1024])->post('https://a.test/2', ['x' => 1]);
            $pool->as('head')->head('https://a.test/3');
        });

        [$get, $post, $head] = array_column($client->pool->batches[0], 'options');

        $this->assertSame(9, $get['timeout']);
        $this->assertSame(ABSPATH . 'wp-includes/certificates/ca-bundle.crt', $get['verify']);
        $this->assertSame(5, $get['redirects']);
        $this->assertArrayNotHasKey('data_format', $get);
        $this->assertSame(
            [['WP_Http', 'browser_redirect_compatibility']],
            $get['hooks']->registered['requests.before_redirect']
        );

        $this->assertFalse($post['verify']);
        $this->assertFalse($post['verifyname']);
        $this->assertSame('body', $post['data_format']);
        $this->assertSame(1024, $post['max_bytes']);

        // Request sets redirection 5 itself, so HEAD keeps it rather than WordPress's HEAD default of 0.
        $this->assertSame(5, $head['redirects']);
    }

    public function test_https_ssl_verify_filter_sets_verify(): void
    {
        add_filter('https_ssl_verify', function () {
            return '/custom/ca.pem';
        });

        $client = new RecordingRequest();

        $client->pool(function ($pool) {
            $pool->get('https://a.test/1');
        });

        $this->assertSame('/custom/ca.pem', $client->pool->batches[0][0]['options']['verify']);
    }
}
