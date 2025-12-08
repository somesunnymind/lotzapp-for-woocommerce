<?php

namespace Lotzwoo\Services;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Lotzwoo\Plugin;
use wpdb;

if (!defined('ABSPATH')) {
    exit;
}

class Menu_Planning_Service
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    private const MAX_SLOT_LOOKAHEAD = 366; // Max. Anzahl an Versuchen für freie Slots

    public function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'lotzwoo_menu_plan';
    }

    public function table_exists(): bool
    {
        global $wpdb;
        $table = $this->table_name();
        $result = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $result === $table;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function get_entries(int $limit = 20, int $offset = 0): array
    {
        if (!$this->table_exists()) {
            return [];
        }

        global $wpdb;
        $table = $this->table_name();

        $query = $wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY scheduled_at DESC LIMIT %d OFFSET %d",
            $limit,
            $offset
        );

        /** @var array<int, array<string, mixed>> $results */
        $results = $wpdb->get_results($query, ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        return $results ?: [];
    }

    public function find_entry(int $id): ?array
    {
        if (!$this->table_exists() || $id <= 0) {
            return null;
        }

        global $wpdb;
        $table = $this->table_name();
        $query = $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id);
        /** @var array<string, mixed>|null $row */
        $row = $wpdb->get_row($query, ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $row ?: null;
    }

    public function find_previous_entry(DateTimeInterface $before): ?array
    {
        if (!$this->table_exists()) {
            return null;
        }

        global $wpdb;
        $table   = $this->table_name();
        $before_utc = $this->to_utc_string($before);

        $query = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE scheduled_at <= %s AND status IN (%s, %s) ORDER BY scheduled_at DESC LIMIT 1",
            $before_utc,
            self::STATUS_PENDING,
            self::STATUS_COMPLETED
        );

        /** @var array<string, mixed>|null $row */
        $row = $wpdb->get_row($query, ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $row ?: null;
    }

    public function find_next_entry(DateTimeInterface $after): ?array
    {
        if (!$this->table_exists()) {
            return null;
        }

        global $wpdb;
        $table   = $this->table_name();
        $after_utc = $this->to_utc_string($after);

        $query = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE scheduled_at > %s AND status IN (%s, %s) ORDER BY scheduled_at ASC LIMIT 1",
            $after_utc,
            self::STATUS_PENDING,
            self::STATUS_COMPLETED
        );

        /** @var array<string, mixed>|null $row */
        $row = $wpdb->get_row($query, ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $row ?: null;
    }

    /**
     * @return array<int, string> UTC timestamps.
     */
    public function get_scheduled_slots(): array
    {
        if (!$this->table_exists()) {
            return [];
        }

        global $wpdb;
        $table = $this->table_name();
        $query = "SELECT scheduled_at FROM {$table} WHERE status != 'cancelled'";
        /** @var array<int, string> $slots */
        $slots = $wpdb->get_col($query); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return array_map(static function ($slot) {
            return (string) $slot;
        }, $slots ?: []);
    }

    public function has_upcoming_entry(DateTimeInterface $now): bool
    {
        if (!$this->table_exists()) {
            return false;
        }

        global $wpdb;
        $table   = $this->table_name();
        $now_utc = $this->to_utc_string($now);

        $query = $wpdb->prepare(
            "SELECT COUNT(1) FROM {$table} WHERE scheduled_at >= %s AND status IN (%s, %s)",
            $now_utc,
            self::STATUS_PENDING,
            self::STATUS_COMPLETED
        );

        /** @var string|int|null $count */
        $count = $wpdb->get_var($query); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return ((int) $count) > 0;
    }

    public function has_active_entry(DateTimeInterface $now): bool
    {
        if (!$this->table_exists()) {
            return false;
        }

        global $wpdb;
        $table   = $this->table_name();
        $now_utc = $this->to_utc_string($now);

        $query = $wpdb->prepare(
            "SELECT COUNT(1) FROM {$table} WHERE scheduled_at <= %s AND status = %s",
            $now_utc,
            self::STATUS_PENDING
        );

        /** @var string|int|null $count */
        $count = $wpdb->get_var($query); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return ((int) $count) > 0;
    }

    public function has_any_entry(): bool
    {
        if (!$this->table_exists()) {
            return false;
        }

        global $wpdb;
        $table = $this->table_name();
        /** @var string|int|null $count */
        $count = $wpdb->get_var("SELECT COUNT(1) FROM {$table}"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return ((int) $count) > 0;
    }

    public function current_entry_id(DateTimeInterface $now): int
    {
        if (!$this->table_exists()) {
            return 0;
        }

        global $wpdb;
        $table   = $this->table_name();
        $now_utc = $this->to_utc_string($now);

        $query = $wpdb->prepare(
            "SELECT id FROM {$table} WHERE scheduled_at <= %s AND status IN (%s, %s) ORDER BY scheduled_at DESC LIMIT 1",
            $now_utc,
            self::STATUS_PENDING,
            self::STATUS_COMPLETED
        );

        /** @var string|int|null $result */
        $result = $wpdb->get_var($query); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (int) $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function get_due_entries(DateTimeInterface $now, int $limit = 5): array
    {
        if (!$this->table_exists()) {
            return [];
        }

        global $wpdb;
        $table   = $this->table_name();
        $current = $this->to_utc_string($now);

        $query = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE status = %s AND scheduled_at <= %s ORDER BY scheduled_at ASC LIMIT %d",
            self::STATUS_PENDING,
            $current,
            $limit
        );

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $wpdb->get_results($query, ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $rows ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function get_menu_tags(): array
    {
        $terms = get_terms([
            'taxonomy'   => 'product_tag',
            'hide_empty' => false,
        ]);

        if (!is_array($terms)) {
            return [];
        }

        $tags = [];
        foreach ($terms as $term) {
            if (!isset($term->slug) || strpos($term->slug, 'currentmenu_') !== 0) {
                continue;
            }

            $category_slug = substr($term->slug, strlen('currentmenu_'));
            $category      = $category_slug !== '' ? get_term_by('slug', $category_slug, 'product_cat') : false;

            $tags[] = [
                'term_id'          => (int) $term->term_id,
                'slug'             => (string) $term->slug,
                'name'             => (string) $term->name,
                'category_slug'    => (string) $category_slug,
                'category_term_id' => $category ? (int) $category->term_id : 0,
                'products'         => $this->get_products_for_category($category_slug),
            ];
        }

        return $tags;
    }

    /**
     * @return array<string, mixed>
     */
    public function get_schedule_snapshot(): array
    {
        $schedule = Plugin::menu_planning_schedule();
        $timezone = $this->get_timezone();
        $now      = new DateTimeImmutable('now', $timezone);

        if ($this->has_any_entry()) {
            $next_slot = $this->next_open_slot($this->get_scheduled_slots());
        } else {
            $next_slot = $now;
        }

        $next_slot_local = $next_slot->setTimezone($timezone);

        $display_local = $this->format_local_datetime($next_slot_local);

        return [
            'frequency'        => $schedule['frequency'],
            'frequency_display'=> $this->translate_frequency($schedule['frequency']),
            'weekday'          => $schedule['weekday'],
            'weekday_display'  => $this->translate_weekday($schedule['weekday']),
            'monthday'         => $schedule['monthday'],
            'monthday_display' => $this->format_monthday_label($schedule['monthday']),
            'time'             => $schedule['time'],
            'summary'          => $this->format_schedule_label($schedule),
            'nextSlotUtc'      => $next_slot->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'nextSlotLocal'    => $next_slot_local->format('Y-m-d H:i:s'),
            'nextSlotDisplay'  => $display_local,
            'nextSlotIso'      => $next_slot->format(DateTimeInterface::ATOM),
            'nextSlotLocalIso' => $next_slot_local->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function get_products_for_category(string $category_slug): array
    {
        if ($category_slug === '' || !function_exists('wc_get_products')) {
            return [];
        }

        $products = wc_get_products([
            'limit'    => -1,
            'status'   => 'publish',
            'category' => [$category_slug],
            'orderby'  => 'title',
            'order'    => 'ASC',
        ]);

        if (!is_array($products)) {
            return [];
        }

        $options = [];
        foreach ($products as $product) {
            if (!$product instanceof \WC_Product) {
                continue;
            }

            $tags = [];
            $product_terms = get_the_terms($product->get_id(), 'product_tag');
            if (is_array($product_terms)) {
                foreach ($product_terms as $term) {
                    $tags[] = [
                        'id'   => (int) $term->term_id,
                        'slug' => (string) $term->slug,
                        'name' => (string) $term->name,
                    ];
                }
            }

            $options[] = [
                'id'   => (int) $product->get_id(),
                'name' => $product->get_name(),
                'tags' => $tags,
                'permalink' => get_permalink($product->get_id()) ?: '',
                'edit_url'  => get_edit_post_link($product->get_id(), '') ?: '',
                'sku'       => (string) $product->get_sku(),
            ];
        }

        return $options;
    }

    /**
     * @param array<string, array<int, int>> $tag_product_map
     */
    public function insert_entry(DateTimeInterface $scheduled_at, array $tag_product_map, ?int $user_id = null): int
    {
        if (!$this->table_exists()) {
            return 0;
        }

        global $wpdb;
        $table = $this->table_name();
        $now_gmt = current_time('mysql', true);

        $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $table,
            [
                'scheduled_at' => $this->to_utc_string($scheduled_at),
                'payload'      => $this->encode_payload($tag_product_map),
                'status'       => self::STATUS_PENDING,
                'created_by'   => $user_id ?: get_current_user_id(),
                'created_at'   => $now_gmt,
                'updated_at'   => $now_gmt,
            ],
            ['%s', '%s', '%s', '%d', '%s', '%s']
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update_entry(int $id, array $data): bool
    {
        if (!$this->table_exists() || $id <= 0) {
            return false;
        }

        global $wpdb;
        $table = $this->table_name();

        $fields  = [];
        $formats = [];

        if (array_key_exists('payload', $data)) {
            $fields['payload'] = $this->encode_payload((array) $data['payload']);
            $formats[]         = '%s';
        }

        if (!empty($data['scheduled_at']) && $data['scheduled_at'] instanceof DateTimeInterface) {
            $fields['scheduled_at'] = $this->to_utc_string($data['scheduled_at']);
            $formats[]              = '%s';
        } elseif (!empty($data['scheduled_at']) && is_string($data['scheduled_at'])) {
            $fields['scheduled_at'] = $this->sanitize_datetime_string($data['scheduled_at']);
            $formats[]              = '%s';
        }

        if (!empty($data['status']) && is_string($data['status'])) {
            $status = strtolower(trim($data['status']));
            if (in_array($status, [self::STATUS_PENDING, self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) {
                $fields['status'] = $status;
                $formats[]        = '%s';
            }
        }

        if (empty($fields)) {
            return false;
        }

        $fields['updated_at'] = current_time('mysql', true);
        $formats[]            = '%s';

        $updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $table,
            $fields,
            ['id' => $id],
            $formats,
            ['%d']
        );

        return $updated !== false;
    }

    public function cancel_entry(int $id): bool
    {
        return $this->update_entry($id, ['status' => self::STATUS_CANCELLED]);
    }

    public function delete_entry(int $id): bool
    {
        if (!$this->table_exists() || $id <= 0) {
            return false;
        }

        global $wpdb;
        $table = $this->table_name();
        $deleted = $wpdb->delete($table, ['id' => $id], ['%d']); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $deleted !== false;
    }

    /**
     * Berechnet den nächsten freien Slot basierend auf den Einstellungen.
     *
     * @param array<int, string> $existing_utc_slots
     */
    public function next_open_slot(array $existing_utc_slots = []): DateTimeImmutable
    {
        $taken = array_fill_keys(array_map(static function ($slot) {
            return (string) $slot;
        }, $existing_utc_slots), true);

        $timezone = $this->get_timezone();
        $candidate = Plugin::next_menu_planning_event($timezone);
        $counter   = 0;

        while ($counter < self::MAX_SLOT_LOOKAHEAD) {
            $candidate_utc = $this->to_utc_string($candidate);
            if (!isset($taken[$candidate_utc])) {
                return $candidate;
            }
            $candidate = Plugin::next_menu_planning_event($timezone, $candidate->modify('+1 minute'));
            $counter++;
        }

        return $candidate;
    }

    /**
     * @return array<string, array<int, int>>
     */
    public function decode_payload(?string $payload): array
    {
        if (!$payload) {
            return [];
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return [];
        }

        $normalized = [];
        foreach ($decoded as $tag => $product_ids) {
            if (!is_array($product_ids)) {
                continue;
            }
            $normalized[$tag] = array_values(array_map('intval', $product_ids));
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function format_entry(array $row): array
    {
        $utc_string = isset($row['scheduled_at']) ? (string) $row['scheduled_at'] : '';
        try {
            $utc   = new DateTimeImmutable($utc_string ?: 'now', new DateTimeZone('UTC'));
        } catch (\Exception $e) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCATCH
            $utc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }

        $timezone = $this->get_timezone();
        $local    = $utc->setTimezone($timezone);
        $now_local = new DateTimeImmutable('now', $timezone);
        $is_active = isset($row['status']) && (string) $row['status'] === self::STATUS_PENDING && $local <= $now_local;

        $formatted_local = $this->format_local_datetime($local);
        $weekday_slug    = strtolower($local->format('l'));
        $weekday_display = $this->translate_weekday($weekday_slug);
        $date_display    = $local->format('d.m.Y');
        $time_display    = $local->format('H:i');

        return [
            'id'                    => isset($row['id']) ? (int) $row['id'] : 0,
            'status'                => isset($row['status']) ? (string) $row['status'] : self::STATUS_PENDING,
            'scheduled_at_utc'      => $utc->format('Y-m-d H:i:s'),
            'scheduled_at_local'    => $local->format('Y-m-d H:i:s'),
            'scheduled_at_iso'      => $utc->format(DateTimeInterface::ATOM),
            'scheduled_at_local_iso'=> $local->format(DateTimeInterface::ATOM),
            'scheduled_at_display'  => $formatted_local,
            'scheduled_date_display'=> $date_display,
            'scheduled_time_display'=> $time_display,
            'scheduled_weekday'     => $weekday_slug,
            'scheduled_weekday_display' => $weekday_display,
            'payload'               => $this->decode_payload(isset($row['payload']) ? (string) $row['payload'] : ''),
            'created_by'            => isset($row['created_by']) ? (int) $row['created_by'] : 0,
            'created_at'            => isset($row['created_at']) ? (string) $row['created_at'] : '',
            'updated_at'            => isset($row['updated_at']) ? (string) $row['updated_at'] : '',
            'is_active'             => $is_active,
            'is_current'            => false,
        ];
    }

    /**
     * @param array<string, array<int, int>> $payload
     */
    private function encode_payload(array $payload): string
    {
        $normalized = [];
        foreach ($payload as $tag => $ids) {
            if (!is_string($tag)) {
                continue;
            }
            $normalized[$tag] = array_values(array_map('intval', (array) $ids));
        }

        return wp_json_encode($normalized);
    }

    private function sanitize_datetime_string(string $datetime): string
    {
        $datetime = trim($datetime);
        if ($datetime === '') {
            return current_time('mysql', true);
        }

        try {
            $object = new DateTimeImmutable($datetime, $this->get_timezone());
        } catch (\Exception $e) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCATCH
            $object = new DateTimeImmutable('now', $this->get_timezone());
        }

        return $this->to_utc_string($object);
    }

    private function to_utc_string(DateTimeInterface $datetime): string
    {
        return $datetime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function format_local_datetime(DateTimeInterface $datetime): string
    {
        return $datetime->format('d.m.Y, H:i');
    }

    private function translate_weekday(string $weekday): string
    {
        $map = [
            'monday'    => __('Montag', 'lotzapp-for-woocommerce'),
            'tuesday'   => __('Dienstag', 'lotzapp-for-woocommerce'),
            'wednesday' => __('Mittwoch', 'lotzapp-for-woocommerce'),
            'thursday'  => __('Donnerstag', 'lotzapp-for-woocommerce'),
            'friday'    => __('Freitag', 'lotzapp-for-woocommerce'),
            'saturday'  => __('Samstag', 'lotzapp-for-woocommerce'),
            'sunday'    => __('Sonntag', 'lotzapp-for-woocommerce'),
        ];

        $weekday = strtolower($weekday);
        return isset($map[$weekday]) ? $map[$weekday] : ucfirst($weekday);
    }

    private function translate_frequency(string $frequency): string
    {
        $map = [
            'daily'   => __('Täglich', 'lotzapp-for-woocommerce'),
            'weekly'  => __('Wöchentlich', 'lotzapp-for-woocommerce'),
            'monthly' => __('Monatlich', 'lotzapp-for-woocommerce'),
        ];

        $frequency = strtolower($frequency);
        return isset($map[$frequency]) ? $map[$frequency] : ucfirst($frequency);
    }

    private function format_monthday_label(int $day): string
    {
        $day = max(1, min(31, $day));
        return sprintf(__('%02d. des Monats', 'lotzapp-for-woocommerce'), $day);
    }

    /**
     * @param array<string, mixed> $schedule
     */
    private function format_schedule_label(array $schedule): string
    {
        $frequency = isset($schedule['frequency']) ? (string) $schedule['frequency'] : 'weekly';
        $time      = isset($schedule['time']) ? (string) $schedule['time'] : '';

        $parts = [$this->translate_frequency($frequency)];

        if ($frequency === 'monthly') {
            $parts[] = $this->format_monthday_label((int) ($schedule['monthday'] ?? 1));
        } elseif ($frequency === 'weekly') {
            $parts[] = $this->translate_weekday(isset($schedule['weekday']) ? (string) $schedule['weekday'] : 'monday');
        }

        $label = implode(' · ', array_filter($parts));
        if ($time !== '') {
            $label = $label !== '' ? $label . ', ' . $time : $time;
        }

        return $label;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array{current: array<int, array<string, mixed>>, history: array<int, array<string, mixed>>}
     */
    public function split_entries(array $entries, int $history_limit = 60): array
    {
        if (empty($entries)) {
            return [
                'current' => [],
                'history' => [],
            ];
        }

        $now        = new DateTimeImmutable('now', $this->get_timezone());
        $current_id = $this->current_entry_id($now);

        $current_entries = [];
        $history_entries = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $entry_id     = isset($entry['id']) ? (int) $entry['id'] : 0;
            $status       = isset($entry['status']) ? (string) $entry['status'] : '';
            $is_completed = $status === self::STATUS_COMPLETED;

            if ($is_completed && $entry_id !== $current_id) {
                $history_entries[] = $entry;
                continue;
            }

            if ($entry_id === $current_id) {
                $entry['is_current'] = true;
            }

            $current_entries[] = $entry;
        }

        usort($history_entries, static function ($a, $b) {
            $a_time = isset($a['scheduled_at_utc']) ? (string) $a['scheduled_at_utc'] : '';
            $b_time = isset($b['scheduled_at_utc']) ? (string) $b['scheduled_at_utc'] : '';
            return strcmp($b_time, $a_time);
        });

        if (count($history_entries) > $history_limit) {
            $history_entries = array_slice($history_entries, 0, $history_limit);
        }

        usort($current_entries, static function ($a, $b) {
            $a_time = isset($a['scheduled_at_utc']) ? (string) $a['scheduled_at_utc'] : '';
            $b_time = isset($b['scheduled_at_utc']) ? (string) $b['scheduled_at_utc'] : '';
            return strcmp($a_time, $b_time);
        });

        return [
            'current' => $current_entries,
            'history' => $history_entries,
        ];
    }

    public function get_timezone(): DateTimeZone
    {
        if (function_exists('wp_timezone')) {
            return wp_timezone();
        }

        $tz = get_option('timezone_string');
        if ($tz) {
            return new DateTimeZone((string) $tz);
        }

        return new DateTimeZone(date_default_timezone_get());
    }

    /**
     * @deprecated Backwards compatibility shim.
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    public function flag_current_entries(array $entries): array
    {
        $split = $this->split_entries($entries);
        return $split['current'];
    }
}
