<?php

namespace Lotzwoo\Providers;

use Lotzwoo\Container;

if (!defined('ABSPATH')) {
    exit;
}

interface Service_Provider_Interface
{
    public function register(Container $container): void;

    public function boot(Container $container): void;
}

