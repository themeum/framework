<?php

namespace Framework\Contracts;

interface Cacheable
{
    /**
     * Get the cache key.
     *
     * @return string
     */
    public function cache($path = null);
}
