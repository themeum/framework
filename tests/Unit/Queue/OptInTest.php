<?php

namespace Framework\Tests\Unit\Queue;

use Framework\Exceptions\QueueException;
use Framework\Queue\QueueServiceProvider;
use Framework\Tests\Support\Queue\Jobs\SendEmail;

class OptInTest extends QueueTestCase
{
    public function test_without_the_provider_nothing_is_hooked_and_dispatch_throws(): void
    {
        $this->assertSame([], $this->queue_hooks());
        $this->assertSame([], $GLOBALS['framework_test_cron']);

        $this->expectException(QueueException::class);

        SendEmail::dispatch(1);
    }

    public function test_the_provider_hooks_the_sweep_and_the_worker_endpoint(): void
    {
        $provider = $this->app->register(new QueueServiceProvider($this->app));
        $provider->boot();

        $this->assertSame(
            ['queue_sweep', 'wp_ajax_nopriv_queue_work', 'wp_ajax_queue_work'],
            $this->queue_hooks()
        );
        $this->assertSame('queue_every_minute', $GLOBALS['framework_test_cron']['queue_sweep']['recurrence']);

        SendEmail::dispatch(1);
        $this->assertCount(1, $this->queue->rows);
    }

    public function test_the_sweep_is_scheduled_only_once(): void
    {
        $provider = $this->app->register(new QueueServiceProvider($this->app));
        $provider->boot();
        $scheduled = $GLOBALS['framework_test_cron']['queue_sweep']['timestamp'];

        $provider->boot();

        $this->assertSame($scheduled, $GLOBALS['framework_test_cron']['queue_sweep']['timestamp']);
    }

    protected function queue_hooks(): array
    {
        return array_values(array_filter(array_keys($GLOBALS['framework_test_actions']), function ($hook) {
            return strpos($hook, 'queue') !== false;
        }));
    }
}
