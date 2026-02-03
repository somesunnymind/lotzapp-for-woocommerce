<?php

namespace Lotzwoo\Providers;

use Lotzwoo\Container;
use Lotzwoo\Emails\Email_Features;

if (!defined('ABSPATH')) {
    exit;
}

class Emails_Service_Provider implements Service_Provider_Interface
{
    public function register(Container $container): void
    {
        $container->set(Email_Features::class, static function () {
            return new Email_Features();
        });
    }

    public function boot(Container $container): void
    {
        require_once plugin_dir_path(LOTZWOO_PLUGIN_FILE) . 'includes/Emails/template_helpers.php';
        $container->get(Email_Features::class)->register();
    }
}
