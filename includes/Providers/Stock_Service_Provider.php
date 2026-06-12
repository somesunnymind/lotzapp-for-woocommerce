<?php

namespace Lotzwoo\Providers;

use Lotzwoo\Container;
use Lotzwoo\Services\Menu_Planning_Service;
use Lotzwoo\Services\Product_Succession_Service;
use Lotzwoo\Services\Stock_Notification_Service;

if (!defined('ABSPATH')) {
    exit;
}

class Stock_Service_Provider implements Service_Provider_Interface
{
    public function register(Container $container): void
    {
        $container->set(Product_Succession_Service::class, static function (Container $container) {
            return new Product_Succession_Service($container->get(Menu_Planning_Service::class));
        });

        $container->set(Stock_Notification_Service::class, static function () {
            return new Stock_Notification_Service();
        });
    }

    public function boot(Container $container): void
    {
        $container->get(Product_Succession_Service::class)->boot();
        $container->get(Stock_Notification_Service::class)->boot();
    }
}
