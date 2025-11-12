<?php

namespace Lotzwoo\Assets;

if (!defined('ABSPATH')) {
    exit;
}

class Image_Management
{
    private const SCRIPT_HANDLE = 'lotzwoo-image-management';
    private const STYLE_HANDLE  = 'lotzwoo-image-management-style';
    private const BASE_STYLE_HANDLE = 'lotzwoo-shortcode-base';
    private const BASE_STYLE_VERSION = '0.1.0';

    private bool $enqueued = false;

    public function enqueue(): void
    {
        if ($this->enqueued) {
            return;
        }

        if (function_exists('wp_enqueue_media')) {
            wp_enqueue_media();
        }

        $script_handle = self::SCRIPT_HANDLE;
        $script_src    = trailingslashit(LOTZWOO_PLUGIN_URL) . 'assets/js/product-image-management.js';
        $style_handle  = self::STYLE_HANDLE;
        $style_src     = trailingslashit(LOTZWOO_PLUGIN_URL) . 'assets/css/product-image-management.css';
        $base_style_handle = $this->enqueue_base_style();

        if (!wp_script_is($script_handle, 'registered')) {
            wp_register_script(
                $script_handle,
                $script_src,
                ['jquery', 'wp-util'],
                '0.1.0',
                true
            );
        }

        if (!wp_style_is($style_handle, 'registered')) {
            wp_register_style(
                $style_handle,
                $style_src,
                [$base_style_handle],
                '0.1.0'
            );
        }

        wp_localize_script(
            $script_handle,
            'lotzwooImageManagement',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('lotzwoo_image_management'),
                'texts'   => [
                    'featuredTitle' => __('Produktbild auswählen', 'lotzapp-for-woocommerce'),
                    'featuredButton'=> __('Bild übernehmen', 'lotzapp-for-woocommerce'),
                    'galleryTitle'  => __('Galeriebilder auswählen', 'lotzapp-for-woocommerce'),
                    'galleryButton' => __('Galerie aktualisieren', 'lotzapp-for-woocommerce'),
                    'errorMessage'  => __('Beim Speichern ist ein Fehler aufgetreten.', 'lotzapp-for-woocommerce'),
                    'invalidFile'   => __('Bitte nur JPG- oder WEBP-Bilder ablegen.', 'lotzapp-for-woocommerce'),
                ],
            ]
        );

        wp_enqueue_script($script_handle);
        wp_enqueue_style($style_handle);

        $this->enqueued = true;
    }

    private function enqueue_base_style(): string
    {
        $handle = self::BASE_STYLE_HANDLE;
        if (!wp_style_is($handle, 'registered')) {
            wp_register_style(
                $handle,
                trailingslashit(LOTZWOO_PLUGIN_URL) . 'assets/css/shortcode-base.css',
                [],
                self::BASE_STYLE_VERSION
            );
        }

        wp_enqueue_style($handle);

        return $handle;
    }
}
