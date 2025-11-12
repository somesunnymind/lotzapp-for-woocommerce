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

    /**
     * @var array<string, string>
     */
    private array $downloaded_invoices = [];

    /**
     * @var array<int, string>
     */
    private array $temporary_files = [];

    public function register(): void
    {
        add_action('init', [$this, 'maybe_register_order_meta']);
        add_shortcode('lotzwoo_tracking_links', [$this, 'render_tracking_shortcode']);
        add_action('woocommerce_email_after_order_table', [$this, 'maybe_render_tracking_block'], 25, 4);
        add_filter('woocommerce_email_attachments', [$this, 'maybe_attach_invoice'], 10, 4);
        add_action('woocommerce_email_after_send', [$this, 'cleanup_temp_files'], 10, 0);
        add_action('shutdown', [$this, 'cleanup_temp_files']);
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
        if (!$this->is_enabled()) {
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

    /**
     * @param WC_Order|mixed $order
     */
    public function maybe_render_tracking_block($order, bool $sent_to_admin, bool $plain_text, $email): void
    {
        if (!$this->is_enabled() || $sent_to_admin) {
            return;
        }

        if (!$order instanceof WC_Order) {
            return;
        }

        if (!$email instanceof WC_Email || $email->id !== 'customer_completed_order') {
            return;
        }

        if ($plain_text) {
            $content = $this->build_plain_text_tracking($order);
            if ($content !== '') {
                echo "\n" . $content . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
            return;
        }

        $content = $this->build_html_tracking($order);
        if ($content !== '') {
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

        $invoice_url = $this->get_invoice_url($order);
        if ($invoice_url === '') {
            return $attachments;
        }

        $file = $this->download_invoice_file($invoice_url);
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

        $anchors = array_map(
            static function (string $url): string {
                $href  = esc_url($url);
                $label = esc_html($url);
                return '<a href="' . $href . '" target="_blank" rel="noopener noreferrer">' . $label . '</a>';
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

        $heading = __('Tracking-Link(s):', 'lotzapp-for-woocommerce');
        return $heading . "\n" . implode("\n", $links) . "\n";
    }

    private function get_tracking_links(WC_Order $order): array
    {
        $raw_values = $order->get_meta(self::TRACKING_META_KEY, false);

        if (!is_array($raw_values) || $raw_values === []) {
            $single = $order->get_meta(self::TRACKING_META_KEY, true);
            $raw_values = $single ? [$single] : [];
        }

        $links = [];
        foreach ($raw_values as $value) {
            foreach ($this->extract_tracking_candidates($value) as $candidate) {
                $url = $this->sanitize_tracking_url($candidate);
                if ($url !== '') {
                    $links[$url] = $url;
                }
            }
        }

        return array_values($links);
    }

    private function extract_tracking_candidates($value): array
    {
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
        if (!$validated) {
            return '';
        }

        return $validated;
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
        return (bool) Plugin::opt('emails_enabled', 0);
    }

    private function log_error(string $message): void
    {
        if (!function_exists('wc_get_logger')) {
            return;
        }

        $logger = wc_get_logger();
        $logger->error($message, ['source' => 'lotzwoo-emails']);
    }
}
