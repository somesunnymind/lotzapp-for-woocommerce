<?php

namespace Lotzwoo\Admin;

use Lotzwoo\Plugin;
use Lotzwoo\Field_Registry;

if (!defined('ABSPATH')) {
    exit;
}

class Settings_Page
{
    /**
     * @var bool
     */
    private $toggle_script_added = false;
    private $schedule_script_added = false;

    public function __construct()
    {
        $current_single_template = Plugin::opt('price_display_single_template', '{{ca_prefix}}{{value}}');
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_post_lotzwoo_create_buffer', [$this, 'handle_create_buffer']);
        add_action('admin_post_lotzwoo_create_image_management_page', [$this, 'handle_create_image_management_page']);
        add_action('admin_post_lotzwoo_create_menu_planning_page', [$this, 'handle_create_menu_planning_page']);
        add_action('admin_post_lotzwoo_send_test_email', [$this, 'handle_send_test_email']);
    }

    public function add_menu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('LotzApp for WooCommerce', 'lotzapp-for-woocommerce'),
            __('LotzApp', 'lotzapp-for-woocommerce'),
            'manage_woocommerce',
            'lotzwoo-settings',
            [$this, 'render']
        );
    }

    public function register_settings(): void
    {
        register_setting('lotzwoo_settings', 'lotzwoo_options', [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize'],
            'default'           => Plugin::defaults(),
        ]);

        $general_page        = 'lotzwoo-settings-general';
        $prices_page         = 'lotzwoo-settings-ca-prices';
        $price_display_page  = 'lotzwoo-settings-price-display';
        $images_page         = 'lotzwoo-settings-product-images';
        $menu_planning_page  = 'lotzwoo-settings-menu-planning';
        $emails_page         = 'lotzwoo-settings-emails';

        add_settings_section(
            'lotzwoo_general',
            __('Allgemein', 'lotzapp-for-woocommerce'),
            function () {
                echo '<p>' . esc_html__('LotzApp-spezifische WooCommerce-Felder sperren.', 'lotzapp-for-woocommerce') . '</p>';
            },
            $general_page
        );

        add_settings_field(
            'locked_fields',
            __('Nicht bearbeitbare WooCommerce Felder (jeweils ein Selektor pro Zeile)', 'lotzapp-for-woocommerce'),
            function () {
                $value = Plugin::opt('locked_fields', []);
                if (is_array($value)) {
                    $value = implode("\n", array_map('trim', $value));
                }
                $placeholder = "#_regular_price\n#_sale_price\ninput[name=\"_stock\"]";
                echo '<textarea name="lotzwoo_options[locked_fields]" rows="6" class="large-text code" placeholder="' . esc_attr($placeholder) . '">' . esc_textarea((string) $value) . '</textarea>';
                echo '<p class="description">' . esc_html__('Diese Felder werden durch LotzApp verwaltet und im WooCommerce-Backend gesperrt.', 'lotzapp-for-woocommerce') . '</p>';
            },
            $general_page,
            'lotzwoo_general'
        );

        add_settings_section(
            'lotzwoo_general_wc_fields',
            __('Zusaetzliche WooCommerce Felder', 'lotzapp-for-woocommerce'),
            function () {
                echo '<p>' . esc_html__('Aktiviert optionale Produktfelder im WooCommerce-Backend.', 'lotzapp-for-woocommerce') . '</p>';
            },
            $general_page
        );

        foreach (Field_Registry::grouped_fields() as $group_slug => $group) {
            if (empty($group['fields'])) {
                continue;
            }
            $label = is_string($group['label'] ?? '') ? $group['label'] : '';
            $this->register_field_group($general_page, (string) $group_slug, $label, $group['fields']);
        }

        add_settings_section(
            'lotzwoo_prices',
            __('Ca-Preise', 'lotzapp-for-woocommerce'),
            function () {
                echo '<p>' . esc_html__('Konfiguration der Ca.-Preiskennzeichnung und Buffer-Logik.', 'lotzapp-for-woocommerce') . '</p>';
            },
            $prices_page
        );

        add_settings_field(
            'price_prefix',
            __('Preis-Prefix (Artikelpreise)', 'lotzapp-for-woocommerce'),
            function () {
                $value = esc_attr(Plugin::opt('price_prefix'));
                echo '<input type="text" name="lotzwoo_options[price_prefix]" value="' . $value . '" class="regular-text" />';
                echo '<p class="description">' . esc_html__('Prefix fuer Preisangaben an Artikeln und Zwischensummen.', 'lotzapp-for-woocommerce') . '</p>';
            },
            $prices_page,
            'lotzwoo_prices'
        );

        add_settings_section(
            'lotzwoo_product_images',
            __('Produktbilder', 'lotzapp-for-woocommerce'),
            function () {
                echo '<p>' . esc_html__('Konfiguration der zentralen Bildverwaltung.', 'lotzapp-for-woocommerce') . '</p>';
            },
            $images_page
        );

        add_settings_field(
            'image_management_page_id',
            __('Bildverwaltung-Seiten-ID', 'lotzapp-for-woocommerce'),
            function () {
                $value = (int) Plugin::opt('image_management_page_id');
                echo '<input id="lotzwoo_image_management_page_id" type="number" name="lotzwoo_options[image_management_page_id]" value="' . esc_attr($value) . '" class="small-text" />';
                echo '<p class="description">' . esc_html__('ID der WordPress-Seite, auf der Produktbilder gesammelt gepflegt werden.', 'lotzapp-for-woocommerce') . '</p>';
                if ($value > 0) {
                    $view_link = get_permalink($value);
                    if ($view_link) {
                        printf(
                            '<p><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
                            esc_url($view_link),
                            esc_html__('Seite anzeigen', 'lotzapp-for-woocommerce')
                        );
                    }
                }
            },
            $images_page,
            'lotzwoo_product_images'
        );

        add_settings_section(
            'lotzwoo_menu_planning',
            __('Menüplanung', 'lotzapp-for-woocommerce'),
            function () {
                echo '<p>' . esc_html__('Konfiguration der zentralen Menüplanung.', 'lotzapp-for-woocommerce') . '</p>';
            },
            $menu_planning_page
        );

        add_settings_field(
            'menu_planning_page_id',
            __('Menüplanung-Seiten-ID', 'lotzapp-for-woocommerce'),
            function () {
                $value = (int) Plugin::opt('menu_planning_page_id');
                echo '<input id="lotzwoo_menu_planning_page_id" type="number" name="lotzwoo_options[menu_planning_page_id]" value="' . esc_attr($value) . '" class="small-text" />';
                echo '<p class="description">' . esc_html__('ID der WordPress-Seite, auf der die Menüplanung gepflegt wird.', 'lotzapp-for-woocommerce') . '</p>';
                if ($value > 0) {
                    $view_link = get_permalink($value);
                    if ($view_link) {
                        printf(
                            '<p><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
                            esc_url($view_link),
                            esc_html__('Seite anzeigen', 'lotzapp-for-woocommerce')
                        );
                    }
                }
            },
            $menu_planning_page,
            'lotzwoo_menu_planning'
        );

        add_settings_section(
            'lotzwoo_menu_planning_schedule',
            __('Zeitpunkt der Men&uuml;-Aktualisierung', 'lotzapp-for-woocommerce'),
            function () {
                echo '<p>' . esc_html__('Setze den Zeitpunkt, zu dem der Men&uuml;plan im Webshop ge&auml;ndert wird.', 'lotzapp-for-woocommerce') . '</p>';
            },
            $menu_planning_page
        );

        add_settings_field(
            'menu_planning_frequency',
            __('Häufigkeit', 'lotzapp-for-woocommerce'),
            function () {
                $value   = (string) Plugin::opt('menu_planning_frequency', 'weekly');
                $choices = $this->menu_planning_frequencies();
                echo '<select id="lotzwoo_menu_planning_frequency" name="lotzwoo_options[menu_planning_frequency]">';
                foreach ($choices as $slug => $label) {
                    printf(
                        '<option value="%s"%s>%s</option>',
                        esc_attr($slug),
                        selected($value, $slug, false),
                        esc_html($label)
                    );
                }
                echo '</select>';
                echo '<p class="description">' . esc_html__('Wähle, wie oft der Menüplan automatisch im Webshop übernommen wird.', 'lotzapp-for-woocommerce') . '</p>';
                $this->ensure_schedule_script();
            },
            $menu_planning_page,
            'lotzwoo_menu_planning_schedule'
        );

        add_settings_field(
            'menu_planning_schedule_target',
            __('Ausführungstag', 'lotzapp-for-woocommerce'),
            function () {
                $weekday  = (string) Plugin::opt('menu_planning_weekday', 'monday');
                $monthday = (int) Plugin::opt('menu_planning_monthday', 1);
                $frequency = (string) Plugin::opt('menu_planning_frequency', 'weekly');
                $weekdays = $this->menu_planning_weekdays();
                $warning_template = __('In Monaten mit weniger als %s Tagen wird die Aktualisierung automatisch am Monatsletzten ausgeführt.', 'lotzapp-for-woocommerce');

                echo '<div class="lotzwoo-menu-planning-target" data-lotzwoo-schedule-target>';

                $weekly_style = $frequency === 'weekly' ? '' : ' style="display:none;"';
                echo '<div class="lotzwoo-menu-planning-target__block" data-schedule-block="weekly"' . $weekly_style . '>';
                echo '<select id="lotzwoo_menu_planning_weekday" name="lotzwoo_options[menu_planning_weekday]">';
                foreach ($weekdays as $slug => $label) {
                    printf(
                        '<option value="%s"%s>%s</option>',
                        esc_attr($slug),
                        selected($weekday, $slug, false),
                        esc_html($label)
                    );
                }
                echo '</select>';
                echo '<p class="description">' . esc_html__('Bestimmt, an welchem Wochentag vorausgewählte Produkte automatisch aktualisiert werden.', 'lotzapp-for-woocommerce') . '</p>';
                echo '</div>';

                $monthly_style = $frequency === 'monthly' ? '' : ' style="display:none;"';
                echo '<div class="lotzwoo-menu-planning-target__block" data-schedule-block="monthly"' . $monthly_style . '>';
                echo '<select id="lotzwoo_menu_planning_monthday" name="lotzwoo_options[menu_planning_monthday]">';
                for ($day = 1; $day <= 31; $day++) {
                    $label = sprintf(__('%02d. des Monats', 'lotzapp-for-woocommerce'), $day);
                    printf(
                        '<option value="%d"%s>%s</option>',
                        $day,
                        selected($monthday, $day, false),
                        esc_html($label)
                    );
                }
                echo '</select>';
                echo '<p class="description">' . esc_html__('Wähle den Kalendertag, an dem die Menüs übertragen werden sollen.', 'lotzapp-for-woocommerce') . '</p>';
                printf(
                    '<p class="description" data-lotzwoo-monthday-warning data-template="%s" style="display:none;"></p>',
                    esc_attr($warning_template)
                );
                echo '</div>';

                $daily_style = $frequency === 'daily' ? '' : ' style="display:none;"';
                echo '<p class="description" data-schedule-block="daily"' . $daily_style . '>' . esc_html__('Bei täglicher Aktualisierung ist kein weiterer Auswahlpunkt erforderlich.', 'lotzapp-for-woocommerce') . '</p>';
                echo '</div>';

                $this->ensure_schedule_script();
            },
            $menu_planning_page,
            'lotzwoo_menu_planning_schedule'
        );

        add_settings_field(
            'menu_planning_time',
            __('Uhrzeit', 'lotzapp-for-woocommerce'),
            function () {
                $value = (string) Plugin::opt('menu_planning_time', '07:00');
                echo '<input id="lotzwoo_menu_planning_time" type="time" name="lotzwoo_options[menu_planning_time]" value="' . esc_attr($value) . '" />';
                echo '<p class="description">' . esc_html__('Uhrzeit (24h-Format) f&uuml;r die automatische Men&uuml;-Aktualisierung.', 'lotzapp-for-woocommerce') . '</p>';
            },
            $menu_planning_page,
            'lotzwoo_menu_planning_schedule'
        );

        add_settings_field(
            'menu_planning_show_backend_links',
            __('Produkt-Backend-Links', 'lotzapp-for-woocommerce'),
            function () {
                $checked = Plugin::opt('menu_planning_show_backend_links') ? 'checked' : '';
                echo '<label><input type="checkbox" name="lotzwoo_options[menu_planning_show_backend_links]" value="1" ' . $checked . ' /> ';
                echo esc_html__('Bearbeiten-Links ins WooCommerce Produkt-Backend anzeigen', 'lotzapp-for-woocommerce') . '</label>';
                echo '<p class="description">' . esc_html__('Blendet in der Menueplanung einen Bearbeiten-Link unter jedem ausgewaehlten Produkt ein.', 'lotzapp-for-woocommerce') . '</p>';
            },
            $menu_planning_page,
            'lotzwoo_menu_planning'
        );

        add_settings_field(
            'total_prefix',
            __('Preis-Prefix (Gesamtsumme)', 'lotzapp-for-woocommerce'),
            function () {
                $value = esc_attr(Plugin::opt('total_prefix', Plugin::opt('price_prefix')));
                echo '<input type="text" name="lotzwoo_options[total_prefix]" value="' . $value . '" class="regular-text" />';
                echo '<p class="description">' . esc_html__('Wird vor der Gesamtsumme im Checkout eingeblendet, wenn Ca.-Artikel enthalten sind.', 'lotzapp-for-woocommerce') . '</p>';
            },
            $prices_page,
            'lotzwoo_prices'
        );

        add_settings_field(
            'show_range_note',
            __('Min/Max-Hinweis anzeigen', 'lotzapp-for-woocommerce'),
            function () {
                $checked = Plugin::opt('show_range_note') ? 'checked' : '';
                echo '<label><input type="checkbox" name="lotzwoo_options[show_range_note]" value="1" ' . $checked . ' /> ';
                echo esc_html__('Aktiviere die Anzeige des Min/Max-Hinweises im Checkout.', 'lotzapp-for-woocommerce') . '</label>';
            },
            $prices_page,
            'lotzwoo_prices'
        );

        add_settings_section(
            'lotzwoo_price_display',
            __('Preisanzeige-Vorlagen', 'lotzapp-for-woocommerce'),
            function () {
                echo '<p>' . esc_html__('Steuert optionale HTML-Templates f&uuml;r die wichtigsten WooCommerce-Preisfelder. Verwende den Platzhalter {{value}}. Sollte ein WooCommerce Preisanzeige-Suffix aktiv sein, wird es innerhalb des value Platzhalters ausgegeben.', 'lotzapp-for-woocommerce') . '</p>';
            },
            $price_display_page
        );

        $price_display_groups = [
            'single-product' => [
                'label'       => __('Einzelproduktseite', 'lotzapp-for-woocommerce'),
                'description' => __('Ersetzt den Standardpreis einfacher Produkte direkt auf der Produktseite. Platzhalter: {{value}}, {{ca_prefix}}.', 'lotzapp-for-woocommerce'),
                'fields'      => [
                    [
                        'option_key'            => 'price_display_single_enabled',
                        'slug'                  => 'single_product_price',
                        'settings_label'        => __('Produktpreis (einfaches Produkt)', 'lotzapp-for-woocommerce'),
                        'settings_description'  => __('Wirkt auf woocommerce_get_price_html bzw. den Preisblock in single-product/price.php. Platzhalter: {{value}}, {{ca_prefix}}.', 'lotzapp-for-woocommerce'),
                        'heading_option_key'    => 'price_display_single_template',
                    ],
                    [
                        'option_key'            => 'price_display_single_regular_enabled',
                        'slug'                  => 'single_product_regular',
                        'settings_label'        => __('Regul�rer Preis (Streichpreis)', 'lotzapp-for-woocommerce'),
                        'settings_description'  => __('Greift, sobald woocommerce_product_get_regular_price & sale-Markup (<del>) im Template erscheinen. Platzhalter: {{value}}, {{ca_prefix}}.', 'lotzapp-for-woocommerce'),
                        'heading_option_key'    => 'price_display_single_regular_template',
                    ],
                    [
                        'option_key'            => 'price_display_single_sale_enabled',
                        'slug'                  => 'single_product_sale',
                        'settings_label'        => __('Aktueller Preis (Sale)', 'lotzapp-for-woocommerce'),
                        'settings_description'  => __('Wird auf den <ins>-Block bzw. woocommerce_product_get_sale_price angewendet. Platzhalter: {{value}}, {{ca_prefix}}.', 'lotzapp-for-woocommerce'),
                        'heading_option_key'    => 'price_display_single_sale_template',
                    ],
                ],
            ],
            'variable-products' => [
                'label'       => __('Variable Produkte', 'lotzapp-for-woocommerce'),
                'description' => __('Standard: WooCommerce zeigt den Spannenpreis als �min � max� an und aktualisiert nach Variantenauswahl den Preis im JSON. Platzhalter: {{value}}, {{ca_prefix}}, {{minvalue}}, {{maxvalue}}, {{prefixed_minvalue}}, {{prefixed_maxvalue}}.', 'lotzapp-for-woocommerce'),
                'fields'      => [
                    [
                        'option_key'            => 'price_display_variable_range_enabled',
                        'slug'                  => 'variable_price_range',
                        'settings_label'        => __('Von-bis Preis Template', 'lotzapp-for-woocommerce'),
                        'settings_description'  => __('Standard: Ausgabe wie �12,00 � � 18,00 �� oberhalb des Buttons. Platzhalter: {{value}}, {{ca_prefix}}, {{minvalue}}, {{maxvalue}}, {{prefixed_minvalue}}, {{prefixed_maxvalue}}.', 'lotzapp-for-woocommerce'),
                        'heading_option_key'    => 'price_display_variable_range_template',
                    ],
                    [
                        'option_key'            => 'price_display_variable_sale_enabled',
                        'slug'                  => 'variable_price_sale',
                        'settings_label'        => __('Sale Range Template', 'lotzapp-for-woocommerce'),
                        'settings_description'  => __('Standard: <del>Regelpreis-Range</del> <ins>Aktuelle Range</ins> wenn Rabatt aktiv ist. Platzhalter: {{value}}, {{ca_prefix}}, {{minvalue}}, {{maxvalue}}, {{prefixed_minvalue}}, {{prefixed_maxvalue}}.', 'lotzapp-for-woocommerce'),
                        'heading_option_key'    => 'price_display_variable_sale_template',
                    ],
                    [
                        'option_key'            => 'price_display_variable_selection_enabled',
                        'slug'                  => 'variable_price_selection',
                        'settings_label'        => __('Frontend Auswahl-Preis Template', 'lotzapp-for-woocommerce'),
                        'settings_description'  => __('Standard: Variation-JavaScript nutzt price_html / display_price (z.B. �15,00 ��). Platzhalter: {{value}}, {{ca_prefix}}.', 'lotzapp-for-woocommerce'),
                        'heading_option_key'    => 'price_display_variable_selection_template',
                    ],
                ],
            ],
            'grouped-products' => [
                'label'       => __('Gruppierte Produkte', 'lotzapp-for-woocommerce'),
                'description' => __('Konfiguration f�r gruppierte Preisangaben. Platzhalter: {{value}}, {{ca_prefix}}.', 'lotzapp-for-woocommerce'),
                'fields'      => [
                    [
                        'option_key'            => 'price_display_grouped_enabled',
                        'slug'                  => 'grouped_product_price',
                        'settings_label'        => __('Gruppenpreis', 'lotzapp-for-woocommerce'),
                        'settings_description'  => __('Steuert die Anzeige von woocommerce_grouped_price_html.', 'lotzapp-for-woocommerce'),
                        'heading_option_key'    => 'price_display_grouped_template',
                    ],
                ],
            ],
            'cart' => [
                'label'       => __('Warenkorb', 'lotzapp-for-woocommerce'),
                'description' => __('Cart-Preis-Theming (Zeilensummen, Versand etc.). Platzhalter: {{value}}, {{ca_prefix}}.', 'lotzapp-for-woocommerce'),
                'fields'      => [
                    [
                        'option_key'            => 'price_display_cart_item_price_enabled',
                        'slug'                  => 'cart_item_price',
                        'settings_label'        => __('Artikelpreis im Warenkorb', 'lotzapp-for-woocommerce'),
                        'settings_description'  => __('Wirkt auf woocommerce_cart_item_price (inkl. Mini-Cart).', 'lotzapp-for-woocommerce'),
                        'heading_option_key'    => 'price_display_cart_item_price_template',
                    ],
                    [
                        'option_key'            => 'price_display_cart_item_subtotal_enabled',
                        'slug'                  => 'cart_item_subtotal',
                        'settings_label'        => __('Artikelzwischensumme im Warenkorb', 'lotzapp-for-woocommerce'),
                        'settings_description'  => __('Wirkt auf woocommerce_cart_item_subtotal.', 'lotzapp-for-woocommerce'),
                        'heading_option_key'    => 'price_display_cart_item_subtotal_template',
                    ],
                ],
            ],
            'checkout' => [
                'label'       => __('Checkout', 'lotzapp-for-woocommerce'),
                'description' => __('Checkout- und Warenkorb-Zusammenfassung. Platzhalter: {{value}}, {{ca_prefix}}.', 'lotzapp-for-woocommerce'),
                'fields'      => [
                    [
                        'option_key'            => 'price_display_cart_subtotal_enabled',
                        'slug'                  => 'cart_subtotal',
                        'settings_label'        => __('Zwischensumme (Cart / Checkout)', 'lotzapp-for-woocommerce'),
                        'settings_description'  => __('Wirkt auf woocommerce_cart_totals_subtotal_html und woocommerce_cart_subtotal.', 'lotzapp-for-woocommerce'),
                        'heading_option_key'    => 'price_display_cart_subtotal_template',
                    ],
                    [
                        'option_key'            => 'price_display_cart_total_enabled',
                        'slug'                  => 'cart_total',
                        'settings_label'        => __('Gesamtsumme (Cart / Checkout)', 'lotzapp-for-woocommerce'),
                        'settings_description'  => __('Wirkt auf woocommerce_cart_totals_order_total_html.', 'lotzapp-for-woocommerce'),
                        'heading_option_key'    => 'price_display_cart_total_template',
                    ],
                ],
            ],
            'emails' => [
                'label'       => __('E-Mails', 'lotzapp-for-woocommerce'),
                'description' => __('Angepasste Preis-Templates in WooCommerce-E-Mails. Platzhalter: {{value}}, {{ca_prefix}}.', 'lotzapp-for-woocommerce'),
                'fields'      => [
                    [
                        'option_key'            => 'price_display_order_total_enabled',
                        'slug'                  => 'order_total',
                        'settings_label'        => __('Bestellsumme (E-Mails / Backend)', 'lotzapp-for-woocommerce'),
                        'settings_description'  => __('Wirkt auf woocommerce_get_formatted_order_total.', 'lotzapp-for-woocommerce'),
                        'heading_option_key'    => 'price_display_order_total_template',
                    ],
                ],
            ],
            'mini-cart' => [
                'label'       => __('Mini-Cart', 'lotzapp-for-woocommerce'),
                'description' => __('Unterst�tzung f�r Off-Canvas / Mini-Cart-Preise folgt.', 'lotzapp-for-woocommerce'),
                'fields'      => [],
            ],
        ];
foreach ($price_display_groups as $slug => $group) {
            $fields = isset($group['fields']) ? (array) $group['fields'] : [];
            $description = isset($group['description']) ? (string) $group['description'] : '';
            $label = isset($group['label']) ? (string) $group['label'] : (string) $slug;
            $this->register_field_group($price_display_page, (string) $slug, $label, $fields, 'lotzwoo_price_display', $description);
        }

        add_settings_field(
            'price_display_custom_css',
            __('Template-spezifisches CSS', 'lotzapp-for-woocommerce'),
            function () {
                $value = (string) Plugin::opt('price_display_custom_css', '');
                $placeholder = ".lotzwoo-price-badge {\n    font-size: 0.85rem;\n}";
                echo '<textarea name="lotzwoo_options[price_display_custom_css]" rows="6" class="large-text code" placeholder="' . esc_attr($placeholder) . '">' . esc_textarea($value) . '</textarea>';
                echo '<p class="description">' . esc_html__('Optionaler CSS-Block, der unterhalb der Preis-Akkordeons gespeichert wird und im Frontend als Inline-Style erscheint.', 'lotzapp-for-woocommerce') . '</p>';
            },
            $price_display_page,
            'lotzwoo_price_display'
       ,
            [
                'class' => 'lotzwoo-price-display-custom-css-row',
            ]
        );

        add_settings_section(
            'lotzwoo_emails',
            __('WooCommerce Emails', 'lotzapp-for-woocommerce'),
            function () {
                echo '<p>' . esc_html__('Konfiguration fuer Tracking-Links und Rechnungsanhaenge.', 'lotzapp-for-woocommerce') . '</p>';
            },
            $emails_page
        );

        add_settings_field(
            'emails_tracking_settings',
            __('Tracking-Links', 'lotzapp-for-woocommerce'),
            function () {
                $tracking_enabled = (bool) Plugin::opt('emails_tracking_enabled', 1);
                $value            = (string) Plugin::opt('emails_tracking_template', $this->default_email_tracking_template());
                $placeholder      = Field_Registry::TEMPLATE_PLACEHOLDER;
                $checked          = $tracking_enabled ? 'checked' : '';
                echo '<label><input type="checkbox" id="lotzwoo_emails_tracking_enabled" name="lotzwoo_options[emails_tracking_enabled]" value="1" ' . $checked . ' /> ';
                echo esc_html__('Tracking-Link Block in WooCommerce-E-Mails anzeigen', 'lotzapp-for-woocommerce') . '</label>';
                echo '<p class="description">' . esc_html__('Aktiviert den Shortcode-Block innerhalb von customer_completed_order.', 'lotzapp-for-woocommerce') . '</p>';

                $style = $tracking_enabled ? '' : ' style="display:none;"';
                echo '<div id="lotzwoo-tracking-template"' . $style . '>';
                echo '<textarea name="lotzwoo_options[emails_tracking_template]" rows="4" class="large-text code">' . esc_textarea($value) . '</textarea>';
                $description = sprintf(
                    __('%s wird durch eine Liste klickbarer Tracking-Links ersetzt.', 'lotzapp-for-woocommerce'),
                    '<code>' . esc_html($placeholder) . '</code>'
                );
                echo '<p class="description">' . wp_kses_post($description) . '</p>';
                echo '</div>';
                ?>
                <script>
                (function(){
                    var checkbox = document.getElementById('lotzwoo_emails_tracking_enabled');
                    var container = document.getElementById('lotzwoo-tracking-template');
                    if (!checkbox || !container) {
                        return;
                    }
                    var toggle = function(){
                        container.style.display = checkbox.checked ? '' : 'none';
                    };
                    checkbox.addEventListener('change', toggle);
                })();
                </script>
                <?php
            },
            $emails_page,
            'lotzwoo_emails'
        );

        add_settings_field(
            'emails_invoice_enabled',
            __('Rechnungsanhang', 'lotzapp-for-woocommerce'),
            function () {
                $enabled = (bool) Plugin::opt('emails_invoice_enabled', 1);
                $checked = $enabled ? 'checked' : '';
                echo '<label><input type="checkbox" name="lotzwoo_options[emails_invoice_enabled]" value="1" ' . $checked . ' /> ';
                echo esc_html__('Rechnung aus LotzApp als Anhang mitsenden, wenn eine URL vorhanden ist.', 'lotzapp-for-woocommerce') . '</label>';
                echo '<p class="description">' . esc_html__('Die Datei wird lokal angehängt oder bei externen URLs heruntergeladen und beigefügt.', 'lotzapp-for-woocommerce') . '</p>';
            },
            $emails_page,
            'lotzwoo_emails'
        );

        add_settings_field(
            'emails_meta_keys',
            __('ERP-Integration', 'lotzapp-for-woocommerce'),
            function () {
                $shortcode_example = '<code>[lotzwoo_tracking_links order_id="123"]</code>';
                echo '<p class="description">' . esc_html__('ERP-Systeme schreiben vor Versand Tracking- und Rechnungslinks in diese Metafelder der Bestellung:', 'lotzapp-for-woocommerce') . '</p>';
                echo '<ul>';
                echo '<li><code>lotzwoo_tracking_url</code> &ndash; ' . esc_html__('eine oder mehrere URLs (jeweils neue Zeile).', 'lotzapp-for-woocommerce') . '</li>';
                echo '<li><code>lotzwoo_invoice_url</code> &ndash; ' . esc_html__('ein PDF-Link, der als Anhang geladen wird.', 'lotzapp-for-woocommerce') . '</li>';
                echo '</ul>';
                $shortcode_text = sprintf(
                    __('Shortcode fuer Emails oder Seiten: %s (order_id optional, in WooCommerce-Emails wird automatisch die aktuelle Bestellung verwendet).', 'lotzapp-for-woocommerce'),
                    $shortcode_example
                );
                echo '<p class="description">' . wp_kses_post($shortcode_text) . '</p>';
            },
            $emails_page,
            'lotzwoo_emails'
        );
    }

    public function enqueue_assets(string $hook_suffix): void
    {
        if ($hook_suffix !== 'woocommerce_page_lotzwoo-settings') {
            return;
        }

        $css_file = trailingslashit(LOTZWOO_PLUGIN_DIR) . 'assets/css/admin.css';
        $css_url  = plugins_url('assets/css/admin.css', LOTZWOO_PLUGIN_FILE);
        $version  = file_exists($css_file) ? (string) filemtime($css_file) : '1.0.0';

        wp_enqueue_style('lotzwoo-admin-settings', $css_url, [], $version);
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     */
    private function register_field_group(string $page, string $group_slug, string $group_label, array $fields, string $section = 'lotzwoo_general_wc_fields', string $group_description = ''): void
    {
        if (empty($fields) && $group_description === '') {
            return;
        }

        $this->ensure_field_toggle_script();

        $sanitized_slug = sanitize_key($group_slug);
        $row_class      = 'lotzwoo-field-group-row lotzwoo-field-group-row-' . sanitize_html_class($sanitized_slug);
        $field_id       = 'lotzwoo_field_group_' . $sanitized_slug;
        $content_id     = 'lotzwoo-field-group-content-' . $sanitized_slug;

        add_settings_field(
            $field_id,
            '',
            function () use ($group_slug, $group_label, $fields, $content_id, $sanitized_slug, $group_description) {
                $container_id = 'lotzwoo-field-group-' . esc_attr($sanitized_slug);
                $label        = $group_label !== '' ? $group_label : $group_slug;
                echo '<div id="' . $container_id . '" class="lotzwoo-field-group" data-lotzwoo-group="' . esc_attr($group_slug) . '">';
                echo '<button type="button" class="lotzwoo-field-group__toggle" aria-expanded="false" aria-controls="' . esc_attr($content_id) . '">';
                echo '<span class="lotzwoo-field-group__title">' . esc_html($label) . '</span>';
                echo '<span class="dashicons dashicons-arrow-right-alt2 lotzwoo-field-group__indicator" aria-hidden="true"></span>';
                echo '</button>';
                echo '<div class="lotzwoo-field-group__content" id="' . esc_attr($content_id) . '" hidden>';
                if ($group_description !== '') {
                    echo '<p class="description lotzwoo-field-group__description">' . esc_html($group_description) . '</p>';
                }
                foreach ($fields as $field) {
                    $this->render_field_toggle($field);
                }
                echo '</div>';
                echo '</div>';
            },
            $page,
            $section,
            [
                'class' => $row_class,
            ]
        );
    }

    /**
     * @param array<string, mixed> $field
     */
    private function render_field_toggle(array $field): void
    {
        $option_key = $field['option_key'];
        $is_enabled = (bool) Plugin::opt($option_key);
        $checked    = $is_enabled ? 'checked' : '';
        $slug       = sanitize_key($field['slug']);
        $target_id  = 'lotzwoo-field-details-' . $slug;

        echo '<div class="lotzwoo-field-toggle" data-lotzwoo-field="' . esc_attr($slug) . '">';
        echo '<label class="lotzwoo-field-toggle__main">';
        echo '<input type="checkbox" name="lotzwoo_options[' . esc_attr($option_key) . ']" value="1" ' . $checked . ' data-lotzwoo-toggle="1" data-target="' . esc_attr($target_id) . '" />';
        echo '<span class="lotzwoo-field-toggle__texts">';
        echo '<span class="lotzwoo-field-toggle__name">' . esc_html($field['settings_label']) . '</span>';
        echo '<span class="lotzwoo-field-toggle__description">' . esc_html($field['settings_description']) . '</span>';
        echo '</span>';
        echo '</label>';

        $style_attr = $is_enabled ? '' : ' style="display:none;"';
        echo '<div id="' . $target_id . '" class="lotzwoo-field-details"' . $style_attr . '>';

        if (!empty($field['heading_option_key'])) {
            $template_value = Plugin::opt($field['heading_option_key'], '');
            $input_id       = 'lotzwoo_template_' . esc_attr($slug);
            $placeholder    = Field_Registry::TEMPLATE_PLACEHOLDER;
            echo '<p><label for="' . $input_id . '">' . esc_html__('HTML-Template fuer die Ausgabe', 'lotzapp-for-woocommerce') . '</label><br />';
            echo '<textarea id="' . $input_id . '" name="lotzwoo_options[' . esc_attr($field['heading_option_key']) . ']" rows="2" class="large-text code">' . esc_textarea((string) $template_value) . '</textarea>';
            $description = sprintf(
                __('Muss den Platzhalter %s enthalten, der durch den Feldwert ersetzt wird.', 'lotzapp-for-woocommerce'),
                '<code>' . esc_html($placeholder) . '</code>'
            );
            echo '<span class="description">' . wp_kses_post($description) . '</span>';
            echo '</p>';
        }

        $shortcodes = isset($field['shortcode']) ? [$field['shortcode']] : ($field['shortcodes'] ?? []);
        if (!empty($shortcodes)) {
            echo '<p class="description">' . esc_html__('Shortcodes:', 'lotzapp-for-woocommerce');
            foreach ($shortcodes as $code) {
                echo ' <code>' . esc_html($code) . '</code>';
            }
            echo '</p>';
        }

        echo '</div>';
        echo '</div>';
    }

    private function ensure_field_toggle_script(): void
    {
        if ($this->toggle_script_added) {
            return;
        }

        add_action('admin_footer', [$this, 'render_field_toggle_script']);
        $this->toggle_script_added = true;
    }

    public function render_field_toggle_script(): void
    {
        $screen = get_current_screen();
        if (!$screen || $screen->base !== 'woocommerce_page_lotzwoo-settings') {
            return;
        }
        ?>
        <style>
        .lotzwoo-field-group-row > th {
            display: none;
        }
        .lotzwoo-field-group {
            border: 1px solid #dcdcde;
            border-radius: 4px;
            margin-bottom: 16px;
            background: #fff;
        }
        .lotzwoo-field-group__toggle {
            width: 100%;
            border: 0;
            background: transparent;
            padding: 12px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            font-size: 15px;
            font-weight: 600;
        }
        .lotzwoo-field-group__indicator {
            transition: transform 0.2s ease;
        }
        .lotzwoo-field-group__toggle[aria-expanded="true"] .lotzwoo-field-group__indicator {
            transform: rotate(90deg);
        }
        .lotzwoo-field-group__content {
            padding: 0 16px 8px;
            border-top: 1px solid #dcdcde;
        }
        .lotzwoo-field-group__description {
            margin: 12px 0 8px;
            color: #50575e;
        }
        .lotzwoo-field-toggle {
            padding: 16px 0;
            border-bottom: 1px solid #f0f0f1;
        }
        .lotzwoo-field-toggle:last-child {
            border-bottom: 0;
        }
        .lotzwoo-field-toggle__main {
            display: flex;
            gap: 12px;
            align-items: flex-start;
        }
        .lotzwoo-field-toggle__texts {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .lotzwoo-field-toggle__name {
            font-weight: 600;
        }
        .lotzwoo-field-toggle__description {
            color: #50575e;
        }
        .lotzwoo-field-details {
            margin-left: 28px;
            margin-top: 8px;
        }
        </style>
        <script>
        (function(){
            function toggleDetails(input){
                if (!input || !input.dataset) {
                    return;
                }
                var targetId = input.dataset.target;
                if (!targetId) {
                    return;
                }
                var container = document.getElementById(targetId);
                if (!container) {
                    return;
                }
                container.style.display = input.checked ? '' : 'none';
            }

            function initToggleDetails(){
                var toggles = document.querySelectorAll('[data-lotzwoo-toggle="1"]');
                toggles.forEach(function(toggle){
                    toggleDetails(toggle);
                    toggle.addEventListener('change', function(){
                        toggleDetails(toggle);
                    });
                });
            }

            function initAccordion(){
                var groups = Array.prototype.slice.call(document.querySelectorAll('.lotzwoo-field-group'));
                if (!groups.length) {
                    return;
                }
                groups.forEach(function(group, index){
                    var toggle = group.querySelector('.lotzwoo-field-group__toggle');
                    var content = group.querySelector('.lotzwoo-field-group__content');
                    if (!toggle || !content) {
                        return;
                    }
                    var open = index === 0;
                    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                    content.hidden = !open;
                    toggle.addEventListener('click', function(){
                        var isOpen = toggle.getAttribute('aria-expanded') === 'true';
                        if (isOpen) {
                            toggle.setAttribute('aria-expanded', 'false');
                            content.hidden = true;
                            return;
                        }
                        groups.forEach(function(other){
                            if (other === group) {
                                return;
                            }
                            var otherToggle = other.querySelector('.lotzwoo-field-group__toggle');
                            var otherContent = other.querySelector('.lotzwoo-field-group__content');
                            if (otherToggle) {
                                otherToggle.setAttribute('aria-expanded', 'false');
                            }
                            if (otherContent) {
                                otherContent.hidden = true;
                            }
                        });
                        toggle.setAttribute('aria-expanded', 'true');
                        content.hidden = false;
                    });
                });
            }

            function init(){
                initToggleDetails();
                initAccordion();
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', init);
            } else {
                init();
            }
        })();
        </script>
        <?php
    }

    private function ensure_schedule_script(): void
    {
        if ($this->schedule_script_added) {
            return;
        }

        add_action('admin_footer', [$this, 'render_schedule_script']);
        $this->schedule_script_added = true;
    }

    public function render_schedule_script(): void
    {
        $screen = get_current_screen();
        if (!$screen || $screen->base !== 'woocommerce_page_lotzwoo-settings') {
            return;
        }
        ?>
        <script>
        (function(){
            function updateScheduleBlocks(){
                var frequency = document.getElementById('lotzwoo_menu_planning_frequency');
                var container = document.querySelector('[data-lotzwoo-schedule-target]');
                if (!frequency || !container) {
                    return;
                }
                var value = frequency.value || '';
                var blocks = container.querySelectorAll('[data-schedule-block]');
                blocks.forEach(function(block){
                    if (!block || !block.dataset) {
                        return;
                    }
                    var targetValue = block.dataset.scheduleBlock || '';
                    block.style.display = targetValue === value ? '' : 'none';
                });
            }

            function updateMonthdayWarning(){
                var select = document.getElementById('lotzwoo_menu_planning_monthday');
                var warning = document.querySelector('[data-lotzwoo-monthday-warning]');
                if (!select || !warning) {
                    return;
                }
                var template = warning.getAttribute('data-template') || '';
                var day = parseInt(select.value, 10);
                if (!day || day < 28 || !template) {
                    warning.style.display = 'none';
                    warning.textContent = '';
                    return;
                }
                var threshold = Math.max(day, 29);
                warning.textContent = template.replace('%s', String(threshold));
                warning.style.display = '';
            }

            function init(){
                updateScheduleBlocks();
                updateMonthdayWarning();

                var frequency = document.getElementById('lotzwoo_menu_planning_frequency');
                if (frequency) {
                    frequency.addEventListener('change', function(){
                        updateScheduleBlocks();
                        updateMonthdayWarning();
                    });
                }

                var monthday = document.getElementById('lotzwoo_menu_planning_monthday');
                if (monthday) {
                    monthday.addEventListener('change', updateMonthdayWarning);
                }
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', init);
            } else {
                init();
            }
        })();
        </script>
        <?php
    }

    public function sanitize($input): array
    {
        $input   = is_array($input) ? $input : [];
        $current = Plugin::opt('meta_key', '_ca_is_estimated');

        $options = Plugin::defaults();
        $options['meta_key'] = $current;

        $options['ca_prices_enabled']    = !empty($input['ca_prices_enabled']) ? 1 : 0;
        $options['price_prefix']         = isset($input['price_prefix']) ? sanitize_text_field($input['price_prefix']) : $options['price_prefix'];
        $options['total_prefix']         = isset($input['total_prefix']) ? sanitize_text_field($input['total_prefix']) : $options['price_prefix'];
        $options['buffer_product_id']    = isset($input['buffer_product_id']) ? absint($input['buffer_product_id']) : $options['buffer_product_id'];
        $options['image_management_page_id'] = isset($input['image_management_page_id']) ? absint($input['image_management_page_id']) : $options['image_management_page_id'];
        $options['menu_planning_page_id']    = isset($input['menu_planning_page_id']) ? absint($input['menu_planning_page_id']) : $options['menu_planning_page_id'];
        $options['menu_planning_frequency']  = isset($input['menu_planning_frequency']) ? $this->sanitize_menu_planning_frequency((string) $input['menu_planning_frequency']) : $this->sanitize_menu_planning_frequency((string) $options['menu_planning_frequency']);
        $options['menu_planning_monthday']   = isset($input['menu_planning_monthday']) ? $this->sanitize_menu_planning_monthday((int) $input['menu_planning_monthday']) : $this->sanitize_menu_planning_monthday((int) $options['menu_planning_monthday']);
        $options['menu_planning_weekday']    = isset($input['menu_planning_weekday']) ? $this->sanitize_menu_planning_weekday((string) $input['menu_planning_weekday']) : $this->sanitize_menu_planning_weekday((string) $options['menu_planning_weekday']);
        $options['menu_planning_time']       = isset($input['menu_planning_time']) ? $this->sanitize_menu_planning_time((string) $input['menu_planning_time']) : $this->sanitize_menu_planning_time((string) $options['menu_planning_time']);
        $options['menu_planning_show_backend_links'] = !empty($input['menu_planning_show_backend_links']) ? 1 : 0;
        $options['show_range_note']      = !empty($input['show_range_note']) ? 1 : 0;
        $options['price_display_single_enabled'] = !empty($input['price_display_single_enabled']) ? 1 : 0;
        $options['price_display_single_regular_enabled'] = !empty($input['price_display_single_regular_enabled']) ? 1 : 0;
        $options['price_display_single_sale_enabled'] = !empty($input['price_display_single_sale_enabled']) ? 1 : 0;
        $options['price_display_variable_range_enabled'] = !empty($input['price_display_variable_range_enabled']) ? 1 : 0;
        $options['price_display_variable_sale_enabled'] = !empty($input['price_display_variable_sale_enabled']) ? 1 : 0;
        $options['price_display_variable_selection_enabled'] = !empty($input['price_display_variable_selection_enabled']) ? 1 : 0;
        $options['price_display_grouped_enabled'] = !empty($input['price_display_grouped_enabled']) ? 1 : 0;
        $options['price_display_cart_item_price_enabled'] = !empty($input['price_display_cart_item_price_enabled']) ? 1 : 0;
        $options['price_display_cart_item_subtotal_enabled'] = !empty($input['price_display_cart_item_subtotal_enabled']) ? 1 : 0;
        $options['price_display_cart_subtotal_enabled'] = !empty($input['price_display_cart_subtotal_enabled']) ? 1 : 0;
        $options['price_display_cart_total_enabled'] = !empty($input['price_display_cart_total_enabled']) ? 1 : 0;
        $options['price_display_order_total_enabled'] = !empty($input['price_display_order_total_enabled']) ? 1 : 0;
        $options['price_display_custom_css'] = isset($input['price_display_custom_css'])
            ? $this->sanitize_price_display_custom_css((string) $input['price_display_custom_css'])
            : $this->sanitize_price_display_custom_css((string) Plugin::opt('price_display_custom_css', $options['price_display_custom_css']));

        $options['emails_tracking_enabled'] = !empty($input['emails_tracking_enabled']) ? 1 : 0;
        $options['emails_invoice_enabled']  = !empty($input['emails_invoice_enabled']) ? 1 : 0;
        $default_email_template          = $this->default_email_tracking_template();
        $current_email_template          = Plugin::opt('emails_tracking_template', $default_email_template);
        $raw_email_template              = isset($input['emails_tracking_template']) ? (string) $input['emails_tracking_template'] : (string) $current_email_template;
        if (trim($raw_email_template) === '') {
            $raw_email_template = $default_email_template;
        }
        $options['emails_tracking_template'] = $this->sanitize_field_template(
            $raw_email_template,
            (string) $current_email_template,
            [
                'slug'           => 'emails_tracking_template',
                'settings_label' => __('Tracking-Link Ausgabe', 'lotzapp-for-woocommerce'),
            ]
        );

        foreach (Field_Registry::all() as $field) {
            $options[$field['option_key']] = !empty($input[$field['option_key']]) ? 1 : 0;
            if (!empty($field['heading_option_key'])) {
                $current_template = Plugin::opt($field['heading_option_key'], '');
                $raw_template     = isset($input[$field['heading_option_key']]) ? (string) $input[$field['heading_option_key']] : '';
                $options[$field['heading_option_key']] = $this->sanitize_field_template($raw_template, (string) $current_template, $field);
            }
        $basic_placeholders = [Field_Registry::TEMPLATE_PLACEHOLDER, '{{ca_prefix}}'];
        }
        $current_single_template = Plugin::opt('price_display_single_template', '{{ca_prefix}}{{value}}');
        $raw_single_template     = isset($input['price_display_single_template']) ? (string) $input['price_display_single_template'] : (string) $current_single_template;
        $options['price_display_single_template'] = $this->sanitize_field_template(
            $raw_single_template,
            (string) $current_single_template,
            [
                'slug'           => 'price_display_single_template',
                'settings_label' => __('Produktpreis (einfaches Produkt)', 'lotzapp-for-woocommerce'),
            ],
            $basic_placeholders
        );

        $current_regular_template = Plugin::opt('price_display_single_regular_template', '{{ca_prefix}}{{value}}');
        $raw_regular_template     = isset($input['price_display_single_regular_template']) ? (string) $input['price_display_single_regular_template'] : (string) $current_regular_template;
        $options['price_display_single_regular_template'] = $this->sanitize_field_template(
            $raw_regular_template,
            (string) $current_regular_template,
            [
                'slug'           => 'price_display_single_regular_template',
                'settings_label' => __('Regul�rer Preis (Streichpreis)', 'lotzapp-for-woocommerce'),
            ],
            $basic_placeholders
        );

        $current_sale_template = Plugin::opt('price_display_single_sale_template', '{{ca_prefix}}{{value}}');
        $raw_sale_template     = isset($input['price_display_single_sale_template']) ? (string) $input['price_display_single_sale_template'] : (string) $current_sale_template;
        $options['price_display_single_sale_template'] = $this->sanitize_field_template(
            $raw_sale_template,
            (string) $current_sale_template,
            [
                'slug'           => 'price_display_single_sale_template',
                'settings_label' => __('Aktueller Preis (Sale)', 'lotzapp-for-woocommerce'),
            ],
            $basic_placeholders
        );

        $range_placeholders = [
            Field_Registry::TEMPLATE_PLACEHOLDER,
            '{{ca_prefix}}',
            '{{minvalue}}',
            '{{maxvalue}}',
            '{{prefixed_minvalue}}',
            '{{prefixed_maxvalue}}',
        ];
        $current_variable_range_template = Plugin::opt('price_display_variable_range_template', '{{ca_prefix}}{{value}}');
        $raw_variable_range_template     = isset($input['price_display_variable_range_template']) ? (string) $input['price_display_variable_range_template'] : (string) $current_variable_range_template;
        $options['price_display_variable_range_template'] = $this->sanitize_field_template(
            $raw_variable_range_template,
            (string) $current_variable_range_template,
            [
                'slug'           => 'price_display_variable_range_template',
                'settings_label' => __('Von-bis Preis Template', 'lotzapp-for-woocommerce'),
            ],
            $range_placeholders
        );

        $current_variable_sale_template = Plugin::opt('price_display_variable_sale_template', '{{ca_prefix}}{{value}}');
        $raw_variable_sale_template     = isset($input['price_display_variable_sale_template']) ? (string) $input['price_display_variable_sale_template'] : (string) $current_variable_sale_template;
        $options['price_display_variable_sale_template'] = $this->sanitize_field_template(
            $raw_variable_sale_template,
            (string) $current_variable_sale_template,
            [
                'slug'           => 'price_display_variable_sale_template',
                'settings_label' => __('Sale Range Template', 'lotzapp-for-woocommerce'),
            ],
            $range_placeholders
        );

        $current_variable_selection_template = Plugin::opt('price_display_variable_selection_template', '{{ca_prefix}}{{value}}');
        $raw_variable_selection_template     = isset($input['price_display_variable_selection_template']) ? (string) $input['price_display_variable_selection_template'] : (string) $current_variable_selection_template;
        $options['price_display_variable_selection_template'] = $this->sanitize_field_template(
            $raw_variable_selection_template,
            (string) $current_variable_selection_template,
            [
                'slug'           => 'price_display_variable_selection_template',
                'settings_label' => __('Frontend Auswahl-Preis Template', 'lotzapp-for-woocommerce'),
            ],
            $basic_placeholders
        );

        $current_grouped_template = Plugin::opt('price_display_grouped_template', '{{ca_prefix}}{{value}}');
        $raw_grouped_template     = isset($input['price_display_grouped_template']) ? (string) $input['price_display_grouped_template'] : (string) $current_grouped_template;
        $options['price_display_grouped_template'] = $this->sanitize_field_template(
            $raw_grouped_template,
            (string) $current_grouped_template,
            [
                'slug'           => 'price_display_grouped_template',
                'settings_label' => __('Gruppenpreis', 'lotzapp-for-woocommerce'),
            ],
            $basic_placeholders
        );

        $current_cart_item_price_template = Plugin::opt('price_display_cart_item_price_template', '{{ca_prefix}}{{value}}');
        $raw_cart_item_price_template     = isset($input['price_display_cart_item_price_template']) ? (string) $input['price_display_cart_item_price_template'] : (string) $current_cart_item_price_template;
        $options['price_display_cart_item_price_template'] = $this->sanitize_field_template(
            $raw_cart_item_price_template,
            (string) $current_cart_item_price_template,
            [
                'slug'           => 'price_display_cart_item_price_template',
                'settings_label' => __('Artikelpreis im Warenkorb', 'lotzapp-for-woocommerce'),
            ],
            $basic_placeholders
        );

        $current_cart_item_subtotal_template = Plugin::opt('price_display_cart_item_subtotal_template', '{{ca_prefix}}{{value}}');
        $raw_cart_item_subtotal_template     = isset($input['price_display_cart_item_subtotal_template']) ? (string) $input['price_display_cart_item_subtotal_template'] : (string) $current_cart_item_subtotal_template;
        $options['price_display_cart_item_subtotal_template'] = $this->sanitize_field_template(
            $raw_cart_item_subtotal_template,
            (string) $current_cart_item_subtotal_template,
            [
                'slug'           => 'price_display_cart_item_subtotal_template',
                'settings_label' => __('Artikelzwischensumme im Warenkorb', 'lotzapp-for-woocommerce'),
            ],
            $basic_placeholders
        );

        $current_cart_subtotal_template = Plugin::opt('price_display_cart_subtotal_template', '{{ca_prefix}}{{value}}');
        $raw_cart_subtotal_template     = isset($input['price_display_cart_subtotal_template']) ? (string) $input['price_display_cart_subtotal_template'] : (string) $current_cart_subtotal_template;
        $options['price_display_cart_subtotal_template'] = $this->sanitize_field_template(
            $raw_cart_subtotal_template,
            (string) $current_cart_subtotal_template,
            [
                'slug'           => 'price_display_cart_subtotal_template',
                'settings_label' => __('Zwischensumme (Cart / Checkout)', 'lotzapp-for-woocommerce'),
            ],
            $basic_placeholders
        );

        $current_cart_total_template = Plugin::opt('price_display_cart_total_template', '{{ca_prefix}}{{value}}');
        $raw_cart_total_template     = isset($input['price_display_cart_total_template']) ? (string) $input['price_display_cart_total_template'] : (string) $current_cart_total_template;
        $options['price_display_cart_total_template'] = $this->sanitize_field_template(
            $raw_cart_total_template,
            (string) $current_cart_total_template,
            [
                'slug'           => 'price_display_cart_total_template',
                'settings_label' => __('Gesamtsumme (Cart / Checkout)', 'lotzapp-for-woocommerce'),
            ],
            $basic_placeholders
        );

        $current_order_total_template = Plugin::opt('price_display_order_total_template', '{{ca_prefix}}{{value}}');
        $raw_order_total_template     = isset($input['price_display_order_total_template']) ? (string) $input['price_display_order_total_template'] : (string) $current_order_total_template;
        $options['price_display_order_total_template'] = $this->sanitize_field_template(
            $raw_order_total_template,
            (string) $current_order_total_template,
            [
                'slug'           => 'price_display_order_total_template',
                'settings_label' => __('Bestellsumme (E-Mails / Backend)', 'lotzapp-for-woocommerce'),
            ],
            $basic_placeholders
        );

        $selectors_raw            = isset($input['locked_fields']) ? (string) $input['locked_fields'] : '';
        
        $raw_single_template     = isset($input['price_display_single_template']) ? (string) $input['price_display_single_template'] : (string) $current_single_template;
        $options['price_display_single_template'] = $this->sanitize_field_template(
            $raw_single_template,
            (string) $current_single_template,
            [
                'slug'           => 'price_display_single_template',
                'settings_label' => __('Produktpreis (einfaches Produkt)', 'lotzapp-for-woocommerce'),
            ]
        );

        $current_regular_template = Plugin::opt('price_display_single_regular_template', Field_Registry::TEMPLATE_PLACEHOLDER);
        $raw_regular_template     = isset($input['price_display_single_regular_template']) ? (string) $input['price_display_single_regular_template'] : (string) $current_regular_template;
        $options['price_display_single_regular_template'] = $this->sanitize_field_template(
            $raw_regular_template,
            (string) $current_regular_template,
            [
                'slug'           => 'price_display_single_regular_template',
                'settings_label' => __('Regulärer Preis (Streichpreis)', 'lotzapp-for-woocommerce'),
            ]
        );

        $current_sale_template = Plugin::opt('price_display_single_sale_template', Field_Registry::TEMPLATE_PLACEHOLDER);
        $raw_sale_template     = isset($input['price_display_single_sale_template']) ? (string) $input['price_display_single_sale_template'] : (string) $current_sale_template;
        $options['price_display_single_sale_template'] = $this->sanitize_field_template(
            $raw_sale_template,
            (string) $current_sale_template,
            [
                'slug'           => 'price_display_single_sale_template',
                'settings_label' => __('Aktueller Preis (Sale)', 'lotzapp-for-woocommerce'),
            ]
        );

        $range_placeholders = [
            Field_Registry::TEMPLATE_PLACEHOLDER,
            '{{ca_prefix}}',
            '{{minvalue}}',
            '{{maxvalue}}',
            '{{prefixed_minvalue}}',
            '{{prefixed_maxvalue}}',
        ];        $current_variable_range_template = Plugin::opt('price_display_variable_range_template', Field_Registry::TEMPLATE_PLACEHOLDER);
        $raw_variable_range_template     = isset($input['price_display_variable_range_template']) ? (string) $input['price_display_variable_range_template'] : (string) $current_variable_range_template;
        $options['price_display_variable_range_template'] = $this->sanitize_field_template(
            $raw_variable_range_template,
            (string) $current_variable_range_template,
            [
                'slug'           => 'price_display_variable_range_template',
                'settings_label' => __('Von-bis Preis Template', 'lotzapp-for-woocommerce'),
            ],
            $range_placeholders
        );

        $current_variable_sale_template = Plugin::opt('price_display_variable_sale_template', Field_Registry::TEMPLATE_PLACEHOLDER);
        $raw_variable_sale_template     = isset($input['price_display_variable_sale_template']) ? (string) $input['price_display_variable_sale_template'] : (string) $current_variable_sale_template;
        $options['price_display_variable_sale_template'] = $this->sanitize_field_template(
            $raw_variable_sale_template,
            (string) $current_variable_sale_template,
            [
                'slug'           => 'price_display_variable_sale_template',
                'settings_label' => __('Sale Range Template', 'lotzapp-for-woocommerce'),
            ],
            $range_placeholders
        );

        $current_variable_selection_template = Plugin::opt('price_display_variable_selection_template', Field_Registry::TEMPLATE_PLACEHOLDER);
        $raw_variable_selection_template     = isset($input['price_display_variable_selection_template']) ? (string) $input['price_display_variable_selection_template'] : (string) $current_variable_selection_template;
        $options['price_display_variable_selection_template'] = $this->sanitize_field_template(
            $raw_variable_selection_template,
            (string) $current_variable_selection_template,
            [
                'slug'           => 'price_display_variable_selection_template',
                'settings_label' => __('Frontend Auswahl-Preis Template', 'lotzapp-for-woocommerce'),
            ]
        );

        $selectors_raw            = isset($input['locked_fields']) ? (string) $input['locked_fields'] : '';
        $options['locked_fields'] = array_values(array_unique(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $selectors_raw)))));

        add_settings_error('lotzwoo_settings', 'lotzwoo-saved', __('Einstellungen gespeichert.', 'lotzapp-for-woocommerce'), 'updated');

        return $options;
    }

    /**
     * @param array<string, mixed> $field
     */
    private function sanitize_field_template(string $raw_value, string $existing_value, array $field, array $allowed_placeholders = [Field_Registry::TEMPLATE_PLACEHOLDER]): string
    {
        $placeholder = Field_Registry::TEMPLATE_PLACEHOLDER;
        $value       = trim($raw_value);
        if ($value === '') {
            return $placeholder;
        }

        $allowed = array_values(array_unique(array_filter(array_map('trim', $allowed_placeholders))));
        if (empty($allowed)) {
            $allowed = [$placeholder];
        }

        $contains_placeholder = false;
        foreach ($allowed as $token) {
            if ($token !== '' && strpos($value, $token) !== false) {
                $contains_placeholder = true;
                break;
            }
        }

        if (!$contains_placeholder) {
            $label = isset($field['settings_label']) ? $field['settings_label'] : (isset($field['slug']) ? $field['slug'] : '');
            $tokens = implode(', ', array_map(static function ($token) {
                return '<code>' . esc_html($token) . '</code>';
            }, $allowed));
            $message = sprintf(
                __('Das Template für „%1$s“ muss mindestens einen der folgenden Platzhalter enthalten: %2$s', 'lotzapp-for-woocommerce'),
                $label,
                $tokens
            );
            add_settings_error(
                'lotzwoo_settings',
                'lotzwoo-template-missing-' . sanitize_key((string) ($field['slug'] ?? 'field')),
                wp_kses_post($message),
                'error'
            );
            return $existing_value;
        }

        $sanitized = trim(wp_kses_post($value));
        if ($sanitized === '') {
            return $placeholder;
        }

        return $sanitized;
    }


    private function sanitize_price_display_custom_css(string $raw_css): string
    {
        $css = sanitize_textarea_field($raw_css);
        $css = (string) preg_replace('/[ \t]+$/m', '', $css);
        return trim($css);
    }


    /**
     * @return array<string, string>
     */
    private function menu_planning_frequencies(): array
    {
        return [
            'monthly' => __('Monatlich', 'lotzapp-for-woocommerce'),
            'weekly'  => __('Wöchentlich', 'lotzapp-for-woocommerce'),
            'daily'   => __('Täglich', 'lotzapp-for-woocommerce'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function menu_planning_weekdays(): array
    {
        return [
            'monday'    => __('Montag', 'lotzapp-for-woocommerce'),
            'tuesday'   => __('Dienstag', 'lotzapp-for-woocommerce'),
            'wednesday' => __('Mittwoch', 'lotzapp-for-woocommerce'),
            'thursday'  => __('Donnerstag', 'lotzapp-for-woocommerce'),
            'friday'    => __('Freitag', 'lotzapp-for-woocommerce'),
            'saturday'  => __('Samstag', 'lotzapp-for-woocommerce'),
            'sunday'    => __('Sonntag', 'lotzapp-for-woocommerce'),
        ];
    }

    private function sanitize_menu_planning_weekday(string $weekday): string
    {
        $weekday = strtolower(trim($weekday));
        $choices = array_keys($this->menu_planning_weekdays());
        if (!in_array($weekday, $choices, true)) {
            $defaults = Plugin::defaults();
            $weekday  = isset($defaults['menu_planning_weekday']) ? (string) $defaults['menu_planning_weekday'] : 'monday';
        }
        return $weekday;
    }

    private function sanitize_menu_planning_frequency(string $frequency): string
    {
        $frequency = strtolower(trim($frequency));
        $choices   = array_keys($this->menu_planning_frequencies());
        if (!in_array($frequency, $choices, true)) {
            $defaults  = Plugin::defaults();
            $frequency = isset($defaults['menu_planning_frequency']) ? (string) $defaults['menu_planning_frequency'] : 'weekly';
        }
        return $frequency;
    }

    private function sanitize_menu_planning_monthday(int $day): int
    {
        if ($day < 1 || $day > 31) {
            $defaults = Plugin::defaults();
            $day      = isset($defaults['menu_planning_monthday']) ? (int) $defaults['menu_planning_monthday'] : 1;
        }
        return $day;
    }

    private function sanitize_menu_planning_time(string $time): string
    {
        $time = trim($time);
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            $defaults = Plugin::defaults();
            $time     = isset($defaults['menu_planning_time']) ? (string) $defaults['menu_planning_time'] : '07:00';
        }
        return $time;
    }

    public function render(): void
    {
        $tab  = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'general';
        $tab  = in_array($tab, ['general', 'ca-prices', 'product-images', 'menu-planning', 'emails'], true) ? $tab : 'general';
        $tabs = [
            'general'   => __('Allgemein', 'lotzapp-for-woocommerce'),
            'ca-prices' => __('Preise', 'lotzapp-for-woocommerce'),
            'product-images' => __('Produktbilder', 'lotzapp-for-woocommerce'),
            'menu-planning'  => __('Menüplanung', 'lotzapp-for-woocommerce'),
            'emails'    => __('Emails', 'lotzapp-for-woocommerce'),
        ];
        $base_url = menu_page_url('lotzwoo-settings', false);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('LotzApp', 'lotzapp-for-woocommerce'); ?></h1>
            <?php settings_errors('lotzwoo_settings'); ?>

            <h2 class="nav-tab-wrapper">
                <?php foreach ($tabs as $slug => $label) :
                    $url    = esc_url(add_query_arg(['tab' => $slug], $base_url));
                    $active = $tab === $slug ? ' nav-tab-active' : '';
                    ?>
                    <a href="<?php echo $url; ?>" class="nav-tab<?php echo $active; ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </h2>

            <form action="options.php" method="post">
                <?php
                settings_fields('lotzwoo_settings');
                if ($tab === 'general') {
                    do_settings_sections('lotzwoo-settings-general');
                } elseif ($tab === 'ca-prices') {
                    $ca_prices_enabled = (bool) Plugin::opt('ca_prices_enabled', 1);
                    ob_start();
                    do_settings_sections('lotzwoo-settings-ca-prices');
                    $ca_sections_html = (string) ob_get_clean();

                    ob_start();
                    do_settings_sections('lotzwoo-settings-price-display');
                    $price_display_html = (string) ob_get_clean();

                    $heading_html = '';
                    $intro_html   = '';

                    if (preg_match('/^\s*<h2[^>]*>.*?<\/h2>/is', $ca_sections_html, $matches)) {
                        $heading_html    = $matches[0];
                        $ca_sections_html = (string) substr($ca_sections_html, strlen($matches[0]));
                    }

                    $ca_sections_html = ltrim($ca_sections_html);

                    if (preg_match('/^\s*<p[^>]*>.*?<\/p>/is', $ca_sections_html, $matches)) {
                        $intro_html      = $matches[0];
                        $ca_sections_html = (string) substr($ca_sections_html, strlen($matches[0]));
                    }

                    $ca_sections_html = ltrim($ca_sections_html);

                    if ($heading_html === '') {
                        $heading_html = '<h2>' . esc_html__('Preise', 'lotzapp-for-woocommerce') . '</h2>';
                    }
                    if ($intro_html === '') {
                        $intro_html = '<p>' . esc_html__('Konfiguration der Ca.-Preiskennzeichnung und Buffer-Logik.', 'lotzapp-for-woocommerce') . '</p>';
                    }

                    $buffer_product_id = (int) Plugin::opt('buffer_product_id');
                    $buffer_edit_link  = $buffer_product_id > 0 ? get_edit_post_link($buffer_product_id, '') : '';
                    ?>
                    <?php echo $heading_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php echo $intro_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <fieldset class="lotzwoo-setting-toggle">
                        <legend class="screen-reader-text"><?php esc_html_e('Ca-Preis-Optionen', 'lotzapp-for-woocommerce'); ?></legend>
                        <label for="lotzwoo_ca_prices_enabled">
                            <input type="checkbox" id="lotzwoo_ca_prices_enabled" name="lotzwoo_options[ca_prices_enabled]" value="1" <?php checked($ca_prices_enabled); ?> />
                            <?php esc_html_e('Ca-Preise aktivieren', 'lotzapp-for-woocommerce'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Schaltet alle Ca-Preis-Funktionen dieses Plugins ein.', 'lotzapp-for-woocommerce'); ?></p>
                    </fieldset>
                    <div id="lotzwoo-ca-prices-settings" <?php echo $ca_prices_enabled ? '' : 'style="display:none;"'; ?>>
                        <?php echo $ca_sections_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <hr />
                        <h2><?php esc_html_e('10% Buffer-Artikel', 'lotzapp-for-woocommerce'); ?></h2>
                        <p><?php esc_html_e('Virtueller, im Shop unsichtbarer Buffer-Artikel, welcher automatisch unloeschbar in den Warenkorb gelegt wird, sobald dieser zumindest 1 Ca-Artikel enthaelt.', 'lotzapp-for-woocommerce'); ?></p>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row">
                                    <label for="lotzwoo_buffer_product_id"><?php esc_html_e('Buffer-Produkt-ID', 'lotzapp-for-woocommerce'); ?></label>
                                </th>
                                <td>
                                    <input id="lotzwoo_buffer_product_id" type="number" name="lotzwoo_options[buffer_product_id]" value="<?php echo esc_attr($buffer_product_id); ?>" class="small-text" />
                                    <p class="description">
                                        <?php
                                        echo esc_html__('Falls vorhanden. Wird beim Anlegen automatisch befuellt. Zum Anlegen des Buffer-Artikels den Button am Ende der Seite nutzen.', 'lotzapp-for-woocommerce');
                                        if ($buffer_edit_link) {
                                            echo '<br /><a href="' . esc_url($buffer_edit_link) . '">' . esc_html__('Buffer-Artikel bearbeiten', 'lotzapp-for-woocommerce') . '</a>';
                                        }
                                        ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <?php if (trim($price_display_html) !== '') : ?>
                        <hr />
                        <div class="lotzwoo-price-display">
                            <?php echo $price_display_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </div>
                    <?php endif; ?>
                    <script>
                    (function() {
                        var checkbox = document.getElementById('lotzwoo_ca_prices_enabled');
                        var settingsContainer = document.getElementById('lotzwoo-ca-prices-settings');
                        if (!checkbox || !settingsContainer) {
                            return;
                        }
                        var toggle = function () {
                            var actionsContainer = document.getElementById('lotzwoo-ca-prices-actions');
                            if (checkbox.checked) {
                                settingsContainer.style.display = '';
                                if (actionsContainer) {
                                    actionsContainer.style.display = '';
                                }
                            } else {
                                settingsContainer.style.display = 'none';
                                if (actionsContainer) {
                                    actionsContainer.style.display = 'none';
                                }
                            }
                        };
                        checkbox.addEventListener('change', toggle);
                        if (document.readyState === 'loading') {
                            document.addEventListener('DOMContentLoaded', toggle);
                        } else {
                            toggle();
                        }
                    })();
                    </script>
                    <?php
                } elseif ($tab === 'product-images') {
                    do_settings_sections('lotzwoo-settings-product-images');
                } elseif ($tab === 'menu-planning') {
                    do_settings_sections('lotzwoo-settings-menu-planning');
                } elseif ($tab === 'emails') {
                    do_settings_sections('lotzwoo-settings-emails');
                }
                submit_button();
                ?>
            </form>

            <?php if ($tab === 'ca-prices') : ?>
                <?php $ca_prices_enabled = isset($ca_prices_enabled) ? $ca_prices_enabled : (bool) Plugin::opt('ca_prices_enabled', 1); ?>
                <div id="lotzwoo-ca-prices-actions" <?php echo $ca_prices_enabled ? '' : 'style="display:none;"'; ?>>
                    <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                        <input type="hidden" name="action" value="lotzwoo_create_buffer" />
                        <?php wp_nonce_field('lotzwoo_create_buffer'); ?>
                        <?php submit_button(__('Neuen 10% Buffer-Artikel anlegen', 'lotzapp-for-woocommerce'), 'secondary'); ?>
                    </form>
                </div>
            <?php elseif ($tab === 'product-images') : ?>
                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                    <input type="hidden" name="action" value="lotzwoo_create_image_management_page" />
                    <?php wp_nonce_field('lotzwoo_create_image_management_page'); ?>
                    <p><?php esc_html_e('Legt eine neue, private WordPress-Seite fuer die zentrale Bildverwaltung an und hinterlegt die ID automatisch. Als Seitentemplate "blank" verwenden (Inhaltsblock auf volle Breite einstellen).', 'lotzapp-for-woocommerce'); ?></p>
                    <?php submit_button(__('Bildverwaltung-Seite anlegen', 'lotzapp-for-woocommerce'), 'secondary'); ?>
                </form>
            <?php elseif ($tab === 'menu-planning') : ?>
                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                    <input type="hidden" name="action" value="lotzwoo_create_menu_planning_page" />
                    <?php wp_nonce_field('lotzwoo_create_menu_planning_page'); ?>
                    <p><?php esc_html_e('Legt eine neue, private WordPress-Seite fuer die Menüplanung an und hinterlegt die ID automatisch. Als Seitentemplate "blank" verwenden (Inhaltsblock auf volle Breite einstellen).', 'lotzapp-for-woocommerce'); ?></p>
                    <?php submit_button(__('Menüplanung-Seite anlegen', 'lotzapp-for-woocommerce'), 'secondary'); ?>
                </form>
            <?php elseif ($tab === 'emails') : ?>
                <p><?php esc_html_e('Hinweis: Die ERP-Schnittstelle muss die genannten Metafelder fuellen, bevor das Versandabschluss-Email verschickt wird.', 'lotzapp-for-woocommerce'); ?></p>
                <hr />
                <h2><?php esc_html_e('Email testen', 'lotzapp-for-woocommerce'); ?></h2>
                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                    <input type="hidden" name="action" value="lotzwoo_send_test_email" />
                    <?php wp_nonce_field('lotzwoo_send_test_email'); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">
                                <label for="lotzwoo_email_test_order_id"><?php esc_html_e('Bestell-ID', 'lotzapp-for-woocommerce'); ?></label>
                            </th>
                            <td>
                                <input type="number" min="1" class="small-text" id="lotzwoo_email_test_order_id" name="lotzwoo_email_test_order_id" required />
                                <p class="description"><?php esc_html_e('Loest das Kunden-E-Mail customer_completed_order fuer die angegebene Bestellung erneut aus, unabhaengig vom aktuellen Status.', 'lotzapp-for-woocommerce'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button(__('customer_completed_order E-Mail senden', 'lotzapp-for-woocommerce'), 'secondary'); ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    private function default_email_tracking_template(): string
    {
        $defaults = Plugin::defaults();
        if (isset($defaults['emails_tracking_template'])) {
            return (string) $defaults['emails_tracking_template'];
        }

        return '<p><strong>Tracking-Links</strong><br>{{value}}</p>';
    }

    public function handle_create_buffer(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Nicht erlaubt.', 'lotzapp-for-woocommerce'));
        }
        check_admin_referer('lotzwoo_create_buffer');

        $product_id = $this->ensure_buffer_product();
        if ($product_id) {
            Plugin::update_opt(['buffer_product_id' => (int) $product_id]);
            add_settings_error('lotzwoo_settings', 'lotzwoo-buffer-created', sprintf(__('Buffer-Artikel vorhanden (ID %d).', 'lotzapp-for-woocommerce'), $product_id), 'updated');
        } else {
            add_settings_error('lotzwoo_settings', 'lotzwoo-buffer-failed', __('Buffer-Artikel konnte nicht erstellt werden.', 'lotzapp-for-woocommerce'), 'error');
        }

        wp_safe_redirect(admin_url('admin.php?page=lotzwoo-settings'));
        exit;
    }

    public function handle_create_image_management_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Nicht erlaubt.', 'lotzapp-for-woocommerce'));
        }
        check_admin_referer('lotzwoo_create_image_management_page');

        $page_id = $this->ensure_image_management_page();
        if ($page_id) {
            Plugin::update_opt(['image_management_page_id' => (int) $page_id]);
            add_settings_error('lotzwoo_settings', 'lotzwoo-image-page-created', sprintf(__('Bildverwaltung-Seite vorhanden (ID %d).', 'lotzapp-for-woocommerce'), $page_id), 'updated');
        } else {
            add_settings_error('lotzwoo_settings', 'lotzwoo-image-page-failed', __('Bildverwaltung-Seite konnte nicht erstellt werden.', 'lotzapp-for-woocommerce'), 'error');
        }

        $redirect = add_query_arg(
            [
                'page' => 'lotzwoo-settings',
                'tab'  => 'product-images',
            ],
            admin_url('admin.php')
        );
        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_create_menu_planning_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Nicht erlaubt.', 'lotzapp-for-woocommerce'));
        }
        check_admin_referer('lotzwoo_create_menu_planning_page');

        $page_id = $this->ensure_menu_planning_page();
        if ($page_id) {
            Plugin::update_opt(['menu_planning_page_id' => (int) $page_id]);
            add_settings_error('lotzwoo_settings', 'lotzwoo-menu-page-created', sprintf(__('Menüplanung-Seite vorhanden (ID %d).', 'lotzapp-for-woocommerce'), $page_id), 'updated');
        } else {
            add_settings_error('lotzwoo_settings', 'lotzwoo-menu-page-failed', __('Menüplanung-Seite konnte nicht erstellt werden.', 'lotzapp-for-woocommerce'), 'error');
        }

        $redirect = add_query_arg(
            [
                'page' => 'lotzwoo-settings',
                'tab'  => 'menu-planning',
            ],
            admin_url('admin.php')
        );
        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_send_test_email(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Nicht erlaubt.', 'lotzapp-for-woocommerce'));
        }
        check_admin_referer('lotzwoo_send_test_email');

        $redirect = $this->get_emails_tab_url();

        $order_id = isset($_POST['lotzwoo_email_test_order_id']) ? absint($_POST['lotzwoo_email_test_order_id']) : 0;
        if ($order_id < 1) {
            add_settings_error('lotzwoo_settings', 'lotzwoo-email-test-invalid', __('Bitte eine gueltige Bestell-ID eingeben.', 'lotzapp-for-woocommerce'), 'error');
            wp_safe_redirect($redirect);
            exit;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            add_settings_error('lotzwoo_settings', 'lotzwoo-email-test-missing-order', sprintf(__('Die Bestellung mit der ID %d wurde nicht gefunden.', 'lotzapp-for-woocommerce'), $order_id), 'error');
            wp_safe_redirect($redirect);
            exit;
        }

        $mailer = function_exists('WC') ? WC()->mailer() : null;
        if (!$mailer || !method_exists($mailer, 'get_emails')) {
            add_settings_error('lotzwoo_settings', 'lotzwoo-email-test-mailer-missing', __('WooCommerce Mailer konnte nicht initialisiert werden.', 'lotzapp-for-woocommerce'), 'error');
            wp_safe_redirect($redirect);
            exit;
        }

        $emails = $mailer->get_emails();
        $email_instance = null;

        if (isset($emails['WC_Email_Customer_Completed_Order'])) {
            $email_instance = $emails['WC_Email_Customer_Completed_Order'];
        } else {
            foreach ((array) $emails as $email) {
                if ($email instanceof \WC_Email && $email->id === 'customer_completed_order') {
                    $email_instance = $email;
                    break;
                }
            }
        }

        if (!$email_instance) {
            add_settings_error('lotzwoo_settings', 'lotzwoo-email-test-handler-missing', __('customer_completed_order E-Mail konnte nicht gefunden werden.', 'lotzapp-for-woocommerce'), 'error');
        } else {
            $email_instance->trigger($order_id, $order);
            add_settings_error('lotzwoo_settings', 'lotzwoo-email-test-success', sprintf(__('Test-E-Mail fuer Bestellung %s wurde versendet.', 'lotzapp-for-woocommerce'), $order->get_order_number()), 'updated');
        }

        wp_safe_redirect($redirect);
        exit;
    }

    private function get_emails_tab_url(): string
    {
        $url = menu_page_url('lotzwoo-settings', false);
        if (!$url) {
            $url = add_query_arg('page', 'lotzwoo-settings', admin_url('admin.php'));
        }

        return add_query_arg('tab', 'emails', $url);
    }

    private function ensure_buffer_product(): int
    {
        $post_id = wp_insert_post([
            'post_title'   => __('Mögliche Preisabweichung für Kiloware', 'lotzapp-for-woocommerce'),
            'post_status'  => 'publish',
            'post_type'    => 'product',
            'post_content' => '',
            'post_excerpt' => __('Einige Artikel werden nach tatsächlichem Gewicht berechnet. Der Endpreis kann bis zu 10 % vom Schätzwert abweichen. Abgebucht wird nur der Betrag für das tatsächlich gewogene Produkt.', 'lotzapp-for-woocommerce'),
        ], true);

        if (is_wp_error($post_id)) {
            return 0;
        }

        update_post_meta($post_id, '_virtual', 'yes');
        update_post_meta($post_id, '_regular_price', '0');
        update_post_meta($post_id, '_price', '0');
        update_post_meta($post_id, '_sold_individually', 'yes');
        update_post_meta($post_id, '_lotzwoo_is_buffer_product', 'yes');

        if (function_exists('wp_set_post_terms')) {
            wp_set_post_terms($post_id, ['exclude-from-catalog', 'exclude-from-search'], 'product_visibility', true);
        }

        return (int) $post_id;
    }

    private function ensure_image_management_page(): int
    {
        $shortcode_content = '[lotzwoo_product_image_management]';
        $existing = (int) Plugin::opt('image_management_page_id');
        if ($existing > 0 && get_post_type($existing) === 'page') {
            update_post_meta($existing, '_lotzwoo_is_image_management_page', 'yes');
            $current_post = get_post($existing);
            if ($current_post && trim((string) $current_post->post_content) !== $shortcode_content) {
                wp_update_post([
                    'ID'           => $existing,
                    'post_content' => $shortcode_content,
                ]);
            }
            return $existing;
        }

        $existing_pages = get_posts([
            'post_type'   => 'page',
            'post_status' => ['private', 'draft', 'publish'],
            'meta_key'    => '_lotzwoo_is_image_management_page',
            'meta_value'  => 'yes',
            'numberposts' => 1,
            'fields'      => 'ids',
        ]);

        if (!empty($existing_pages)) {
            $page_id = (int) $existing_pages[0];
            $current_post = get_post($page_id);
            if ($current_post && trim((string) $current_post->post_content) !== $shortcode_content) {
                wp_update_post([
                    'ID'           => $page_id,
                    'post_content' => $shortcode_content,
                ]);
            }
            return $page_id;
        }

        $post_id = wp_insert_post([
            'post_title'   => __('LotzApp Bildverwaltung', 'lotzapp-for-woocommerce'),
            'post_status'  => 'private',
            'post_type'    => 'page',
            'post_content' => $shortcode_content,
            'post_name'    => 'lotzapp-bildverwaltung',
            'meta_input'   => [
                '_lotzwoo_is_image_management_page' => 'yes',
            ],
        ], true);

        if (is_wp_error($post_id)) {
            return 0;
        }

        return (int) $post_id;
    }

    private function ensure_menu_planning_page(): int
    {
        $shortcode_content = '[lotzwoo_menu_planning]';
        $existing = (int) Plugin::opt('menu_planning_page_id');
        if ($existing > 0 && get_post_type($existing) === 'page') {
            update_post_meta($existing, '_lotzwoo_is_menu_planning_page', 'yes');
            $current_post = get_post($existing);
            if ($current_post && trim((string) $current_post->post_content) !== $shortcode_content) {
                wp_update_post([
                    'ID'           => $existing,
                    'post_content' => $shortcode_content,
                ]);
            }
            return $existing;
        }

        $existing_pages = get_posts([
            'post_type'   => 'page',
            'post_status' => ['private', 'draft', 'publish'],
            'meta_key'    => '_lotzwoo_is_menu_planning_page',
            'meta_value'  => 'yes',
            'numberposts' => 1,
            'fields'      => 'ids',
        ]);

        if (!empty($existing_pages)) {
            $page_id = (int) $existing_pages[0];
            $current_post = get_post($page_id);
            if ($current_post && trim((string) $current_post->post_content) !== $shortcode_content) {
                wp_update_post([
                    'ID'           => $page_id,
                    'post_content' => $shortcode_content,
                ]);
            }
            return $page_id;
        }

        $post_id = wp_insert_post([
            'post_title'   => __('LotzApp Menüplanung', 'lotzapp-for-woocommerce'),
            'post_status'  => 'private',
            'post_type'    => 'page',
            'post_content' => $shortcode_content,
            'post_name'    => 'lotzapp-menueplanung',
            'meta_input'   => [
                '_lotzwoo_is_menu_planning_page' => 'yes',
            ],
        ], true);

        if (is_wp_error($post_id)) {
            return 0;
        }

        return (int) $post_id;
    }
}






