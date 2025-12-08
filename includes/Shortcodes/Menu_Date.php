<?php

namespace Lotzwoo\Shortcodes;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Lotzwoo\Plugin;
use Lotzwoo\Services\Menu_Planning_Service;

if (!defined('ABSPATH')) {
    exit;
}

class Menu_Date
{
    private Menu_Planning_Service $service;

    public function __construct(Menu_Planning_Service $service)
    {
        $this->service = $service;
    }

    public function register(): void
    {
        add_shortcode('lotzmenu_date', [$this, 'render']);
    }

    public function render($atts = [], $content = '', $tag = ''): string
    {
        $atts = shortcode_atts([
            'period' => 'current',
            'value'  => 'start',
            'format' => '',
        ], $atts, $tag);

        $period = $this->sanitize_period(isset($atts['period']) ? (string) $atts['period'] : '');
        $value  = $this->sanitize_value(isset($atts['value']) ? (string) $atts['value'] : '');
        $format = isset($atts['format']) && is_string($atts['format']) ? (string) $atts['format'] : '';
        $format = $format !== '' ? $format : $this->default_format();

        $bounds = $this->get_period_bounds($period);
        if ($bounds === null) {
            return '';
        }

        $timezone = $this->service->get_timezone();
        $now      = new DateTimeImmutable('now', $timezone);

        if ($value === 'start') {
            return $this->format_datetime($bounds['start'], $format);
        }

        if ($value === 'end') {
            return $this->format_datetime($bounds['end'], $format);
        }

        return $this->format_remaining($now, $bounds['end']);
    }

    /**
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable}|null
     */
    private function get_period_bounds(string $period): ?array
    {
        $timezone = $this->service->get_timezone();
        $now      = new DateTimeImmutable('now', $timezone);
        $schedule = Plugin::menu_planning_schedule();

        $current_entry = $this->service->table_exists() ? $this->service->find_previous_entry($now) : null;
        $next_entry    = $this->service->table_exists() ? $this->service->find_next_entry($now) : null;

        $current_start = $this->entry_to_local_datetime($current_entry, $timezone);
        $next_start    = $this->entry_to_local_datetime($next_entry, $timezone);

        if ($period === 'next') {
            $next_start = $next_start ?: $this->next_occurrence($now);
            if ($next_start <= $now) {
                $next_start = $this->next_occurrence($now);
            }
            $next_end_entry = $this->service->table_exists() ? $this->service->find_next_entry($next_start->modify('+1 minute')) : null;
            $next_end = $this->entry_to_local_datetime($next_end_entry, $timezone);
            $next_end = $next_end ?: $this->next_occurrence($next_start);
            if ($next_end <= $next_start) {
                $next_end = $this->next_occurrence($next_start);
            }

            return [
                'start' => $next_start,
                'end'   => $next_end,
            ];
        }

        $start = $current_start ?: $this->previous_occurrence($schedule, $now);
        if ($start > $now) {
            $start = $this->previous_occurrence($schedule, $now);
        }

        $end = $next_start ?: $this->next_occurrence($now);
        if ($end <= $now) {
            $end = $this->next_occurrence($now);
        }

        return [
            'start' => $start,
            'end'   => $end,
        ];
    }

    private function format_datetime(DateTimeInterface $datetime, string $format): string
    {
        $timestamp = $datetime->getTimestamp();
        $timezone  = method_exists($datetime, 'getTimezone') ? $datetime->getTimezone() : $this->service->get_timezone();

        if (function_exists('wp_date')) {
            return (string) wp_date($format, $timestamp, $timezone);
        }

        return date_i18n($format, $timestamp);
    }

    private function format_remaining(DateTimeImmutable $now, DateTimeImmutable $end): string
    {
        $end_timestamp = $end->getTimestamp();
        $now_timestamp = $now->getTimestamp();

        if ($end_timestamp <= $now_timestamp) {
            return '0';
        }

        return human_time_diff($now_timestamp, $end_timestamp);
    }

