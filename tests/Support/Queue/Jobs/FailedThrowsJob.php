<?php

namespace Framework\Tests\Support\Queue\Jobs;

use Framework\Contracts\ShouldQueue;
use Framework\Queue\Concerns\Queueable;
use RuntimeException;

class FailedThrowsJob implements ShouldQueue
{
    use Queueable;

    public function handle()
    {
        throw new RuntimeException('handle failed');
    }

    public function failed()
    {
        throw new RuntimeException('failed() failed too');
    }
}
