<?php

namespace Framework\Tests\Support\Validation;

use Framework\Supports\MessagesBag;

class CountingMessagesBag extends MessagesBag
{
    public int $lookups = 0;

    public function get(string $key, ...$args)
    {
        $this->lookups++;

        return parent::get($key, ...$args);
    }
}
