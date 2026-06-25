<?php

namespace Lotzwoo\Admin;

use Lotzwoo\Translations\Email_Content_Extractor;
use Lotzwoo\Translations\MO_Writer;
use Lotzwoo\Translations\PO_File;
use Lotzwoo\Translations\String_Scanner;
use Lotzwoo\Translations\TP_Bridge;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * LotzApp Translations admin page.
 *
 * Single control surface for translating the plugin: detects TranslatePress's
 * configured target languages + MT engine and offers a per-language "Generate"
 * button. The generation pipeline itself (source-string scanner, PO/MO writer,
 * TP MT bridge, TP dictionary pre-warm) is wired in subsequent phases — this
 * file is the foundation: menu, status read-out, languages table, handler stub.
 */
class Translations_Page
{
    public const MENU_SLUG     = 'lotzwoo-translations';
    public const NONCE_ACTION  = 'lotzwoo_translations_generate';
    public const ADMIN_ACTION  = 'lotzwoo_translations_generate';

    public function __construct()
    {
        add_action('admin_post_' . self::ADMIN_ACTION, [$this, 'handle_generate']);
    }

    public function render(): void
    {
        $this->render_content(true);
    }

    public function render_tab(): void
    {
        $this->render_content(false);
    }

    private function render_content(bool $standalone): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Keine Berechtigung.', 'lotzapp-for-woocommerce'));
        }

        $source    = $this->source_locale();
        $tp        = $this->translatepress_status();
        $languages = $this->target_languages();
        $flash     = $this->flash_notice();

        if ($standalone) {
            echo '<div class="wrap">';
            echo '<h1>' . esc_html__('LotzApp - Uebersetzungen', 'lotzapp-for-woocommerce') . '</h1>';
        } else {
            echo '<h2 style="margin-top:18px;">' . esc_html__('TranslatePress', 'lotzapp-for-woocommerce') . '</h2>';
        }

        if ($flash !== null) {
            printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                esc_attr($flash['type']),
                esc_html($flash['message'])
            );
        }

        // Status panel.
        echo '<table class="widefat" style="max-width:780px;margin:14px 0;"><tbody>';
        printf(
            '<tr><th style="width:260px;">%s</th><td><code>%s</code></td></tr>',
            esc_html__('Quellsprache (Plugin)', 'lotzapp-for-woocommerce'),
            esc_html($source)
        );
        printf(
            '<tr><th>%s</th><td>%s</td></tr>',
            esc_html__('TranslatePress', 'lotzapp-for-woocommerce'),
            $tp['active']
                ? esc_html__('aktiv', 'lotzapp-for-woocommerce')
                : '<span style="color:#a00;">' . esc_html__('nicht aktiv – Plugin installieren und aktivieren', 'lotzapp-for-woocommerce') . '</span>'
        );
        $mt_label = $tp['mt_engine_label'];
        $mt_cell  = $mt_label !== ''
            ? esc_html(sprintf(__('aktiv (%s)', 'lotzapp-for-woocommerce'), $mt_label))
            : '<span style="color:#666;">' . esc_html__('nicht konfiguriert — manueller Workflow in Loco ist ohne MT möglich', 'lotzapp-for-woocommerce') . '</span>';
        printf('<tr><th>%s</th><td>%s</td></tr>', esc_html__('Auto-Übersetzungs-Engine (optional, über TP)', 'lotzapp-for-woocommerce'), $mt_cell);
        echo '</tbody></table>';

        if (empty($languages)) {
            echo '<div class="notice notice-warning inline"><p>' .
                esc_html__('Es sind keine Zielsprachen in TranslatePress konfiguriert. Füge sie unter Einstellungen → TranslatePress → Allgemein → Übersetzungssprachen hinzu — dann erscheinen sie hier.', 'lotzapp-for-woocommerce') .
                '</p></div>';
            if ($standalone) {
                echo '</div>';
            }
            return;
        }

        $can_generate = $tp['active'];

        $scope       = 'ui';
        $scope_label = __('Übersetzungsdateien für LotzApp for WooCommerce', 'lotzapp-for-woocommerce');

        // The main plugin translation file intentionally includes all relevant
        // plugin strings plus custom WooCommerce email content.
        echo '<h2 style="margin-top:18px;">' . esc_html($scope_label) . '</h2>';

        echo '<table class="widefat striped" style="max-width:980px;"><thead><tr>';
        echo '<th>' . esc_html__('Sprache', 'lotzapp-for-woocommerce') . '</th>';
        echo '<th>' . esc_html__('Datei (.po/.mo)', 'lotzapp-for-woocommerce') . '</th>';
        echo '<th>' . esc_html__('Aktion', 'lotzapp-for-woocommerce') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($languages as $locale => $label) {
            echo '<tr>';
            echo '<td><strong>' . esc_html($label) . '</strong> <code>' . esc_html($locale) . '</code></td>';
            echo '<td>' . esc_html($this->po_file_status($locale)) . '</td>';
            echo '<td>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline;">';
            echo '<input type="hidden" name="action" value="' . esc_attr(self::ADMIN_ACTION) . '" />';
            echo '<input type="hidden" name="locale" value="' . esc_attr($locale) . '" />';
            wp_nonce_field(self::NONCE_ACTION);
            $extra = $can_generate ? '' : 'disabled="disabled"';
            submit_button(__('Generieren', 'lotzapp-for-woocommerce'), 'primary small', 'submit', false, $extra);
            echo '</form>';

            $loco_url = $this->loco_url_for($locale);
            if ($loco_url !== '') {
                echo ' <a class="button button-secondary button-small" href="' . esc_url($loco_url) . '">'
                    . esc_html__('In Loco öffnen', 'lotzapp-for-woocommerce') . '</a>';
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<p class="description"><strong>' . esc_html__('Workflow:', 'lotzapp-for-woocommerce') . '</strong> '
            . esc_html__('1. Klicke „Generieren" für die gewünschte Sprache — alle Plugin-Strings sowie eigene WooCommerce-E-Mail-Betreffzeilen, -Überschriften und LotzApp-Mailtexte werden nach wp-content/languages/plugins/ als .po + .mo geschrieben. 2. Klicke „In Loco öffnen" — Loco zeigt die LotzApp-Übersetzungsdateien. 3. Klicke in Loco auf die Zeile deiner Sprache (z. B. „English (UK)") — der Editor öffnet sich, übersetze die Strings und speichere. Loco schreibt direkt in dieselbe Datei. Beim nächsten „Generieren" werden deine manuellen Übersetzungen automatisch beibehalten.', 'lotzapp-for-woocommerce')
            . '</p>';

        if ($standalone) {
            echo '</div>';
        }
    }

    public function handle_generate(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Keine Berechtigung.', 'lotzapp-for-woocommerce'));
        }
        check_admin_referer(self::NONCE_ACTION);

        $locale = isset($_POST['locale']) ? sanitize_text_field(wp_unslash($_POST['locale'])) : '';
        if ($locale === '' || !array_key_exists($locale, $this->target_languages())) {
            $this->redirect_back('no-locale');
            return;
        }

        $scope    = 'ui';
        $basename = 'lotzapp-for-woocommerce';

        try {
            // 1. Collect all relevant plugin and custom WooCommerce email strings.
            $entries = (new String_Scanner())->scan(LOTZWOO_PLUGIN_DIR);
            $entries = $this->merge_all_email_content_entries($entries);
            $total = count($entries);
            if ($total === 0) {
                $this->redirect_back('empty', $locale, [], $scope);
                return;
            }

            // 2. Load existing translations from the previously-written .mo
            //    (preserves manual edits made in Loco across re-generates).
            $dir = trailingslashit(WP_LANG_DIR) . 'plugins/';
            $mo_path = $dir . $basename . '-' . $locale . '.mo';
            $existing = MO_Writer::read($mo_path);

            $translations    = [];
            $preserved_count = 0;
            $needs_mt        = [];
            foreach ($entries as $key => $entry) {
                $mo_key = ($entry['msgctxt'] !== null ? $entry['msgctxt'] . "\x04" : '') . $entry['msgid'];
                if ($entry['msgid_plural'] !== null) {
                    $mo_key .= "\x00" . $entry['msgid_plural'];
                }

                if (isset($existing[$mo_key]) && $existing[$mo_key] !== '') {
                    if ($entry['msgid_plural'] !== null) {
                        $parts = explode("\x00", $existing[$mo_key]);
                        $translations[$key]              = $parts[0] ?? '';
                        $translations[$key . '__plural'] = $parts[1] ?? '';
                    } else {
                        $translations[$key] = $existing[$mo_key];
                    }
                    $preserved_count++;
                } else {
                    $translations[$key] = '';
                    $needs_mt[$key]     = $entry['msgid'];
                }
            }

            // 3. Run MT only on entries that still need a translation.
            $bridge        = new TP_Bridge();
            $source_locale = $this->source_locale();
            $mt_count      = 0;
            if (!empty($needs_mt)) {
                $mt_result = $bridge->translate($needs_mt, $source_locale, $locale);
                foreach ($mt_result as $key => $val) {
                    if (is_string($val) && $val !== '') {
                        $translations[$key] = $val;
                        $mt_count++;
                    }
                }
            }

            // 4. Compose .po + build .mo and write side-by-side.
            $po_text = (new PO_File())->compose($entries, $translations, $locale);
            $mo_data = (new MO_Writer())->build_from_entries($entries, $translations, $locale);

            if (!is_dir($dir)) {
                wp_mkdir_p($dir);
            }
            $base  = $dir . $basename . '-' . $locale;
            $po_ok = file_put_contents($base . '.po', $po_text) !== false;
            $mo_ok = file_put_contents($base . '.mo', $mo_data) !== false;
            if (!$po_ok || !$mo_ok) {
                $this->redirect_back('write-failed', $locale, [], $scope);
                return;
            }

            $mt_available = $bridge->is_available();
            $code = ($preserved_count > 0)
                ? ($mt_available ? 'merged' : 'merged-no-mt')
                : ($mt_available ? 'generated' : 'generated-no-mt');

            $this->redirect_back($code, $locale, [
                'total'      => $total,
                'translated' => $mt_count,
                'preserved'  => $preserved_count,
            ], $scope);
        } catch (\Throwable $e) {
            $this->redirect_back('error', $locale, ['error' => $e->getMessage()], $scope);
        }
    }

    /**
     * Add custom WooCommerce email subject/heading/body strings to the main
     * plugin translation file as a convenience for Loco Translate users.
     *
     * @param array<string, array{msgid:string, msgid_plural:?string, msgctxt:?string, refs:string[]}> $entries
     * @return array<string, array{msgid:string, msgid_plural:?string, msgctxt:?string, refs:string[]}>
     */
    private function merge_all_email_content_entries(array $entries): array
    {
        $extractor = new Email_Content_Extractor();
        foreach ($extractor->emails_with_content() as $email_id => $_title) {
            foreach ($extractor->extract($email_id) as $_field => $entry) {
                $key = ($entry['msgctxt'] !== null ? $entry['msgctxt'] . "\x04" : '') . $entry['msgid'];
                if (!isset($entries[$key])) {
                    $entries[$key] = $entry;
                    continue;
                }

                foreach ($entry['refs'] as $ref) {
                    if (!in_array($ref, $entries[$key]['refs'], true)) {
                        $entries[$key]['refs'][] = $ref;
                    }
                }
            }
        }

        ksort($entries);
        return $entries;
    }
    /**
     * @param array{total?:int, translated?:int, preserved?:int, error?:string} $extras
     */
    private function redirect_back(string $msg, string $locale = '', array $extras = [], string $scope = 'ui'): void
    {
        $args = ['page' => 'lotzwoo-settings', 'tab' => 'translatepress', 'lotzwoo_msg' => $msg];
        if ($locale !== '') {
            $args['locale'] = $locale;
        }
        if (isset($extras['total']))      { $args['total'] = (int) $extras['total']; }
        if (isset($extras['translated'])) { $args['done']  = (int) $extras['translated']; }
        if (isset($extras['preserved']))  { $args['kept']  = (int) $extras['preserved']; }
        if (isset($extras['error']))      { $args['err']   = substr((string) $extras['error'], 0, 200); }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    /**
     * @return array{type:string,message:string}|null
     */
    private function flash_notice(): ?array
    {
        $msg   = isset($_GET['lotzwoo_msg']) ? sanitize_text_field(wp_unslash($_GET['lotzwoo_msg'])) : '';
        $loc   = isset($_GET['locale']) ? sanitize_text_field(wp_unslash($_GET['locale'])) : '';
        $total = isset($_GET['total']) ? (int) $_GET['total'] : 0;
        $done  = isset($_GET['done'])  ? (int) $_GET['done']  : 0;
        $kept  = isset($_GET['kept'])  ? (int) $_GET['kept']  : 0;
        $err   = isset($_GET['err'])   ? sanitize_text_field(wp_unslash($_GET['err'])) : '';
        if ($msg === '') {
            return null;
        }
        $loc_disp = $loc !== '' ? $loc : '?';

        return match ($msg) {
            'generated' => [
                'type'    => 'success',
                'message' => sprintf(
                    __('Generiert für %1$s: %2$d von %3$d Strings per MT übersetzt; .po + .mo nach wp-content/languages/plugins/ geschrieben. Restliche Strings kannst du in Loco manuell übersetzen.', 'lotzapp-for-woocommerce'),
                    $loc_disp,
                    $done,
                    $total
                ),
            ],
            'generated-no-mt' => [
                'type'    => 'info',
                'message' => sprintf(
                    __('Für %1$s erstellt: %2$d Strings extrahiert; .po + .mo nach wp-content/languages/plugins/ geschrieben. Keine MT-Engine konfiguriert — öffne die .po in Loco Translate und übersetze manuell. Loco speichert direkt in dieselbe Datei.', 'lotzapp-for-woocommerce'),
                    $loc_disp,
                    $total
                ),
            ],
            'merged' => [
                'type'    => 'success',
                'message' => sprintf(
                    __('Aktualisiert für %1$s: %2$d bestehende Übersetzungen beibehalten, %3$d neu per MT übersetzt, von insgesamt %4$d Strings.', 'lotzapp-for-woocommerce'),
                    $loc_disp,
                    $kept,
                    $done,
                    $total
                ),
            ],
            'merged-no-mt' => [
                'type'    => 'info',
                'message' => sprintf(
                    __('Aktualisiert für %1$s: %2$d bestehende Übersetzungen beibehalten (keine MT-Engine; neue Strings bleiben leer). Insgesamt %3$d Strings; öffne in Loco zum Bearbeiten.', 'lotzapp-for-woocommerce'),
                    $loc_disp,
                    $kept,
                    $total
                ),
            ],
            'empty' => [
                'type'    => 'warning',
                'message' => __('Keine Strings im Plugin gefunden — nichts zu schreiben.', 'lotzapp-for-woocommerce'),
            ],
            'write-failed' => [
                'type'    => 'error',
                'message' => sprintf(
                    __('Schreiben nach wp-content/languages/plugins/ fehlgeschlagen für %s. Verzeichnisrechte prüfen.', 'lotzapp-for-woocommerce'),
                    $loc_disp
                ),
            ],
            'error' => [
                'type'    => 'error',
                'message' => sprintf(
                    __('Fehler beim Generieren für %1$s: %2$s', 'lotzapp-for-woocommerce'),
                    $loc_disp,
                    $err !== '' ? $err : __('unbekannt', 'lotzapp-for-woocommerce')
                ),
            ],
            'no-locale' => [
                'type'    => 'error',
                'message' => __('Keine (gültige) Zielsprache angegeben.', 'lotzapp-for-woocommerce'),
            ],
            default => null,
        };
    }

    private function source_locale(): string
    {
        $settings = (array) get_option('trp_settings', []);
        $default  = isset($settings['default-language']) ? (string) $settings['default-language'] : '';
        return $default !== '' ? $default : (string) get_locale();
    }

    /** @return array<string,string> locale => human label */
    private function target_languages(): array
    {
        $settings = (array) get_option('trp_settings', []);
        $targets  = (isset($settings['translation-languages']) && is_array($settings['translation-languages']))
            ? $settings['translation-languages']
            : [];
        $default  = isset($settings['default-language']) ? (string) $settings['default-language'] : '';

        $out = [];
        foreach ($targets as $locale) {
            $locale = (string) $locale;
            if ($locale === '' || $locale === $default) {
                continue;
            }
            $out[$locale] = $this->locale_label($locale);
        }
        ksort($out);
        return $out;
    }

    /**
     * @return array{active:bool, mt_engine_label:string, mt_engine_code:string}
     */
    private function translatepress_status(): array
    {
        $active = class_exists('TRP_Translate_Press') || class_exists('\\TRP_Translate_Press');

        $mt = (array) get_option('trp_machine_translation_settings', []);
        $on = (isset($mt['machine-translation']) && $mt['machine-translation'] === 'yes');

        $engine_code  = $on ? (string) ($mt['translation-engine'] ?? '') : '';
        $engine_label = '';
        if ($engine_code !== '') {
            $engine_label = match ($engine_code) {
                'google_translate_v2' => 'Google Translate',
                'deepl'               => 'DeepL',
                default               => $engine_code,
            };
        }

        return [
            'active'          => $active,
            'mt_engine_label' => $engine_label,
            'mt_engine_code'  => $engine_code,
        ];
    }

    private function po_file_status(string $locale): string
    {
        $base = 'lotzapp-for-woocommerce';
        $paths = [
            trailingslashit(WP_LANG_DIR) . 'plugins/' . $base . '-' . $locale,
            LOTZWOO_PLUGIN_DIR . 'languages/' . $base . '-' . $locale,
        ];

        foreach ($paths as $base_path) {
            $mo_path = $base_path . '.mo';
            $po_path = $base_path . '.po';
            if (!file_exists($mo_path) && !file_exists($po_path)) {
                continue;
            }

            $size_path = file_exists($mo_path) ? $mo_path : $po_path;
            $count     = file_exists($po_path) ? $this->count_po_strings($po_path) : 0;
            $size      = size_format((int) filesize($size_path));

            if ($count > 0) {
                return sprintf(
                    /* translators: 1: file size, 2: string count */
                    __('vorhanden (%1$s, %2$d Strings)', 'lotzapp-for-woocommerce'),
                    $size,
                    $count
                );
            }

            return sprintf(__('vorhanden (%s)', 'lotzapp-for-woocommerce'), $size);
        }

        return __('nicht generiert', 'lotzapp-for-woocommerce');
    }

    private function count_po_strings(string $path): int
    {
        $content = @file_get_contents($path);
        if (!is_string($content) || $content === '') {
            return 0;
        }

        preg_match_all('/^msgid\s+"/m', $content, $matches);
        $count = isset($matches[0]) ? count($matches[0]) : 0;

        // Exclude the PO header entry: msgid "".
        return max(0, $count - 1);
    }

    /**
     * Build a deep-link into Loco Translate for a specific locale.
     *
     * - If the target .po file exists, link straight to Loco's file-edit view
     *   for that file (bundle + domain + path) — the per-language editor for
     *   exactly the file LotzApp just wrote.
     * - Else fall back to Loco's bundle overview (action=view).
     * - Returns '' if Loco isn't installed.
     *
     * Route format note: Loco_mvc_AdminRouter::generate() takes a route like
     * "plugin-file-edit" (the type + action, no "loco-" prefix). Passing
     * "loco-plugin-file-edit" mis-splits into page=loco-loco and breaks the URL.
     *
     * Bundle handle note: Loco identifies plugins by directory/main-file.php,
     * not just the directory slug — passing the slug alone yields
     * "Plugin nicht gefunden".
     */
    private function loco_url_for(string $locale): string
    {
        if (!class_exists('Loco_mvc_AdminRouter') || !method_exists('Loco_mvc_AdminRouter', 'generate')) {
            return '';
        }

        $bundle = defined('LOTZWOO_PLUGIN_FILE')
            ? plugin_basename(LOTZWOO_PLUGIN_FILE)
            : 'lotzapp-for-woocommerce/lotzapp-for-woocommerce.php';

        $domain    = 'lotzapp-for-woocommerce';
        $basename  = 'lotzapp-for-woocommerce';
        // Path param is relative to WP_CONTENT_DIR (no leading "wp-content/"),
        // because Loco's BaseController calls $file->normalize(WP_CONTENT_DIR)
        // which prepends the base; including "wp-content/" doubles the prefix.
        $po_rel     = 'languages/plugins/' . $basename . '-' . $locale . '.po';
        $po_abs     = trailingslashit(WP_LANG_DIR) . 'plugins/' . $basename . '-' . $locale . '.po';

        // Deep-link to file editor when the .po actually exists.
        if (file_exists($po_abs)) {
            try {
                $url = \Loco_mvc_AdminRouter::generate('plugin-file-edit', [
                    'bundle' => $bundle,
                    'domain' => $domain,
                    'path'   => $po_rel,
                ]);
                if (is_string($url) && $url !== '') {
                    return $url;
                }
            } catch (\Throwable $e) {
                // fall through to bundle view
            }
            // Hand-built fallback if generate() returned empty.
            return add_query_arg([
                'page'   => 'loco-plugin',
                'action' => 'file-edit',
                'bundle' => $bundle,
                'domain' => $domain,
                'path'   => $po_rel,
            ], admin_url('admin.php'));
        }

        // Fallback: bundle overview (still scoped to our plugin).
        try {
            $url = \Loco_mvc_AdminRouter::generate('plugin-view', ['bundle' => $bundle]);
            if (is_string($url) && $url !== '') {
                return $url;
            }
        } catch (\Throwable $e) {
            // fall through to hand-built URL
        }

        return add_query_arg([
            'page'   => 'loco-plugin',
            'action' => 'view',
            'bundle' => $bundle,
        ], admin_url('admin.php'));
    }

    private function locale_label(string $locale): string
    {
        static $names = [
            'en_US' => 'English (US)',
            'en_GB' => 'English (UK)',
            'de_DE' => 'Deutsch',
            'de_AT' => 'Deutsch (Österreich)',
            'de_CH' => 'Deutsch (Schweiz)',
            'fr_FR' => 'Français',
            'it_IT' => 'Italiano',
            'es_ES' => 'Español',
            'pt_PT' => 'Português',
            'pt_BR' => 'Português (Brasil)',
            'nl_NL' => 'Nederlands',
            'pl_PL' => 'Polski',
            'sv_SE' => 'Svenska',
            'da_DK' => 'Dansk',
            'fi'    => 'Suomi',
            'nb_NO' => 'Norsk bokmål',
            'cs_CZ' => 'Čeština',
            'sk_SK' => 'Slovenčina',
            'hu_HU' => 'Magyar',
            'el'    => 'Ελληνικά',
            'ro_RO' => 'Română',
            'bg_BG' => 'Български',
            'hr'    => 'Hrvatski',
            'sl_SI' => 'Slovenščina',
            'et'    => 'Eesti',
            'lv'    => 'Latviešu',
            'lt_LT' => 'Lietuvių',
            'mt_MT' => 'Malti',
            'ga'    => 'Gaeilge',
        ];
        return $names[$locale] ?? $locale;
    }
}
