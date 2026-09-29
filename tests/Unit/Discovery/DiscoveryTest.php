<?php

namespace Framework\Tests\Unit\Discovery;

use Framework\Discovery\ListenerDiscovery;
use Framework\Discovery\PolicyDiscovery;
use Framework\Tests\Support\Discovery\App\Events\OrderPlaced;
use Framework\Tests\Support\Discovery\App\Listeners\LogOrder;
use Framework\Tests\Support\Discovery\App\Listeners\Order\Paid\NotifyWarehouse;
use Framework\Tests\Support\Discovery\App\Listeners\Order\SendInvoice;
use Framework\Tests\Support\Discovery\App\Models\Post;
use Framework\Tests\Support\Discovery\App\Models\Shop\Product;
use Framework\Tests\Support\Discovery\App\Policies\PostPolicy;
use Framework\Tests\Support\Discovery\App\Policies\Shop\ProductPolicy;
use Framework\Tests\Unit\TestCase;

class DiscoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = $this->bootstrap_application();
        $app->use_app_mode('development');
        $app->use_app_path(self::framework_path() . '/tests/Support/Discovery/App');

        $namespace = new \ReflectionProperty($app, 'namespace');
        $namespace->setAccessible(true);
        $namespace->setValue($app, 'Framework\\Tests\\Support\\Discovery\\App\\');
    }

    public function test_listeners_in_nested_folders_are_discovered_in_priority_order(): void
    {
        $listeners = (new ListenerDiscovery())->discover()->listeners();

        $this->assertSame(
            [OrderPlaced::class => [NotifyWarehouse::class, SendInvoice::class, LogOrder::class]],
            $listeners
        );
    }

    public function test_policies_in_nested_folders_map_to_models_in_matching_folders(): void
    {
        $policies = (new PolicyDiscovery())->discover()->policies();

        $this->assertSame(
            [
                ['model' => Post::class, 'policy' => PostPolicy::class],
                ['model' => Product::class, 'policy' => ProductPolicy::class],
            ],
            $policies
        );
    }
}
