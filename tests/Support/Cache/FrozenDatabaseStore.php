<?php

namespace Framework\Tests\Support\Cache;

use Framework\Cache\Stores\DatabaseStore;

class FrozenDatabaseStore extends DatabaseStore
{
    use FreezesTime;
}
