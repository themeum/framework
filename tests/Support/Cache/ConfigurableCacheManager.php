<?php

namespace Framework\Tests\Support\Cache;

use Framework\Cache\CacheManager;

/**
 * A cache manager whose store configuration a test can supply directly.
 *
 * The framework resolves configuration from the application's config directory, which a unit
 * test has no file in, so this replaces only that lookup.
 */
class ConfigurableCacheManager extends CacheManager
{
    protected array $stores_configuration = [];

    public function set_stores(array $stores): self
    {
        $this->stores_configuration = $stores;
        $this->forget_resolved();

        return $this;
    }

    protected function default_configuration()
    {
        return $this->stores_configuration ?: parent::default_configuration();
    }
}
