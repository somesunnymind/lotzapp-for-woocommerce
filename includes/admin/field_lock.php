<?php

namespace Lotzwoo\Admin;

use Lotzwoo\Plugin;

if (!defined('ABSPATH')) {
    exit;
}

class Field_Lock
{
    private const SCRIPT_HANDLE = 'lotzwoo-field-lock';
    private const STYLE_HANDLE  = 'lotzwoo-field-lock-style';

    public function __construct()
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function enqueue_assets(string $hook_suffix): void
    {
        $selectors = $this->get_selectors();
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Lotzwoo FieldLock: enqueue_assets on ' . $hook_suffix . ' selectors=' . wp_json_encode($selectors));
        }
        if (empty($selectors)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Lotzwoo FieldLock: aborting because selectors empty');
            }
            return;
        }

        if (!$this->should_enqueue_for_screen($hook_suffix)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Lotzwoo FieldLock: should_enqueue_for_screen returned false');
            }
            return;
        }

        $script_handle  = self::SCRIPT_HANDLE;
        $script_fs      = trailingslashit(LOTZWOO_PLUGIN_DIR) . 'assets/js/admin-field-lock.js';
        $script_url     = plugins_url('assets/js/admin-field-lock.js', LOTZWOO_PLUGIN_FILE);
        $script_version = file_exists($script_fs) ? (string) filemtime($script_fs) : '0.1.0';
        wp_register_script(
            $script_handle,
            $script_url,
            ['jquery'],
            $script_version,
            true
        );
        wp_enqueue_script($script_handle);

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $debug_payload = [
            'selectors' => $selectors,
            'screen'    => $screen ? [
                'id'        => $screen->id,
                'base'      => $screen->base,
                'post_type' => $screen->post_type,
            ] : null,
            'hook'      => $hook_suffix ?? '',
        ];
        wp_add_inline_script(
            $script_handle,
            'window.lotzwooFieldLockDebug = ' . wp_json_encode($debug_payload) . ';',
            'before'
        );

        wp_localize_script(
            $script_handle,
            'lotzwooFieldLockData',
            [
                'selectors' => $selectors,
                'iconUrl'   => plugins_url('resources/lotzapp_icon.svg', LOTZWOO_PLUGIN_FILE),
                'tooltip'   => __('Wird in LotzApp verwaltet', 'lotzapp-for-woocommerce'),
            ]
        );

        $style_handle  = self::STYLE_HANDLE;
        $style_version = $script_version;
        wp_register_style($style_handle, false, [], $style_version);
        wp_enqueue_style($style_handle);

        $css = <<<CSS

.lotzapp-locked-field:focus {
    box-shadow: none !important;
}
.lotzapp-lock-icon {
    display: inline-flex;
    align-items: center;
    margin-left: 4px;
}
.lotzapp-lock-icon img {
    width: 1.4rem;
    height: 1.4rem;
    display: block;
    position: relative!important;
    z-index: 1!important;

}
CSS;
        wp_add_inline_style($style_handle, $css);
    }

    private function should_enqueue_for_screen(string $hook_suffix): bool
    {
        $screen     = function_exists('get_current_screen') ? get_current_screen() : null;
        $page_param = isset($_GET['page']) ? (string) $_GET['page'] : '';

        // WooCommerce Einstellungen (admin.php?page=wc-settings...)
        if ($page_param && strpos($page_param, 'wc-settings') === 0) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Lotzwoo FieldLock: allowed via page param ' . $page_param);
            }
            return true;
        }

        if ($hook_suffix && strpos($hook_suffix, 'wc-settings') !== false) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Lotzwoo FieldLock: allowed via hook_suffix ' . $hook_suffix);
            }
            return true;
        }

        if ($screen) {
            $screen_id   = (string) $screen->id;
            $screen_base = (string) $screen->base;
            if (strpos($screen_id, 'woocommerce_page_wc-settings') === 0 || strpos($screen_base, 'woocommerce_page_wc-settings') === 0) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('Lotzwoo FieldLock: allowed via screen id/base ' . $screen_id . ' / ' . $screen_base);
                }
                return true;
            }
        }

        // Fallback f??r Produktbearbeitung
        global $typenow, $pagenow;

        if (!$typenow && isset($GLOBALS['typenow'])) {
            $typenow = $GLOBALS['typenow'];
        }
        if (!$typenow && $screen && !empty($screen->post_type)) {
            $typenow = $screen->post_type;
        }
        if (!$typenow && isset($_GET['post_type'])) {
            $typenow = sanitize_key((string) $_GET['post_type']);
        }
        if (!$typenow && isset($_GET['post'])) {
            $post_id = absint($_GET['post']);
            if ($post_id) {
                $type = get_post_type($post_id);
                if ($type) {
                    $typenow = $type;
                }
            }
        }
        if (!$typenow && isset($_POST['post_ID'])) {
            $post_id = absint($_POST['post_ID']);
            if ($post_id) {
                $type = get_post_type($post_id);
                if ($type) {
                    $typenow = $type;
                }
            }
        }

        if ($typenow !== 'product') {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Lotzwoo FieldLock: typenow "' . $typenow . '" is not product');
            }
            return false;
        }

        if (!$pagenow && isset($GLOBALS['pagenow'])) {
            $pagenow = $GLOBALS['pagenow'];
        }

        $allowed_pages = ['post.php', 'post-new.php', 'edit.php'];

        if ($screen && in_array($screen->base, ['post', 'post-new', 'edit'], true)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Lotzwoo FieldLock: allowed via screen base ' . $screen->base);
            }
            return true;
        }

        if ($pagenow && in_array($pagenow, $allowed_pages, true)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Lotzwoo FieldLock: allowed via pagenow ' . $pagenow);
            }
            return true;
        }

        if ($screen && empty($screen->post_type)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Lotzwoo FieldLock: allowed because screen post_type empty');
            }
            return true;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Lotzwoo FieldLock: final fallback false');
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function get_selectors(): array
    {
        $raw = Plugin::opt('locked_fields', []);

        if (is_string($raw)) {
            $raw = preg_split('/\r\n|\r|\n/', $raw);
        }

        if (!is_array($raw)) {
            return [];
        }

        $selectors = [];
        foreach ($raw as $selector) {
            if (!is_string($selector)) {
                continue;
            }
            $selector = trim($selector);
            if ($selector !== '') {
                $selectors[] = $selector;
            }
        }

        return array_values(array_unique($selectors));
    }
}

