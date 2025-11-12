<?php

namespace Lotzwoo\Services;

use DateTimeImmutable;
use DateTimeZone;

if (!defined('ABSPATH')) {
    exit;
}

class Menu_Planning_Runner
{
    private const CRON_HOOK     = 'lotzwoo_menu_planning_run';
    private const CRON_INTERVAL = 'lotzwoo_menu_planning_interval';
    private const LOCK_KEY      = 'lotzwoo_menu_planning_lock';

    private Menu_Planning_Service $service;

    public function __construct(Menu_Planning_Service $service)
    {
        $this->service = $service;
    }

    public function boot(): void
    {
        add_filter('cron_schedules', [$this, 'register_schedule']);
        add_action(self::CRON_HOOK, [$this, 'run']);
        add_action('init', [$this, 'ensure_event'], 20);
    }

    /**
     * @param array<string, array<string, mixed>> $schedules
     * @return array<string, array<string, mixed>>
     */
    public function register_schedule(array $schedules): array
    {
        if (!isset($schedules[self::CRON_INTERVAL])) {
            $schedules[self::CRON_INTERVAL] = [
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display'  => __('LotzApp Menüplanung (15 Minuten)', 'lotzapp-for-woocommerce'),
            ];
        }

        return $schedules;
    }

    public function ensure_event(): void
    {
        if (wp_next_scheduled(self::CRON_HOOK)) {
            return;
        }

        wp_schedule_event(time() + MINUTE_IN_SECONDS, self::CRON_INTERVAL, self::CRON_HOOK);
    }

    public static function unschedule_event(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    public function run(): void
    {
        if ($this->is_locked() || !$this->service->table_exists()) {
            return;
        }

        $this->acquire_lock();

        try {
            $now        = new DateTimeImmutable('now', $this->service->get_timezone());
            $due_entries = $this->service->get_due_entries($now, 5);

            foreach ($due_entries as $entry) {
                if ($this->is_entry_runnable($entry)) {
                    $this->process_entry($entry);
                }
            }
        } finally {
            $this->release_lock();
        }
    }

    public function run_entry(int $entry_id): bool
    {
        if (!$this->service->table_exists() || $this->is_locked()) {
            return false;
        }

        $entry = $this->service->find_entry($entry_id);
        if (!$entry || !$this->is_entry_runnable($entry)) {
            return false;
        }

        $this->acquire_lock();
        try {
            $this->process_entry($entry);
        } finally {
            $this->release_lock();
        }

        return true;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function process_entry(array $entry): void
    {
        $payload = $this->service->decode_payload($entry['payload'] ?? '');

        foreach ($payload as $tag_slug => $product_ids) {
            $this->sync_tag_with_products((string) $tag_slug, (array) $product_ids);
        }

        $this->service->update_entry(
            (int) $entry['id'],
            [
                'status' => Menu_Planning_Service::STATUS_COMPLETED,
            ]
        );
    }

    /**
     * @param array<int, int> $product_ids
     */
    private function sync_tag_with_products(string $tag_slug, array $product_ids): void
    {
        $tag_slug = sanitize_title($tag_slug);
        if ($tag_slug === '') {
            return;
        }

        $term = get_term_by('slug', $tag_slug, 'product_tag');
        if (!$term || is_wp_error($term)) {
            return;
        }

        $product_ids = array_values(array_unique(array_filter(array_map('intval', $product_ids))));

        $current_ids = $this->get_products_for_tag((int) $term->term_id);

        $remove_ids = array_diff($current_ids, $product_ids);
        $add_ids    = array_diff($product_ids, $current_ids);

        foreach ($remove_ids as $product_id) {
            wp_remove_object_terms($product_id, (int) $term->term_id, 'product_tag');
        }

        foreach ($add_ids as $product_id) {
            wp_set_object_terms($product_id, (int) $term->term_id, 'product_tag', true);
        }
    }

    /**
     * @return array<int, int>
     */
    private function get_products_for_tag(int $term_id): array
    {
        $products = get_posts([
            'post_type'      => 'product',
            'post_status'    => ['publish', 'pending', 'draft', 'private'],
            'numberposts'    => -1,
            'fields'         => 'ids',
            'tax_query'      => [
                [
                    'taxonomy' => 'product_tag',
                    'terms'    => [$term_id],
                    'operator' => 'IN',
                ],
            ],
            'suppress_filters' => false,
        ]);

        if (!is_array($products)) {
            return [];
        }

        return array_values(array_map('intval', $products));
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function is_entry_runnable(array $entry): bool
    {
        if (!isset($entry['status']) || !in_array((string) $entry['status'], [Menu_Planning_Service::STATUS_PENDING, Menu_Planning_Service::STATUS_COMPLETED], true)) {
            return false;
        }

        if (empty($entry['scheduled_at'])) {
            return false;
        }

        try {
            $scheduled = new DateTimeImmutable((string) $entry['scheduled_at'], new DateTimeZone('UTC'));
        } catch (\Exception $e) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCATCH
            return false;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return $scheduled <= $now;
    }

    private function is_locked(): bool
    {
        return (bool) get_transient(self::LOCK_KEY);
    }

    private function acquire_lock(): void
    {
        set_transient(self::LOCK_KEY, 1, 5 * MINUTE_IN_SECONDS);
    }

    private function release_lock(): void
    {
        delete_transient(self::LOCK_KEY);
    }
}




