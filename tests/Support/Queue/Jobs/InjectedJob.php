<?php

namespace Framework\Tests\Support\Queue\Jobs;

use Framework\Contracts\ShouldQueue;
use Framework\Queue\Concerns\Queueable;

class InjectedJob implements ShouldQueue
{
    use Queueable;

    public function handle(Mailer $mailer)
    {
        Journal::write('injected', $mailer->name);
    }
}
