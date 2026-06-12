<?php

namespace Lotzwoo\Admin;

use Lotzwoo\Plugin;

if (!defined('ABSPATH')) {
    exit;
}

class Successor_Product_Field
{
    public const META_KEY = '_lotzwoo_successor_product_id';

    public function __construct()
    {
        add_action('woocommerce_product_options_inventory_product_data', [$this, 'add_field']);
        add_action('woocommerce_admin_process_product_object', [$this, 'save_field']);
        add_action('woocommerce_product_quick_edit_end', [$this, 'add_quick_edit_field']);
        add_action('woocommerce_product_quick_edit_save', [$this, 'save_quick_edit_field']);
        add_action('manage_product_posts_custom_column', [$this, 'add_quick_edit_row_data'], 10, 2);
        add_action('admin_footer-edit.php', [$this, 'quick_edit_script']);
    }

    public function add_field(): void
    {
        if (!Plugin::opt('product_succession_enabled')) {
            return;
        }

        global $post;
        $product_id = $post ? (int) $post->ID : 0;
        $current_id = $product_id ? (int) get_post_meta($product_id, self::META_KEY, true) : 0;

        // Preload every candidate product as an <option> and let WooCommerce's
        // local wc-enhanced-select (Select2) filter client-side. This avoids the
        // per-keystroke admin-ajax round-trips that made the AJAX search slow.
        $candidates = $this->get_candidate_products($product_id);

        echo '<p class="form-field _lotzwoo_successor_product_id_field">';
        echo '<label for="_lotzwoo_successor_product_id">' . esc_html__('Nachfolgeprodukt', 'lotzapp-for-woocommerce') . '</label>';
        echo '<select class="wc-enhanced-select" id="_lotzwoo_successor_product_id" name="_lotzwoo_successor_product_id" '
            . 'style="width:50%;" '
            . 'data-placeholder="' . esc_attr__('Produkt suchen…', 'lotzapp-for-woocommerce') . '" '
            . 'data-allow_clear="true">';

        // Empty option so the placeholder shows and the selection can be cleared.
        echo '<option value=""></option>';

        $rendered_current = false;
        foreach ($candidates as $candidate_id => $candidate_label) {
            $selected = ((int) $candidate_id === $current_id) ? ' selected' : '';
            if ($selected !== '') {
                $rendered_current = true;
            }
            echo '<option value="' . esc_attr((string) $candidate_id) . '"' . $selected . '>' . esc_html($candidate_label) . '</option>';
        }

        // Keep the saved successor selectable even if a category filter would
        // otherwise exclude it (e.g. it was chosen before the filter was on).
        if ($current_id > 0 && !$rendered_current) {
            $current_product = wc_get_product($current_id);
            $current_label   = $current_product instanceof \WC_Product
                ? $current_product->get_name()
                : '#' . $current_id;
            echo '<option value="' . esc_attr((string) $current_id) . '" selected>' . esc_html($current_label) . '</option>';
        }

        echo '</select>';
        echo '<span class="description" style="display:block;clear:both;padding-top:6px;">'
            . esc_html__('Wenn dieses Produkt im aktuell aktiven Menüplan ausverkauft ist, kann LotzApp es durch das gewählte Nachfolgeprodukt ersetzen. Zukünftige Menüpläne bleiben unverändert.', 'lotzapp-for-woocommerce')
            . '</span>';
        echo '</p>';
    }

    /**
     * Returns published products as an [id => name] map, ordered by name.
     * Excludes the current product, and restricts to the current product's
     * categories when the "same category only" option is enabled.
     *
     * @return array<int, string>
     */
    private function get_candidate_products(int $current_product_id): array
    {
        $query_args = [
            'post_type'              => 'product',
            'post_status'            => 'publish',
            'posts_per_page'         => -1,
            'orderby'                => 'title',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ];

        if ($current_product_id > 0) {
            $query_args['post__not_in'] = [$current_product_id];
        }

        if (Plugin::opt('product_succession_same_category_only') && $current_product_id > 0) {
            $cat_ids = wp_get_post_terms($current_product_id, 'product_cat', ['fields' => 'ids']);
            if (!is_wp_error($cat_ids) && !empty($cat_ids)) {
                $query_args['tax_query'] = [[
                    'taxonomy' => 'product_cat',
                    'field'    => 'term_id',
                    'terms'    => array_map('intval', $cat_ids),
                ]];
            } else {
                // Current product has no category: nothing can match.
                return [];
            }
        }

        $posts   = get_posts($query_args);
        $results = [];
        foreach ($posts as $candidate) {
            $results[(int) $candidate->ID] = get_the_title($candidate);
        }

        return $results;
    }

