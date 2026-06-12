<?php

namespace Lotzwoo\Services;

use Lotzwoo\Admin\Successor_Product_Field;
use Lotzwoo\Plugin;
use DateTimeImmutable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * When a product that is currently "online" in the menu plan reaches stock 0,
 * move its currentmenu_* product tags to the configured successor product so
 * that the successor takes its place.
 */
class Product_Succession_Service
{
    private const META_DONE = '_lotzwoo_succession_done';
    private const META_RESERVATION_STATE = '_lotzwoo_reservation_succession_state';
    private const WARNING_TRANSIENT_PREFIX = 'lotzwoo_succession_warning_';

    private Menu_Planning_Service $menu_service;
    /**
     * @var array<int, bool>
     */
    private array $deferred_unpaid_order_stock_product_ids = [];

    public function __construct(Menu_Planning_Service $menu_service)
    {
        $this->menu_service = $menu_service;
    }

    public function boot(): void
    {
        if (!Plugin::opt('product_succession_enabled')) {
            return;
        }
        if (get_option('woocommerce_manage_stock') !== 'yes') {
            return;
        }
        // Fires whenever WC writes a new stock quantity (via order, REST, admin, etc.).
        add_action('woocommerce_product_set_stock', [$this, 'on_stock_change'], 30, 1);
        // Backstop: variations / external code that only flips the status.
        add_action('woocommerce_product_set_stock_status', [$this, 'on_stock_status_change'], 30, 3);
        add_filter('woocommerce_can_reduce_order_stock', [$this, 'remember_unpaid_order_stock_reduction'], 10, 2);
        add_action('woocommerce_reduce_order_stock', [$this, 'on_order_stock_reduced'], 20, 1);
        // Product edit screens can save stale taxonomy selections after a stock
        // change. Re-assert completed successor swaps after the product save.
        add_action('save_post_product', [$this, 'on_product_saved'], 100, 3);
        add_action('admin_init', [$this, 'repair_active_successor_collisions'], 50);
        add_action('wp_ajax_lotzwoo_menu_plan_list', [$this, 'repair_active_successor_collisions'], 5);

        add_action('woocommerce_checkout_order_created', [$this, 'on_order_stock_reserved'], 20, 1);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'on_order_stock_reserved'], 20, 1);

        add_action('woocommerce_checkout_order_exception', [$this, 'on_order_stock_released'], 20, 1);
        add_action('woocommerce_payment_complete', [$this, 'on_order_stock_released'], 20, 1);
        add_action('woocommerce_order_status_cancelled', [$this, 'on_order_stock_released'], 20, 1);
        add_action('woocommerce_order_status_completed', [$this, 'on_order_stock_released'], 20, 1);
        add_action('woocommerce_order_status_processing', [$this, 'on_order_stock_released'], 20, 1);
        add_action('woocommerce_order_status_on-hold', [$this, 'on_order_stock_released'], 20, 1);

        add_action('admin_notices', [$this, 'render_admin_notices']);
    }

    public function on_stock_change(\WC_Product $product): void
    {
        $stock = $product->get_stock_quantity();
        if ($stock === null) {
            return; // Stock not managed for this product.
        }
        if ((int) $stock > 0) {
            $this->enforce_completed_stock_swap($product);
            $this->maybe_clear_stale_stock_swap_marker($product);
            if ($this->effective_stock($product) > 0) {
                $this->maybe_restore_reservation_swap($product);
            }
            return;
        }
        if ($this->is_deferred_unpaid_order_stock_product((int) $product->get_id())) {
            return;
        }
        $this->swap_with_successor($product, 'stock');
    }

    public function on_product_saved(int $post_id, $post, bool $update): void
    {
        unset($post, $update);

        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($post_id)) {
            return;
        }

        $product = wc_get_product($post_id);
        if (!$product instanceof \WC_Product) {
            return;
        }

        $this->enforce_completed_stock_swap($product);
        $this->maybe_clear_stale_stock_swap_marker($product);
    }

    public function repair_active_successor_collisions(): void
    {
        $term_ids = $this->current_menu_term_ids();
        if (empty($term_ids)) {
            return;
        }

        $product_ids = get_posts([
            'post_type'        => 'product',
            'post_status'      => ['publish', 'pending', 'draft', 'private'],
            'numberposts'      => -1,
            'fields'           => 'ids',
            'tax_query'        => [
                [
                    'taxonomy' => 'product_tag',
                    'terms'    => $term_ids,
                    'operator' => 'IN',
                ],
            ],
            'suppress_filters' => false,
        ]);

        if (!is_array($product_ids) || empty($product_ids)) {
            return;
        }

        foreach ($product_ids as $product_id) {
            $product = wc_get_product((int) $product_id);
            if ($product instanceof \WC_Product) {
                $this->retry_blocked_successor_swap($product);
                $this->enforce_completed_stock_swap($product);
                $this->repair_successor_pair_collision($product);
            }
        }

        $this->repair_cancelled_unpaid_successor_swaps();
    }

    /**
     * Signature: do_action('woocommerce_product_set_stock_status', $product_id, $stock_status, $product)
     * in newer WC versions; older signatures pass only $product_id and $stock_status.
     */
    public function on_stock_status_change(int $product_id, string $stock_status, $product = null): void
    {
        if ($stock_status !== 'outofstock') {
            return;
        }
        if (!$product instanceof \WC_Product) {
            $product = wc_get_product($product_id);
        }
        if (!$product instanceof \WC_Product) {
            return;
        }
        if ($this->is_deferred_unpaid_order_stock_product((int) $product->get_id())) {
            return;
        }
        $this->swap_with_successor($product, 'stock');
    }

    public function remember_unpaid_order_stock_reduction(bool $can_reduce, $order): bool
    {
        $order = $order instanceof \WC_Order ? $order : wc_get_order($order);
        if (!$can_reduce || !$order instanceof \WC_Order || !$this->should_treat_unpaid_order_as_reservation($order)) {
            return $can_reduce;
        }

        foreach ($this->products_from_order($order) as $product) {
            $this->deferred_unpaid_order_stock_product_ids[(int) $product->get_id()] = true;
        }

        return $can_reduce;
    }

    public function on_order_stock_reduced($order): void
    {
        $order = $order instanceof \WC_Order ? $order : wc_get_order($order);
        if (!$order instanceof \WC_Order) {
            $this->deferred_unpaid_order_stock_product_ids = [];
            return;
        }

        foreach ($this->products_from_order($order) as $product) {
            $stock = $product->get_stock_quantity();
            if ($stock === null) {
                continue;
            }

            if ((int) $stock <= 0) {
                $reason = $this->should_treat_unpaid_order_as_reservation($order) ? 'reservation' : 'stock';
                $this->swap_with_successor($product, $reason, (int) $order->get_id());
            }
        }

        $this->deferred_unpaid_order_stock_product_ids = [];
    }

    public function on_order_stock_reserved($order): void
    {
        $order = $order instanceof \WC_Order ? $order : wc_get_order($order);
        if (!$order instanceof \WC_Order) {
            return;
        }

        foreach ($this->products_from_order($order) as $product) {
            $stock = $product->get_stock_quantity();
            if ($stock === null) {
                continue;
            }

            if ((int) $stock <= 0) {
                $reason = $this->should_treat_unpaid_order_as_reservation($order) ? 'reservation' : 'stock';
                $this->swap_with_successor($product, $reason, (int) $order->get_id());
                continue;
            }

            if ($this->effective_stock($product) <= 0) {
                $this->swap_with_successor($product, 'reservation', (int) $order->get_id());
            }
        }
    }

    public function on_order_stock_released($order): void
    {
        $order = $order instanceof \WC_Order ? $order : wc_get_order($order);
        if (!$order instanceof \WC_Order) {
            return;
        }

        if ($this->is_active_unpaid_order_status($order)) {
            return;
        }

        foreach ($this->products_from_order($order) as $product) {
            $stock = $product->get_stock_quantity();
            if ($stock === null) {
                continue;
            }

            if ((int) $stock <= 0) {
                $this->promote_reservation_swap_to_stock_swap($product);
                continue;
            }

            if ($this->effective_stock($product) > 0) {
                if (!$this->maybe_restore_reservation_swap($product)) {
                    $this->restore_unpaid_order_swap($product, $order);
                }
            }
        }
    }

    private function swap_with_successor(\WC_Product $product, string $reason, ?int $order_id = null): bool
    {
        $product_id = (int) $product->get_id();

        // Bail if we've already swapped for this exhaustion event.
        $this->maybe_clear_stale_stock_swap_marker($product);
        $sentinel = $product->get_meta(self::META_DONE, true);
        if ($this->is_completed_stock_swap_marker($sentinel) || $sentinel === 'no-successor') {
            return false;
        }
        if ($reason === 'reservation' && is_array($product->get_meta(self::META_RESERVATION_STATE, true))) {
            return false;
        }

        // Find which currentmenu_* tags this product is currently in.
        $terms = wp_get_object_terms($product_id, 'product_tag');
        if (is_wp_error($terms) || !is_array($terms)) {
            return false;
        }
        $current_menu_terms = [];
        foreach ($terms as $term) {
            if (isset($term->slug) && strpos((string) $term->slug, 'currentmenu_') === 0) {
                $current_menu_terms[] = $term;
            }
        }
        if (empty($current_menu_terms)) {
            return false; // Product isn't part of any active menu plan.
        }
        $current_menu_terms = $this->filter_terms_by_current_entry_payload($product_id, $current_menu_terms);

        $successor_id = (int) get_post_meta($product_id, Successor_Product_Field::META_KEY, true);
        if ($successor_id <= 0 || $successor_id === $product_id) {
            // Mark so we don't keep checking on subsequent stock writes for this
            // product while the "no successor configured" state holds.
            $product->update_meta_data(self::META_DONE, 'no-successor');
            $product->save_meta_data();
            return false;
        }

        $successor = wc_get_product($successor_id);
        if (!$successor instanceof \WC_Product || $successor->get_status() !== 'publish') {
            return false;
        }

        if (!$this->is_successor_available($successor)) {
            $this->notify_admin_successor_unavailable($product, $successor, $reason);
            return false;
        }

        $term_ids = array_map(static function ($term) {
            return (int) $term->term_id;
        }, $current_menu_terms);
        $term_slugs = array_map(static function ($term) {
            return isset($term->slug) ? (string) $term->slug : '';
        }, $current_menu_terms);

        $reservation_state = [];
        if ($reason === 'reservation') {
            $reservation_state = $this->build_reservation_state($product, $successor, $term_ids, $term_slugs, $order_id);
        }

        // Add tags to the successor (append=true so existing tags are preserved),
        // then remove them from the depleted product.
        wp_set_object_terms($successor_id, $term_ids, 'product_tag', true);
        wp_remove_object_terms($product_id, $term_ids, 'product_tag');
        if (!$this->menu_service->replace_product_in_current_entry($product_id, $successor_id, $term_slugs)) {
            $this->menu_service->sync_current_entry_payload_from_terms($term_slugs);
        }

        clean_post_cache($successor_id);
        clean_post_cache($product_id);

        if ($reason === 'reservation') {
            $product->update_meta_data(self::META_RESERVATION_STATE, $reservation_state);
        } else {
            $product->update_meta_data(self::META_DONE, $this->build_stock_swap_state($product, $successor, $term_ids, $term_slugs, $order_id));
        }
        $product->save_meta_data();

        do_action('lotzwoo_product_succession_applied', $product, $successor, $term_ids);

        return true;
    }

    private function enforce_completed_stock_swap(\WC_Product $product): bool
    {
        $marker = $product->get_meta(self::META_DONE, true);
        if (!$this->is_completed_stock_swap_marker($marker)) {
            return false;
        }

        if (!$this->marker_belongs_to_current_entry($marker)) {
            $product->delete_meta_data(self::META_DONE);
            $product->save_meta_data();
            return false;
        }

        $product_id   = (int) $product->get_id();
        $successor_id = $this->successor_id_from_marker_or_product($marker, $product_id);
        if ($successor_id <= 0 || $successor_id === $product_id) {
            return false;
        }

        $successor = wc_get_product($successor_id);
        if (!$successor instanceof \WC_Product) {
            return false;
        }

        $product_terms = $this->current_menu_terms_for_product($product_id);
        if (empty($product_terms)) {
            return false;
        }

        $marker_slugs = $this->term_slugs_from_marker($marker);
        $target_terms = [];
        foreach ($product_terms as $term) {
            $slug = isset($term->slug) ? (string) $term->slug : '';
            if (!empty($marker_slugs) && !in_array($slug, $marker_slugs, true)) {
                continue;
            }
            $target_terms[] = $term;
        }

        if (empty($target_terms)) {
            return false;
        }

        $term_ids = array_map(static function ($term) {
            return (int) $term->term_id;
        }, $target_terms);
        $term_slugs = array_map(static function ($term) {
            return isset($term->slug) ? (string) $term->slug : '';
        }, $target_terms);

        $stock = $product->get_stock_quantity();
        if ($stock !== null && (int) $stock > 0 && !$this->product_has_any_terms($successor_id, $term_ids)) {
            $product->delete_meta_data(self::META_DONE);
            $product->save_meta_data();
            clean_post_cache($product_id);
            return false;
        }

        wp_set_object_terms($successor_id, $term_ids, 'product_tag', true);
        wp_remove_object_terms($product_id, $term_ids, 'product_tag');

        if (!$this->menu_service->replace_product_in_current_entry($product_id, $successor_id, $term_slugs)) {
            $this->menu_service->sync_current_entry_payload_from_terms($term_slugs);
        }

        if (!is_array($marker)) {
            $product->update_meta_data(self::META_DONE, $this->build_stock_swap_state($product, $successor, $term_ids, $term_slugs, null));
            $product->save_meta_data();
        }

        clean_post_cache($successor_id);
        clean_post_cache($product_id);

        return true;
    }

    private function repair_successor_pair_collision(\WC_Product $product): bool
    {
        $product_id = (int) $product->get_id();
        $successor_id = (int) get_post_meta($product_id, Successor_Product_Field::META_KEY, true);
        if ($successor_id <= 0 || $successor_id === $product_id) {
            return false;
        }

        $successor = wc_get_product($successor_id);
        if (!$successor instanceof \WC_Product) {
            return false;
        }

        $product_terms = $this->current_menu_terms_for_product($product_id);
        if (empty($product_terms)) {
            return false;
        }

        $successor_terms = wp_get_object_terms($successor_id, 'product_tag');
        if (is_wp_error($successor_terms) || !is_array($successor_terms) || empty($successor_terms)) {
            return false;
        }

        $successor_term_ids = [];
        foreach ($successor_terms as $term) {
            if (isset($term->slug) && strpos((string) $term->slug, 'currentmenu_') === 0) {
                $successor_term_ids[] = (int) $term->term_id;
            }
        }

        if (empty($successor_term_ids)) {
            return false;
        }

        $collision_terms = [];
        foreach ($product_terms as $term) {
            if (in_array((int) $term->term_id, $successor_term_ids, true)) {
                $collision_terms[] = $term;
            }
        }

        if (empty($collision_terms)) {
            return false;
        }

        $term_ids = array_map(static function ($term) {
            return (int) $term->term_id;
        }, $collision_terms);
        $term_slugs = array_map(static function ($term) {
            return isset($term->slug) ? (string) $term->slug : '';
        }, $collision_terms);

        wp_remove_object_terms($product_id, $term_ids, 'product_tag');
        wp_set_object_terms($successor_id, $term_ids, 'product_tag', true);

        if (!$this->menu_service->replace_product_in_current_entry($product_id, $successor_id, $term_slugs)) {
            $this->menu_service->sync_current_entry_payload_from_terms($term_slugs);
        }

        $product->update_meta_data(self::META_DONE, $this->build_stock_swap_state($product, $successor, $term_ids, $term_slugs, null));
        $product->save_meta_data();

        clean_post_cache($successor_id);
        clean_post_cache($product_id);

        return true;
    }

    private function retry_blocked_successor_swap(\WC_Product $product): bool
    {
        $stock = $product->get_stock_quantity();
        if ($stock === null || (int) $stock > 0) {
            return false;
        }

        if (empty($this->current_menu_terms_for_product((int) $product->get_id()))) {
            return false;
        }

        $sentinel = $product->get_meta(self::META_DONE, true);
        if ($sentinel === 'no-successor') {
            $successor_id = (int) get_post_meta((int) $product->get_id(), Successor_Product_Field::META_KEY, true);
            if ($successor_id > 0 && $successor_id !== (int) $product->get_id()) {
                $product->delete_meta_data(self::META_DONE);
                $product->save_meta_data();
            }
        }

        if ($this->is_completed_stock_swap_marker($sentinel)) {
            return $this->enforce_completed_stock_swap($product);
        }

        $active_unpaid_order_id = $this->active_unpaid_order_id_for_product($product);
        if ($active_unpaid_order_id > 0) {
            return $this->swap_with_successor($product, 'reservation', $active_unpaid_order_id);
        }

        return $this->swap_with_successor($product, 'stock');
    }

    private function restore_unpaid_order_swap(\WC_Product $product, \WC_Order $order): bool
    {
        if (!$this->unpaid_orders_as_reserved_enabled() || $order->is_paid() || !$order->has_status(['cancelled', 'pending'])) {
            return false;
        }

        $reservation_state = $product->get_meta(self::META_RESERVATION_STATE, true);
        $stock_marker = $product->get_meta(self::META_DONE, true);
        if (!$this->state_matches_order($reservation_state, $order) && !$this->legacy_or_missing_marker_matches_order($stock_marker, $order)) {
            return false;
        }

        $product_id = (int) $product->get_id();
        $successor_id = (int) get_post_meta($product_id, Successor_Product_Field::META_KEY, true);
        if ($successor_id <= 0 || $successor_id === $product_id) {
            return false;
        }

        $successor = wc_get_product($successor_id);
        if (!$successor instanceof \WC_Product) {
            return false;
        }

        $successor_terms = $this->current_menu_terms_for_product($successor_id);
        if (empty($successor_terms)) {
            return false;
        }

        $marker_slugs = $this->term_slugs_from_marker($reservation_state);
        $candidate_terms = $this->filter_terms_by_current_entry_payload($successor_id, $successor_terms);
        $target_terms = [];

        foreach ($candidate_terms as $term) {
            $slug = isset($term->slug) ? (string) $term->slug : '';
            if (!empty($marker_slugs) && !in_array($slug, $marker_slugs, true)) {
                continue;
            }
            $target_terms[] = $term;
        }

        if (empty($target_terms)) {
            return false;
        }

        $term_ids = array_map(static function ($term) {
            return (int) $term->term_id;
        }, $target_terms);
        $term_slugs = array_map(static function ($term) {
            return isset($term->slug) ? (string) $term->slug : '';
        }, $target_terms);

        wp_set_object_terms($product_id, $term_ids, 'product_tag', true);
        wp_remove_object_terms($successor_id, $term_ids, 'product_tag');

        if (!$this->menu_service->replace_product_in_current_entry($successor_id, $product_id, $term_slugs)) {
            $this->menu_service->sync_current_entry_payload_from_terms($term_slugs);
        }

        $product->delete_meta_data(self::META_DONE);
        $product->delete_meta_data(self::META_RESERVATION_STATE);
        $product->save_meta_data();

        clean_post_cache($successor_id);
        clean_post_cache($product_id);

        do_action('lotzwoo_product_succession_restored', $product, $successor_id, $term_ids);

        return true;
    }

    private function repair_cancelled_unpaid_successor_swaps(): void
    {
        if (!$this->unpaid_orders_as_reserved_enabled()) {
            return;
        }

        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''",
                Successor_Product_Field::META_KEY
            ),
            ARRAY_A
        ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

        if (!is_array($rows) || empty($rows)) {
            return;
        }

        foreach ($rows as $row) {
            $product_id = isset($row['post_id']) ? (int) $row['post_id'] : 0;
            $successor_id = isset($row['meta_value']) ? (int) $row['meta_value'] : 0;
            if ($product_id <= 0 || $successor_id <= 0 || $product_id === $successor_id) {
                continue;
            }

            $product = wc_get_product($product_id);
            if (!$product instanceof \WC_Product) {
                continue;
            }

            $stock = $product->get_stock_quantity();
            if ($stock === null || (int) $stock <= 0) {
                continue;
            }

            if (!empty($this->current_menu_terms_for_product($product_id))) {
                continue;
            }

            if (empty($this->current_menu_terms_for_product($successor_id))) {
                continue;
            }

            $reservation_state = $product->get_meta(self::META_RESERVATION_STATE, true);
            if (!is_array($reservation_state)) {
                continue;
            }

            $order = $this->cancelled_unpaid_order_for_product($product);
            if ($order instanceof \WC_Order) {
                $this->restore_unpaid_order_swap($product, $order);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function build_stock_swap_state(\WC_Product $product, \WC_Product $successor, array $term_ids, array $term_slugs, ?int $order_id): array
    {
        return [
            'status'           => 'swapped',
            'reason'           => 'stock',
            'product_id'       => (int) $product->get_id(),
            'successor_id'     => (int) $successor->get_id(),
            'term_ids'         => array_values(array_map('intval', $term_ids)),
            'term_slugs'       => array_values(array_filter(array_map('strval', $term_slugs))),
            'current_entry_id' => $this->menu_service->current_entry_id(new DateTimeImmutable('now', $this->menu_service->get_timezone())),
            'order_id'         => $order_id ?: 0,
            'created_at'       => current_time('mysql', true),
        ];
    }

    private function maybe_clear_stale_stock_swap_marker(\WC_Product $product): bool
    {
        $marker = $product->get_meta(self::META_DONE, true);
        if (!is_array($marker)) {
            return false;
        }

        if ($this->marker_belongs_to_current_entry($marker)) {
            return false;
        }

        $product->delete_meta_data(self::META_DONE);
        $product->save_meta_data();
        return true;
    }

    private function is_completed_stock_swap_marker($marker): bool
    {
        if ($marker === 'yes') {
            return true;
        }

        return is_array($marker) && (($marker['status'] ?? '') === 'swapped') && (($marker['reason'] ?? '') === 'stock');
    }

    private function marker_belongs_to_current_entry($marker): bool
    {
        if (!is_array($marker)) {
            return true;
        }

        $marker_entry_id = (int) ($marker['current_entry_id'] ?? 0);
        if ($marker_entry_id <= 0) {
            return true;
        }

        $current_entry_id = $this->menu_service->current_entry_id(new DateTimeImmutable('now', $this->menu_service->get_timezone()));
        return $current_entry_id === $marker_entry_id;
    }

    private function state_matches_order($state, \WC_Order $order): bool
    {
        return is_array($state) && (int) ($state['order_id'] ?? 0) === (int) $order->get_id();
    }

    private function legacy_or_missing_marker_matches_order($marker, \WC_Order $order): bool
    {
        if (is_array($marker)) {
            return false;
        }

        return $marker === '' || $marker === null;
    }

    private function successor_id_from_marker_or_product($marker, int $product_id): int
    {
        if (is_array($marker) && !empty($marker['successor_id'])) {
            return (int) $marker['successor_id'];
        }

        return (int) get_post_meta($product_id, Successor_Product_Field::META_KEY, true);
    }

    /**
     * @return array<int, string>
     */
    private function term_slugs_from_marker($marker): array
    {
        if (!is_array($marker) || empty($marker['term_slugs']) || !is_array($marker['term_slugs'])) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $marker['term_slugs'])));
    }

    /**
     * @return array<int, \WP_Term>
     */
    private function current_menu_terms_for_product(int $product_id): array
    {
        $terms = wp_get_object_terms($product_id, 'product_tag');
        if (is_wp_error($terms) || !is_array($terms)) {
            return [];
        }

        $current_menu_terms = [];
        foreach ($terms as $term) {
            if (isset($term->slug) && strpos((string) $term->slug, 'currentmenu_') === 0) {
                $current_menu_terms[] = $term;
            }
        }

        return $current_menu_terms;
    }

    /**
     * @param array<int, int> $term_ids
     */
    private function product_has_any_terms(int $product_id, array $term_ids): bool
    {
        $term_ids = array_values(array_filter(array_map('intval', $term_ids)));
        if ($product_id <= 0 || empty($term_ids)) {
            return false;
        }

        $product_terms = wp_get_object_terms($product_id, 'product_tag', ['fields' => 'ids']);
        if (!is_array($product_terms)) {
            return false;
        }

        return !empty(array_intersect(array_map('intval', $product_terms), $term_ids));
    }

    /**
     * @return array<int, int>
     */
    private function current_menu_term_ids(): array
    {
        $terms = get_terms([
            'taxonomy'   => 'product_tag',
            'hide_empty' => false,
        ]);

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

    /**
     * @return array<int, \WC_Product>
     */
    private function filter_terms_by_current_entry_payload(int $product_id, array $current_menu_terms): array
    {
        $current_id = $this->menu_service->current_entry_id(new DateTimeImmutable('now', $this->menu_service->get_timezone()));
        if ($current_id <= 0) {
            return $current_menu_terms;
        }

        $entry = $this->menu_service->find_entry($current_id);
        if (!$entry) {
            return $current_menu_terms;
        }

        $payload = $this->menu_service->decode_payload(isset($entry['payload']) ? (string) $entry['payload'] : '');
        if (empty($payload)) {
            return $current_menu_terms;
        }

        $terms_by_slug = [];
        foreach ($current_menu_terms as $term) {
            if (isset($term->slug)) {
                $terms_by_slug[(string) $term->slug] = $term;
            }
        }

        $matched_terms = [];
        foreach ($payload as $slug => $product_ids) {
            if (!is_string($slug) || !isset($terms_by_slug[$slug]) || !is_array($product_ids)) {
                continue;
            }

            if (in_array($product_id, array_map('intval', $product_ids), true)) {
                $matched_terms[] = $terms_by_slug[$slug];
            }
        }

        return empty($matched_terms) ? $current_menu_terms : $matched_terms;
    }

    /**
     * @return array<int, \WC_Product>
     */
    private function products_from_order(\WC_Order $order): array
    {
        $products = [];

        foreach ($order->get_items('line_item') as $item) {
            if (!is_callable([$item, 'get_product'])) {
                continue;
            }

            $product = $item->get_product();
            if (!$product instanceof \WC_Product) {
                continue;
            }

            $managed_id = (int) $product->get_stock_managed_by_id();
            if ($managed_id > 0 && $managed_id !== (int) $product->get_id()) {
                $managed_product = wc_get_product($managed_id);
                if ($managed_product instanceof \WC_Product) {
                    $product = $managed_product;
                }
            }

            $products[(int) $product->get_id()] = $product;
        }

        return array_values($products);
    }

    private function is_deferred_unpaid_order_stock_product(int $product_id): bool
    {
        return isset($this->deferred_unpaid_order_stock_product_ids[$product_id]);
    }

    private function should_treat_unpaid_order_as_reservation(\WC_Order $order): bool
    {
        if (!$this->unpaid_orders_as_reserved_enabled()) {
            return false;
        }

        if ($order->is_paid()) {
            return false;
        }

        return $this->is_active_unpaid_order_status($order);
    }

    private function is_active_unpaid_order_status(\WC_Order $order): bool
    {
        return $order->has_status(['pending', 'on-hold']);
    }

    private function unpaid_orders_as_reserved_enabled(): bool
    {
        if (!Plugin::opt('product_succession_unpaid_as_reserved')) {
            return false;
        }

        return trim((string) get_option('woocommerce_hold_stock_minutes', '')) !== '';
    }

    private function active_unpaid_order_id_for_product(\WC_Product $product): int
    {
        if (!$this->unpaid_orders_as_reserved_enabled() || !function_exists('wc_get_orders')) {
            return 0;
        }

        $product_id = (int) $product->get_id();
        if ($product_id <= 0) {
            return 0;
        }

        $orders = wc_get_orders([
            'status'  => ['pending', 'on-hold'],
            'limit'   => 20,
            'orderby' => 'date',
            'order'   => 'DESC',
            'return'  => 'objects',
        ]);

        if (!is_array($orders)) {
            return 0;
        }

        foreach ($orders as $order) {
            if (!$order instanceof \WC_Order || $order->is_paid()) {
                continue;
            }

            foreach ($this->products_from_order($order) as $order_product) {
                if ((int) $order_product->get_id() === $product_id) {
                    return (int) $order->get_id();
                }
            }
        }

        return 0;
    }

    private function cancelled_unpaid_order_for_product(\WC_Product $product): ?\WC_Order
    {
        if (!$this->unpaid_orders_as_reserved_enabled() || !function_exists('wc_get_orders')) {
            return null;
        }

        $product_id = (int) $product->get_id();
        if ($product_id <= 0) {
            return null;
        }

        $orders = wc_get_orders([
            'status'  => ['cancelled', 'pending'],
            'limit'   => 20,
            'orderby' => 'date',
            'order'   => 'DESC',
            'return'  => 'objects',
        ]);

        if (!is_array($orders)) {
            return null;
        }

        foreach ($orders as $order) {
            if (!$order instanceof \WC_Order || $order->is_paid()) {
                continue;
            }

            foreach ($this->products_from_order($order) as $order_product) {
                if ((int) $order_product->get_id() === $product_id) {
                    return $order;
                }
            }
        }

        return null;
    }

    private function effective_stock(\WC_Product $product, int $exclude_order_id = 0): int
    {
        $stock = $product->get_stock_quantity();
        if ($stock === null) {
            return 0;
        }

        $reserved = function_exists('wc_get_held_stock_quantity')
            ? (int) wc_get_held_stock_quantity($product, $exclude_order_id)
            : 0;

        return (int) $stock - $reserved;
    }

    private function is_successor_available(\WC_Product $successor): bool
    {
        $stock = $successor->get_stock_quantity();
        if ($stock === null) {
            return true;
        }

        return (int) $stock > 0 && $this->effective_stock($successor) > 0;
    }

    /**
     * @param array<int, int>    $term_ids
     * @param array<int, string> $term_slugs
     * @return array<string, mixed>
     */
    private function build_reservation_state(\WC_Product $product, \WC_Product $successor, array $term_ids, array $term_slugs, ?int $order_id): array
    {
        $current_entry_id = $this->menu_service->current_entry_id(new DateTimeImmutable('now', $this->menu_service->get_timezone()));
        $payload_before   = [];

        if ($current_entry_id > 0) {
            $entry = $this->menu_service->find_entry($current_entry_id);
            if ($entry) {
                $payload = $this->menu_service->decode_payload(isset($entry['payload']) ? (string) $entry['payload'] : '');
                foreach ($term_slugs as $slug) {
                    if ($slug !== '' && isset($payload[$slug])) {
                        $payload_before[$slug] = $payload[$slug];
                    }
                }
            }
        }

        $successor_terms = wp_get_object_terms((int) $successor->get_id(), 'product_tag', ['fields' => 'ids']);
        $successor_term_ids_before = is_array($successor_terms)
            ? array_values(array_intersect(array_map('intval', $successor_terms), $term_ids))
            : [];

        return [
            'product_id'                    => (int) $product->get_id(),
            'successor_id'                  => (int) $successor->get_id(),
            'term_ids'                      => array_values(array_map('intval', $term_ids)),
            'term_slugs'                    => array_values(array_filter(array_map('strval', $term_slugs))),
            'successor_term_ids_before'     => $successor_term_ids_before,
            'current_entry_id'              => $current_entry_id,
            'payload_before'                => $payload_before,
            'order_id'                      => $order_id ?: 0,
            'created_at'                    => current_time('mysql', true),
        ];
    }

    private function maybe_restore_reservation_swap(\WC_Product $product): bool
    {
        $state = $product->get_meta(self::META_RESERVATION_STATE, true);
        if (!is_array($state)) {
            return false;
        }

        $product_id   = (int) ($state['product_id'] ?? $product->get_id());
        $successor_id = (int) ($state['successor_id'] ?? 0);
        $term_ids     = isset($state['term_ids']) && is_array($state['term_ids']) ? array_map('intval', $state['term_ids']) : [];

        if ($product_id <= 0 || $successor_id <= 0 || empty($term_ids)) {
            $product->delete_meta_data(self::META_RESERVATION_STATE);
            $product->save_meta_data();
            return false;
        }

        $current_entry_id = $this->menu_service->current_entry_id(new DateTimeImmutable('now', $this->menu_service->get_timezone()));
        $state_entry_id   = (int) ($state['current_entry_id'] ?? 0);

        if ($state_entry_id > 0 && $current_entry_id !== $state_entry_id) {
            $product->delete_meta_data(self::META_RESERVATION_STATE);
            $product->save_meta_data();
            return false;
        }

        $successor_before = isset($state['successor_term_ids_before']) && is_array($state['successor_term_ids_before'])
            ? array_map('intval', $state['successor_term_ids_before'])
            : [];
        $remove_from_successor = array_values(array_diff($term_ids, $successor_before));

        wp_set_object_terms($product_id, $term_ids, 'product_tag', true);
        if (!empty($remove_from_successor)) {
            wp_remove_object_terms($successor_id, $remove_from_successor, 'product_tag');
        }

        if ($current_entry_id > 0 && isset($state['payload_before']) && is_array($state['payload_before'])) {
            $entry = $this->menu_service->find_entry($current_entry_id);
            if ($entry) {
                $payload = $this->menu_service->decode_payload(isset($entry['payload']) ? (string) $entry['payload'] : '');
                foreach ($state['payload_before'] as $slug => $ids) {
                    if (is_string($slug) && is_array($ids)) {
                        $payload[$slug] = array_values(array_map('intval', $ids));
                    }
                }
                $this->menu_service->update_entry($current_entry_id, ['payload' => $payload]);
            }
        }

        clean_post_cache($successor_id);
        clean_post_cache($product_id);

        $product->delete_meta_data(self::META_RESERVATION_STATE);
        $product->save_meta_data();

        do_action('lotzwoo_product_succession_restored', $product, $successor_id, $term_ids);

        return true;
    }

    private function promote_reservation_swap_to_stock_swap(\WC_Product $product): void
    {
        $state = $product->get_meta(self::META_RESERVATION_STATE, true);
        if (!is_array($state)) {
            return;
        }

        $successor_id = (int) ($state['successor_id'] ?? 0);
        $successor = $successor_id > 0 ? wc_get_product($successor_id) : null;
        $term_ids = isset($state['term_ids']) && is_array($state['term_ids']) ? array_map('intval', $state['term_ids']) : [];
        $term_slugs = isset($state['term_slugs']) && is_array($state['term_slugs']) ? array_map('strval', $state['term_slugs']) : [];

        $product->delete_meta_data(self::META_RESERVATION_STATE);
        if ($successor instanceof \WC_Product && !empty($term_ids)) {
            $product->update_meta_data(self::META_DONE, $this->build_stock_swap_state($product, $successor, $term_ids, $term_slugs, (int) ($state['order_id'] ?? 0)));
        } else {
            $product->update_meta_data(self::META_DONE, 'yes');
        }
        $product->save_meta_data();
    }

    private function notify_admin_successor_unavailable(\WC_Product $product, \WC_Product $successor, string $reason): void
    {
        $key = self::WARNING_TRANSIENT_PREFIX . md5((string) $product->get_id() . ':' . (string) $successor->get_id() . ':' . $reason);
        if (get_transient($key)) {
            return;
        }

        set_transient($key, '1', HOUR_IN_SECONDS);

        $admin_email = (string) get_option('admin_email');
        if ($admin_email === '') {
            return;
        }

        $subject = sprintf(
            __('LotzApp Warnung: Nachfolgeprodukt für "%s" nicht verfügbar', 'lotzapp-for-woocommerce'),
            $product->get_name()
        );
        $message = sprintf(
            __("Das Produkt \"%1\$s\" ist im Menüplan aktiv, kann aber nicht auf das Nachfolgeprodukt \"%2\$s\" wechseln, weil dieses keinen verfügbaren Lagerbestand hat.\n\nAuslöser: %3\$s\nOriginalprodukt-ID: %4\$d\nNachfolgeprodukt-ID: %5\$d", 'lotzapp-for-woocommerce'),
            $product->get_name(),
            $successor->get_name(),
            $reason === 'reservation' ? __('Reservierung', 'lotzapp-for-woocommerce') : __('Lagerbestand', 'lotzapp-for-woocommerce'),
            (int) $product->get_id(),
            (int) $successor->get_id()
        );

        wp_mail($admin_email, $subject, $message);
    }

    public function render_admin_notices(): void
    {
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            return;
        }

        $warnings = $this->active_menu_stock_warnings();
        if (empty($warnings)) {
            return;
        }

        echo '<div class="notice notice-error"><p><strong>' . esc_html__('LotzApp Produktnachfolge: Eingriff erforderlich', 'lotzapp-for-woocommerce') . '</strong></p><ul>';
        foreach ($warnings as $warning) {
            echo '<li>' . esc_html($warning) . '</li>';
        }
        echo '</ul></div>';
    }

    /**
     * @return array<int, string>
     */
    private function active_menu_stock_warnings(): array
    {
        $terms = get_terms([
            'taxonomy'   => 'product_tag',
            'hide_empty' => false,
        ]);

        if (!is_array($terms) || empty($terms)) {
            return [];
        }

        $term_ids = [];
        foreach ($terms as $term) {
            if (isset($term->slug) && strpos((string) $term->slug, 'currentmenu_') === 0) {
                $term_ids[] = (int) $term->term_id;
            }
        }

        if (empty($term_ids)) {
            return [];
        }

        $product_ids = get_posts([
            'post_type'      => 'product',
            'post_status'    => ['publish', 'pending', 'draft', 'private'],
            'numberposts'    => -1,
            'fields'         => 'ids',
            'tax_query'      => [
                [
                    'taxonomy' => 'product_tag',
                    'terms'    => $term_ids,
                    'operator' => 'IN',
                ],
            ],
            'suppress_filters' => false,
        ]);

        if (!is_array($product_ids) || empty($product_ids)) {
            return [];
        }

        $warnings = [];
        foreach ($product_ids as $product_id) {
            $product = wc_get_product((int) $product_id);
            if (!$product instanceof \WC_Product) {
                continue;
            }

            $stock = $product->get_stock_quantity();
            if ($stock === null || (int) $stock > 0) {
                continue;
            }

            $successor_id = (int) get_post_meta((int) $product->get_id(), Successor_Product_Field::META_KEY, true);
            if ($successor_id <= 0 || $successor_id === (int) $product->get_id()) {
                $warnings[] = sprintf(
                    __('"%s" ist im Menüplan aktiv, hat Lagerstand 0 und kein Nachfolgeprodukt definiert.', 'lotzapp-for-woocommerce'),
                    $product->get_name()
                );
                continue;
            }

            $successor = wc_get_product($successor_id);
            if (!$successor instanceof \WC_Product || !$this->is_successor_available($successor)) {
                $warnings[] = sprintf(
                    __('"%1$s" ist im Menüplan aktiv, hat Lagerstand 0 und kann nicht auf "%2$s" wechseln, weil das Nachfolgeprodukt nicht verfügbar ist.', 'lotzapp-for-woocommerce'),
                    $product->get_name(),
                    $successor instanceof \WC_Product ? $successor->get_name() : '#' . $successor_id
                );
            }
        }

        return $warnings;
    }
}
