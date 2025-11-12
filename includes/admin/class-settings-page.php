<?php

namespace Lotzwoo\Admin;

use Lotzwoo\Plugin;

if (!defined('ABSPATH')) {
    exit;
}

class Settings_Page
{
    private const OPTION_GROUP = 'lotzwoo_options_group';
    private const OPTION_NAME  = 'lotzwoo_options';
    private const MENU_SLUG    = 'lotzapp-for-woocommerce';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function add_menu_page(): void
    {
        add_menu_page(
            __('LotzApp for WooCommerce', 'lotzapp-for-woocommerce'),
            __('LotzApp', 'lotzapp-for-woocommerce'),
            'manage_woocommerce',
            self::MENU_SLUG,
            [$this, 'render_page'],
            'dashicons-cart'
        );
    }

    public function register_settings(): void
    {
        register_setting(self::OPTION_GROUP, self::OPTION_NAME, [$this, 'sanitize']);

        add_settings_section(
            'lotzwoo_main_section',
            __('Grundeinstellungen', 'lotzapp-for-woocommerce'),
            static function () {
                echo '<p>' . esc_html__('Minimale Grundkonfiguration. Kernfunktionen folgen in n?chsten Schritten.', 'lotzapp-for-woocommerce') . '</p>';
            },
            self::MENU_SLUG
        );

        add_settings_field('prefix', __('Preis-Prefix', 'lotzapp-for-woocommerce'), [$this, 'field_prefix'], self::MENU_SLUG, 'lotzwoo_main_section');
        add_settings_field('buffer_product_id', __('Buffer-Produkt ID', 'lotzapp-for-woocommerce'), [$this, 'field_buffer'], self::MENU_SLUG, 'lotzwoo_main_section');
        add_settings_field('locked_fields', __('Nicht bearbeitbare WooCommerce Felder (jeweils ein Selektor pro Zeile)', 'lotzapp-for-woocommerce'), [$this, 'field_locked_fields'], self::MENU_SLUG, 'lotzwoo_main_section');
    }

    public function sanitize($input): array
    {
        $input = is_array($input) ? $input : [];

        $clean = [
            'prefix'            => sanitize_text_field($input['prefix'] ?? 'Ca. '),
            'buffer_product_id' => isset($input['buffer_product_id']) ? preg_replace('/\D+/', '', (string) $input['buffer_product_id']) : '',
                        'locked_fields'     => [],
        ];

        if (isset($input['locked_fields'])) {
            $selectors = is_array($input['locked_fields'])
                ? $input['locked_fields']
                : preg_split('/\r\n|\r|\n/', (string) $input['locked_fields']);
            $selectors = array_filter(array_map(static function ($selector) {
                return is_string($selector) ? trim($selector) : '';
            }, (array) $selectors));
            $clean['locked_fields'] = array_values(array_unique($selectors));
        }

        return $clean;
    }

    private function get_option(string $key, $default = '')
    {
        $options = get_option(self::OPTION_NAME, []);
        return $options[$key] ?? $default;
    }

