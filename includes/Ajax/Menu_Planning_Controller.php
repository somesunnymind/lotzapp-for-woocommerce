<?php

namespace Lotzwoo\Ajax;

use DateTimeImmutable;
use Lotzwoo\Services\Menu_Planning_Runner;
use Lotzwoo\Services\Menu_Planning_Service;

if (!defined('ABSPATH')) {
    exit;
}

class Menu_Planning_Controller
{
    private const NONCE_ACTION = 'lotzwoo_menu_planning';

    private Menu_Planning_Service $service;
    private Menu_Planning_Runner $runner;

    public function __construct(Menu_Planning_Service $service, Menu_Planning_Runner $runner)
    {
        $this->service = $service;
        $this->runner  = $runner;
    }

    public function register(): void
    {
        add_action('wp_ajax_lotzwoo_menu_plan_list', [$this, 'handle_list']);
        add_action('wp_ajax_lotzwoo_menu_plan_create', [$this, 'handle_create']);
        add_action('wp_ajax_lotzwoo_menu_plan_update', [$this, 'handle_update']);
        add_action('wp_ajax_lotzwoo_menu_plan_delete', [$this, 'handle_delete']);
        add_action('wp_ajax_lotzwoo_menu_plan_run_now', [$this, 'handle_run_now']);
    }

    public function handle_list(): void
    {
        $this->guard_request();

        $limit  = isset($_GET['limit']) ? min(100, max(1, absint($_GET['limit']))) : 20;
        $offset = isset($_GET['offset']) ? max(0, absint($_GET['offset'])) : 0;

        if (!$this->service->table_exists()) {
            wp_send_json_success([
                'entries'        => [],
                'history'        => [],
                'tags'           => $this->service->get_menu_tags(),
                'schedule'       => $this->service->get_schedule_snapshot(),
                'needsMigration' => true,
            ]);
        }

        $this->service->sync_current_entry_payload_from_active_terms();

        $fetch_limit = $limit + 60;
        $entries_raw = $this->service->get_entries($fetch_limit, $offset);
        $entries     = array_map([$this->service, 'format_entry'], $entries_raw);
        $split       = $this->service->split_entries($entries);

        wp_send_json_success([
            'entries'  => $split['current'],
            'history'  => $split['history'],
            'tags'     => $this->service->get_menu_tags(),
            'schedule' => $this->service->get_schedule_snapshot(),
        ]);
    }

    public function handle_create(): void
    {
        $this->guard_request();

        if (!$this->service->table_exists()) {
            wp_send_json_error(['message' => __('Menüplanungstabelle nicht vorhanden.', 'lotzapp-for-woocommerce')], 500);
        }

        $timezone = $this->service->get_timezone();
        $now      = new DateTimeImmutable('now', $timezone);
        $now_minute = $now->setTime((int) $now->format('H'), (int) $now->format('i'), 0);

        // Caller may supply an explicit scheduled_at (popup dialog). When absent
        // we fall back to the legacy auto-slot calculation -- BUT only in auto
        // mode; in manual mode every entry must carry an explicit timestamp.
        $scheduled_at_input = isset($_POST['scheduled_at']) ? (string) wp_unslash($_POST['scheduled_at']) : '';
        $scheduled_at = $this->parse_user_datetime($scheduled_at_input, $timezone);
        if ($scheduled_at instanceof DateTimeImmutable && $scheduled_at < $now_minute) {
            wp_send_json_error(['message' => __('Bitte einen Zeitpunkt ab jetzt waehlen.', 'lotzapp-for-woocommerce')], 400);
        }

        if ($scheduled_at === null) {
            if (\Lotzwoo\Plugin::menu_planning_mode() === 'manual') {
                wp_send_json_error([
                    'message' => __('Im manuellen Modus muss Datum und Uhrzeit gesetzt werden.', 'lotzapp-for-woocommerce'),
                ], 400);
            }

            if ($this->service->has_any_entry()) {
                $existing     = $this->service->get_scheduled_slots();
                $scheduled_at = $this->service->next_open_slot($existing);
            } else {
                $scheduled_at = $now;
            }
        }

        $payload = $this->sanitize_payload(isset($_POST['payload']) ? wp_unslash($_POST['payload']) : null);

        $entry_id = $this->service->insert_entry($scheduled_at, $payload);
        if (!$entry_id) {
            wp_send_json_error(['message' => __('Menüplan konnte nicht angelegt werden.', 'lotzapp-for-woocommerce')], 500);
        }

        wp_send_json_success([
            'schedule' => $this->service->get_schedule_snapshot(),
            'id'       => $entry_id,
        ]);
    }
    public function handle_update(): void
    {
        $this->guard_request();

        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        if ($id <= 0) {
            wp_send_json_error(['message' => __('Ungültige ID.', 'lotzapp-for-woocommerce')], 400);
        }

        $entry = $this->service->find_entry($id);
        if (!$entry) {
            wp_send_json_error(['message' => __('Eintrag nicht gefunden.', 'lotzapp-for-woocommerce')], 404);
        }

        $payload = null;
        if (isset($_POST['payload'])) {
            $payload = $this->sanitize_payload(wp_unslash($_POST['payload']));
        }

        $status = isset($_POST['status']) ? sanitize_key((string) wp_unslash($_POST['status'])) : null;

        $scheduled_at_input = isset($_POST['scheduled_at']) ? (string) wp_unslash($_POST['scheduled_at']) : '';
        $timezone           = $this->service->get_timezone();
        $scheduled_at       = $scheduled_at_input !== ''
            ? $this->parse_user_datetime($scheduled_at_input, $timezone)
            : null;
        $now                = new DateTimeImmutable('now', $timezone);
        $now_minute         = $now->setTime((int) $now->format('H'), (int) $now->format('i'), 0);
        if ($scheduled_at instanceof DateTimeImmutable && $scheduled_at < $now_minute) {
            wp_send_json_error(['message' => __('Bitte einen Zeitpunkt ab jetzt waehlen.', 'lotzapp-for-woocommerce')], 400);
        }

        $apply_now = !empty($_POST['apply_now']) && (string) $_POST['apply_now'] !== '0';

        $update_data = [];
        if ($payload !== null) {
            $update_data['payload'] = $payload;
        }
        if ($status) {
            $update_data['status'] = $status;
        }
        if ($scheduled_at instanceof DateTimeImmutable) {
            $update_data['scheduled_at'] = $scheduled_at;
        }

        if (empty($update_data)) {
            wp_send_json_error(['message' => __('Keine Änderungen übermittelt.', 'lotzapp-for-woocommerce')], 400);
        }

        if (!$this->service->update_entry($id, $update_data)) {
            wp_send_json_error(['message' => __('Eintrag konnte nicht aktualisiert werden.', 'lotzapp-for-woocommerce')], 500);
        }

        if ($apply_now && !$this->runner->run_entry($id, true)) {
            wp_send_json_error(['message' => __('Der Menüplan konnte noch nicht angewendet werden. Bitte überprüfe den Zeitpunkt und versuche es erneut.', 'lotzapp-for-woocommerce')], 400);
        }

        wp_send_json_success([
            'schedule' => $this->service->get_schedule_snapshot(),
        ]);
    }

