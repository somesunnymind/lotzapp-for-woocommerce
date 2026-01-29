<?php

namespace Lotzwoo\Frontend;

use Lotzwoo\Field_Registry;
use Lotzwoo\Plugin;
use WC_Cart;
use WC_Product;
use WC_Product_Variation;
use WC_Tax;

if (!defined('ABSPATH')) {
    exit;
}

class Deposit_Manager
{
    private const META_KEY = '_lotzwoo_deposit';

    public function __construct()
    {
        add_action('woocommerce_cart_calculate_fees', [$this, 'add_deposit_fees'], 20, 1);
        add_filter('woocommerce_cart_contents_total', [$this, 'filter_cart_contents_total'], 20, 1);
        add_filter('woocommerce_cart_contents_tax', [$this, 'filter_cart_contents_tax'], 20, 1);
        add_action('woocommerce_widget_shopping_cart_before_buttons', [$this, 'render_mini_cart_fees']);
    }

    public function add_deposit_fees(WC_Cart $cart): void
    {
        if (!$this->is_enabled() || $this->should_skip_cart_context($cart)) {
            return;
        }

        $totals = $this->calculate_deposit_totals($cart);
        if (empty($totals['amounts'])) {
            return;
        }

        foreach ($totals['amounts'] as $tax_class => $amount) {
            if ($amount <= 0) {
                continue;
            }
            $label = $this->build_fee_label($tax_class, $totals['rate_labels'][$tax_class] ?? '');
            $cart->add_fee($label, $amount, true, (string) $tax_class);
        }
    }

    public function filter_cart_contents_total($total)
    {
        if (!$this->is_enabled() || Plugin::opt('deposit_exclude_from_shipping_minimum', 1)) {
            return $total;
        }

        $cart = WC()->cart;
        if (!$cart instanceof WC_Cart) {
            return $total;
        }

        $totals = $this->calculate_deposit_totals($cart);
        $deposit_total = array_sum($totals['amounts']);

        return $total + $deposit_total;
    }

    public function filter_cart_contents_tax($total)
    {
        if (!$this->is_enabled() || Plugin::opt('deposit_exclude_from_shipping_minimum', 1)) {
            return $total;
        }

        $cart = WC()->cart;
        if (!$cart instanceof WC_Cart) {
            return $total;
        }

        $totals = $this->calculate_deposit_totals($cart);
        $deposit_tax = array_sum($totals['taxes']);

        return $total + $deposit_tax;
    }

    public function render_mini_cart_fees(): void
    {
        if (!$this->is_enabled()) {
            return;
        }

        $cart = WC()->cart;
        if (!$cart instanceof WC_Cart) {
            return;
        }

        $fees = array_filter((array) $cart->get_fees(), function ($fee) {
            if (!is_object($fee) || !isset($fee->name)) {
                return false;
            }
            $prefix = __('Pfand', 'lotzapp-for-woocommerce');
            return strpos((string) $fee->name, $prefix) === 0;
        });

        if (empty($fees)) {
            return;
        }

        echo '<ul class="lotzwoo-mini-cart-fees">';
        foreach ($fees as $fee) {
            $amount_html = function_exists('wc_cart_totals_fee_html') ? wc_cart_totals_fee_html($fee) : wc_price((float) ($fee->amount ?? 0));
            echo '<li class="lotzwoo-mini-cart-fees__item">' . esc_html((string) $fee->name) . ': ' . wp_kses_post($amount_html) . '</li>';
        }
        echo '</ul>';
    }

    private function is_enabled(): bool
    {
        return (bool) Plugin::opt('deposit_enabled', 0) && (bool) Plugin::opt('enable_deposit_field', 0);
    }

