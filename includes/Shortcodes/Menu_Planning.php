<?php

namespace Lotzwoo\Shortcodes;

use Lotzwoo\Assets\Menu_Planning as Menu_Planning_Assets;
use Lotzwoo\Services\Menu_Planning_Service;

if (!defined('ABSPATH')) {
    exit;
}

class Menu_Planning
{
    private Menu_Planning_Service $service;
    private Menu_Planning_Assets $assets;

    public function __construct(Menu_Planning_Service $service, Menu_Planning_Assets $assets)
    {
        $this->service = $service;
        $this->assets  = $assets;
    }

    public function register(): void
    {
        add_shortcode('lotzwoo_menu_planning', [$this, 'render']);
    }

    public function render($atts = [], $content = '', $tag = ''): string
    {
        if (!current_user_can('manage_woocommerce')) {
            return '<p>' . esc_html__('Diese Ansicht erfordert die Berechtigung zum Verwalten von WooCommerce.', 'lotzapp-for-woocommerce') . '</p>';
        }

        $table_exists = $this->service->table_exists();
        if ($table_exists) {
            $entries_raw = $this->service->get_entries(80, 0);
            $entries     = array_map([$this->service, 'format_entry'], $entries_raw);
            $split       = $this->service->split_entries($entries);
        } else {
            $split = [
                'current' => [],
                'history' => [],
            ];
        }

        $initial_state = [
            'tableExists'     => $table_exists,
            'needsMigration'  => !$table_exists,
            'entries'         => $split['current'],
            'historyEntries'  => $split['history'],
            'tags'            => $this->service->get_menu_tags(),
            'schedule'        => $this->service->get_schedule_snapshot(),
        ];

        $this->assets->enqueue($initial_state);

        return $this->render_template([
            'initial_state' => $initial_state,
            'table_exists'  => $table_exists,
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render_template(array $context): string
    {
        $template = trailingslashit(LOTZWOO_PLUGIN_DIR) . 'templates/shortcodes/menu-planning.php';
        if (!file_exists($template)) {
            return '';
        }

        $initial_state = $context['initial_state'];
        $table_exists  = $context['table_exists'];

        ob_start();
        include $template;
        return (string) ob_get_clean();
    }
}
