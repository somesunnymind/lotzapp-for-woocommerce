<?php

namespace Lotzwoo\Frontend;

use Lotzwoo\Field_Registry;
use Lotzwoo\Plugin;
use Lotzwoo\Helpers\Estimated;

if (!defined('ABSPATH')) {
    exit;
}

class Price_Display_Templates
{
    public function __construct()
    {
        add_filter('woocommerce_get_price_html', [$this, 'filter_simple_price_html'], 50, 2);
        add_filter('woocommerce_grouped_price_html', [$this, 'filter_grouped_price_html'], 50, 2);
        add_filter('woocommerce_variable_price_html', [$this, 'filter_variable_price_html'], 50, 2);
        add_filter('woocommerce_variable_sale_price_html', [$this, 'filter_variable_sale_price_html'], 50, 2);
        add_filter('woocommerce_available_variation', [$this, 'filter_available_variation_data'], 30, 3);

        add_filter('woocommerce_cart_item_price', [$this, 'filter_cart_item_price'], 50, 3);
        add_filter('woocommerce_cart_item_subtotal', [$this, 'filter_cart_item_subtotal'], 50, 3);
        add_filter('woocommerce_cart_totals_subtotal_html', [$this, 'filter_cart_totals_subtotal_html'], 50, 1);
        add_filter('woocommerce_cart_subtotal', [$this, 'filter_cart_subtotal'], 50, 3);
        add_filter('woocommerce_cart_totals_order_total_html', [$this, 'filter_cart_totals_order_total_html'], 50, 1);
        add_filter('woocommerce_get_formatted_order_total', [$this, 'filter_order_total'], 50, 2);

    }

    public function filter_simple_price_html($price_html, $product)
    {
        if (!$product instanceof \WC_Product || !$product->is_type('simple')) {
            return $price_html;
        }

        if (is_admin() && !(defined('DOING_AJAX') && DOING_AJAX)) {
            return $price_html;
        }

        $templates = $this->get_single_product_templates();
        if (!$templates['main']['enabled'] && !$templates['regular']['enabled'] && !$templates['sale']['enabled']) {
            return $price_html;
        }

        $context = $this->build_product_context($product);
        $updated_html = $price_html;

        if ($product->is_on_sale()) {
            if ($templates['regular']['enabled']) {
                $updated_html = $this->replace_tagged_segment($updated_html, 'del', $templates['regular']['template'], $context);
            }
            if ($templates['sale']['enabled']) {
                $updated_html = $this->replace_tagged_segment($updated_html, 'ins', $templates['sale']['template'], $context);
            }
        } elseif ($templates['regular']['enabled']) {
            $updated_html = $this->apply_template($templates['regular']['template'], $updated_html, $context);
        }

        if ($templates['main']['enabled']) {
            $updated_html = $this->apply_template($templates['main']['template'], $updated_html, $context);
        }

        return $updated_html;
    }

    public function filter_grouped_price_html($price_html, $product)
    {
        if (!$product instanceof \WC_Product) {
            return $price_html;
        }

        $template = $this->get_grouped_template();
        if (!$template['enabled']) {
            return $price_html;
        }

        return $this->apply_template($template['template'], $price_html, $this->build_product_context($product));
    }

    public function filter_variable_price_html($price_html, $product)
    {
        if (!$product instanceof \WC_Product_Variable) {
            return $price_html;
        }

        $templates = $this->get_variable_templates();
        $context   = $this->get_variable_placeholder_context($product);
        $default_html = $this->format_variable_range_html($product);

        if ($product->is_on_sale() && $templates['sale']['enabled']) {
            return $this->apply_template($templates['sale']['template'], $this->format_variable_sale_range_html($product), $context);
        }

        if ($templates['range']['enabled']) {
            return $this->apply_template($templates['range']['template'], $default_html, $context);
        }

        return $price_html;
    }

    public function filter_variable_sale_price_html($price_html, $product)
    {
        if (!$product instanceof \WC_Product_Variable) {
            return $price_html;
        }

        $templates = $this->get_variable_templates();
        $context   = $this->get_variable_placeholder_context($product);
        $default_html = $this->format_variable_sale_range_html($product);

        if ($templates['sale']['enabled']) {
            return $this->apply_template($templates['sale']['template'], $default_html, $context);
        }

        if ($templates['range']['enabled']) {
            return $this->apply_template($templates['range']['template'], $this->format_variable_range_html($product), $context);
        }

        return $price_html;
    }

