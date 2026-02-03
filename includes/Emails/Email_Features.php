<?php

namespace Lotzwoo\Emails;

use Lotzwoo\Field_Registry;
use Lotzwoo\Plugin;
use WC_Email;
use WC_Order;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

class Email_Features
{
    private const TRACKING_META_KEY = 'lotzwoo_tracking_url';
    private const INVOICE_META_KEY  = 'lotzwoo_invoice_url';

    private static ?self $instance = null;

    /**
     * @var array<string, string>
     */
    private array $downloaded_invoices = [];

    /**
     * @var array<int, string>
     */
    private array $temporary_files = [];

    /**
     * @var array<string, bool>
     */
    private array $rendered_tracking_blocks = [];

    public function register(): void
    {
        self::$instance = $this;

        add_action('init', [$this, 'maybe_register_order_meta']);
        add_shortcode('lotzwoo_tracking_links', [$this, 'render_tracking_shortcode']);
        add_filter('woocommerce_email_attachments', [$this, 'maybe_attach_invoice'], 10, 4);
        add_action('woocommerce_email_after_send', [$this, 'cleanup_temp_files'], 10, 0);
        add_action('shutdown', [$this, 'cleanup_temp_files']);

        if (is_admin()) {
            add_action('add_meta_boxes', [$this, 'register_order_meta_box'], 20, 2);
            add_action('woocommerce_process_shop_order_meta', [$this, 'save_admin_order_fields'], 20, 2);
        }
    }

    public function maybe_register_order_meta(): void
    {
        if (!$this->is_enabled()) {
            return;
        }

        if (!function_exists('register_post_meta')) {
            return;
        }

        register_post_meta(
            'shop_order',
            self::TRACKING_META_KEY,
            [
                'type'              => 'string',
                'single'            => true,
                'show_in_rest'      => [
                    'schema' => [
                        'type'        => 'string',
                        'description' => __('LotzApp Tracking Links (je Zeile ein Link).', 'lotzapp-for-woocommerce'),
                    ],
                ],
                'sanitize_callback' => [$this, 'sanitize_tracking_meta_value'],
                'auth_callback'     => static function () {
                    return current_user_can('edit_shop_orders');
                },
            ]
        );

        register_post_meta(
            'shop_order',
            self::INVOICE_META_KEY,
            [
                'type'              => 'string',
                'single'            => true,
                'show_in_rest'      => [
                    'schema' => [
                        'type'        => 'string',
                        'description' => __('LotzApp Rechnungs-PDF (URL).', 'lotzapp-for-woocommerce'),
                    ],
                ],
                'sanitize_callback' => [$this, 'sanitize_invoice_meta_value'],
                'auth_callback'     => static function () {
                    return current_user_can('edit_shop_orders');
                },
            ]
        );
    }

    public function render_tracking_shortcode($atts = []): string
    {
        if (!$this->tracking_feature_enabled()) {
            return '';
        }

        $atts = shortcode_atts(
            [
                'order_id'   => 0,
                'plain_text' => '',
            ],
            $atts,
            'lotzwoo_tracking_links'
        );

        $order = $this->resolve_order((int) $atts['order_id']);
        if (!$order) {
            return '';
        }

        $plain_text = filter_var($atts['plain_text'], FILTER_VALIDATE_BOOLEAN);

        return $plain_text
            ? $this->build_plain_text_tracking($order)
            : $this->build_html_tracking($order);
    }

    public static function instance(): ?self
    {
        return self::$instance;
    }

    /**
     * Template helper for custom email placement.
     *
     * @param WC_Order|int|null $order
     */
    public function render_tracking_block_for_template($order = null, bool $plain_text = false, string $email_id = 'customer_completed_order'): string
    {
        if (!$this->tracking_feature_enabled()) {
            return '';
        }

        if (is_numeric($order)) {
            $order = $this->resolve_order((int) $order);
        }

        if (!$order instanceof WC_Order) {
            return '';
        }

        if ($email_id !== '' && $email_id !== 'customer_completed_order') {
            return '';
        }

        if ($email_id !== '' && $this->has_rendered_tracking_block($order, $email_id)) {
            return '';
        }

        $content = $plain_text
            ? $this->build_plain_text_tracking($order)
            : $this->build_html_tracking($order);

        if ($content !== '' && $email_id !== '') {
            $this->mark_tracking_block_rendered($order, $email_id);
        }

        return $content;
    }

