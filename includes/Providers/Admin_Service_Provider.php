<?php

namespace Lotzwoo\Providers;

use Lotzwoo\Admin\Field_Lock;
use Lotzwoo\Admin\Product_Custom_Fields;
use Lotzwoo\Admin\Delivery_Time_Field;
use Lotzwoo\Admin\Product_Flag;
use Lotzwoo\Admin\Settings_Page;
use Lotzwoo\Container;

if (!defined('ABSPATH')) {
    exit;
}

class Admin_Service_Provider implements Service_Provider_Interface
{
    public function register(Container $container): void
    {
        $container->set(Settings_Page::class, static function () {
            return new Settings_Page();
        });

        $container->set(Product_Flag::class, static function () {
            return new Product_Flag();
        });

        $container->set(Field_Lock::class, static function () {
            return new Field_Lock();
        });

        $container->set(Product_Custom_Fields::class, static function () {
            return new Product_Custom_Fields();
        });

        $container->set(Delivery_Time_Field::class, static function () {
            return new Delivery_Time_Field();
        });
    }

    public function boot(Container $container): void
    {
        if (!is_admin()) {
            return;
        }

        $container->get(Settings_Page::class);
        $container->get(Product_Flag::class);
        $container->get(Field_Lock::class);
        $container->get(Product_Custom_Fields::class);
        $container->get(Delivery_Time_Field::class);
    }
}