    public function save_field(\WC_Product $product): void
    {
        if (!Plugin::opt('product_succession_enabled')) {
            return;
        }

        if (!isset($_POST['_lotzwoo_successor_product_id'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return;
        }

        $raw = wp_unslash($_POST['_lotzwoo_successor_product_id']); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $id  = absint($raw);

        // Guard against self-reference and non-product IDs.
        if ($id === (int) $product->get_id()) {
            $id = 0;
        }
        if ($id > 0 && !wc_get_product($id)) {
            $id = 0;
        }

        if ($id > 0) {
            $product->update_meta_data(self::META_KEY, $id);
        } else {
            $product->delete_meta_data(self::META_KEY);
        }
    }

    public function add_quick_edit_field(): void
    {
        if (!Plugin::opt('product_succession_enabled')) {
            return;
        }

        $candidates = $this->get_candidate_products(0);

        echo '<label class="alignleft lotzwoo-quick-edit-successor" style="clear:both;margin-top:8px;">';
        echo '<span class="title">' . esc_html__('Nachfolgeprodukt', 'lotzapp-for-woocommerce') . '</span>';
        echo '<span class="input-text-wrap">';
        echo '<select name="_lotzwoo_successor_product_id" class="lotzwoo-quick-edit-successor-select" style="width:100%;">';
        echo '<option value="">' . esc_html__('Kein Nachfolgeprodukt', 'lotzapp-for-woocommerce') . '</option>';
        foreach ($candidates as $candidate_id => $candidate_label) {
            $category_ids = wp_get_post_terms((int) $candidate_id, 'product_cat', ['fields' => 'ids']);
            $category_ids = is_wp_error($category_ids) ? [] : array_values(array_map('intval', (array) $category_ids));
            echo '<option value="' . esc_attr((string) $candidate_id) . '" data-category-ids="' . esc_attr(implode(',', $category_ids)) . '">' . esc_html($candidate_label) . '</option>';
        }
        echo '</select>';
        echo '</span>';
        echo '</label>';
    }

    public function save_quick_edit_field(\WC_Product $product): void
    {
        $this->save_field($product);
    }

    public function add_quick_edit_row_data(string $column, int $post_id): void
    {
        if (!Plugin::opt('product_succession_enabled') || $column !== 'name') {
            return;
        }

        $successor_id = (int) get_post_meta($post_id, self::META_KEY, true);
        $category_ids = wp_get_post_terms($post_id, 'product_cat', ['fields' => 'ids']);
        $category_ids = is_wp_error($category_ids) ? [] : array_values(array_map('intval', (array) $category_ids));
        echo '<span class="hidden lotzwoo-successor-product-id" data-product-id="' . esc_attr((string) $post_id) . '" data-successor-product-id="' . esc_attr((string) $successor_id) . '" data-category-ids="' . esc_attr(implode(',', $category_ids)) . '"></span>';
    }

    public function quick_edit_script(): void
    {
        if (!Plugin::opt('product_succession_enabled')) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'edit-product') {
            return;
        }
        $same_category_only = Plugin::opt('product_succession_same_category_only') ? 'true' : 'false';
        ?>
        <script>
        (function () {
            var sameCategoryOnly = <?php echo $same_category_only; ?>;
            if (!window.inlineEditPost) {
                return;
            }

            function parseIds(value) {
                if (!value) {
                    return [];
                }
                return String(value).split(',').map(function (item) {
                    return parseInt(item, 10);
                }).filter(function (item) {
                    return Number.isFinite(item) && item > 0;
                });
            }

            function intersects(left, right) {
                for (var i = 0; i < left.length; i += 1) {
                    if (right.indexOf(left[i]) !== -1) {
                        return true;
                    }
                }
                return false;
            }

            function applyCandidateFilter(select, productId, successorId, productCategoryIds) {
                for (var i = 0; i < select.options.length; i += 1) {
                    var option = select.options[i];
                    var value = parseInt(option.value, 10);
                    var allow = !option.value || value !== productId;

                    if (sameCategoryOnly && option.value && value !== successorId) {
                        allow = allow && productCategoryIds.length > 0 && intersects(productCategoryIds, parseIds(option.dataset.categoryIds || ''));
                    }

                    option.hidden = !allow;
                    option.disabled = !allow;
                }
            }

            var originalEdit = window.inlineEditPost.edit;
            window.inlineEditPost.edit = function (id) {
                originalEdit.apply(this, arguments);

                var postId = 0;
                if (typeof id === 'object' && id) {
                    postId = parseInt(this.getId(id), 10);
                } else {
                    postId = parseInt(id, 10);
                }
                if (!postId) {
                    return;
                }

                var row = document.getElementById('post-' + postId);
                var editRow = document.getElementById('edit-' + postId);
                if (!row || !editRow) {
                    return;
                }

                var meta = row.querySelector('.lotzwoo-successor-product-id');
                var select = editRow.querySelector('select[name="_lotzwoo_successor_product_id"]');
                if (!select) {
                    return;
                }

                var value = meta && meta.dataset ? meta.dataset.successorProductId || '' : '';
                var productId = meta && meta.dataset ? parseInt(meta.dataset.productId || postId, 10) : postId;
                var categoryIds = meta && meta.dataset ? parseIds(meta.dataset.categoryIds || '') : [];
                var successorId = parseInt(value || '0', 10);
                applyCandidateFilter(select, productId, successorId, categoryIds);

                var hasOption = false;
                for (var i = 0; i < select.options.length; i += 1) {
                    if (select.options[i].value === value && !select.options[i].disabled) {
                        hasOption = true;
                        break;
                    }
                }
                select.value = value && hasOption ? value : '';
            };
        })();
        </script>
        <?php
    }
}