    /**
     * @param WC_Order|mixed $order
     */
    public function maybe_render_tracking_block($order, bool $sent_to_admin, bool $plain_text, $email): void
    {
        if (!$this->tracking_feature_enabled() || $sent_to_admin) {
            return;
        }

        if (!$order instanceof WC_Order) {
            return;
        }

        $email_id = $email instanceof WC_Email ? $email->id : '';
        if ($email_id !== 'customer_completed_order') {
            return;
        }

        if ($this->has_rendered_tracking_block($order, $email_id)) {
            return;
        }

        if ($plain_text) {
            $content = $this->build_plain_text_tracking($order);
            if ($content !== '') {
                $this->mark_tracking_block_rendered($order, $email_id);
                echo "\n" . $content . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
            return;
        }

        $content = $this->build_html_tracking($order);
        if ($content !== '') {
            $this->mark_tracking_block_rendered($order, $email_id);
            echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    /**
     * @param WC_Order|mixed $order
     * @param mixed          $email
     */
    public function maybe_attach_invoice(array $attachments, string $email_id, $order, $email = null): array
    {
        if (!$this->is_enabled() || $email_id !== 'customer_completed_order') {
            return $attachments;
        }

        if (!$order instanceof WC_Order) {
            return $attachments;
        }

        if (!$this->invoice_feature_enabled()) {
            return $attachments;
        }

        $invoice_url = $this->get_invoice_url($order);
        if ($invoice_url === '') {
            return $attachments;
        }

        $file = $this->resolve_invoice_file($invoice_url);
        if (!$file) {
            return $attachments;
        }

        $attachments[] = $file;
        return $attachments;
    }

    public function cleanup_temp_files(): void
    {
        if (empty($this->temporary_files)) {
            return;
        }

        foreach ($this->temporary_files as $file) {
            if (is_string($file) && $file !== '' && file_exists($file)) {
                @unlink($file);
            }
        }

        $this->temporary_files    = [];
        $this->downloaded_invoices = [];
    }

    public function sanitize_tracking_meta_value($value): string
    {
        if (is_array($value)) {
            $value = implode("\n", array_map('sanitize_textarea_field', $value));
        }

        return sanitize_textarea_field((string) $value);
    }

    public function sanitize_invoice_meta_value($value): string
    {
        $value = is_array($value) ? reset($value) : $value;
        $value = is_string($value) ? trim($value) : '';
        $url   = esc_url_raw($value);
        return $url ?: '';
    }

    private function build_html_tracking(WC_Order $order): string
    {
        $links = $this->get_tracking_links($order);
        if (empty($links)) {
            return '';
        }

        $label_base = __('Sendung verfolgen', 'lotzapp-for-woocommerce');
        $total      = count($links);
        $index      = 0;
        $anchors    = array_map(
            static function (string $url) use ($label_base, $total, &$index): string {
                $index++;
                $href  = esc_url($url);
                $label = $label_base;
                if ($total > 1) {
                    $label .= ' (' . $index . ')';
                }
                return '<a href="' . $href . '" target="_blank" rel="noopener noreferrer">' . esc_html($label) . '</a>';
            },
            $links
        );

        $template = $this->get_tracking_template();
        $markup   = str_replace(Field_Registry::TEMPLATE_PLACEHOLDER, implode('<br />', $anchors), $template);
        $markup   = apply_filters('lotzwoo/emails/tracking_html', $markup, $links, $order);

        return wp_kses_post($markup);
    }

    private function build_plain_text_tracking(WC_Order $order): string
    {
        $links = $this->get_tracking_links($order);
        if (empty($links)) {
            return '';
        }

        $label_base = __('Sendung verfolgen', 'lotzapp-for-woocommerce');
        $total      = count($links);
        $lines      = [];

        foreach ($links as $index => $url) {
            $label = $label_base;
            if ($total > 1) {
                $label .= ' (' . ($index + 1) . ')';
            }
            $lines[] = $label . ': ' . $url;
        }

        return implode("\n", $lines) . "\n";
    }

    private function get_tracking_links(WC_Order $order): array
    {
        $raw_values = $order->get_meta(self::TRACKING_META_KEY, false);

        if (!is_array($raw_values) || $raw_values === []) {
            $single = $order->get_meta(self::TRACKING_META_KEY, true);
            $raw_values = $single ? [$single] : [];
        }

        foreach ($raw_values as &$raw_value) {
            if ($raw_value instanceof \WC_Meta_Data) {
                $data      = $raw_value->get_data();
                $raw_value = isset($data['value']) ? $data['value'] : '';
            }
        }
        unset($raw_value);

        $links = [];
        foreach ($raw_values as $value) {
            foreach ($this->extract_tracking_candidates($value) as $candidate) {
                $url = $this->sanitize_tracking_url($candidate);
                if ($url !== '') {
                    $links[$url] = $url;
                }
            }
        }

        if (empty($links)) {
            $raw_single = $order->get_meta(self::TRACKING_META_KEY, true);
            if (is_string($raw_single) && trim($raw_single) !== '') {
                $this->log_debug(sprintf('Tracking meta present but no valid URLs for order %d: %s', $order->get_id(), $raw_single));
            }
        }

        return array_values($links);
    }

    private function extract_tracking_candidates($value): array
    {
        if ($value instanceof \WC_Meta_Data) {
            $data  = $value->get_data();
            $value = isset($data['value']) ? $data['value'] : '';
        }

        if (is_array($value)) {
            $collected = [];
            foreach ($value as $sub_value) {
                $collected = array_merge($collected, $this->extract_tracking_candidates($sub_value));
            }
            return $collected;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return [];
        }

        if ($value[0] === '[') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $this->extract_tracking_candidates($decoded);
            }
        }

        return array_filter(array_map('trim', preg_split('/[\r\n]+/', $value)));
    }

    private function sanitize_tracking_url(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $url = esc_url_raw($value);
        if (!$url) {
            return '';
        }

        $validated = wp_http_validate_url($url);
        if ($validated) {
            return $validated;
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        return $url;
    }

    private function get_tracking_template(): string
    {
        $template = Plugin::opt('emails_tracking_template', '');
        if (!is_string($template) || trim($template) === '' || strpos($template, Field_Registry::TEMPLATE_PLACEHOLDER) === false) {
            $defaults = Plugin::defaults();
            $template = isset($defaults['emails_tracking_template']) ? (string) $defaults['emails_tracking_template'] : '{{value}}';
        }

        return $template;
    }

    private function get_invoice_url(WC_Order $order): string
    {
        $value = $order->get_meta(self::INVOICE_META_KEY, true);
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return '';
        }

        $url = esc_url_raw($value);
        return $url && wp_http_validate_url($url) ? $url : '';
    }

    private function resolve_invoice_file(string $url): ?string
    {
        $local = $this->map_local_invoice_path($url);
        if ($local && file_exists($local)) {
            return $local;
        }

        return $this->download_invoice_file($url);
    }

    private function map_local_invoice_path(string $url): ?string
    {
        $uploads = wp_get_upload_dir();
        if (!empty($uploads['baseurl']) && strpos($url, $uploads['baseurl']) === 0) {
            $relative = substr($url, strlen($uploads['baseurl']));
            $path     = wp_normalize_path($uploads['basedir'] . $relative);
            if (file_exists($path)) {
                return $path;
            }
        }

        $site_url = trailingslashit(home_url());
        if (strpos($url, $site_url) === 0) {
            $relative = ltrim(substr($url, strlen($site_url)), '/');
            $path     = wp_normalize_path(trailingslashit(ABSPATH) . $relative);
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    private function download_invoice_file(string $url): ?string
    {
        if (isset($this->downloaded_invoices[$url]) && is_string($this->downloaded_invoices[$url]) && file_exists($this->downloaded_invoices[$url])) {
            return $this->downloaded_invoices[$url];
        }

        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $tmp_file = download_url($url);

        if ($tmp_file instanceof WP_Error) {
            $this->log_error(sprintf('Invoice download failed: %s', $tmp_file->get_error_message()));
            return null;
        }

        if (!is_string($tmp_file) || !file_exists($tmp_file)) {
            $this->log_error(__('Invoice download failed: invalid temporary file path.', 'lotzapp-for-woocommerce'));
            return null;
        }

        $this->temporary_files[]           = $tmp_file;
        $this->downloaded_invoices[$url] = $tmp_file;

        return $tmp_file;
    }

    private function resolve_order(int $order_id): ?WC_Order
    {
        if ($order_id > 0) {
            $order = wc_get_order($order_id);
            return $order instanceof WC_Order ? $order : null;
        }

        global $order;

        return $order instanceof WC_Order ? $order : null;
    }

    private function is_enabled(): bool
    {
        return $this->tracking_feature_enabled() || $this->invoice_feature_enabled();
    }

    private function tracking_feature_enabled(): bool
    {
        return (bool) Plugin::opt('emails_tracking_enabled', 1);
    }

    private function invoice_feature_enabled(): bool
    {
        return (bool) Plugin::opt('emails_invoice_enabled', 1);
    }

    private function log_error(string $message): void
    {
        if (!function_exists('wc_get_logger')) {
            return;
        }

        $logger = wc_get_logger();
        $logger->error($message, ['source' => 'lotzwoo-emails']);
    }

    private function log_debug(string $message): void
    {
        if (!function_exists('wc_get_logger') || !defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $logger = wc_get_logger();
        $logger->debug($message, ['source' => 'lotzwoo-emails']);
    }

    private function has_rendered_tracking_block(WC_Order $order, string $email_id): bool
    {
        $key = $order->get_id() . ':' . $email_id;
        return isset($this->rendered_tracking_blocks[$key]);
    }

    private function mark_tracking_block_rendered(WC_Order $order, string $email_id): void
    {
        $key = $order->get_id() . ':' . $email_id;
        $this->rendered_tracking_blocks[$key] = true;
    }

    /**
     * Registers a dedicated meta box for order screens (classic + HPOS).
     *
     * @param string|\WP_Screen $screen_id
     * @param mixed             $order_or_post
     */
    public function register_order_meta_box($screen_id, $order_or_post = null): void
    {
        if (!$this->is_order_screen($screen_id)) {
            return;
        }

        $target = is_object($screen_id) && property_exists($screen_id, 'id') ? $screen_id->id : $screen_id;

        add_meta_box(
            'lotzwoo-order-emails',
            __('LotzApp Emails', 'lotzapp-for-woocommerce'),
            [$this, 'render_order_meta_box'],
            $target,
            'normal',
            'default'
        );
    }

    /**
     * Meta box callback that normalizes the passed object to a WC_Order.
     *
     * @param \WP_Post|WC_Order|null $post_or_order
     */
    public function render_order_meta_box($post_or_order): void
    {
        if ($post_or_order instanceof WC_Order) {
            $order = $post_or_order;
        } elseif ($post_or_order instanceof \WP_Post || is_numeric($post_or_order)) {
            $order = wc_get_order($post_or_order);
        } else {
            $order = null;
        }

        if (!$order instanceof WC_Order) {
            echo '<p>' . esc_html__('Order context not available.', 'lotzapp-for-woocommerce') . '</p>';
            return;
        }

        $this->render_admin_order_fields($order);
    }

    /**
     * Checks whether the current screen belongs to an order editor (classic or HPOS).
     *
     * @param mixed $screen_id
     */
    private function is_order_screen($screen_id): bool
    {
        if ($screen_id instanceof \WP_Screen) {
            $screen_id = $screen_id->id;
        }

        if (!is_string($screen_id) || $screen_id === '') {
            return false;
        }

        if ($screen_id === 'shop_order' || strpos($screen_id, 'shop_order') === 0) {
            return true;
        }

        if (strpos($screen_id, 'woocommerce_page_wc-orders') === 0) {
            return true;
        }

        return false;
    }

    public function render_admin_order_fields(WC_Order $order): void
    {
        wp_nonce_field('lotzwoo_save_order_meta', 'lotzwoo_order_meta_nonce');

        $tracking_value = $this->get_raw_tracking_value($order);
        $invoice_value  = (string) $order->get_meta(self::INVOICE_META_KEY, true);
        ?>
        <div class="lotzwoo-email-meta-fields">
            <p class="form-field lotzwoo_tracking_url_field">
                <label for="lotzwoo_tracking_url"><?php esc_html_e('Tracking-Links', 'lotzapp-for-woocommerce'); ?></label>
                <textarea style="min-height:90px; display:block;" id="lotzwoo_tracking_url" name="lotzwoo_tracking_url" class="large-text"><?php echo esc_textarea($tracking_value); ?></textarea>
                <span class="description"><?php esc_html_e('Ein Link pro Zeile. Wird in WooCommerce-Mails mit dem LotzApp Tracking Link Template-Helper ausgegeben.', 'lotzapp-for-woocommerce'); ?></span>
            </p>
            <p class="form-field lotzwoo_invoice_url_field">
                <label for="lotzwoo_invoice_url"><?php esc_html_e('Rechnungs-PDF URL', 'lotzapp-for-woocommerce'); ?></label>
                <input type="url" class="widefat" id="lotzwoo_invoice_url" name="lotzwoo_invoice_url" value="" style="display:block;"<?php echo esc_attr($invoice_value); ?>" />
                <span class="description"><?php esc_html_e('Wird heruntergeladen und als Anhang an customer_completed_order angefuegt.', 'lotzapp-for-woocommerce'); ?></span>
            </p>
        </div>
        <?php
    }

    public function save_admin_order_fields(int $order_id, $post): void
    {
        if (!isset($_POST['lotzwoo_order_meta_nonce']) || !wp_verify_nonce((string) wp_unslash($_POST['lotzwoo_order_meta_nonce']), 'lotzwoo_save_order_meta')) {
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return;
        }

        if (isset($_POST['lotzwoo_tracking_url'])) {
            $raw_tracking = (string) wp_unslash($_POST['lotzwoo_tracking_url']);
            $sanitized    = $this->sanitize_tracking_meta_value($raw_tracking);
            if ($sanitized === '') {
                $order->delete_meta_data(self::TRACKING_META_KEY);
            } else {
                $order->update_meta_data(self::TRACKING_META_KEY, $sanitized);
            }
        }

        if (isset($_POST['lotzwoo_invoice_url'])) {
            $raw_invoice = (string) wp_unslash($_POST['lotzwoo_invoice_url']);
            $sanitized   = $this->sanitize_invoice_meta_value($raw_invoice);
            if ($sanitized === '') {
                $order->delete_meta_data(self::INVOICE_META_KEY);
            } else {
                $order->update_meta_data(self::INVOICE_META_KEY, $sanitized);
            }
        }

        $order->save();
    }

    private function get_raw_tracking_value(WC_Order $order): string
    {
        $value = $order->get_meta(self::TRACKING_META_KEY, true);
        if (is_array($value)) {
            $flattened = [];
            array_walk_recursive($value, static function ($item) use (&$flattened) {
                if (is_scalar($item)) {
                    $flattened[] = (string) $item;
                }
            });
            $value = implode("\n", $flattened);
        }

        return (string) $value;
    }
}
