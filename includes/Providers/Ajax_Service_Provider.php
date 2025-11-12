<?php

namespace Lotzwoo\Providers;

use Lotzwoo\Ajax\Menu_Planning_Controller;
use Lotzwoo\Ajax\Product_Media_Controller;
use Lotzwoo\Container;
use Lotzwoo\Services\Menu_Planning_Runner;
use Lotzwoo\Services\Menu_Planning_Service;
use Lotzwoo\Services\Product_Media_Service;

if (!defined('ABSPATH')) {
    exit;
}

class Ajax_Service_Provider implements Service_Provider_Interface
{
    public function register(Container $container): void
    {
        $container->set(Product_Media_Controller::class, static function (Container $container) {
            return new Product_Media_Controller($container->get(Product_Media_Service::class));
        });
        $container->set(Menu_Planning_Controller::class, static function (Container $container) {
            return new Menu_Planning_Controller(
                $container->get(Menu_Planning_Service::class),
                $container->get(Menu_Planning_Runner::class)
            );
        });
    }

    public function boot(Container $container): void
    {
        $controller = $container->get(Product_Media_Controller::class);
        $controller->register();
        $container->get(Menu_Planning_Controller::class)->register();
    }
}
