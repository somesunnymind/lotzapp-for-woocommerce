<?php

namespace Lotzwoo\Admin;

use Lotzwoo\Plugin;

class Product_Flag
{
    public function __construct()
    {
        add_action('woocommerce_product_options_general_product_data', [$this, 'add_field']);
        add_action('woocommerce_admin_process_product_object', [$this, 'save_field']);
    }

    public function add_field(): void
    {
        if (!Plugin::ca_prices_enabled()) {
            return;
        }

        $meta_key = Plugin::opt('meta_key');
        echo '<div class="options_group">';
        woocommerce_wp_checkbox([
            'id'          => $meta_key,
            'label'       => __('Endpreis steht erst beim Kommissionieren fest?', 'lotzapp-for-woocommerce'),
            'desc_tip'    => true,
            'description' => __('Aktiviere, um dieses Produkt als Ca.-Artikel zu markieren.', 'lotzapp-for-woocommerce'),
        ]);
        echo '</div>';
    }

    public function save_field(\WC_Product $product): void
    {
        if (!Plugin::ca_prices_enabled()) {
            return;
        }

        $meta_key = Plugin::opt('meta_key');
        $value    = isset($_POST[$meta_key]) && $_POST[$meta_key] === 'yes' ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $product->update_meta_data($meta_key, $value);
    }
}

