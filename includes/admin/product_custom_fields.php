<?php

namespace Lotzwoo\Admin;

use Lotzwoo\Plugin;
use Lotzwoo\Field_Registry;

if (!defined('ABSPATH')) {
    exit;
}

class Product_Custom_Fields
{
    public function __construct()
    {
        add_action('init', [$this, 'register_meta']);
        add_action('woocommerce_product_options_general_product_data', [$this, 'render_product_fields']);
        add_action('woocommerce_admin_process_product_object', [$this, 'save_product_fields']);
        add_action('woocommerce_product_after_variable_attributes', [$this, 'render_variation_fields'], 10, 3);
        add_action('woocommerce_save_product_variation', [$this, 'save_variation_fields'], 10, 2);
    }

    public function register_meta(): void
    {
        foreach (Field_Registry::all() as $field) {
            $this->register_single_meta('product', $field);
            $this->register_single_meta('product_variation', $field);
        }
    }

    private function register_single_meta(string $post_type, array $field): void
    {
        register_post_meta($post_type, $field['meta_key'], [
            'single'            => true,
            'type'              => 'string',
            'sanitize_callback' => $field['sanitize_callback'] ?? 'sanitize_text_field',
            'show_in_rest'      => true,
            'auth_callback'     => static function () {
                return current_user_can('edit_products');
            },
        ]);
    }

    public function render_product_fields(): void
    {
        global $post;
        if (!$post || $post->post_type !== 'product') {
            return;
        }

        $product_id = (int) $post->ID;
        foreach (Field_Registry::all() as $field) {
            if (!Plugin::opt($field['option_key'])) {
                continue;
            }

            $type  = $field['field_type'] ?? 'textarea';
            $value = get_post_meta($product_id, $field['meta_key'], true);
            if ($this->is_organic_field($field) && !$this->has_organic_label($product_id)) {
                $value = '';
            }

            if ($type === 'checkbox') {
                woocommerce_wp_checkbox([
                    'id'          => $field['meta_key'],
                    'label'       => $field['product_field_label'],
                    'desc_tip'    => true,
                    'description' => $field['product_field_description'],
                    'value'       => $value === 'yes' ? 'yes' : 'no',
                ]);
            } else {
                $this->render_input_field($field, $value, 'product');
            }
        }
    }

    public function save_product_fields(\WC_Product $product): void
    {
        foreach (Field_Registry::all() as $field) {
            if (!Plugin::opt($field['option_key'])) {
                $product->delete_meta_data($field['meta_key']);
                continue;
            }

            if ($this->should_clear_organic_field($product, $field)) {
                $product->delete_meta_data($field['meta_key']);
                continue;
            }

            $type = $field['field_type'] ?? 'textarea';

            if ($type === 'checkbox') {
                $raw   = isset($_POST[$field['meta_key']]) ? wp_unslash((string) $_POST[$field['meta_key']]) : 'no';
                $value = $raw === 'yes' ? 'yes' : 'no';
                $product->update_meta_data($field['meta_key'], $value);
                continue;
            }

            $value = isset($_POST[$field['meta_key']]) ? $this->sanitize_field_input($field, (string) wp_unslash((string) $_POST[$field['meta_key']])) : '';
            if ($value === '') {
                $product->delete_meta_data($field['meta_key']);
            } else {
                $product->update_meta_data($field['meta_key'], $value);
            }
        }
    }

    public function render_variation_fields(int $loop, array $variation_data, $variation): void
    {
        $variation_id = 0;
        if ($variation instanceof \WC_Product_Variation) {
            $variation_id = $variation->get_id();
        } elseif (is_object($variation) && isset($variation->ID)) {
            $variation_id = (int) $variation->ID;
        }

        foreach (Field_Registry::all() as $field) {
            if (!Plugin::opt($field['option_key'])) {
                continue;
            }

            $type  = $field['field_type'] ?? 'textarea';
            $value = $variation_id ? get_post_meta($variation_id, $field['meta_key'], true) : '';
            if ($variation_id && $this->is_organic_field($field) && !$this->has_organic_label($variation_id)) {
                $value = '';
            }
            $slug      = sanitize_key($field['slug']);
            $field_id   = 'lotzwoo_' . $slug . '_' . $loop;
            $field_name = 'lotzwoo_field[' . $slug . '][' . ($variation_id ?: $loop) . ']';

            if ($type === 'checkbox') {
                woocommerce_wp_checkbox([
                    'id'            => $field_id,
                    'name'          => $field_name,
                    'label'         => $field['variation_field_label'],
                    'desc_tip'      => true,
                    'description'   => $field['variation_field_description'],
                    'wrapper_class' => 'form-row-full',
                    'value'         => $value === 'yes' ? 'yes' : 'no',
                ]);
            } else {
                $this->render_input_field($field, $value, 'variation', [
                    'id'            => $field_id,
                    'name'          => $field_name,
                    'wrapper_class' => 'form-row-full',
                ]);
            }
        }
    }

