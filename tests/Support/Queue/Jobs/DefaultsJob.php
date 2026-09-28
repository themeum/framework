<?php

namespace Framework\Tests\Support\Queue\Jobs;

use Framework\Contracts\ShouldQueue;
use Framework\Queue\Concerns\Queueable;

class DefaultsJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->on_queue('emails')->with_priority(5)->delay(60);
    }

    public function handle()
    {
        Journal::write('defaults');
    }
}
