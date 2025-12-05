<?php

namespace Lotzwoo\Providers;

use Lotzwoo\Assets\Image_Management;
use Lotzwoo\Assets\Menu_Planning;
use Lotzwoo\Assets\Blocks_Price_Display;
use Lotzwoo\Container;

if (!defined('ABSPATH')) {
    exit;
}

class Assets_Service_Provider implements Service_Provider_Interface
{
    public function register(Container $container): void
    {
        $container->set(Image_Management::class, static function () {
            return new Image_Management();
        });
        $container->set(Menu_Planning::class, static function () {
            return new Menu_Planning();
        });
        $container->set(Blocks_Price_Display::class, static function () {
            return new Blocks_Price_Display();
        });
    }

    public function boot(Container $container): void
    {
        // Assets are enqueued on demand by the shortcode.
        $container->get(Image_Management::class);
        $container->get(Menu_Planning::class);
        $container->get(Blocks_Price_Display::class);
    }
}
