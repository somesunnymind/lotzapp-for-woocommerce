<?php

namespace Lotzwoo\Shortcodes;

use Lotzwoo\Services\Delivery_Time_Service;

if (!defined('ABSPATH')) {
    exit;
}

class Delivery_Time
{
    private Delivery_Time_Service $delivery_times;

    public function __construct(Delivery_Time_Service $delivery_times)
    {
        $this->delivery_times = $delivery_times;
    }

    public function register(): void
    {
        add_shortcode('lotzwoo_delivery_time', [$this, 'render']);
    }

    /**
     * @param array<string, mixed> $atts
     */
    public function render($atts = []): string
    {
        $atts = shortcode_atts(
            [
                'product_id' => 0,
            ],
            $atts,
            'lotzwoo_delivery_time'
        );

        $product_id = absint($atts['product_id']);
        if ($product_id === 0 && function_exists('get_the_ID')) {
            $product_id = (int) get_the_ID();
        }

        if ($product_id <= 0 || !function_exists('wc_get_product')) {
            return '';
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return '';
        }

        $delivery_id = (string) $product->get_meta(Delivery_Time_Service::META_KEY);
        if ($delivery_id === '' && $product instanceof \WC_Product_Variation) {
            $parent_id = $product->get_parent_id();
            if ($parent_id > 0) {
                $parent = wc_get_product($parent_id);
                if ($parent) {
                    $delivery_id = (string) $parent->get_meta(Delivery_Time_Service::META_KEY);
                }
            }
        }

        if ($delivery_id === '') {
            return '';
        }

        $entry = $this->delivery_times->find_delivery_time($delivery_id);
        if (!$entry) {
            return '';
        }

        return $this->delivery_times->format_output($entry);
    }
}
