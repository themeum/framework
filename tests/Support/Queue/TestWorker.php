<?php

namespace Framework\Tests\Support\Queue;

use Framework\Queue\JobRecord;
use Framework\Queue\Worker;

/**
 * A worker whose elapsed time is simulated: each job it processes costs a set number of seconds.
 */
class TestWorker extends Worker
{
    public float $seconds_per_job = 0.0;

    public array $processed = [];

    protected float $fake_elapsed = 0.0;

    public function run(array $options = [])
    {
        $this->fake_elapsed = 0.0;

        return parent::run($options);
    }

    public function process(JobRecord $record)
    {
        $this->processed[] = $record->id();

        parent::process($record);

        $this->fake_elapsed += $this->seconds_per_job;
    }

    protected function elapsed()
    {
        return $this->fake_elapsed;
    }
}
