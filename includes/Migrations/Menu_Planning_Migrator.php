<?php

namespace Lotzwoo\Migrations;

use wpdb;

if (!defined('ABSPATH')) {
    exit;
}

class Menu_Planning_Migrator
{
    private const OPTION_KEY = 'lotzwoo_menu_plan_table_version';
    private const TABLE_VERSION = 1;

    public function maybe_run(): void
    {
        $current = (int) get_option(self::OPTION_KEY, 0);
        if ($current >= self::TABLE_VERSION) {
            return;
        }

        $this->run();
        update_option(self::OPTION_KEY, self::TABLE_VERSION);
    }

    private function run(): void
    {
        global $wpdb;
        if (!$wpdb instanceof wpdb) {
            return;
        }

        $table_name      = $wpdb->prefix . 'lotzwoo_menu_plan';
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            scheduled_at DATETIME NOT NULL,
            payload LONGTEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY scheduled_status (scheduled_at, status),
            KEY status_idx (status)
        ) {$charset_collate};";

        dbDelta($sql);
    }
}
