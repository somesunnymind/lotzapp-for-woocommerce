<?php

namespace Lotzwoo\Assets;

if (!defined('ABSPATH')) {
    exit;
}

class Blocks_Price_Display
{
    private const SCRIPT_HANDLE = 'lotzwoo-blocks-price-display';
    private const SCRIPT_VERSION = '0.1.0';

    public function __construct()
    {
        add_action('enqueue_block_assets', [$this, 'enqueue']);
    }

    public function enqueue(): void
    {
        $handle = self::SCRIPT_HANDLE;
        $src    = trailingslashit(LOTZWOO_PLUGIN_URL) . 'assets/js/blocks-price-display.js';

        if (!wp_script_is($handle, 'registered')) {
            wp_register_script(
                $handle,
                $src,
                ['wc-blocks-checkout', 'wp-element'],
                self::SCRIPT_VERSION,
                true
            );
        }

        wp_enqueue_script($handle);
    }
}
