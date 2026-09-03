<?php

namespace Framework\Tests\Support\Cache;

use Framework\Cache\Repository;

class FrozenRepository extends Repository
{
    use FreezesTime;
}
