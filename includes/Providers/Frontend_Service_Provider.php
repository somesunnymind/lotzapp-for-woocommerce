<?php

namespace Lotzwoo\Providers;

use Lotzwoo\Container;
use Lotzwoo\Frontend\Buffer_Manager;
use Lotzwoo\Frontend\Checkout_Range_Note;
use Lotzwoo\Frontend\Price_Prefix;
use Lotzwoo\Frontend\Product_Custom_Fields_Display;

if (!defined('ABSPATH')) {
    exit;
}

class Frontend_Service_Provider implements Service_Provider_Interface
{
    public function register(Container $container): void
    {
        $container->set(Price_Prefix::class, static function () {
            return new Price_Prefix();
        });

        $container->set(Buffer_Manager::class, static function () {
            return new Buffer_Manager();
        });

        $container->set(Checkout_Range_Note::class, static function () {
            return new Checkout_Range_Note();
        });

        $container->set(Product_Custom_Fields_Display::class, static function () {
            return new Product_Custom_Fields_Display();
        });
    }

    public function boot(Container $container): void
    {
        $container->get(Price_Prefix::class);
        $container->get(Buffer_Manager::class);
        $container->get(Checkout_Range_Note::class);
        $container->get(Product_Custom_Fields_Display::class);
    }
}

