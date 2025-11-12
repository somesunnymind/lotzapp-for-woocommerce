<?php

namespace Lotzwoo\Assets;

use Lotzwoo\Plugin;

if (!defined('ABSPATH')) {
    exit;
}

class Menu_Planning
{
    private const SCRIPT_HANDLE = 'lotzwoo-menu-planning';
    private const STYLE_HANDLE  = 'lotzwoo-menu-planning-style';
    private const LIB_SCRIPT_HANDLE = 'lotzwoo-tom-select';
    private const LIB_STYLE_HANDLE  = 'lotzwoo-tom-select-style';
    private const DND_SCRIPT_HANDLE = 'lotzwoo-menu-planning-dnd';
    private const BASE_STYLE_HANDLE = 'lotzwoo-shortcode-base';
    private const BASE_STYLE_VERSION = '0.1.0';

    /**
     * @var array<string, mixed>
     */
    private array $context = [];

    private bool $enqueued = false;

    /**
     * @param array<string, mixed> $context
     */
    public function enqueue(array $context = []): void
    {
        if (!empty($context)) {
            $this->context = $context;
        }

        if ($this->enqueued) {
            return;
        }

        $script_handle = self::SCRIPT_HANDLE;
        $style_handle  = self::STYLE_HANDLE;

        $script_src = trailingslashit(LOTZWOO_PLUGIN_URL) . 'assets/js/menu-planning.js';
        $style_src  = trailingslashit(LOTZWOO_PLUGIN_URL) . 'assets/css/menu-planning.css';
        $base_style_handle = $this->enqueue_base_style();

        if (!wp_script_is(self::LIB_SCRIPT_HANDLE, 'registered')) {
            wp_register_script(
                self::LIB_SCRIPT_HANDLE,
                trailingslashit(LOTZWOO_PLUGIN_URL) . 'assets/js/tom-select.min.js',
                [],
                '2.3.1',
                true
            );
        }

        if (!wp_style_is(self::LIB_STYLE_HANDLE, 'registered')) {
            wp_register_style(
                self::LIB_STYLE_HANDLE,
                trailingslashit(LOTZWOO_PLUGIN_URL) . 'assets/css/tom-select.min.css',
                [],
                '2.3.1'
            );
        }

        if (!wp_script_is($script_handle, 'registered')) {
            wp_register_script(
                $script_handle,
                $script_src,
                [self::LIB_SCRIPT_HANDLE],
                '0.1.0',
                true
            );
        }

        if (!wp_script_is(self::DND_SCRIPT_HANDLE, 'registered')) {
            wp_register_script(
                self::DND_SCRIPT_HANDLE,
                trailingslashit(LOTZWOO_PLUGIN_URL) . 'assets/js/menu-planning-dnd.js',
                [$script_handle],
                '0.1.0',
                true
            );
        }

        if (!wp_style_is($style_handle, 'registered')) {
            wp_register_style(
                $style_handle,
                $style_src,
                [$base_style_handle],
                '0.1.0'
            );
        }

        wp_localize_script(
            $script_handle,
            'lotzwooMenuPlanning',
            [
                'ajaxUrl'      => admin_url('admin-ajax.php'),
                'nonce'        => wp_create_nonce('lotzwoo_menu_planning'),
                'initialState' => $this->context,
                'showProductEditLinks' => Plugin::opt('menu_planning_show_backend_links') ? 1 : 0,
                'i18n'         => [
                    'heading'                 => __("Men\u{00FC}planung", 'lotzapp-for-woocommerce'),
                    'createButton'            => __("Neuen Men\u{00FC}plan anlegen", 'lotzapp-for-woocommerce'),
                    'save'                    => __("\u{00C4}nderungen speichern", 'lotzapp-for-woocommerce'),
                    'saved'                   => __('Gespeichert', 'lotzapp-for-woocommerce'),
                    'remove'                  => __('Termin entfernen', 'lotzapp-for-woocommerce'),
                    'loading'                 => __("Lade Men\u{00FC}planung \u{2026}", 'lotzapp-for-woocommerce'),
                    'empty'                   => __("Noch keine Men\u{00FC}plan-Eintr\u{00E4}ge vorhanden.", 'lotzapp-for-woocommerce'),
                    'timeColumn'              => __('Zeitpunkt', 'lotzapp-for-woocommerce'),
                    'actionsColumn'           => __('Aktionen', 'lotzapp-for-woocommerce'),
                    'statusColumn'            => __('Status', 'lotzapp-for-woocommerce'),
                    'assignmentsColumn'       => __('Zuordnungen', 'lotzapp-for-woocommerce'),
                    'statusPending'           => __('Ausstehend', 'lotzapp-for-woocommerce'),
                    'statusCompleted'         => __('Abgeschlossen', 'lotzapp-for-woocommerce'),
                    'statusActive'            => __('Aktiv', 'lotzapp-for-woocommerce'),
                    'statusCancelled'         => __('Abgebrochen', 'lotzapp-for-woocommerce'),
                    'tableMissing'            => __("Die Men\u{00FC}planungstabelle fehlt. Bitte Plugin aktualisieren oder die Einstellungen erneut speichern.", 'lotzapp-for-woocommerce'),
                    'errorGeneric'            => __('Es ist ein Fehler aufgetreten.', 'lotzapp-for-woocommerce'),
                    'confirmRemove'           => __('Diesen Termin wirklich entfernen?', 'lotzapp-for-woocommerce'),
                    'noProducts'              => __('Keine passenden Produkte vorhanden.', 'lotzapp-for-woocommerce'),
                    'addProduct'              => __("Produkt hinzuf\u{00FC}gen", 'lotzapp-for-woocommerce'),
                    'tabCurrent'              => __("Aktuelle & geplante Men\u{00FC}pl\u{00E4}ne", 'lotzapp-for-woocommerce'),
                    'tabHistory'              => __("Vergangene Men\u{00FC}pl\u{00E4}ne", 'lotzapp-for-woocommerce'),
                    'historyEmpty'            => __("Keine vergangenen Men\u{00FC}pl\u{00E4}ne vorhanden.", 'lotzapp-for-woocommerce'),
                    'historyAssignmentsEmpty' => __('Keine Produktzuordnungen gespeichert.', 'lotzapp-for-woocommerce'),
                    'applyNow'                => __("\u{00C4}nderungen jetzt anwenden", 'lotzapp-for-woocommerce'),
                    'applyingNow'             => __("\u{00C4}nderungen werden angewendet \u{2026}", 'lotzapp-for-woocommerce'),
                    'countdownRemaining'      => __('Noch %s', 'lotzapp-for-woocommerce'),
                    'countdownIn'             => __('In %s', 'lotzapp-for-woocommerce'),
                    'countdownDaySingle'      => __('1 Tag', 'lotzapp-for-woocommerce'),
                    'countdownDayPlural'      => __('%s Tage', 'lotzapp-for-woocommerce'),
                    'viewProduct'             => __('Anzeigen', 'lotzapp-for-woocommerce'),
                    'editProduct'             => __('Bearbeiten', 'lotzapp-for-woocommerce'),
                    'skuLabel'                => __('LotzApp-ID: %s', 'lotzapp-for-woocommerce'),
                    'skuMissing'              => __('fehlt', 'lotzapp-for-woocommerce'),
                ],
            ]
        );

        wp_enqueue_script(self::LIB_SCRIPT_HANDLE);
        wp_enqueue_script($script_handle);
        wp_enqueue_script(self::DND_SCRIPT_HANDLE);
        wp_enqueue_style(self::LIB_STYLE_HANDLE);
        wp_enqueue_style($style_handle);

        $this->enqueued = true;
    }

    private function enqueue_base_style(): string
    {
        $handle = self::BASE_STYLE_HANDLE;
        if (!wp_style_is($handle, 'registered')) {
            wp_register_style(
                $handle,
                trailingslashit(LOTZWOO_PLUGIN_URL) . 'assets/css/shortcode-base.css',
                [],
                self::BASE_STYLE_VERSION
            );
        }

        wp_enqueue_style($handle);

        return $handle;
    }
}

