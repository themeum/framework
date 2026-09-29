<?php

namespace Framework\Tests\Support\Discovery\App\Listeners\Order;

use Framework\Tests\Support\Discovery\App\Events\OrderPlaced;
use Framework\Listener;

class SendInvoice extends Listener
{
    public function handle(OrderPlaced $event)
    {
    }

    public function priority()
    {
        return 5;
    }
}
