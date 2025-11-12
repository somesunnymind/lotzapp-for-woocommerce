<?php

namespace Lotzwoo\Providers;

use Lotzwoo\Container;
use Lotzwoo\Services\Menu_Planning_Runner;
use Lotzwoo\Services\Menu_Planning_Service;

if (!defined('ABSPATH')) {
    exit;
}

class Cron_Service_Provider implements Service_Provider_Interface
{
    public function register(Container $container): void
    {
        $container->set(Menu_Planning_Runner::class, static function (Container $container) {
            return new Menu_Planning_Runner($container->get(Menu_Planning_Service::class));
        });
    }

    public function boot(Container $container): void
    {
        $container->get(Menu_Planning_Runner::class)->boot();
    }
}
