<?php

namespace Lotzwoo\Services;

use Lotzwoo\Admin\Successor_Product_Field;
use Lotzwoo\Plugin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Extends WooCommerce's built-in low-stock / out-of-stock notification emails
 * with Nachfolgeprodukt (successor) information.
 *
 * We do NOT send our own emails. WooCommerce already fires these notifications
 * based on its Inventory settings (enable toggles, low-stock threshold,
 * recipient under WooCommerce -> Settings -> Products -> Inventory). We only
 * rewrite the message body via WC's content filters.
 */
class Stock_Notification_Service
{
    public function boot(): void
    {
        if (!Plugin::opt('stock_notifications_enabled')) {
            return;
        }

        add_filter('woocommerce_email_content_low_stock', [$this, 'low_stock_content'], 10, 2);
        add_filter('woocommerce_email_content_no_stock', [$this, 'no_stock_content'], 10, 2);
    }

    /**
     * @param string $message
     * @param mixed  $product
     * @return string
     */
    public function low_stock_content($message, $product)
    {
        if (!$product instanceof \WC_Product) {
            return $message;
        }

        $name        = $product->get_name();
        $stock       = $product->get_stock_quantity();
        $stock_label = $stock === null ? '0' : (string) (int) $stock;
        $successor   = $this->successor_product($product);

        if ($successor instanceof \WC_Product) {
            return sprintf(
                /* translators: 1: product name, 2: remaining stock, 3: successor product name */
                __('Lagerstand von Produkt "%1$s" niedrig: Noch "%2$s" auf Lager. Nachfolgeprodukt: "%3$s"', 'lotzapp-for-woocommerce'),
                $name,
                $stock_label,
                $successor->get_name()
            );
        }

        return sprintf(
            /* translators: 1: product name, 2: remaining stock */
            __('Lagerstand von Produkt "%1$s" niedrig: Noch "%2$s" auf Lager. ACHTUNG: Kein Nachfolgeprodukt definiert!', 'lotzapp-for-woocommerce'),
            $name,
            $stock_label
        );
    }

    /**
     * @param string $message
     * @param mixed  $product
     * @return string
     */
    public function no_stock_content($message, $product)
    {
        if (!$product instanceof \WC_Product) {
            return $message;
        }

        $name      = $product->get_name();
        $successor = $this->successor_product($product);

        if ($successor instanceof \WC_Product) {
            $successor_stock = $successor->get_stock_quantity();
            $successor_label = $successor_stock === null
                ? __('unbekannt', 'lotzapp-for-woocommerce')
                : (string) (int) $successor_stock;

            if (!$this->successor_is_available($successor) || !$this->successor_has_current_menu_slot($product, $successor)) {
                return sprintf(
                    /* translators: 1: out-of-stock product name, 2: successor product name, 3: successor stock */
                    __('Produkt "%1$s" ausverkauft. ACHTUNG: Nachfolgeprodukt "%2$s" ist nicht aktiv, weil es keinen verfügbaren Lagerbestand hat (Lagerstand: "%3$s").', 'lotzapp-for-woocommerce'),
                    $name,
                    $successor->get_name(),
                    $successor_label
                );
            }

            return sprintf(
                /* translators: 1: out-of-stock product name, 2: successor product name, 3: successor stock */
                __('Produkt "%1$s" ausverkauft. Nachfolgeprodukt "%2$s" ab sofort aktiv (Lagerstand: "%3$s")', 'lotzapp-for-woocommerce'),
                $name,
                $successor->get_name(),
                $successor_label
            );
        }

        return sprintf(
            /* translators: 1: out-of-stock product name */
            __('Produkt "%1$s" ausverkauft. ACHTUNG: Kein Nachfolgeprodukt definiert!', 'lotzapp-for-woocommerce'),
            $name
        );
    }

    private function successor_product(\WC_Product $product): ?\WC_Product
    {
        $id = (int) get_post_meta($product->get_id(), Successor_Product_Field::META_KEY, true);
        if ($id <= 0) {
            return null;
        }
        $successor = wc_get_product($id);

        return $successor instanceof \WC_Product ? $successor : null;
    }

    private function successor_is_available(\WC_Product $successor): bool
    {
        if ($successor->get_status() !== 'publish') {
            return false;
        }

        $stock = $successor->get_stock_quantity();
        if ($stock === null) {
            return true;
        }

        if ((int) $stock <= 0) {
            return false;
        }

        $reserved = function_exists('wc_get_held_stock_quantity')
            ? (int) wc_get_held_stock_quantity($successor)
            : 0;

        return ((int) $stock - $reserved) > 0;
    }

    private function successor_has_current_menu_slot(\WC_Product $product, \WC_Product $successor): bool
    {
        $product_term_ids = $this->current_menu_term_ids($product);
        if (empty($product_term_ids)) {
            return false;
        }

        $successor_term_ids = wp_get_object_terms((int) $successor->get_id(), 'product_tag', ['fields' => 'ids']);
        if (!is_array($successor_term_ids)) {
            return false;
        }

        return !empty(array_intersect($product_term_ids, array_map('intval', $successor_term_ids)));
    }

    /**
     * @return array<int, int>
     */
    private function current_menu_term_ids(\WC_Product $product): array
    {
        $terms = wp_get_object_terms((int) $product->get_id(), 'product_tag');
        if (!is_array($terms)) {
            return [];
        }

        $term_ids = [];
        foreach ($terms as $term) {
            if (isset($term->slug) && strpos((string) $term->slug, 'currentmenu_') === 0) {
                $term_ids[] = (int) $term->term_id;
            }
        }

        return $term_ids;
    }
}
