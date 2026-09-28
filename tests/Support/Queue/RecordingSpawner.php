<?php

namespace Framework\Tests\Support\Queue;

use Framework\Queue\Spawner;
use Framework\Tests\Support\Cache\FreezesTime;

/**
 * A spawner that counts loopback requests instead of sending them, with an in-memory chain lock.
 */
class RecordingSpawner extends Spawner
{
    use FreezesTime;

    public int $spawned = 0;

    public function spawn()
    {
        $this->spawned++;
    }

    public function chain_lock(): TestLock
    {
        return $this->lock();
    }

    protected function lock()
    {
        return new TestLock('queue_chain');
    }

    protected function prepare_runtime()
    {
        // Raising the time limit would leak into the rest of the test run.
    }
}
