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
            wp_send_json_error(['message' => __('Men�planungstabelle nicht vorhanden.', 'lotzapp-for-woocommerce')], 500);
        }

        $timezone = $this->service->get_timezone();
        $now      = new DateTimeImmutable('now', $timezone);

        if ($this->service->has_any_entry()) {
            $existing  = $this->service->get_scheduled_slots();
            $next_slot = $this->service->next_open_slot($existing);
        } else {
            $next_slot = $now;
        }
        $payload = $this->sanitize_payload(isset($_POST['payload']) ? wp_unslash($_POST['payload']) : null);

        $entry_id = $this->service->insert_entry($next_slot, $payload);
        if (!$entry_id) {
            wp_send_json_error(['message' => __('Men�plan konnte nicht angelegt werden.', 'lotzapp-for-woocommerce')], 500);
        }

        wp_send_json_success([
            'schedule' => $this->service->get_schedule_snapshot(),
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

        $update_data = [];
        if ($payload !== null) {
            $update_data['payload'] = $payload;
        }
        if ($status) {
            $update_data['status'] = $status;
        }

        if (empty($update_data)) {
            wp_send_json_error(['message' => __('Keine Änderungen übermittelt.', 'lotzapp-for-woocommerce')], 400);
        }

        if (!$this->service->update_entry($id, $update_data)) {
            wp_send_json_error(['message' => __('Eintrag konnte nicht aktualisiert werden.', 'lotzapp-for-woocommerce')], 500);
        }

        wp_send_json_success();
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

        if (!$this->runner->run_entry($id)) {
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
