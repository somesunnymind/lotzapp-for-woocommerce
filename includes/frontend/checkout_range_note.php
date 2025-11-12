<?php

namespace Lotzwoo\Frontend;

use Lotzwoo\Helpers\Estimated;
use Lotzwoo\Plugin;

class Checkout_Range_Note
{
    public function __construct()
    {
        add_action('woocommerce_review_order_after_order_total', [$this, 'render_checkout_range'], 20);
        add_action('woocommerce_cart_totals_after_order_total', [$this, 'render_cart_range'], 20);
    }

    private function get_range_data(): ?array
    {
        if (!Plugin::ca_prices_enabled()) {
            return null;
        }

        if (!Plugin::opt('show_range_note')) {
            return null;
        }

        if (!function_exists('WC')) {
            return null;
        }

        $cart = WC()->cart;
        if (!$cart instanceof \WC_Cart) {
            return null;
        }

        if (!Estimated::cart_has_estimated($cart)) {
            return null;
        }

        $buffer_amount = Estimated::get_cart_buffer_amount($cart);
        if ($buffer_amount <= 0) {
            return null;
        }

        $totals = (array) $cart->get_totals();
        $total  = isset($totals['total']) ? (float) $totals['total'] : 0.0;

        $min = max(0.0, $total - $buffer_amount);

        return [
            'min'      => $min,
            'min_html' => wc_price($min),
            'enabled'  => true,
        ];
    }

    public function render_checkout_range(): void
    {
        $range = $this->get_range_data();
        if (!$range) {
            return;
        }

        $note = sprintf(
            /* translators: 1: formatted minimum total */
            __( 'min. %1$s', 'lotzapp-for-woocommerce' ),
            $range['min_html']
        );

        echo '<tr class="lotzwoo-range-note"><td colspan="2"><small style="display:block;text-align:right;font-size:0.6em;line-height:1.1;">' . wp_kses_post($note) . '</small></td></tr>';
    }

    public function render_cart_range(): void
    {
        $range = $this->get_range_data();
        if (!$range) {
            return;
        }

        $note = sprintf(
            /* translators: 1: formatted minimum total */
            __( 'min. %1$s', 'lotzapp-for-woocommerce' ),
            $range['min_html']
        );

        echo '<tr class="lotzwoo-range-note"><td colspan="2"><small style="display:block;text-align:right;font-size:0.6em;line-height:1.1;">' . wp_kses_post($note) . '</small></td></tr>';
    }
}

