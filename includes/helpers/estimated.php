<?php

namespace Lotzwoo\Helpers;

use Lotzwoo\Plugin;

class Estimated
{
    public static function get_meta_key(): string
    {
        return (string) Plugin::opt('meta_key', '_ca_is_estimated');
    }

    private static function is_positive_flag($value): bool
    {
        return in_array($value, ['yes', '1', 1, true], true);
    }

    public static function is_estimated_product($product): bool
    {
        if (!Plugin::ca_prices_enabled()) {
            return false;
        }

        if (is_numeric($product)) {
            if (!function_exists('wc_get_product')) {
                return false;
            }
            $product = wc_get_product((int) $product);
        }

        if (!$product || !is_object($product) || !($product instanceof \WC_Product)) {
            return false;
        }

        $meta_key = self::get_meta_key();

        if (method_exists($product, 'get_meta')) {
            $value = $product->get_meta($meta_key, true);
            if (self::is_positive_flag($value)) {
                return true;
            }
        }

        if (method_exists($product, 'get_id')) {
            $post_id = $product->get_id();
            if ($post_id && self::is_positive_flag(get_post_meta($post_id, $meta_key, true))) {
                return true;
            }
        }

        if (method_exists($product, 'get_parent_id')) {
            $parent_id = (int) $product->get_parent_id();
            if ($parent_id && self::is_positive_flag(get_post_meta($parent_id, $meta_key, true))) {
                return true;
            }
        }

        return false;
    }

    public static function cart_has_estimated(\WC_Cart $cart = null): bool
    {
        if (!Plugin::ca_prices_enabled()) {
            return false;
        }

        if (!$cart) {
            if (!function_exists('WC')) {
                return false;
            }
            $cart = WC()->cart;
        }

        if (!$cart || !is_object($cart)) {
            return false;
        }

        foreach ((array) $cart->get_cart() as $item) {
            $product = $item['data'] ?? null;
            if (self::is_estimated_product($product)) {
                return true;
            }
        }

        return false;
    }

    public static function get_cart_estimated_identifiers(\WC_Cart $cart = null): array
    {
        if (!Plugin::ca_prices_enabled()) {
            return [
                'product_ids'    => [],
                'variation_ids'  => [],
                'cart_item_keys' => [],
            ];
        }

        if (!$cart) {
            if (!function_exists('WC')) {
                return [
                    'product_ids'       => [],
                    'variation_ids'     => [],
                    'cart_item_keys'    => [],
                ];
            }
            $cart = WC()->cart;
        }

        $product_ids   = [];
        $variation_ids = [];
        $cart_keys     = [];

        if ($cart && is_object($cart)) {
            foreach ((array) $cart->get_cart() as $key => $item) {
                $product = $item['data'] ?? null;
                if (!self::is_estimated_product($product)) {
                    continue;
                }

                $cart_keys[] = (string) $key;

                if ($product && method_exists($product, 'get_id')) {
                    $product_ids[] = (int) $product->get_id();
                }

                if (!empty($item['product_id'])) {
                    $product_ids[] = (int) $item['product_id'];
                }

                if (!empty($item['variation_id'])) {
                    $variation_ids[] = (int) $item['variation_id'];
                }

                if ($product && method_exists($product, 'get_parent_id')) {
                    $parent = (int) $product->get_parent_id();
                    if ($parent) {
                        $product_ids[] = $parent;
                    }
                }
            }
        }

        $product_ids   = array_values(array_unique(array_filter($product_ids)));
        $variation_ids = array_values(array_unique(array_filter($variation_ids)));
        $cart_keys     = array_values(array_unique(array_filter($cart_keys)));

        return [
            'product_ids'       => $product_ids,
            'variation_ids'     => $variation_ids,
            'cart_item_keys'    => $cart_keys,
        ];
    }

    public static function get_cart_items_snapshot(\WC_Cart $cart = null): array
    {
        if (!Plugin::ca_prices_enabled()) {
            return [];
        }

        if (!$cart) {
            if (!function_exists('WC')) {
                return [];
            }
            $cart = WC()->cart;
        }

        if (!$cart || !is_object($cart)) {
            return [];
        }

        $items = [];

        foreach ((array) $cart->get_cart() as $key => $item) {
            $product      = $item['data'] ?? null;
            $product_id   = isset($item['product_id']) ? (int) $item['product_id'] : 0;
            $variation_id = isset($item['variation_id']) ? (int) $item['variation_id'] : 0;

            if ($product instanceof \WC_Product) {
                if (!$product_id) {
                    $product_id = (int) $product->get_id();
                }

                if ($product->is_type('variation')) {
                    $variation_id = (int) $product->get_id();
                    if (!$product_id) {
                        $product_id = (int) $product->get_parent_id();
                    }
                }
            }

            $permalink = '';
            if ($product instanceof \WC_Product && method_exists($product, 'get_permalink')) {
                $permalink = (string) $product->get_permalink();
            } elseif ($product_id) {
                $permalink = (string) get_permalink($product_id);
            }

            $items[] = [
                'key'          => (string) $key,
                'product_id'   => $product_id,
                'variation_id' => $variation_id,
                'permalink'    => $permalink,
                'is_estimated' => self::is_estimated_product($product),
            ];
        }

        return $items;
    }

