<?php

namespace Framework\Tests\Support\Queue\Jobs;

use Framework\Contracts\ShouldQueue;
use Framework\Queue\Concerns\Queueable;

class ReleasingJob implements ShouldQueue
{
    use Queueable;

    public function handle()
    {
        $this->release(30);
    }

    public function failed()
    {
        Journal::write('failed');
    }
}