    public function filter_available_variation_data($variation_data, $product, $variation)
    {
        if (!$variation instanceof \WC_Product_Variation) {
            return $variation_data;
        }

        $templates = $this->get_variable_templates();
        if (!$templates['selection']['enabled']) {
            return $variation_data;
        }

        $context = $this->build_product_context($variation);
        if (!empty($variation_data['price_html'])) {
            $variation_data['price_html'] = $this->apply_template($templates['selection']['template'], (string) $variation_data['price_html'], $context);
        }

        if (isset($variation_data['display_price'])) {
            $formatted = function_exists('wc_price') ? wc_price((float) $variation_data['display_price']) : (string) $variation_data['display_price'];
            $variation_data['lotzwoo_display_price_html'] = $this->apply_template($templates['selection']['template'], $formatted, $context);
        }

        return $variation_data;
    }

    public function filter_cart_item_price($price_html, $cart_item, $cart_item_key)
    {
        $templates = $this->get_cart_templates();
        if (!$templates['item_price']['enabled']) {
            return $price_html;
        }

        $product = isset($cart_item['data']) ? $cart_item['data'] : null;
        if (!$product instanceof \WC_Product) {
            return $price_html;
        }

        return $this->apply_template($templates['item_price']['template'], $price_html, $this->build_product_context($product));
    }

    public function filter_cart_item_subtotal($subtotal_html, $cart_item, $cart_item_key)
    {
        $templates = $this->get_cart_templates();
        if (!$templates['item_subtotal']['enabled']) {
            return $subtotal_html;
        }

        $product = isset($cart_item['data']) ? $cart_item['data'] : null;
        if (!$product instanceof \WC_Product) {
            return $subtotal_html;
        }

        return $this->apply_template($templates['item_subtotal']['template'], $subtotal_html, $this->build_product_context($product));
    }

    public function filter_cart_totals_subtotal_html($subtotal_html)
    {
        $cart = $this->resolve_cart();
        $templates = $this->get_cart_templates();
        if (!$templates['subtotal']['enabled']) {
            return $subtotal_html;
        }

        return $this->apply_template($templates['subtotal']['template'], $subtotal_html, $this->build_cart_context($cart));
    }

    public function filter_cart_subtotal($cart_subtotal, $compound, $cart)
    {
        $templates = $this->get_cart_templates();
        if (!$templates['subtotal']['enabled']) {
            return $cart_subtotal;
        }

        return $this->apply_template($templates['subtotal']['template'], $cart_subtotal, $this->build_cart_context($cart));
    }

    public function filter_cart_totals_order_total_html($total_html)
    {
        $cart = $this->resolve_cart();
        $templates = $this->get_cart_templates();
        if (!$templates['total']['enabled']) {
            return $total_html;
        }

        return $this->apply_template($templates['total']['template'], $total_html, $this->build_cart_totals_context($cart));
    }

    public function filter_order_total($formatted_total, $order)
    {
        $templates = $this->get_order_templates();
        if (!$templates['order_total']['enabled']) {
            return $formatted_total;
        }

        return $this->apply_template($templates['order_total']['template'], $formatted_total, $this->build_order_context($order));
    }

    private function get_single_product_templates(): array
    {
        return [
            'main'    => [
                'enabled'  => (bool) Plugin::opt('price_display_single_enabled', 1),
                'template' => (string) Plugin::opt('price_display_single_template', '{{ca_prefix}}{{value}}'),
            ],
            'regular' => [
                'enabled'  => (bool) Plugin::opt('price_display_single_regular_enabled', 1),
                'template' => (string) Plugin::opt('price_display_single_regular_template', '{{ca_prefix}}{{value}}'),
            ],
            'sale'    => [
                'enabled'  => (bool) Plugin::opt('price_display_single_sale_enabled', 1),
                'template' => (string) Plugin::opt('price_display_single_sale_template', '{{ca_prefix}}{{value}}'),
            ],
        ];
    }

    private function get_grouped_template(): array
    {
        return [
            'enabled'  => (bool) Plugin::opt('price_display_grouped_enabled', 1),
            'template' => (string) Plugin::opt('price_display_grouped_template', '{{ca_prefix}}{{value}}'),
        ];
    }

