<?php

namespace Lotzwoo\Frontend;

use Lotzwoo\Plugin;
use Lotzwoo\Helpers\Estimated;

class Buffer_Manager
{
    private bool $syncing = false;

    public function __construct()
    {
        add_action('woocommerce_before_calculate_totals', [$this, 'maybe_sync_buffer'], 50, 1);
        add_filter('woocommerce_cart_item_remove_link', [$this, 'filter_remove_link'], 10, 2);
        add_filter('woocommerce_cart_item_quantity', [$this, 'filter_cart_item_quantity'], 10, 3);
        add_filter('woocommerce_cart_item_class', [$this, 'filter_cart_item_class'], 10, 3);
    }

    private function get_buffer_product_id(): int
    {
        return (int) Plugin::opt('buffer_product_id');
    }

    private function get_cart($cart = null)
    {
        if ($cart instanceof \WC_Cart) {
            return $cart;
        }

        if (!function_exists('WC')) {
            return null;
        }

        $global_cart = WC()->cart;
        if ($global_cart instanceof \WC_Cart) {
            return $global_cart;
        }

        return null;
    }

    private function is_applicable_context(): bool
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return false;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }

        return true;
    }

    private function is_buffer_cart_item(array $cart_item): bool
    {
        if (!empty($cart_item['lotzwoo_is_buffer']) && $cart_item['lotzwoo_is_buffer'] === 'yes') {
            return true;
        }

        if (!empty($cart_item['_lotzwoo_is_buffer']) && $cart_item['_lotzwoo_is_buffer'] === 'yes') {
            return true;
        }

        foreach ($this->legacy_buffer_cart_flags() as $legacy_key) {
            if (!empty($cart_item[$legacy_key]) && $cart_item[$legacy_key] === 'yes') {
                return true;
            }
        }

        $buffer_id = $this->get_buffer_product_id();
        if (!$buffer_id) {
            return false;
        }

        if (!empty($cart_item['product_id']) && (int) $cart_item['product_id'] === $buffer_id) {
            return true;
        }

        if (!empty($cart_item['data']) && $cart_item['data'] instanceof \WC_Product && (int) $cart_item['data']->get_id() === $buffer_id) {
            return true;
        }

        return false;
    }

    private function find_buffer_key(\WC_Cart $cart): ?string
    {
        foreach ((array) $cart->get_cart() as $key => $item) {
            if ($this->is_buffer_cart_item($item)) {
                return (string) $key;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function legacy_buffer_cart_flags(): array
    {
        $prefix = implode('', ['c', 'a', 'e', 'p']);
        return [
            $prefix . '_is_buffer',
            '_' . $prefix . '_is_buffer',
        ];
    }

    private function move_buffer_to_end(\WC_Cart $cart, string $key): void
    {
        if (!property_exists($cart, 'cart_contents') || !isset($cart->cart_contents[$key])) {
            return;
        }

        $item = $cart->cart_contents[$key];
        unset($cart->cart_contents[$key]);
        $cart->cart_contents[$key] = $item;
    }

    private function calculate_buffer_target(\WC_Cart $cart): float
    {
        $subtotal = Estimated::get_cart_estimated_display_subtotal($cart);

        $buffer_ratio = apply_filters('lotzwoo_buffer_ratio', 0.10, $cart);
        $buffer_ratio = is_numeric($buffer_ratio) ? (float) $buffer_ratio : 0.10;
        if ($buffer_ratio < 0) {
            $buffer_ratio = 0.0;
        }

        $raw_amount = $subtotal * $buffer_ratio;
        /**
         * Filter the raw buffer amount before rounding.
         *
         * @param float   $raw_amount Calculated raw amount.
         * @param float   $subtotal   Subtotal of estimated products.
         * @param float   $ratio      Applied buffer ratio.
         * @param \WC_Cart $cart       Current cart.
         */
        $raw_amount = apply_filters('lotzwoo_buffer_raw_amount', $raw_amount, $subtotal, $buffer_ratio, $cart);

        if (!is_numeric($raw_amount)) {
            $raw_amount = 0.0;
        }

        $decimals = function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2;
        $rounded = round((float) $raw_amount, $decimals);
        if ($rounded < 0) {
            $rounded = 0.0;
        }

        /**
         * Final buffer amount applied to the cart item.
         *
         * @param float    $rounded  Rounded buffer amount.
         * @param float    $subtotal Subtotal of estimated products.
         * @param float    $ratio    Applied buffer ratio.
         * @param \WC_Cart $cart     Current cart.
         */
        $final = apply_filters('lotzwoo_buffer_amount', $rounded, $subtotal, $buffer_ratio, $cart);
        if (!is_numeric($final) || $final < 0) {
            $final = 0.0;
        }

        return (float) $final;
    }

    private function apply_buffer_pricing(\WC_Cart $cart, string $buffer_key): void
    {
        if (!property_exists($cart, 'cart_contents') || !isset($cart->cart_contents[$buffer_key])) {
            return;
        }

        $amount = $this->calculate_buffer_target($cart);
        $cart_item =& $cart->cart_contents[$buffer_key];

        $cart_item['lotzwoo_is_buffer'] = 'yes';
        $cart_item['lotzwoo_buffer_amount'] = $amount;
        $cart_item['line_subtotal'] = $amount;
        $cart_item['line_total'] = $amount;
        $cart_item['line_subtotal_tax'] = 0;
        $cart_item['line_tax'] = 0;
        $cart_item['line_tax_data'] = [
            'total'    => [],
            'subtotal' => [],
        ];

        if (isset($cart_item['data']) && $cart_item['data'] instanceof \WC_Product) {
            $product = $cart_item['data'];
            if (method_exists($product, 'set_price')) {
                $product->set_price($amount);
            }
            if (method_exists($product, 'set_regular_price')) {
                $product->set_regular_price($amount);
            }
        }
    }

    public function maybe_sync_buffer($cart = null): void
    {
        if ($this->syncing) {
            return;
        }

        if (!$this->is_applicable_context()) {
            return;
        }

        $cart = $this->get_cart($cart);
        if (!$cart) {
            return;
        }

        $existing_buffer_keys = [];
        foreach ((array) $cart->get_cart() as $key => $item) {
            if ($this->is_buffer_cart_item($item)) {
                $existing_buffer_keys[] = (string) $key;
            }
        }

        if (!Plugin::ca_prices_enabled()) {
            foreach ($existing_buffer_keys as $buffer_key) {
                $cart->remove_cart_item($buffer_key);
            }
            return;
        }

        $buffer_id = $this->get_buffer_product_id();
        if (!$buffer_id) {
            foreach ($existing_buffer_keys as $buffer_key) {
                $cart->remove_cart_item($buffer_key);
            }
            return;
        }

        $this->syncing = true;

        $has_estimated = Estimated::cart_has_estimated($cart);
        $buffer_key    = !empty($existing_buffer_keys) ? $existing_buffer_keys[0] : $this->find_buffer_key($cart);

        if ($has_estimated) {
            if (!$buffer_key) {
                $new_key = $cart->add_to_cart($buffer_id, 1, 0, [], [
                    'lotzwoo_is_buffer' => 'yes',
                ]);
                if ($new_key) {
                    $buffer_key = $new_key;
                }
            } else {
                $cart_item = $cart->get_cart_item($buffer_key);
                if (isset($cart_item['quantity']) && (int) $cart_item['quantity'] !== 1) {
                    $cart->set_quantity($buffer_key, 1, false);
                }
            }

            if ($buffer_key) {
                $this->apply_buffer_pricing($cart, $buffer_key);
                $this->move_buffer_to_end($cart, $buffer_key);
            }
        } else {
            if ($buffer_key) {
                $cart->remove_cart_item($buffer_key);
            }
        }

        $this->syncing = false;
    }

    public function filter_remove_link($link, $cart_item_key)
    {
        if (!Plugin::ca_prices_enabled()) {
            return $link;
        }

        $cart = $this->get_cart();
        if (!$cart) {
            return $link;
        }

        $item = $cart->get_cart_item($cart_item_key);
        if ($item && $this->is_buffer_cart_item($item)) {
            return '';
        }

        return $link;
    }

    public function filter_cart_item_quantity($product_quantity, $cart_item_key, $cart_item)
    {
        if (!Plugin::ca_prices_enabled()) {
            return $product_quantity;
        }

        if ($this->is_buffer_cart_item($cart_item)) {
            return '<span class="lotzwoo-buffer-qty">' . esc_html('1') . '</span>';
        }

        return $product_quantity;
    }

    public function filter_cart_item_class($class, $cart_item, $cart_item_key)
    {
        if (!Plugin::ca_prices_enabled()) {
            return $class;
        }

        if (!is_string($class)) {
            $class = '';
        }
        if ($this->is_buffer_cart_item($cart_item)) {
            $class .= ' lotzwoo-buffer-item';
        }

        return $class;
    }
}

