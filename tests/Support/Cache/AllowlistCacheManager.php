<?php

namespace Framework\Tests\Support\Cache;

use Framework\Supports\ValueCodec;

/**
 * A configurable cache manager whose cache.allowed_classes a test can supply directly, since a
 * unit test has no config file for config() to read.
 */
class AllowlistCacheManager extends ConfigurableCacheManager
{
    protected array $allowed_classes = [];

    public function set_allowed_classes(array $classes): self
    {
        $this->allowed_classes = $classes;
        $this->forget_resolved();

        return $this;
    }

    protected function value_codec()
    {
        return new ValueCodec($this->allowed_classes);
    }
}