    public function render_page(): void
    {
        if (isset($_POST['lotzwoo_create_buffer'])) {
            check_admin_referer('lotzwoo_create_buffer_action', 'lotzwoo_create_buffer_nonce');
            $this->handle_create_buffer_click();
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('LotzApp', 'lotzapp-for-woocommerce'); ?></h1>
            <?php settings_errors(self::OPTION_GROUP); ?>

            <form method="post">
                <?php wp_nonce_field('lotzwoo_create_buffer_action', 'lotzwoo_create_buffer_nonce'); ?>
                <input type="hidden" name="lotzwoo_create_buffer" value="1" />
                <?php submit_button(__('10% Buffer-Artikel anlegen', 'lotzapp-for-woocommerce'), 'secondary', 'lotzwoo_create_buffer_btn', false); ?>
            </form>

            <hr />

            <form action="options.php" method="post">
                <?php
                settings_fields(self::OPTION_GROUP);
                do_settings_sections(self::MENU_SLUG);
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function field_prefix(): void
    {
        $value = $this->get_option('prefix', 'Ca. ');
        echo '<input type="text" name="' . esc_attr(self::OPTION_NAME) . '[prefix]" value="' . esc_attr($value) . '" class="regular-text" placeholder="Ca. " />';
    }

    public function field_buffer(): void
    {
        $value     = $this->get_option('buffer_product_id', '');
        $buffer_id = absint($value);
        $edit_link = $buffer_id ? get_edit_post_link($buffer_id, '') : '';

        echo '<input type="number" min="1" name="' . esc_attr(self::OPTION_NAME) . '[buffer_product_id]" value="' . esc_attr($value) . '" class="small-text" />';
        echo '<p class="description">' . esc_html__('ID des in WooCommerce angelegten Buffer-Produkts (wird automatisch hinzugefügt/entfernt – später).', 'lotzapp-for-woocommerce') . '</p>';

        $description = esc_html__('Falls vorhanden. Wird beim Anlegen automatisch befuellt.', 'lotzapp-for-woocommerce');
        if ($edit_link) {
            $description .= '<br /><a href="' . esc_url($edit_link) . '">' . esc_html__('Buffer-Artikel bearbeiten', 'lotzapp-for-woocommerce') . '</a>';
        }

        echo '<p class="description">' . $description . '</p>';
    }

    public function field_locked_fields(): void
    {
        $value = $this->get_option('locked_fields', []);
        if (is_array($value)) {
            $value = implode("\n", array_map('trim', $value));
        }
        $placeholder = "#_regular_price\n#_sale_price\ninput[name=\"_stock\"]";
        echo '<textarea name="' . esc_attr(self::OPTION_NAME) . '[locked_fields]" rows="6" class="large-text code" placeholder="' . esc_attr($placeholder) . '">' . esc_textarea((string) $value) . '</textarea>';
        echo '<p class="description">' . esc_html__('Diese Felder werden durch LotzApp verwaltet und im WooCommerce-Backend gesperrt.', 'lotzapp-for-woocommerce') . '</p>';
    }

    private function handle_create_buffer_click(): void
    {
        $options    = get_option(self::OPTION_NAME, []);
        $current_id = isset($options['buffer_product_id']) ? absint($options['buffer_product_id']) : 0;

        if ($current_id) {
            add_settings_error(self::OPTION_GROUP, 'lotzwoo-buffer-exists', __('Hinweis: Der bestehende Buffer-Artikel bleibt erhalten. Es wird zus?tzlich ein neuer Buffer-Artikel angelegt und ab sofort verwendet.', 'lotzapp-for-woocommerce'), 'updated');
        } else {
            add_settings_error(self::OPTION_GROUP, 'lotzwoo-buffer-create', __('Es ist noch keine Buffer-Produkt-ID gesetzt. Es wird nun ein neuer Buffer-Artikel angelegt.', 'lotzapp-for-woocommerce'), 'updated');
        }

        $new_id = $this->create_hidden_buffer_product();
        if (is_wp_error($new_id)) {
            add_settings_error(self::OPTION_GROUP, 'lotzwoo-buffer-error', sprintf(__('Fehler beim Anlegen des Buffer-Artikels: %s', 'lotzapp-for-woocommerce'), $new_id->get_error_message()), 'error');
            return;
        }

        if ($new_id) {
            $options['buffer_product_id'] = $new_id;
            update_option(self::OPTION_NAME, $options);
            add_settings_error(self::OPTION_GROUP, 'lotzwoo-buffer-created', sprintf(__('Buffer-Artikel angelegt. Neue ID: %d', 'lotzapp-for-woocommerce'), $new_id), 'updated');
        }
    }

    private function create_hidden_buffer_product()
    {
        if (!class_exists('WC_Product_Simple')) {
            return new \WP_Error('no_wc', __('WooCommerce ist nicht geladen.', 'lotzapp-for-woocommerce'));
        }

        try {
            $product = new \WC_Product_Simple();
            $product->set_name(__('Mögliche Preisabweichung für Kiloware', 'lotzapp-for-woocommerce'));
            $product->set_short_description(__('Einige Artikel werden nach tatsächlichem Gewicht berechnet. Der Endpreis kann bis zu 10 % vom Schätzwert abweichen. Abgebucht wird nur der Betrag für das tatsächlich gewogene Produkt.', 'lotzapp-for-woocommerce'));
            $product->set_status('publish');
            $product->set_catalog_visibility('hidden');
            $product->set_virtual(true);
            $product->set_sold_individually(true);
            $product->set_manage_stock(false);
            $product->set_regular_price(0);

            $product_id = $product->save();
            if ($product_id && !is_wp_error($product_id)) {
                update_post_meta($product_id, '_lotzwoo_is_buffer_product', 1);
                update_post_meta($product_id, '_lotzwoo_not_listable', 1);
            }

            return $product_id;
        } catch (\Throwable $exception) {
            return new \WP_Error('exception', $exception->getMessage());
        }
    }
}




