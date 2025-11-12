<?php

namespace Lotzwoo\Ajax;

use Lotzwoo\Services\Product_Media_Service;

if (!defined('ABSPATH')) {
    exit;
}

class Product_Media_Controller
{
    private Product_Media_Service $media_service;

    public function __construct(Product_Media_Service $media_service)
    {
        $this->media_service = $media_service;
    }

    public function register(): void
    {
        add_action('wp_ajax_lotzwoo_update_product_media', [$this, 'handle_update']);
        add_action('wp_ajax_lotzwoo_upload_product_media', [$this, 'handle_upload']);
    }

    public function handle_update(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Nicht erlaubt.', 'lotzapp-for-woocommerce')], 403);
        }

        check_ajax_referer('lotzwoo_image_management', 'nonce');

        $object_id   = isset($_POST['objectId']) ? absint($_POST['objectId']) : 0;
        $object_type = isset($_POST['objectType']) ? sanitize_key((string) wp_unslash($_POST['objectType'])) : '';
        $field       = isset($_POST['field']) ? sanitize_key((string) wp_unslash($_POST['field'])) : '';

        if (!$object_id || !in_array($object_type, ['product', 'variation'], true) || !in_array($field, ['featured', 'gallery'], true)) {
            wp_send_json_error(['message' => __('Ungültige Anfrage.', 'lotzapp-for-woocommerce')], 400);
        }

        $product = wc_get_product($object_id);
        if (!$product instanceof \WC_Product) {
            wp_send_json_error(['message' => __('Produkt nicht gefunden.', 'lotzapp-for-woocommerce')], 404);
        }

        $is_variation = $product instanceof \WC_Product_Variation;
        if (($is_variation && $object_type !== 'variation') || (!$is_variation && $object_type !== 'product')) {
            wp_send_json_error(['message' => __('Produktzuordnung ungültig.', 'lotzapp-for-woocommerce')], 400);
        }

        if ($field === 'gallery' && $is_variation) {
            wp_send_json_error(['message' => __('Varianten unterstützen keine Galerie.', 'lotzapp-for-woocommerce')], 400);
        }

        if ($field === 'featured') {
            $attachment_id = isset($_POST['attachmentId']) ? absint($_POST['attachmentId']) : 0;
            if ($attachment_id > 0) {
                set_post_thumbnail($object_id, $attachment_id);
            } else {
                delete_post_thumbnail($object_id);
            }
        } else {
            $ids_raw = isset($_POST['attachmentIds']) ? (array) wp_unslash($_POST['attachmentIds']) : [];
            $ids     = array_values(array_filter(array_map('absint', $ids_raw)));
            update_post_meta($object_id, '_product_image_gallery', implode(',', $ids));
        }

        wc_delete_product_transients($object_id);
        clean_post_cache($object_id);

        if ($is_variation) {
            $parent_id = (int) $product->get_parent_id();
            if ($parent_id > 0) {
                wc_delete_product_transients($parent_id);
                clean_post_cache($parent_id);
            }
        }

        $fresh_product = wc_get_product($object_id);
        if (!$fresh_product instanceof \WC_Product) {
            $fresh_product = $product;
        }

        if ($field === 'featured') {
            wp_send_json_success([
                'html' => $this->media_service->render_featured_cell_content($fresh_product),
                'id'   => (int) $fresh_product->get_image_id(),
            ]);
        }

        wp_send_json_success([
            'html' => $this->media_service->render_gallery_cell_content($fresh_product),
            'ids'  => array_map('intval', $fresh_product->get_gallery_image_ids()),
        ]);
    }

    public function handle_upload(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Nicht erlaubt.', 'lotzapp-for-woocommerce')], 403);
        }

        check_ajax_referer('lotzwoo_image_management', 'nonce');

        if (empty($_FILES['file'])) {
            wp_send_json_error(['message' => __('Keine Datei empfangen.', 'lotzapp-for-woocommerce')], 400);
        }

        $file = $_FILES['file'];
        if (!is_array($file) || empty($file['tmp_name']) || !is_uploaded_file((string) $file['tmp_name'])) {
            wp_send_json_error(['message' => __('Die Datei konnte nicht verarbeitet werden.', 'lotzapp-for-woocommerce')], 400);
        }

        $allowed = [
            'jpg|jpeg|jpe' => 'image/jpeg',
            'webp'         => 'image/webp',
        ];

        $filetype = wp_check_filetype_and_ext($file['tmp_name'], $file['name'], $allowed);
        if (empty($filetype['type']) || empty($filetype['ext']) || !in_array($filetype['type'], $allowed, true)) {
            wp_send_json_error(['message' => __('Ungültiger Dateityp. Bitte JPG oder WEBP verwenden.', 'lotzapp-for-woocommerce')], 400);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $overrides = [
            'test_form' => false,
            'mimes'     => $allowed,
        ];

        $attachment_id = media_handle_upload('file', 0, [], $overrides);
        if (is_wp_error($attachment_id)) {
            wp_send_json_error(['message' => $attachment_id->get_error_message()], 400);
        }

        $file_path = get_attached_file($attachment_id);
        $data      = [
            'id'       => (int) $attachment_id,
            'url'      => wp_get_attachment_url($attachment_id),
            'filename' => $file_path ? basename($file_path) : '',
        ];

        wp_send_json_success($data);
    }

}