    private function should_skip_cart_context(WC_Cart $cart): bool
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return true;
        }

        if (method_exists($cart, 'is_empty') && $cart->is_empty()) {
            return true;
        }

        return false;
    }

    /**
     * @return array{amounts: array<string, float>, taxes: array<string, float>, rate_labels: array<string, string>}
     */
    private function calculate_deposit_totals(WC_Cart $cart): array
    {
        $amounts = [];
        $taxes = [];
        $rate_labels = [];

        foreach ((array) $cart->get_cart() as $item) {
            if (empty($item['data']) || !$item['data'] instanceof WC_Product) {
                continue;
            }

            $product = $item['data'];
            $deposit = $this->get_product_deposit_amount($product);
            if ($deposit <= 0) {
                continue;
            }

            $quantity = isset($item['quantity']) ? (int) $item['quantity'] : 0;
            if ($quantity <= 0) {
                continue;
            }

            $tax_class = (string) $product->get_tax_class();
            $unit_ex_tax = $this->get_deposit_ex_tax($product, $deposit);
            $line_ex_tax = $unit_ex_tax * $quantity;

            $amounts[$tax_class] = ($amounts[$tax_class] ?? 0) + $line_ex_tax;

            $rates = WC_Tax::get_rates($tax_class);
            $line_taxes = WC_Tax::calc_tax($line_ex_tax, $rates, false);
            $taxes[$tax_class] = ($taxes[$tax_class] ?? 0) + array_sum($line_taxes);

            if (!isset($rate_labels[$tax_class])) {
                $rate_labels[$tax_class] = $this->get_tax_rate_label($rates);
            }
        }

        $decimals = wc_get_price_decimals();
        foreach ($amounts as $tax_class => $amount) {
            $amounts[$tax_class] = (float) wc_format_decimal($amount, $decimals);
        }
        foreach ($taxes as $tax_class => $amount) {
            $taxes[$tax_class] = (float) wc_format_decimal($amount, $decimals);
        }

        return [
            'amounts' => $amounts,
            'taxes' => $taxes,
            'rate_labels' => $rate_labels,
        ];
    }

    private function get_product_deposit_amount(WC_Product $product): float
    {
        $product_id = (int) $product->get_id();
        $value = get_post_meta($product_id, self::META_KEY, true);

        if ($this->is_empty_value($value) && $product instanceof WC_Product_Variation) {
            $parent_id = $product->get_parent_id();
            if ($parent_id > 0) {
                $value = get_post_meta($parent_id, self::META_KEY, true);
            }
        }

        if ($this->is_empty_value($value)) {
            $parent_id = $product->get_parent_id();
            if ($parent_id > 0) {
                $parent_value = get_post_meta($parent_id, self::META_KEY, true);
                if (!$this->is_empty_value($parent_value)) {
                    $value = $parent_value;
                }
            }
        }

        $normalized = Field_Registry::sanitize_deposit_amount($value);
        if ($normalized === '') {
            return 0.0;
        }

        return (float) $normalized;
    }

    private function is_empty_value($value): bool
    {
        if (is_array($value)) {
            $value = implode('', $value);
        }

        if (!is_string($value)) {
            return true;
        }

        return trim($value) === '';
    }

    private function get_deposit_ex_tax(WC_Product $product, float $deposit): float
    {
        if (!wc_prices_include_tax()) {
            return $deposit;
        }

        $ex_tax = wc_get_price_excluding_tax($product, [
            'price' => $deposit,
        ]);

        return (float) $ex_tax;
    }

    /**
     * @param array<int|string, array<string, mixed>> $rates
     */
    private function get_tax_rate_label(array $rates): string
    {
        if (empty($rates)) {
            return __('Steuerfrei', 'lotzapp-for-woocommerce');
        }

        $labels = [];
        foreach ($rates as $rate_id => $rate) {
            $label = WC_Tax::get_rate_label($rate_id);
            if ($label === '') {
                $label = WC_Tax::get_rate_percent($rate_id);
            }
            if ($label !== '') {
                $labels[] = $label;
            }
        }

        $labels = array_unique(array_filter($labels));
        if (empty($labels)) {
            return __('Steuerfrei', 'lotzapp-for-woocommerce');
        }

        return implode(' + ', $labels);
    }

    private function build_fee_label(string $tax_class, string $rate_label): string
    {
        $base_label = __('Pfand', 'lotzapp-for-woocommerce');
        if (!Plugin::opt('deposit_show_tax_label', 0)) {
            return $base_label;
        }

        $rate_label = $rate_label !== '' ? $rate_label : __('Steuerfrei', 'lotzapp-for-woocommerce');

        return sprintf('%s (%s)', $base_label, $rate_label);
    }
}
