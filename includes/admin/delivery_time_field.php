<?php

namespace Lotzwoo\Admin;

use Lotzwoo\Services\Delivery_Time_Service;

if (!defined('ABSPATH')) {
    exit;
}

class Delivery_Time_Field
{
    private Delivery_Time_Service $delivery_times;

    public function __construct()
    {
        $this->delivery_times = new Delivery_Time_Service();

        add_action('init', [$this, 'register_meta']);
        add_action('woocommerce_product_options_shipping', [$this, 'render_field']);
        add_action('woocommerce_admin_process_product_object', [$this, 'save_field']);
    }

    public function register_meta(): void
    {
        register_post_meta('product', Delivery_Time_Service::META_KEY, [
            'single'            => true,
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'show_in_rest'      => true,
            'auth_callback'     => static function () {
                return current_user_can('edit_products');
            },
        ]);
    }

    public function render_field(): void
    {
        global $post;
        if (!$post || $post->post_type !== 'product') {
            return;
        }

        $options = $this->delivery_times->get_delivery_time_options();
        $options = ['' => __('Keine Auswahl', 'lotzapp-for-woocommerce')] + $options;

        woocommerce_wp_select([
            'id'          => Delivery_Time_Service::META_KEY,
            'label'       => __('Voraussichtlicher Liefertermin', 'lotzapp-for-woocommerce'),
            'description' => __('Auswahl der im LotzApp-Backend definierten Lieferzeiten.', 'lotzapp-for-woocommerce'),
            'desc_tip'    => true,
            'options'     => $options,
            'class'       => 'wc-enhanced-select',
        ]);
    }

    public function save_field(\WC_Product $product): void
    {
        $raw = isset($_POST[Delivery_Time_Service::META_KEY])
            ? sanitize_text_field((string) wp_unslash((string) $_POST[Delivery_Time_Service::META_KEY]))
            : '';

        if ($raw === '') {
            $product->delete_meta_data(Delivery_Time_Service::META_KEY);
            return;
        }

        if (!$this->delivery_times->find_delivery_time($raw)) {
            $product->delete_meta_data(Delivery_Time_Service::META_KEY);
            return;
        }

        $product->update_meta_data(Delivery_Time_Service::META_KEY, $raw);
    }
}
