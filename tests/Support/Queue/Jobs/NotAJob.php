<?php

namespace Framework\Tests\Support\Queue\Jobs;

class NotAJob
{
    public function handle()
    {
        Journal::write('not a job ran');
    }
}
