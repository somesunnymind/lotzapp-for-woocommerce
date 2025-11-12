<?php

namespace Lotzwoo\Frontend;

use Lotzwoo\Field_Registry;
use Lotzwoo\Plugin;
use WC_Product;
use WC_Product_Variation;

if (!defined('ABSPATH')) {
    exit;
}

class Product_Custom_Fields_Display
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private $shortcodes = [];

    public function __construct()
    {
        add_action('init', [$this, 'register_shortcodes']);
    }

    public function register_shortcodes(): void
    {
        foreach (Field_Registry::all() as $field) {
            $shortcode = isset($field['shortcode']) ? (string) $field['shortcode'] : '';
            if ($shortcode === '') {
                continue;
            }

            $this->shortcodes[$shortcode] = $field;
            add_shortcode($shortcode, [$this, 'render_shortcode']);
        }
    }

    /**
     * @param array<string, mixed> $atts
     * @param string               $content
     * @param string               $tag
     */
    public function render_shortcode($atts = [], $content = '', $tag = ''): string
    {
        if (!is_string($tag) || $tag === '' || !isset($this->shortcodes[$tag])) {
            return '';
        }

        $field = $this->shortcodes[$tag];

        $option_key = isset($field['option_key']) ? (string) $field['option_key'] : '';
        if ($option_key !== '' && !Plugin::opt($option_key)) {
            return '';
        }

        $atts = shortcode_atts([
            'product_id'   => 0,
            'post_id'      => 0,
            'id'           => 0,
            'variation_id' => 0,
            'before'       => '',
            'after'        => '',
            'fallback'     => '',
            'wrap'         => '',
            'class'        => '',
            'label'        => '',
        ], $atts, $tag);

        $product_id = $this->resolve_product_id($atts);
        if ($product_id <= 0) {
            return '';
        }

        $value = $this->get_field_value($field, $product_id);
        $formatted = $this->format_field_value($field, $value, $atts);

        if ($formatted === '') {
            $fallback = isset($atts['fallback']) ? (string) $atts['fallback'] : '';
            if ($fallback === '') {
                return '';
            }
            $formatted = esc_html($fallback);
        }

        $formatted = $this->apply_heading($field, $formatted, $product_id, $atts);
        if ($formatted === '') {
            return '';
        }

        $output = $this->wrap_output($formatted, $atts);

        if (!empty($field['legacy_filters']) && is_array($field['legacy_filters'])) {
            foreach ($field['legacy_filters'] as $filter) {
                $output = apply_filters($filter, $output, $field, $product_id, $atts);
            }
        }

        /**
         * Allow developers to adjust the final output for Lotzwoo product custom fields.
         *
         * @param string                     $output
         * @param array<string, mixed>       $field
         * @param int                        $product_id
         * @param array<string, mixed>       $atts
         * @param string                     $tag
         */
        $output = apply_filters('lotzwoo_product_custom_field_output', $output, $field, $product_id, $atts, $tag);

        $before = isset($atts['before']) ? (string) $atts['before'] : '';
        $after  = isset($atts['after']) ? (string) $atts['after'] : '';

        return $before . $output . $after;
    }

    /**
     * @param array<string, mixed> $atts
     */
    private function resolve_product_id(array $atts): int
    {
        $candidates = ['variation_id', 'product_id', 'post_id', 'id'];

        foreach ($candidates as $key) {
            if (!empty($atts[$key])) {
                $id = (int) $atts[$key];
                if ($id > 0) {
                    return $id;
                }
            }
        }

        if (isset($GLOBALS['product']) && $GLOBALS['product'] instanceof WC_Product) {
            return (int) $GLOBALS['product']->get_id();
        }

        $queried = get_the_ID();
        if ($queried) {
            return (int) $queried;
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $field
     * @return mixed
     */
    private function get_field_value(array $field, int $product_id)
    {
        $meta_key = isset($field['meta_key']) ? (string) $field['meta_key'] : '';
        if ($meta_key === '') {
            return '';
        }

        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;

        if ($product instanceof WC_Product_Variation) {
            $value = get_post_meta($product->get_id(), $meta_key, true);
            if ($this->is_empty_value($value)) {
                $parent_id = $product->get_parent_id();
                if ($parent_id > 0) {
                    $value = get_post_meta($parent_id, $meta_key, true);
                }
            }
            return $value;
        }

        $value = get_post_meta($product_id, $meta_key, true);

        if ($this->is_empty_value($value) && $product instanceof WC_Product) {
            $parent_id = $product->get_parent_id();
            if ($parent_id > 0) {
                $parent_value = get_post_meta($parent_id, $meta_key, true);
                if (!$this->is_empty_value($parent_value)) {
                    $value = $parent_value;
                }
            }
        }

        return $value;
    }

    /**
     * @param mixed $value
     */
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

    /**
     * @param array<string, mixed> $field
     * @param mixed                $value
     * @param array<string, mixed> $atts
     */
    private function format_field_value(array $field, $value, array $atts): string
    {
        $type = isset($field['field_type']) ? (string) $field['field_type'] : 'textarea';

        if ($type === 'checkbox') {
            if ($value !== 'yes' && $value !== '1' && $value !== 1) {
                return '';
            }

            $label = trim((string) ($atts['label'] ?: ($field['display_true_label'] ?? '')));
            if ($label === '') {
                $label = __('Yes', 'lotzapp-for-woocommerce');
            }

            return esc_html($label);
        }

        if (!is_string($value)) {
            return '';
        }

        $text = trim($value);
        if ($text === '') {
            return '';
        }

        /**
         * Filter raw text for Lotzwoo product custom fields before escaping.
         *
         * @param string               $text
         * @param array<string, mixed> $field
         * @param array<string, mixed> $atts
         */
        $text = apply_filters('lotzwoo_product_custom_field_text', $text, $field, $atts);

        return nl2br(esc_html($text));
    }

    /**
     * @param array<string, mixed> $atts
     */
    private function wrap_output(string $output, array $atts): string
    {
        $wrap = isset($atts['wrap']) ? sanitize_key((string) $atts['wrap']) : '';
        if ($wrap === '') {
            return $output;
        }

        $class_attr = '';
        $classes = isset($atts['class']) ? trim((string) $atts['class']) : '';

        if ($classes !== '') {
            $class_parts = preg_split('/\s+/', $classes);
            $class_parts = $class_parts ? array_map('sanitize_html_class', $class_parts) : [];
            $class_parts = array_filter($class_parts, static function ($part) {
                return $part !== '';
            });

            if (!empty($class_parts)) {
                $class_attr = ' class="' . esc_attr(implode(' ', $class_parts)) . '"';
            }
        }

        return sprintf('<%1$s%2$s>%3$s</%1$s>', tag_escape($wrap), $class_attr, $output);
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $atts
     */
    private function apply_heading(array $field, string $content, int $product_id, array $atts): string
    {
        $heading_key = isset($field['heading_option_key']) ? (string) $field['heading_option_key'] : '';
        if ($heading_key === '') {
            return $content;
        }

        $template = Plugin::opt($heading_key, '');
        if (!is_string($template)) {
            $template = '';
        }
        $template = trim($template);
        if ($template === '') {
            return $content;
        }

        $placeholder = Field_Registry::TEMPLATE_PLACEHOLDER;

        /**
         * Filter the template markup for Lotzwoo custom field output.
         *
         * @param string               $template
         * @param array<string, mixed> $field
         * @param int                  $product_id
         * @param array<string, mixed> $atts
         */
        $template = apply_filters('lotzwoo_product_custom_field_template', $template, $field, $product_id, $atts);
        if (!is_string($template)) {
            $template = '';
        }

        if (strpos($template, $placeholder) === false) {
            return $content;
        }

        $output = str_replace($placeholder, $content, $template);

        /**
         * Filter the rendered heading output for Lotzwoo custom field output.
         *
         * @param string               $output
         * @param array<string, mixed> $field
         * @param int                  $product_id
         * @param array<string, mixed> $atts
         * @param string               $template
         */
        $output = apply_filters('lotzwoo_product_custom_field_heading', $output, $field, $product_id, $atts, $template);

        return $output === '' ? $content : $output;
    }
}