    private function get_variable_templates(): array
    {
        return [
            'range'      => [
                'enabled'  => (bool) Plugin::opt('price_display_variable_range_enabled', 1),
                'template' => (string) Plugin::opt('price_display_variable_range_template', '{{ca_prefix}}{{value}}'),
            ],
            'sale'       => [
                'enabled'  => (bool) Plugin::opt('price_display_variable_sale_enabled', 1),
                'template' => (string) Plugin::opt('price_display_variable_sale_template', '{{ca_prefix}}{{value}}'),
            ],
            'selection'  => [
                'enabled'  => (bool) Plugin::opt('price_display_variable_selection_enabled', 1),
                'template' => (string) Plugin::opt('price_display_variable_selection_template', '{{ca_prefix}}{{value}}'),
            ],
        ];
    }

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

    private function get_order_templates(): array
    {
        return [
            'order_total' => [
                'enabled'  => (bool) Plugin::opt('price_display_order_total_enabled', 1),
                'template' => (string) Plugin::opt('price_display_order_total_template', '{{ca_prefix}}{{value}}'),
            ],
        ];
    }

    private function apply_template(string $template, string $value, array $context = []): string
    {
        $template = trim($template);
        if ($template === '') {
            return $value;
        }

        $placeholder = Field_Registry::TEMPLATE_PLACEHOLDER;
        if (!isset($context['{{ca_prefix}}'])) {
            $context['{{ca_prefix}}'] = '';
        }

        $replacements = [$placeholder => $value];

        foreach ($context as $token => $replacement) {
            if (!is_string($token) || $token === '') {
                continue;
            }
            $replacements[trim($token)] = (string) $replacement;
        }

        $contains = false;
        foreach ($replacements as $token => $_replacement) {
            if ($token !== '' && strpos($template, $token) !== false) {
                $contains = true;
                break;
            }
        }

        if (!$contains) {
            return $value;
        }

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    private function replace_tagged_segment(string $html, string $tag, string $template, array $context = []): string
    {
        $tag = strtolower($tag);
        if ($tag === '' || trim($template) === '') {
            return $html;
        }

        $pattern = sprintf('/(<%1$s\b[^>]*>.*?<\/%1$s>)/is', preg_quote($tag, '/'));
        if (!preg_match($pattern, $html, $matches, PREG_OFFSET_CAPTURE)) {
            return $html;
        }

        $segment = $matches[1][0];
        $offset  = (int) $matches[1][1];
        $replacement = $this->apply_template($template, $segment, $context);

        return substr($html, 0, $offset) . $replacement . substr($html, $offset + strlen($segment));
    }

    private function get_variable_placeholder_context(\WC_Product_Variable $product): array
    {
        $placeholders = [];
        $min_price = $product->get_variation_price('min', true);
        $max_price = $product->get_variation_price('max', true);
        $should_prefix = Plugin::ca_prices_enabled() && $this->variable_has_estimated_variations($product);
        $placeholders['{{ca_prefix}}'] = $this->format_prefix($this->get_line_prefix_option(), $should_prefix);

        if ($min_price !== '') {
            $formatted_min = $this->format_price_amount($min_price);
            $placeholders['{{minvalue}}'] = $formatted_min;
            $placeholders['{{prefixed_minvalue}}'] = $this->maybe_prefix_amount($formatted_min, $placeholders['{{ca_prefix}}']);
        }
        if ($max_price !== '') {
            $formatted_max = $this->format_price_amount($max_price);
            $placeholders['{{maxvalue}}'] = $formatted_max;
            $placeholders['{{prefixed_maxvalue}}'] = $this->maybe_prefix_amount($formatted_max, $placeholders['{{ca_prefix}}']);
        }

        return $placeholders;
    }

    private function format_variable_range_html(\WC_Product_Variable $product): string
    {
        $min_price = (float) $product->get_variation_price('min', true);
        $max_price = (float) $product->get_variation_price('max', true);
        return $this->format_price_range_html($min_price, $max_price);
    }

    private function format_variable_sale_range_html(\WC_Product_Variable $product): string
    {
        $min_regular = (float) $product->get_variation_regular_price('min', true);
        $max_regular = (float) $product->get_variation_regular_price('max', true);
        $min_sale    = (float) $product->get_variation_price('min', true);
        $max_sale    = (float) $product->get_variation_price('max', true);

        $regular_html = $this->format_price_range_html($min_regular, $max_regular);
        $sale_html    = $this->format_price_range_html($min_sale, $max_sale);

        if ($regular_html === $sale_html) {
            return $sale_html;
        }

        return wc_format_sale_price($regular_html, $sale_html);
    }

    private function format_price_range_html(float $min, float $max): string
    {
        $min_html = wc_price($min);
        $max_html = wc_price($max);

        if ($min === $max) {
            return $min_html;
        }

        return wc_format_price_range($min_html, $max_html);
    }

    private function format_price_amount($amount): string
    {
        if (!is_numeric($amount)) {
            return (string) $amount;
        }

        if (function_exists('wc_price')) {
            return (string) wc_price((float) $amount);
        }

        return (string) $amount;
    }

    private function build_product_context(?\WC_Product $product): array
    {
        $should_prefix = $product && Plugin::ca_prices_enabled() && Estimated::is_estimated_product($product);
        return ['{{ca_prefix}}' => $this->format_prefix($this->get_line_prefix_option(), $should_prefix)];
    }

    private function build_cart_context(?\WC_Cart $cart = null): array
    {
        $cart = $this->resolve_cart($cart);
        $should_prefix = Plugin::ca_prices_enabled() && $cart && Estimated::cart_has_estimated($cart);
        return ['{{ca_prefix}}' => $this->format_prefix($this->get_total_prefix_option(), $should_prefix)];
    }

    private function build_cart_totals_context(?\WC_Cart $cart = null): array
    {
        return $this->build_cart_context($cart);
    }

    private function build_order_context($order): array
    {
        $should_prefix = Plugin::ca_prices_enabled() && $this->order_has_estimated($order);
        return ['{{ca_prefix}}' => $this->format_prefix($this->get_total_prefix_option(), $should_prefix)];
    }

    private function format_prefix(string $prefix, bool $include): string
    {
        $prefix = trim($prefix);
        if (!$include || $prefix === '') {
            return '';
        }

        return esc_html($prefix) . ' ';
    }

    private function maybe_prefix_amount(string $formatted, string $prefix_html): string
    {
        if ($prefix_html === '') {
            return $formatted;
        }

        $plain_prefix = trim(wp_strip_all_tags($prefix_html));
        $plain_value  = trim(wp_strip_all_tags($formatted));
        if ($plain_prefix !== '' && $plain_value !== '' && strpos($plain_value, $plain_prefix) === 0) {
            return $formatted;
        }

        return $prefix_html . $formatted;
    }

    private function is_estimated_product(?\WC_Product $product): bool
    {
        return $product instanceof \WC_Product && Estimated::is_estimated_product($product);
    }

    private function variable_has_estimated_variations(\WC_Product_Variable $product): bool
    {
        if (Estimated::is_estimated_product($product)) {
            return true;
        }

        foreach ($product->get_children() as $child_id) {
            $child = wc_get_product($child_id);
            if ($child && Estimated::is_estimated_product($child)) {
                return true;
            }
        }

        return false;
    }

    private function order_has_estimated($order): bool
    {
        if (!$order || !is_object($order) || !method_exists($order, 'get_items')) {
            return false;
        }

        foreach ($order->get_items() as $item) {
            if (!method_exists($item, 'get_product')) {
                continue;
            }
            $product = $item->get_product();
            if ($product && Estimated::is_estimated_product($product)) {
                return true;
            }
        }

        return false;
    }

    private function get_line_prefix_option(): string
    {
        return (string) Plugin::opt('price_prefix', 'Ca. ');
    }

    private function get_total_prefix_option(): string
    {
        $total_prefix = Plugin::opt('total_prefix', null);
        if (!is_string($total_prefix) || trim($total_prefix) === '') {
            $total_prefix = $this->get_line_prefix_option();
        }
        return (string) $total_prefix;
    }
    private function resolve_cart($cart = null): ?\WC_Cart
    {
        if ($cart instanceof \WC_Cart) {
            return $cart;
        }

        if (function_exists('WC')) {
            $global_cart = WC()->cart;
            if ($global_cart instanceof \WC_Cart) {
                return $global_cart;
            }
        }

        return null;
    }

    private function cart_has_estimated_products($cart = null): bool
    {
        $cart = $this->resolve_cart($cart);
        if (!$cart) {
            return false;
        }

        return Estimated::cart_has_estimated($cart);
    }

}
