<?php

namespace Framework\Tests\Support\Queue\Jobs;

use Framework\Contracts\ShouldQueue;
use Framework\Queue\Concerns\Queueable;
use RuntimeException;

class AttemptsJob implements ShouldQueue
{
    use Queueable;

    protected $tries = 2;

    public function handle()
    {
        Journal::write('attempts', $this->attempts());

        if ($this->attempts() === 1) {
            throw new RuntimeException('first try fails');
        }
    }
}