    private function sanitize_period(string $period): string
    {
        $period = strtolower(trim($period));
        return in_array($period, ['next', 'current'], true) ? $period : 'current';
    }

    private function sanitize_value(string $value): string
    {
        $value = strtolower(trim($value));
        $allowed = ['start', 'end', 'remaining'];
        return in_array($value, $allowed, true) ? $value : 'start';
    }

    private function default_format(): string
    {
        $date_format = get_option('date_format') ?: 'Y-m-d';
        $time_format = get_option('time_format') ?: 'H:i';
        return trim($date_format . ' ' . $time_format);
    }

    private function entry_to_local_datetime(?array $entry, DateTimeZone $timezone): ?DateTimeImmutable
    {
        if (!$entry || empty($entry['scheduled_at'])) {
            return null;
        }

        try {
            $utc = new DateTimeImmutable((string) $entry['scheduled_at'], new DateTimeZone('UTC'));
        } catch (\Exception $e) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCATCH
            return null;
        }

        return $utc->setTimezone($timezone);
    }

    private function next_occurrence(DateTimeImmutable $reference): DateTimeImmutable
    {
        return Plugin::next_menu_planning_event($this->service->get_timezone(), $reference->modify('+1 minute'));
    }

    private function previous_occurrence(array $schedule, DateTimeImmutable $reference): DateTimeImmutable
    {
        [$hour, $minute] = $this->parse_time($schedule['time'] ?? '00:00');

        $frequency = $schedule['frequency'] ?? 'weekly';
        if ($frequency === 'daily') {
            return $this->previous_daily_occurrence($reference, $hour, $minute);
        }

        if ($frequency === 'monthly') {
            $day = isset($schedule['monthday']) ? (int) $schedule['monthday'] : 1;
            return $this->previous_monthly_occurrence($reference, $day, $hour, $minute);
        }

        $weekday = isset($schedule['weekday']) ? (string) $schedule['weekday'] : 'monday';
        return $this->previous_weekly_occurrence($reference, $weekday, $hour, $minute);
    }

    /**
     * @return array{0:int,1:int}
     */
    private function parse_time(string $time): array
    {
        $parts  = array_map('intval', explode(':', $time));
        $hour   = isset($parts[0]) ? max(0, min(23, (int) $parts[0])) : 0;
        $minute = isset($parts[1]) ? max(0, min(59, (int) $parts[1])) : 0;

        return [$hour, $minute];
    }

    private function previous_daily_occurrence(DateTimeImmutable $from, int $hour, int $minute): DateTimeImmutable
    {
        $candidate = $from->setTime($hour, $minute);
        if ($candidate > $from) {
            $candidate = $candidate->modify('-1 day');
        }
        return $candidate;
    }

    private function previous_weekly_occurrence(DateTimeImmutable $from, string $weekday, int $hour, int $minute): DateTimeImmutable
    {
        $weekday = strtolower(trim($weekday));
        $valid   = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        if (!in_array($weekday, $valid, true)) {
            $weekday = 'monday';
        }

        $candidate = $from->modify('this ' . $weekday)->setTime($hour, $minute);
        if ($candidate > $from) {
            $candidate = $candidate->modify('-1 week');
        }
        return $candidate;
    }

    private function previous_monthly_occurrence(DateTimeImmutable $from, int $monthday, int $hour, int $minute): DateTimeImmutable
    {
        $monthday = max(1, min(31, $monthday));
        $candidate = $this->build_monthly_candidate($from, $monthday, $hour, $minute);

        if ($candidate > $from) {
            $previous  = $from->modify('first day of last month');
            $candidate = $this->build_monthly_candidate($previous, $monthday, $hour, $minute);
        }

        return $candidate;
    }

    private function build_monthly_candidate(DateTimeImmutable $reference, int $monthday, int $hour, int $minute): DateTimeImmutable
    {
        $year  = (int) $reference->format('Y');
        $month = (int) $reference->format('n');
        $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $target_day    = min($monthday, $days_in_month);

        return $reference
            ->setDate($year, $month, $target_day)
            ->setTime($hour, $minute);
    }
}
