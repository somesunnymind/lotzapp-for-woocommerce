<?php

namespace Lotzwoo\Services;

use Lotzwoo\Settings\Repository;

if (!defined('ABSPATH')) {
    exit;
}

class Product_Media_Service
{
    private Repository $repository;

    public function __construct(Repository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function get_items(): array
    {
        if (!function_exists('wc_get_products')) {
            return [];
        }

        $items      = [];
        $buffer_id  = (int) $this->repository->get('buffer_product_id', 0);
        $query_args = [
            'status'  => ['publish', 'pending', 'draft', 'private'],
            'type'    => ['simple', 'variable'],
            'limit'   => -1,
            'orderby' => 'title',
            'order'   => 'ASC',
            'return'  => 'objects',
        ];

        if ($buffer_id > 0) {
            $query_args['exclude'] = [$buffer_id];
        }

        $products = wc_get_products($query_args);
        foreach ($products as $product) {
            if (!$product instanceof \WC_Product) {
                continue;
            }

            if (in_array($product->get_status(), ['trash', 'auto-draft'], true)) {
                continue;
            }

            $items[] = $this->prepare_entry($product);

            if ($product->is_type('variable')) {
                foreach ($product->get_children() as $child_id) {
                    $variation = wc_get_product($child_id);
                    if (!$variation instanceof \WC_Product_Variation) {
                        continue;
                    }

                    if (in_array($variation->get_status(), ['trash', 'auto-draft'], true)) {
                        continue;
                    }

                    $items[] = $this->prepare_entry($variation);
                }
            }
        }

        if ($buffer_id > 0) {
            $items = array_values(array_filter(
                $items,
                static function (array $item) use ($buffer_id): bool {
                    $item_id  = isset($item['id']) ? (int) $item['id'] : 0;
                    $group_id = isset($item['group_id']) ? (int) $item['group_id'] : $item_id;

                    return $item_id !== $buffer_id && $group_id !== $buffer_id;
                }
            ));
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function prepare_entry(\WC_Product $product): array
    {
        $is_variation = $product instanceof \WC_Product_Variation;
        $label        = $is_variation ? wp_strip_all_tags($product->get_formatted_name()) : $product->get_name();
        $group_id     = $is_variation ? (int) $product->get_parent_id() : $product->get_id();

        if ($group_id <= 0) {
            $group_id = $product->get_id();
        }

        return [
            'id'          => $product->get_id(),
            'product'     => $product,
            'object_type' => $is_variation ? 'variation' : 'product',
            'type_slug'   => $is_variation ? 'variation' : $product->get_type(),
            'type_label'  => $is_variation ? __('Variante', 'lotzapp-for-woocommerce') : $this->get_product_type_label($product->get_type()),
            'label'       => $label,
            'group_id'    => $group_id,
            'is_parent'   => $is_variation ? 0 : 1,
        ];
    }

    private function get_product_type_label(string $type): string
    {
        $map = [
            'simple'   => __('Einfach', 'lotzapp-for-woocommerce'),
            'variable' => __('Variabel', 'lotzapp-for-woocommerce'),
        ];

        if (isset($map[$type])) {
            return $map[$type];
        }

        if (function_exists('wc_get_product_type_label')) {
            $label = wc_get_product_type_label($type);
            if ($label) {
                return $label;
            }
        }

        return ucfirst($type);
    }

    public function render_featured_cell_content(\WC_Product $product): string
    {
        $attachment_id = (int) $product->get_image_id();

        if ($attachment_id > 0) {
            $alt   = $product instanceof \WC_Product_Variation ? wp_strip_all_tags($product->get_formatted_name()) : $product->get_name();
            $image = wp_get_attachment_image($attachment_id, 'full', false, [
                'class' => 'lotzwoo-image-management__thumbnail',
                'alt'   => $alt,
            ]);

            if ($image) {
                $button_label = esc_attr__('Bild entfernen', 'lotzapp-for-woocommerce');
                return sprintf(
                    '<span class="lotzwoo-image-management__media" data-attachment-id="%1$d">%2$s<button type="button" class="lotzwoo-image-management__remove" data-remove-field="featured" aria-label="%3$s">%4$s</button></span>',
                    $attachment_id,
                    $image,
                    $button_label,
                    $this->remove_icon_markup()
                );
            }
        }

        $label = __('Bild <u>auswählen</u><br><small>oder hier ablegen</small>', 'lotzapp-for-woocommerce');
        $label = wp_kses($label, ['u' => [], 'br' => [], 'small' => []]);
        return sprintf('<span class="lotzwoo-image-management__placeholder">%s</span>', $label);
    }

    public function render_gallery_cell_content(\WC_Product $product): string
    {
        if ($product instanceof \WC_Product_Variation) {
            $message = __('Galeriebilder sind für Varianten nicht verfügbar.', 'lotzapp-for-woocommerce');
            return sprintf('<span class="lotzwoo-image-management__placeholder">%s</span>', esc_html($message));
        }

        $ids = $product->get_gallery_image_ids();
        if (!is_array($ids) || empty($ids)) {
            $label = __('Galeriebilder <u>auswählen</u><br><small>oder hier ablegen</small>', 'lotzapp-for-woocommerce');
            $label = wp_kses($label, ['u' => [], 'br' => [], 'small' => []]);
            return sprintf('<span class="lotzwoo-image-management__placeholder">%s</span>', $label);
        }

        $items        = [];
        $button_label = esc_attr__('Galeriebild entfernen', 'lotzapp-for-woocommerce');
        $icon         = $this->remove_icon_markup();

        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }

            $image = wp_get_attachment_image($id, 'full', false, [
                'class' => 'lotzwoo-image-management__thumbnail',
                'alt'   => $product->get_name(),
            ]);

            if ($image) {
                $items[] = sprintf(
                    '<span class="lotzwoo-image-management__gallery-item" draggable="true" data-attachment-id="%1$d">%2$s<button type="button" class="lotzwoo-image-management__remove" data-remove-field="gallery" data-attachment-id="%1$d" aria-label="%3$s">%4$s</button></span>',
                    $id,
                    $image,
                    $button_label,
                    $icon
                );
            }
        }

        if (empty($items)) {
            $label = __('Galeriebilder <u>auswählen</u><br><small>oder hier ablegen</small>', 'lotzapp-for-woocommerce');
            $label = wp_kses($label, ['u' => [], 'br' => [], 'small' => []]);
            return sprintf('<span class="lotzwoo-image-management__placeholder">%s</span>', $label);
        }

        return sprintf('<div class="lotzwoo-image-management__gallery">%s</div>', implode('', $items));
    }

    public function remove_icon_markup(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" role="img" aria-hidden="true"><path d="M9 3a1 1 0 0 0-.894.553L7.382 5H4a1 1 0 1 0 0 2h1v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7h1a1 1 0 1 0 0-2h-3.382l-.724-1.447A1 1 0 0 0 15 3H9Zm1.618 2h2.764l.5 1h-3.764l.5-1ZM9 9a1 1 0 1 0-2 0v8a1 1 0 1 0 2 0V9Zm4 0a1 1 0 0 0-2 0v8a1 1 0 0 0 2 0V9Zm2-1a1 1 0 0 1 2 0v8a1 1 0 1 1-2 0V8Z"/></svg>';
    }
}
