<?php

namespace Lotzwoo\Providers;

use Lotzwoo\Assets\Image_Management;
use Lotzwoo\Assets\Menu_Planning as Menu_Planning_Assets;
use Lotzwoo\Container;
use Lotzwoo\Services\Menu_Planning_Service;
use Lotzwoo\Services\Product_Media_Service;
use Lotzwoo\Shortcodes\Product_Image_Management;
use Lotzwoo\Shortcodes\Menu_Planning;
use Lotzwoo\Shortcodes\Menu_Date;

if (!defined('ABSPATH')) {
    exit;
}

class Shortcode_Service_Provider implements Service_Provider_Interface
{
    public function register(Container $container): void
    {
        $container->set(Product_Image_Management::class, static function (Container $container) {
            return new Product_Image_Management(
                $container->get(Product_Media_Service::class),
                $container->get(Image_Management::class)
            );
        });
        $container->set(Menu_Planning::class, static function (Container $container) {
            return new Menu_Planning(
                $container->get(Menu_Planning_Service::class),
                $container->get(Menu_Planning_Assets::class)
            );
        });
        $container->set(Menu_Date::class, static function (Container $container) {
            return new Menu_Date(
                $container->get(Menu_Planning_Service::class)
            );
        });
    }

    public function boot(Container $container): void
    {
        $container->get(Product_Image_Management::class)->register();
        $container->get(Menu_Planning::class)->register();
        $container->get(Menu_Date::class)->register();
    }
}
