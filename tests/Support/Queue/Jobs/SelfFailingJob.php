<?php

namespace Framework\Tests\Support\Queue\Jobs;

use Framework\Contracts\ShouldQueue;
use Framework\Queue\Concerns\Queueable;
use RuntimeException;
use Throwable;

class SelfFailingJob implements ShouldQueue
{
    use Queueable;

    protected $tries = 3;

    public function handle()
    {
        $this->fail(new RuntimeException('gave up'));
    }

    public function failed(Throwable $exception)
    {
        Journal::write('failed', $exception->getMessage());
    }
}
