<?php

namespace Framework\Tests\Support\Cache;

use Framework\Cache\Stores\FileStore;

class FrozenFileStore extends FileStore
{
    use FreezesTime;
}
