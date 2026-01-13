<?php

namespace Lotzwoo\Services;

use DateTimeImmutable;
use DateTimeZone;
use Lotzwoo\Plugin;

if (!defined('ABSPATH')) {
    exit;
}

class Delivery_Time_Service
{
    public const OPTION_KEY = 'delivery_times';
    public const META_KEY = '_lotzwoo_delivery_time';
    public const TYPE_TEXT = 'text';
    public const TYPE_MENU_DAYS = 'menu_days';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function get_delivery_times(): array
    {
        $stored = Plugin::opt(self::OPTION_KEY, []);
        if (!is_array($stored)) {
            return [];
        }

        return $this->normalize_entries($stored);
    }

    /**
     * @return array<string, string>
     */
    public function get_delivery_time_options(): array
    {
        $options = [];
        foreach ($this->get_delivery_times() as $entry) {
            $id = isset($entry['id']) ? (string) $entry['id'] : '';
            if ($id === '') {
                continue;
            }
            $options[$id] = $this->format_label($entry);
        }

        return $options;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find_delivery_time(string $id): ?array
    {
        $id = sanitize_key($id);
        if ($id === '') {
            return null;
        }

        foreach ($this->get_delivery_times() as $entry) {
            if (($entry['id'] ?? '') === $id) {
                return $entry;
            }
        }

        return null;
    }

    public function format_output(array $entry): string
    {
        $type = isset($entry['type']) ? (string) $entry['type'] : '';
        if ($type === self::TYPE_TEXT) {
            return isset($entry['text']) ? (string) $entry['text'] : '';
        }

        if ($type !== self::TYPE_MENU_DAYS) {
            return '';
        }

        $days = isset($entry['days']) ? (int) $entry['days'] : 0;
        $days = max(0, $days);

        $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone(date_default_timezone_get());
        $base     = Plugin::next_menu_planning_event($timezone);
        $target   = $base->modify(sprintf('+%d days', $days));

        return function_exists('wp_date')
            ? wp_date('l, d.m.Y', $target->getTimestamp(), $timezone)
            : $target->format('l, d.m.Y');
    }

    public function create_id(): string
    {
        if (function_exists('wp_generate_uuid4')) {
            return 'dt_' . sanitize_key(wp_generate_uuid4());
        }

        return 'dt_' . sanitize_key(uniqid('', true));
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function format_label(array $entry): string
    {
        $type = isset($entry['type']) ? (string) $entry['type'] : '';
        if ($type === self::TYPE_TEXT) {
            return isset($entry['text']) ? (string) $entry['text'] : '';
        }

        if ($type === self::TYPE_MENU_DAYS) {
            $days = isset($entry['days']) ? (int) $entry['days'] : 0;
            $days = max(0, $days);
            if ($days === 1) {
                return __('1 Tag nach dem naechsten Menueplanwechsel', 'lotzapp-for-woocommerce');
            }
            return sprintf(__('%d Tage nach dem naechsten Menueplanwechsel', 'lotzapp-for-woocommerce'), $days);
        }

        return '';
    }

    /**
     * @param array<int, mixed> $stored
     * @return array<int, array<string, mixed>>
     */
    private function normalize_entries(array $stored): array
    {
        $normalized = [];

        foreach ($stored as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $type = isset($entry['type']) ? (string) $entry['type'] : '';
            $id   = isset($entry['id']) ? sanitize_key((string) $entry['id']) : '';
            if ($id === '') {
                $id = $this->create_id();
            }

            if ($type === self::TYPE_TEXT) {
                $text = isset($entry['text']) ? sanitize_text_field((string) $entry['text']) : '';
                if ($text === '') {
                    continue;
                }
                $normalized[] = [
                    'id'   => $id,
                    'type' => $type,
                    'text' => $text,
                ];
                continue;
            }

            if ($type === self::TYPE_MENU_DAYS) {
                $days = isset($entry['days']) ? (int) $entry['days'] : 0;
                $days = max(0, $days);
                $normalized[] = [
                    'id'   => $id,
                    'type' => $type,
                    'days' => $days,
                ];
            }
        }

        return $normalized;
    }
}
