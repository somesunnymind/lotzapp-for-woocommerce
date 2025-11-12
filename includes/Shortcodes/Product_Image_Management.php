<?php

namespace Lotzwoo\Shortcodes;

use Lotzwoo\Assets\Image_Management;
use Lotzwoo\Services\Product_Media_Service;

if (!defined('ABSPATH')) {
    exit;
}

class Product_Image_Management
{
    private Product_Media_Service $media_service;
    private Image_Management $assets;

    public function __construct(Product_Media_Service $media_service, Image_Management $assets)
    {
        $this->media_service = $media_service;
        $this->assets        = $assets;
    }

    public function register(): void
    {
        add_shortcode('lotzwoo_product_image_management', [$this, 'render']);
    }

    public function render($atts = [], $content = '', $tag = ''): string
    {
        if (!current_user_can('manage_woocommerce')) {
            return '<p>' . esc_html__('Diese Ansicht erfordert die Berechtigung zum Verwalten von WooCommerce.', 'lotzapp-for-woocommerce') . '</p>';
        }

        if (!function_exists('wc_get_products')) {
            return '<p>' . esc_html__('WooCommerce ist erforderlich, um die Bildverwaltung zu nutzen.', 'lotzapp-for-woocommerce') . '</p>';
        }

        $items = $this->media_service->get_items();
        if (empty($items)) {
            return '<p>' . esc_html__('Keine Produkte gefunden.', 'lotzapp-for-woocommerce') . '</p>';
        }

        $this->assets->enqueue();

        return $this->render_template([
            'items'         => $items,
            'media_service' => $this->media_service,
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render_template(array $context): string
    {
        $template = trailingslashit(LOTZWOO_PLUGIN_DIR) . 'templates/shortcodes/product-image-management.php';
        if (!file_exists($template)) {
            return '';
        }

        ob_start();
        /** @var array<int, array<string, mixed>> $items */
        $items = $context['items'];
        /** @var Product_Media_Service $media_service */
        $media_service = $context['media_service'];

        include $template;

        return (string) ob_get_clean();
    }
}

