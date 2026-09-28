<?php

namespace Framework\Tests\Support\Queue\Jobs;

use Framework\Contracts\ShouldQueue;
use Framework\Queue\Concerns\Queueable;
use RuntimeException;
use Throwable;

class ThrowingJob implements ShouldQueue
{
    use Queueable;

    protected $tries = 3;

    protected $backoff = [10, 60];

    public function handle()
    {
        Journal::write('attempt', $this->attempts());

        throw new RuntimeException('boom');
    }

    public function failed(Throwable $exception)
    {
        Journal::write('failed', $exception->getMessage());
    }
}
