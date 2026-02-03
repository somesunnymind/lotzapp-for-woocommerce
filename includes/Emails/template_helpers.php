<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('lotzwoo_render_tracking_links')) {
    /**
     * Render the LotzApp tracking block inside WooCommerce email templates.
     *
     * @param \WC_Order|int|null $order
     */
    function lotzwoo_render_tracking_links($order = null, bool $plain_text = false, string $email_id = 'customer_completed_order'): string
    {
        $service = \Lotzwoo\Emails\Email_Features::instance();
        if (!$service) {
            return '';
        }

        return $service->render_tracking_block_for_template($order, $plain_text, $email_id);
    }
}
