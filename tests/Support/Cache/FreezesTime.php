<?php

namespace Framework\Tests\Support\Cache;

/**
 * Lets a cache class be driven by a clock the test controls.
 *
 * The cache reads the clock through one overridable method precisely so that expiry, freshness
 * windows and forever lifetimes can be exercised without sleeping.
 */
trait FreezesTime
{
    protected $frozen_time;

    public function freeze(int $timestamp): self
    {
        $this->frozen_time = $timestamp;

        return $this;
    }

    public function travel(int $seconds): self
    {
        $this->frozen_time = $this->current_timestamp() + $seconds;

        return $this;
    }

    protected function current_timestamp()
    {
        return $this->frozen_time ?? time();
    }
}