    public function save_variation_fields(int $variation_id, int $loop): void
    {
        foreach (Field_Registry::all() as $field) {
            if (!Plugin::opt($field['option_key'])) {
                delete_post_meta($variation_id, $field['meta_key']);
                continue;
            }

            if ($this->should_clear_organic_field($variation_id, $field)) {
                delete_post_meta($variation_id, $field['meta_key']);
                continue;
            }

            $type   = $field['field_type'] ?? 'textarea';
            $slug   = sanitize_key($field['slug']);
            $groups = isset($_POST['lotzwoo_field']) && is_array($_POST['lotzwoo_field']) ? $_POST['lotzwoo_field'] : [];
            $posted = isset($groups[$slug]) && is_array($groups[$slug]) ? $groups[$slug] : [];
            $raw    = $posted[$variation_id] ?? ($posted[$loop] ?? '');

            if ($type === 'checkbox') {
                $value = is_string($raw) && $raw === 'yes' ? 'yes' : 'no';
                update_post_meta($variation_id, $field['meta_key'], $value);
                continue;
            }

            $value  = is_string($raw) ? $this->sanitize_field_input($field, (string) wp_unslash($raw)) : '';

            if ($value === '') {
                delete_post_meta($variation_id, $field['meta_key']);
            } else {
                update_post_meta($variation_id, $field['meta_key'], $value);
            }
        }
    }

    /**
     * @param array<string, mixed> $field
     */
    private function sanitize_field_input(array $field, string $value): string
    {
        $callback = $field['sanitize_callback'] ?? 'sanitize_textarea_field';
        if (!is_callable($callback)) {
            $callback = 'sanitize_text_field';
        }

        $sanitized = call_user_func($callback, $value);
        if (!is_string($sanitized)) {
            return '';
        }

        return trim($sanitized);
    }

    /**
     * @param array<string, mixed> $field
     * @param mixed                $value
     * @param array<string, mixed> $overrides
     */
    private function render_input_field(array $field, $value, string $context, array $overrides = []): void
    {
        $type = $field['field_type'] ?? 'textarea';
        $base_args = [
            'id'          => $field['meta_key'],
            'label'       => $context === 'product' ? $field['product_field_label'] : $field['variation_field_label'],
            'desc_tip'    => true,
            'description' => $context === 'product' ? $field['product_field_description'] : $field['variation_field_description'],
            'value'       => $value,
        ];

        $args = array_merge($base_args, $overrides);

        if ($type === 'text') {
            woocommerce_wp_text_input($args);
            return;
        }

        if ($type === 'number') {
            $args['type'] = 'number';
            $args['custom_attributes'] = array_merge(
                [
                    'step' => '0.01',
                    'min'  => '0',
                ],
                isset($field['number_attributes']) && is_array($field['number_attributes']) ? $field['number_attributes'] : []
            );
            woocommerce_wp_text_input($args);
            return;
        }

        $args['rows'] = $field['textarea_rows'] ?? 3;
        woocommerce_wp_textarea_input($args);
    }

    /**
     * @param array<string, mixed> $field
     */
    private function should_clear_organic_field($product_or_id, array $field): bool
    {
        if (!$this->is_organic_field($field)) {
            return false;
        }

        $product_id = $product_or_id instanceof \WC_Product ? $product_or_id->get_id() : (int) $product_or_id;
        if ($product_id <= 0) {
            return true;
        }

        return !$this->has_organic_label($product_id);
    }

    private function is_organic_field(array $field): bool
    {
        return in_array($field['slug'], ['organic_cert_number', 'organic_origin'], true);
    }

    private function has_organic_label(int $product_id): bool
    {
        $value = get_post_meta($product_id, '_lotzwoo_organic_label', true);
        if ($value === 'yes') {
            return true;
        }

        if ($value === 'no') {
            return false;
        }

        if (!function_exists('wc_get_product')) {
            return false;
        }

        $product = wc_get_product($product_id);
        if ($product instanceof \WC_Product_Variation) {
            $parent_id = $product->get_parent_id();
            if ($parent_id > 0) {
                return get_post_meta($parent_id, '_lotzwoo_organic_label', true) === 'yes';
            }
        }

        return false;
    }
}
