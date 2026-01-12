<?php
/**
 * Plugin Name:       LotzApp for WooCommerce
 * Description:       Erweitert WooCommerce-Shops um Ca.-Preislogik, Admin-Tools zur Datenpflege, automatisierte Menüaktualisierung und mehr.
 * Version:           0.1.6.1
 * Author:            somesunnymind.com
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Text Domain:       lotzapp-for-woocommerce
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Minimum environment checks.
define('LOTZWOO_MIN_PHP', '7.4');
if (version_compare(PHP_VERSION, LOTZWOO_MIN_PHP, '<')) {
    add_action('admin_notices', static function () {
        echo '<div class="notice notice-error"><p>' . esc_html__('LotzApp for WooCommerce requires PHP 7.4 or higher.', 'lotzapp-for-woocommerce') . '</p></div>';
    });
    return;
}

// Define plugin constants.
if (!defined('LOTZWOO_PLUGIN_FILE')) {
    define('LOTZWOO_PLUGIN_FILE', __FILE__);
}
if (!defined('LOTZWOO_PLUGIN_DIR')) {
    define('LOTZWOO_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('LOTZWOO_PLUGIN_URL')) {
    define('LOTZWOO_PLUGIN_URL', plugin_dir_url(__FILE__));
}
if (!defined('LOTZWOO_GITHUB_OWNER')) {
    define('LOTZWOO_GITHUB_OWNER', 'somesunnymind');
}
if (!defined('LOTZWOO_GITHUB_REPOSITORY')) {
    define('LOTZWOO_GITHUB_REPOSITORY', 'lotzapp-for-woocommerce');
}
if (!defined('LOTZWOO_GITHUB_BRANCH')) {
    define('LOTZWOO_GITHUB_BRANCH', 'main');
}

// Declare HPOS (Custom Order Tables) compatibility.
add_action('before_woocommerce_init', static function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Lightweight PSR-4-like autoloader for the Lotzwoo namespace.
spl_autoload_register(static function ($class) {
    if (strpos($class, 'Lotzwoo\\') !== 0) {
        return;
    }

    $relative = substr($class, strlen('Lotzwoo\\'));
    $base_dir = LOTZWOO_PLUGIN_DIR . 'includes' . DIRECTORY_SEPARATOR;

    $candidates = [];

    $ns_path = str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
    $candidates[] = $base_dir . $ns_path;
    $candidates[] = $base_dir . strtolower($ns_path);
    $ns_dir = dirname($ns_path);
    $ns_file = basename($ns_path);
    if ($ns_dir !== '.' && $ns_file !== '') {
        $candidates[] = $base_dir . $ns_dir . DIRECTORY_SEPARATOR . strtolower($ns_file);
    }

    $parts = explode('\\', $relative);
    $parts = array_map(static function ($part) {
        $part = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $part);
        $part = preg_replace('/([A-Z])([A-Z][a-z])/', '$1_$2', $part);
        return strtolower($part);
    }, $parts);
    $candidates[] = $base_dir . implode(DIRECTORY_SEPARATOR, $parts) . '.php';

    foreach ($candidates as $file) {
        if ($file && file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

$lotzwoo_updater_config = [
    'owner'       => LOTZWOO_GITHUB_OWNER,
    'repository'  => LOTZWOO_GITHUB_REPOSITORY,
    'branch'      => LOTZWOO_GITHUB_BRANCH,
    'plugin_file' => LOTZWOO_PLUGIN_FILE,
    'slug'        => 'lotzapp-for-woocommerce',
    'token'       => defined('LOTZWOO_GITHUB_TOKEN') ? LOTZWOO_GITHUB_TOKEN : null,
];

$lotzwoo_updater_config = apply_filters('lotzwoo/github_updater_config', $lotzwoo_updater_config);

Lotzwoo\Updates\GitHub_Updater::boot($lotzwoo_updater_config);

register_activation_hook(__FILE__, static function () {
    Lotzwoo\Plugin::activate();
});

register_deactivation_hook(__FILE__, static function () {
    Lotzwoo\Plugin::deactivate();
});

// Ensure WooCommerce present before booting the plugin.
add_action('plugins_loaded', static function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', static function () {
            echo '<div class="notice notice-error"><p>' . esc_html__('LotzApp for WooCommerce requires WooCommerce to be active.', 'lotzapp-for-woocommerce') . '</p></div>';
        });
        return;
    }

    // Boot the plugin.
    Lotzwoo\Plugin::instance();
});