    public static function get_cart_estimated_display_subtotal(\WC_Cart $cart = null): float
    {
        if (!Plugin::ca_prices_enabled()) {
            return 0.0;
        }

        if (!$cart) {
            if (!function_exists('WC')) {
                return 0.0;
            }
            $cart = WC()->cart;
        }

        if (!$cart || !is_object($cart)) {
            return 0.0;
        }

        $subtotal = 0.0;

        foreach ((array) $cart->get_cart() as $item) {
            $product = $item['data'] ?? null;
            if (!self::is_estimated_product($product)) {
                continue;
            }

            $quantity = isset($item['quantity']) ? (float) $item['quantity'] : 0.0;
            if ($quantity <= 0) {
                continue;
            }

            $unit_price = 0.0;
            if ($product instanceof \WC_Product) {
                if (function_exists('wc_get_price_to_display')) {
                    $unit_price = (float) wc_get_price_to_display($product, ['qty' => 1]);
                } else {
                    $unit_price = (float) $product->get_price();
                }
            }

            if ($unit_price <= 0.0) {
                $line_subtotal = isset($item['line_subtotal']) ? (float) $item['line_subtotal'] : 0.0;
                if ($line_subtotal <= 0.0 && isset($item['line_total'])) {
                    $line_subtotal = (float) $item['line_total'];
                }
                if ($line_subtotal > 0.0 && $quantity > 0.0) {
                    $unit_price = $line_subtotal / $quantity;
                }
            }

            if ($unit_price <= 0.0) {
                continue;
            }

            $subtotal += $unit_price * $quantity;
        }

        $subtotal = max(0.0, (float) $subtotal);

        return (float) apply_filters('lotzwoo_estimated_display_subtotal', $subtotal, $cart);
    }

    public static function get_cart_buffer_amount(\WC_Cart $cart = null): float
    {
        if (!Plugin::ca_prices_enabled()) {
            return 0.0;
        }

        if (!$cart) {
            if (!function_exists('WC')) {
                return 0.0;
            }
            $cart = WC()->cart;
        }

        if (!$cart || !is_object($cart)) {
            return 0.0;
        }

        $buffer_product_id = (int) Plugin::opt('buffer_product_id');
        if ($buffer_product_id <= 0) {
            return 0.0;
        }

        foreach ((array) $cart->get_cart() as $item) {
            $is_buffer = false;

            if (isset($item['lotzwoo_is_buffer']) && $item['lotzwoo_is_buffer'] === 'yes') {
                $is_buffer = true;
            }
            if (isset($item['_lotzwoo_is_buffer']) && $item['_lotzwoo_is_buffer'] === 'yes') {
                $is_buffer = true;
            }
            if (isset($item['product_id']) && (int) $item['product_id'] === $buffer_product_id) {
                $is_buffer = true;
            }
            if (!$is_buffer && isset($item['variation_id']) && (int) $item['variation_id'] === $buffer_product_id) {
                $is_buffer = true;
            }
            if (!$is_buffer && isset($item['data']) && $item['data'] instanceof \WC_Product) {
                if ((int) $item['data']->get_id() === $buffer_product_id) {
                    $is_buffer = true;
                }
            }

            if (!$is_buffer) {
                continue;
            }

            $amount = 0.0;

            if (isset($item['line_total'])) {
                $amount = (float) $item['line_total'];
            }

            if ($amount <= 0.0 && isset($item['data']) && $item['data'] instanceof \WC_Product) {
                $product = $item['data'];
                $qty = isset($item['quantity']) ? (float) $item['quantity'] : 1.0;
                $price = method_exists($product, 'get_price') ? (float) $product->get_price() : 0.0;
                $amount = $price * max(1.0, $qty);
            }

            if ($amount <= 0.0 && isset($item['lotzwoo_buffer_amount'])) {
                $amount = (float) $item['lotzwoo_buffer_amount'];
            }

            return (float) apply_filters('lotzwoo_buffer_amount_display', max(0.0, $amount), $cart, $item);
        }

        return 0.0;
    }
}

