<?php

namespace Framework\Tests\Support\Discovery\App\Listeners\Order\Paid;

use Framework\Tests\Support\Discovery\App\Events\OrderPlaced;
use Framework\Listener;

class NotifyWarehouse extends Listener
{
    public function handle(OrderPlaced $event)
    {
    }

    public function priority()
    {
        return 10;
    }
}
