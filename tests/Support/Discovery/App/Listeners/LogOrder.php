<?php

namespace Framework\Tests\Support\Discovery\App\Listeners;

use Framework\Tests\Support\Discovery\App\Events\OrderPlaced;
use Framework\Listener;

class LogOrder extends Listener
{
    public function handle(OrderPlaced $event)
    {
    }
}
