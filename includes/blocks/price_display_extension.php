<?php

namespace Lotzwoo\Blocks;

use WC_Cart;
use WC_Product;
use Lotzwoo\Frontend\Price_Display_Templates;
use Lotzwoo\Plugin;

if (!defined('ABSPATH')) {
    exit;
}

class Price_Display_Extension
{
    private Price_Display_Templates $templates;

    public function __construct(?Price_Display_Templates $templates = null)
    {
        $this->templates = $templates ?: new Price_Display_Templates();
        add_action('init', [$this, 'register_store_api_extensions']);
    }

    public function register_store_api_extensions(): void
    {
        if (!function_exists('woocommerce_store_api_register_endpoint_data')) {
            return;
        }

        woocommerce_store_api_register_endpoint_data([
            'endpoint'        => 'cart-item',
            'namespace'       => 'lotzwoo',
            'schema_callback' => [$this, 'get_cart_item_schema'],
            'data_callback'   => [$this, 'get_cart_item_extension'],
            'schema_type'     => ARRAY_A,
        ]);

        foreach (['cart', 'checkout'] as $endpoint) {
            woocommerce_store_api_register_endpoint_data([
                'endpoint'        => $endpoint,
                'namespace'       => 'lotzwoo',
                'schema_callback' => [$this, 'get_cart_schema'],
                'data_callback'   => [$this, 'get_cart_extension'],
                'schema_type'     => ARRAY_A,
            ]);
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function get_cart_item_schema(): array
    {
        return [
            'item_price_html'    => [
                'description' => __('LotzApp Preis-Template (Einzelpreis)', 'lotzapp-for-woocommerce'),
                'type'        => 'string',
                'context'     => ['view'],
            ],
            'item_subtotal_html' => [
                'description' => __('LotzApp Preis-Template (Zwischensumme)', 'lotzapp-for-woocommerce'),
                'type'        => 'string',
                'context'     => ['view'],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function get_cart_schema(): array
    {
        return [
            'cart_subtotal_html' => [
                'description' => __('LotzApp Template fuer Warenkorb-Zwischensumme', 'lotzapp-for-woocommerce'),
                'type'        => 'string',
                'context'     => ['view'],
            ],
            'cart_total_html'    => [
                'description' => __('LotzApp Template fuer Warenkorb-Gesamtsumme', 'lotzapp-for-woocommerce'),
                'type'        => 'string',
                'context'     => ['view'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $cart_item
     * @return array<string, string>
     */
    public function get_cart_item_extension(array $cart_item): array
    {
        $cart = $this->get_cart();
        if (!$cart) {
            return [];
        }

        $product = isset($cart_item['data']) ? $cart_item['data'] : null;
        if (!$product instanceof WC_Product) {
            return [];
        }

        $templates = $this->get_cart_templates();
        $price_html = '';
        $subtotal_html = '';

        if ($templates['item_price']['enabled']) {
            $price_html = $this->templates->render_block_product_template($templates['item_price']['template'], $product);
        }

        if ($templates['item_subtotal']['enabled']) {
            $subtotal_html = $this->templates->render_block_product_template($templates['item_subtotal']['template'], $product);
        }

        return [
            'item_price_html'    => (string) $price_html,
            'item_subtotal_html' => (string) $subtotal_html,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function get_cart_extension(...$args): array
    {
        $cart = $this->get_cart();
        if (!$cart) {
            return [];
        }

        $templates = $this->get_cart_templates();

        $subtotal_html = '';
        $total_html    = '';

        if ($templates['subtotal']['enabled']) {
            $subtotal_html = $this->templates->render_block_cart_subtotal_template($templates['subtotal']['template'], $cart);
        }

        if ($templates['total']['enabled']) {
            $total_html = $this->templates->render_block_cart_total_template($templates['total']['template'], $cart);
        }

        return [
            'cart_subtotal_html' => (string) $subtotal_html,
            'cart_total_html'    => (string) $total_html,
        ];
    }

    /**
     * @return array<string, array{enabled:bool,template:string}>
     */
    private function get_cart_templates(): array
    {
        return [
            'item_price'    => [
                'enabled'  => (bool) Plugin::opt('price_display_cart_item_price_enabled', 1),
                'template' => (string) Plugin::opt('price_display_cart_item_price_template', '{{ca_prefix}}{{value}}'),
            ],
            'item_subtotal' => [
                'enabled'  => (bool) Plugin::opt('price_display_cart_item_subtotal_enabled', 1),
                'template' => (string) Plugin::opt('price_display_cart_item_subtotal_template', '{{ca_prefix}}{{value}}'),
            ],
            'subtotal'      => [
                'enabled'  => (bool) Plugin::opt('price_display_cart_subtotal_enabled', 1),
                'template' => (string) Plugin::opt('price_display_cart_subtotal_template', '{{ca_prefix}}{{value}}'),
            ],
            'total'         => [
                'enabled'  => (bool) Plugin::opt('price_display_cart_total_enabled', 1),
                'template' => (string) Plugin::opt('price_display_cart_total_template', '{{ca_prefix}}{{value}}'),
            ],
        ];
    }

    private function get_cart(): ?WC_Cart
    {
        if (!function_exists('WC')) {
            return null;
        }

        $cart = WC()->cart;
        return $cart instanceof WC_Cart ? $cart : null;
    }
}
