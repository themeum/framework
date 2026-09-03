<?php

namespace Framework\Tests\Support\RateLimiting;

use Closure;
use Framework\Cache\Repository;
use Framework\RateLimiting\RateLimiter;
use Framework\Tests\Support\Cache\FreezesTime;

/**
 * A limiter driven by a clock the test controls, counting in a repository the test supplies.
 *
 * Serialization is bypassed here so that counting can be exercised without a database; the locks
 * that serialization depends on are covered on their own in LockTest.
 */
class FrozenRateLimiter extends RateLimiter
{
    use FreezesTime;

    protected Repository $repository;

    public function set_cache(Repository $repository): self
    {
        $this->repository = $repository;

        return $this;
    }

    public function cache()
    {
        return $this->repository;
    }

    protected function serialized(string $key, Closure $callback)
    {
        return $callback();
    }
}
