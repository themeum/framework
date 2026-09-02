<?php

namespace Framework\Tests\Support\Cache;

use Framework\Cache\Stores\ArrayStore;

class FrozenArrayStore extends ArrayStore
{
    use FreezesTime;
}
