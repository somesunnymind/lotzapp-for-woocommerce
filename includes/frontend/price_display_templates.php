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
        add_filter('woocommerce_get_price_html', [$this, 'filter_single_price_html'], 50, 2);
        add_filter('woocommerce_variable_price_html', [$this, 'filter_variable_price_html'], 50, 2);
        add_filter('woocommerce_variable_sale_price_html', [$this, 'filter_variable_sale_price_html'], 50, 2);
        add_filter('woocommerce_available_variation', [$this, 'filter_available_variation_data'], 30, 3);
    }

    /**
     * @param string      $price_html
     * @param \WC_Product $product
     */
    public function filter_single_price_html($price_html, $product)
    {
        if (!$this->should_handle_single_product($product)) {
            return $price_html;
        }

        $templates = $this->get_single_product_templates();
        if (!$templates['main']['enabled'] && !$templates['regular']['enabled'] && !$templates['sale']['enabled']) {
            return $price_html;
        }

        $updated_html = $price_html;

        if ($product->is_on_sale()) {
            if ($templates['regular']['enabled']) {
                $updated_html = $this->replace_tagged_segment($updated_html, 'del', $templates['regular']['template']);
            }
            if ($templates['sale']['enabled']) {
                $updated_html = $this->replace_tagged_segment($updated_html, 'ins', $templates['sale']['template']);
            }
        } elseif ($templates['regular']['enabled']) {
            $updated_html = $this->apply_template($templates['regular']['template'], $updated_html);
        }

        if ($templates['main']['enabled']) {
            $updated_html = $this->apply_template($templates['main']['template'], $updated_html);
        }

        return $updated_html;
    }

    /**
     * @param string                $price_html
     * @param \WC_Product_Variable $product
     */
    public function filter_variable_price_html($price_html, $product)
    {
        if (!$this->should_handle_variable_product($product)) {
            return $price_html;
        }

        $templates = $this->get_variable_templates();
        $context   = $this->get_variable_placeholder_context($product);
        if ($product->is_on_sale()) {
            if ($templates['sale']['enabled']) {
                return $this->apply_template($templates['sale']['template'], $price_html, $context);
            }
        }

        if ($templates['range']['enabled']) {
            return $this->apply_template($templates['range']['template'], $price_html, $context);
        }

        return $price_html;
    }

    /**
     * @param string                $price_html
     * @param \WC_Product_Variable $product
     */
    public function filter_variable_sale_price_html($price_html, $product)
    {
        if (!$this->should_handle_variable_product($product)) {
            return $price_html;
        }

        $templates = $this->get_variable_templates();
        $context   = $this->get_variable_placeholder_context($product);
        if ($templates['sale']['enabled']) {
            return $this->apply_template($templates['sale']['template'], $price_html, $context);
        }

        if ($templates['range']['enabled']) {
            return $this->apply_template($templates['range']['template'], $price_html, $context);
        }

        return $price_html;
    }

    /**
     * @param array<string,mixed>   $variation_data
     * @param \WC_Product_Variable  $product
     * @param \WC_Product_Variation $variation
     * @return array<string,mixed>
     */
    public function filter_available_variation_data($variation_data, $product, $variation)
    {
        if (!$variation instanceof \WC_Product_Variation) {
            return $variation_data;
        }

        $parent = $variation->get_parent_id();
        $variation_product = $product instanceof \WC_Product_Variable ? $product : ($parent ? wc_get_product($parent) : null);
        if (!$this->should_handle_variable_product($variation_product)) {
            return $variation_data;
        }

        $templates = $this->get_variable_templates();
        if (!$templates['selection']['enabled']) {
            return $variation_data;
        }

        $template = $templates['selection']['template'];

        if (!empty($variation_data['price_html'])) {
            $variation_data['price_html'] = $this->apply_template($template, (string) $variation_data['price_html']);
        }

        if (isset($variation_data['display_price'])) {
            $formatted = function_exists('wc_price') ? wc_price((float) $variation_data['display_price']) : (string) $variation_data['display_price'];
            $variation_data['lotzwoo_display_price_html'] = $this->apply_template($template, $formatted);
        }

        return $variation_data;
    }

    /**
     * @param mixed $product
     */
    private function should_handle_single_product($product): bool
    {
        if (!$product instanceof \WC_Product) {
            return false;
        }

        if (!$product->is_type('simple')) {
            return false;
        }

        if (is_admin() && !(function_exists('wp_doing_ajax') && wp_doing_ajax())) {
            return false;
        }

        $is_single_context = function_exists('is_product') && is_product();
        if (!$is_single_context) {
            $is_single_context = did_action('woocommerce_single_product_summary') > 0;
        }

        return (bool) $is_single_context;
    }

    /**
     * @param mixed $product
     */
    private function should_handle_variable_product($product): bool
    {
        return $product instanceof \WC_Product_Variable;
    }

    /**
     * @return array<string, array{enabled:bool,template:string}>
     */
    private function get_single_product_templates(): array
    {
        $placeholder = Field_Registry::TEMPLATE_PLACEHOLDER;

        return [
            'main'    => [
                'enabled'  => (bool) Plugin::opt('price_display_single_enabled'),
                'template' => (string) Plugin::opt('price_display_single_template', $placeholder),
            ],
            'regular' => [
                'enabled'  => (bool) Plugin::opt('price_display_single_regular_enabled'),
                'template' => (string) Plugin::opt('price_display_single_regular_template', $placeholder),
            ],
            'sale'    => [
                'enabled'  => (bool) Plugin::opt('price_display_single_sale_enabled'),
                'template' => (string) Plugin::opt('price_display_single_sale_template', $placeholder),
            ],
        ];
    }

    /**
     * @return array<string, array{enabled:bool,template:string}>
     */
    private function get_variable_templates(): array
    {
        $placeholder = Field_Registry::TEMPLATE_PLACEHOLDER;

        return [
            'range'      => [
                'enabled'  => (bool) Plugin::opt('price_display_variable_range_enabled'),
                'template' => (string) Plugin::opt('price_display_variable_range_template', $placeholder),
            ],
            'sale'       => [
                'enabled'  => (bool) Plugin::opt('price_display_variable_sale_enabled'),
                'template' => (string) Plugin::opt('price_display_variable_sale_template', $placeholder),
            ],
            'selection'  => [
                'enabled'  => (bool) Plugin::opt('price_display_variable_selection_enabled'),
                'template' => (string) Plugin::opt('price_display_variable_selection_template', $placeholder),
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

        $pattern = sprintf('/(<%1$s\b[^>]*>.*?<\\/%1$s>)/is', preg_quote($tag, '/'));
        if (!preg_match($pattern, $html, $matches, PREG_OFFSET_CAPTURE)) {
            return $html;
        }

        $segment = $matches[1][0];
        $offset  = (int) $matches[1][1];
        $replacement = $this->apply_template($template, $segment, $context);

        return substr($html, 0, $offset) . $replacement . substr($html, $offset + strlen($segment));
    }

    /**
     * @return array<string, string>
     */
    private function get_variable_placeholder_context(\WC_Product_Variable $product): array
    {
        $placeholders = [];
        $min_price = $product->get_variation_price('min', true);
        $max_price = $product->get_variation_price('max', true);

        if ($min_price !== '') {
            $formatted_min = $this->format_price_amount($min_price);
            $placeholders['{{minvalue}}'] = $formatted_min;
            $placeholders['{{prefixed_minvalue}}'] = $this->maybe_prefix_amount($formatted_min, $product);
        }
        if ($max_price !== '') {
            $formatted_max = $this->format_price_amount($max_price);
            $placeholders['{{maxvalue}}'] = $formatted_max;
            $placeholders['{{prefixed_maxvalue}}'] = $this->maybe_prefix_amount($formatted_max, $product);
        }

        return $placeholders;
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

    private function maybe_prefix_amount(string $formatted_amount, ?\WC_Product $product): string
    {
        if (!$product || !Estimated::is_estimated_product($product)) {
            return $formatted_amount;
        }

        $prefix = trim((string) Plugin::opt('price_prefix', 'Ca. '));
        if ($prefix === '') {
            return $formatted_amount;
        }

        $plain = trim(wp_strip_all_tags($formatted_amount));
        if ($plain !== '' && strpos($plain, $prefix) === 0) {
            return $formatted_amount;
        }

        return esc_html($prefix) . ' ' . $formatted_amount;
    }
}
