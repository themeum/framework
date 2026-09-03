<?php

namespace Framework\Tests\Support\RateLimiting;

use Framework\Http\Request;

/**
 * A request whose trusted proxy list is injected rather than read from a config file, so that the
 * address matching itself stays under test.
 */
class ConfigurableRequest extends Request
{
    /** @var array */
    public array $proxies = [];

    public function trust(array $proxies): self
    {
        $this->proxies = $proxies;

        return $this;
    }

    public function with_server(array $server): self
    {
        $this->server = $server;

        return $this;
    }

    protected function trusted_proxies()
    {
        return $this->proxies;
    }
}