    public function handle_delete(): void
    {
        $this->guard_request();

        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        if ($id <= 0) {
            wp_send_json_error(['message' => __('Ungültige ID.', 'lotzapp-for-woocommerce')], 400);
        }

        if (!$this->service->delete_entry($id)) {
            wp_send_json_error(['message' => __('Eintrag konnte nicht entfernt werden.', 'lotzapp-for-woocommerce')], 500);
        }

        wp_send_json_success();
    }

    public function handle_run_now(): void
    {
        $this->guard_request();

        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        if ($id <= 0) {
            wp_send_json_error(['message' => __('Ungültige ID.', 'lotzapp-for-woocommerce')], 400);
        }

        $entry = $this->service->find_entry($id);
        if (!$entry) {
            wp_send_json_error(['message' => __('Eintrag nicht gefunden.', 'lotzapp-for-woocommerce')], 404);
        }

        if (isset($_POST['payload'])) {
            $payload = $this->sanitize_payload(wp_unslash($_POST['payload']));
            $this->service->update_entry($id, ['payload' => $payload]);
            $entry = $this->service->find_entry($id);
            if (!$entry) {
                wp_send_json_error(['message' => __('Eintrag konnte nach dem Speichern nicht geladen werden.', 'lotzapp-for-woocommerce')], 500);
            }
        }

        if (!$this->runner->run_entry($id, true)) {
            wp_send_json_error(['message' => __('Der Menüplan konnte noch nicht angewendet werden. Bitte überprüfe den Zeitpunkt und versuche es erneut.', 'lotzapp-for-woocommerce')], 400);
        }

        wp_send_json_success([
            'schedule' => $this->service->get_schedule_snapshot(),
        ]);
    }

    private function guard_request(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Nicht erlaubt.', 'lotzapp-for-woocommerce')], 403);
        }

        check_ajax_referer(self::NONCE_ACTION, 'nonce');
    }

    /**
     * Parse a user-supplied date/time string in the site's local timezone.
     * Accepts ISO-ish or "Y-m-d H:i" / "Y-m-d\TH:i" pairs from <input type="date"> + time.
     * Returns null if the value is empty or unparseable so callers can fall back.
     */
    private function parse_user_datetime(string $input, \DateTimeZone $timezone): ?DateTimeImmutable
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        $normalized = str_replace('T', ' ', $input);
        try {
            return new DateTimeImmutable($normalized, $timezone);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * @param string|null $raw
     * @return array<string, array<int, int>>
     */
    private function sanitize_payload($raw): array
    {
        if (is_array($raw)) {
            $data = $raw;
        } elseif (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $data    = is_array($decoded) ? $decoded : [];
        } else {
            $data = [];
        }

        $clean = [];
        foreach ($data as $tag => $product_ids) {
            if (!is_string($tag)) {
                continue;
            }
            if (!is_array($product_ids)) {
                continue;
            }
            $clean[$tag] = array_values(array_filter(array_map('absint', $product_ids)));
        }

        return $clean;
    }

}
