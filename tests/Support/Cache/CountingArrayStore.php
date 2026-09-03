<?php

namespace Framework\Tests\Support\Cache;

use Framework\Cache\Stores\ArrayStore;

class CountingArrayStore extends ArrayStore
{
    public int $reads = 0;

    public function get_entry(string $key)
    {
        $this->reads++;

        return parent::get_entry($key);
    }
}
