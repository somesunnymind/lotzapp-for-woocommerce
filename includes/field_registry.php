<?php

namespace Lotzwoo;

if (!defined('ABSPATH')) {
    exit;
}

class Field_Registry
{
    public const TEMPLATE_PLACEHOLDER = '{{value}}';

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            'unit' => [
                'slug'                       => 'unit',
                'option_key'                 => 'enable_unit_field',
                'meta_key'                   => '_lotzwoo_unit',
                'shortcode'                  => 'lotzwoo_unit',
                'heading_option_key'         => 'heading_unit_field',
                'field_type'                 => 'text',
                'settings_label'             => __('Einheit', 'lotzapp-for-woocommerce'),
                'settings_description'       => __('Aktiviert ein Textfeld fuer die Mengeneinheit.', 'lotzapp-for-woocommerce'),
                'product_field_label'        => __('Einheit', 'lotzapp-for-woocommerce'),
                'product_field_description'  => __('Trage die Mengeneinheit (z. B. kg, l) ein.', 'lotzapp-for-woocommerce'),
                'variation_field_label'      => __('Einheit', 'lotzapp-for-woocommerce'),
                'variation_field_description'=> __('Mengeneinheit fuer diese Variante.', 'lotzapp-for-woocommerce'),
                'sanitize_callback'          => 'sanitize_text_field',
            ],
            'base_price' => [
                'slug'                       => 'base_price',
                'option_key'                 => 'enable_base_price_field',
                'meta_key'                   => '_lotzwoo_base_price',
                'shortcode'                  => 'lotzwoo_base_price',
                'heading_option_key'         => 'heading_base_price_field',
                'field_type'                 => 'number',
                'settings_label'             => __('Grundpreis', 'lotzapp-for-woocommerce'),
                'settings_description'       => __('Aktiviert ein Zahlenfeld fuer den Grundpreis (zwei Nachkommastellen).', 'lotzapp-for-woocommerce'),
                'product_field_label'        => __('Grundpreis', 'lotzapp-for-woocommerce'),
                'product_field_description'  => __('Grundpreis mit zwei Nachkommastellen.', 'lotzapp-for-woocommerce'),
                'variation_field_label'      => __('Grundpreis', 'lotzapp-for-woocommerce'),
                'variation_field_description'=> __('Grundpreis fuer diese Variante (zwei Nachkommastellen).', 'lotzapp-for-woocommerce'),
                'sanitize_callback'          => [self::class, 'sanitize_base_price'],
                'number_attributes'          => [
                    'step' => '0.01',
                    'min'  => '0',
                ],
            ],
            'base_unit' => [
                'slug'                       => 'base_unit',
                'option_key'                 => 'enable_base_unit_field',
                'meta_key'                   => '_lotzwoo_base_unit',
                'shortcode'                  => 'lotzwoo_base_unit',
                'heading_option_key'         => 'heading_base_unit_field',
                'field_type'                 => 'text',
                'settings_label'             => __('Grundeinheit', 'lotzapp-for-woocommerce'),
                'settings_description'       => __('Aktiviert ein Textfeld fuer die Grundeinheit des Grundpreises.', 'lotzapp-for-woocommerce'),
                'product_field_label'        => __('Grundeinheit', 'lotzapp-for-woocommerce'),
                'product_field_description'  => __('Bezeichne die Grundeinheit (z. B. 1 kg, 100 g).', 'lotzapp-for-woocommerce'),
                'variation_field_label'      => __('Grundeinheit', 'lotzapp-for-woocommerce'),
                'variation_field_description'=> __('Grundeinheit fuer diese Variante.', 'lotzapp-for-woocommerce'),
                'sanitize_callback'          => 'sanitize_text_field',
            ],
            'allergens' => [
                'slug'                       => 'allergens',
                'option_key'                 => 'enable_allergen_field',
                'meta_key'                   => '_lotzwoo_allergens',
                'shortcode'                  => 'lotzwoo_allergens',
                'heading_option_key'         => 'heading_allergen_field',
                'field_type'                 => 'textarea',
                'settings_label'             => __('Allergene', 'lotzapp-for-woocommerce'),
                'settings_description'       => __('Fuegt WooCommerce Produkten ein Textfeld fuer Allergene hinzu.', 'lotzapp-for-woocommerce'),
                'product_field_label'        => __('Allergene', 'lotzapp-for-woocommerce'),
                'product_field_description'  => __('Freitextfeld fuer Allergen-Hinweise, sichtbar wenn die Option aktiviert ist.', 'lotzapp-for-woocommerce'),
                'variation_field_label'      => __('Allergene', 'lotzapp-for-woocommerce'),
                'variation_field_description'=> __('Freitextfeld fuer Allergen-Hinweise pro Variante.', 'lotzapp-for-woocommerce'),
                'sanitize_callback'          => 'sanitize_textarea_field',
                'textarea_rows'              => 3,
                'legacy_filters'             => ['lotzwoo_product_allergens_shortcode_output'],
            ],
            'ingredients' => [
                'slug'                       => 'ingredients',
                'option_key'                 => 'enable_ingredient_field',
                'meta_key'                   => '_lotzwoo_ingredients',
                'shortcode'                  => 'lotzwoo_ingredients',
                'heading_option_key'         => 'heading_ingredient_field',
                'field_type'                 => 'textarea',
                'settings_label'             => __('Zutaten', 'lotzapp-for-woocommerce'),
                'settings_description'       => __('Fuegt WooCommerce Produkten ein Textfeld fuer Zutaten hinzu.', 'lotzapp-for-woocommerce'),
                'product_field_label'        => __('Zutaten', 'lotzapp-for-woocommerce'),
                'product_field_description'  => __('Freitextfeld fuer Zutatenangaben, sichtbar wenn die Option aktiviert ist.', 'lotzapp-for-woocommerce'),
                'variation_field_label'      => __('Zutaten', 'lotzapp-for-woocommerce'),
                'variation_field_description'=> __('Freitextfeld fuer Zutatenangaben pro Variante.', 'lotzapp-for-woocommerce'),
                'sanitize_callback'          => 'sanitize_textarea_field',
                'textarea_rows'              => 3,
                'legacy_filters'             => ['lotzwoo_product_ingredients_shortcode_output'],
            ],
            'ingredients_quid' => [
                'slug'                       => 'ingredients_quid',
                'option_key'                 => 'enable_ingredients_quid_field',
                'meta_key'                   => '_lotzwoo_ingredients_quid',
                'shortcode'                  => 'lotzwoo_ingredients_quid',
                'heading_option_key'         => 'heading_ingredients_quid_field',
                'field_type'                 => 'textarea',
                'settings_label'             => __('Zutaten (QUID)', 'lotzapp-for-woocommerce'),
                'settings_description'       => __('Fuegt WooCommerce Produkten ein Textfeld fuer QUID-konforme Zutatenangaben hinzu.', 'lotzapp-for-woocommerce'),
                'product_field_label'        => __('Zutaten (QUID)', 'lotzapp-for-woocommerce'),
                'product_field_description'  => __('Freitextfeld fuer QUID-konforme Zutatenangaben, sichtbar wenn die Option aktiviert ist.', 'lotzapp-for-woocommerce'),
                'variation_field_label'      => __('Zutaten (QUID)', 'lotzapp-for-woocommerce'),
                'variation_field_description'=> __('Freitextfeld fuer QUID-konforme Zutatenangaben pro Variante.', 'lotzapp-for-woocommerce'),
                'sanitize_callback'          => 'sanitize_textarea_field',
                'textarea_rows'              => 3,
            ],
            'shelf_life' => [
                'slug'                       => 'shelf_life',
                'option_key'                 => 'enable_shelf_life_field',
                'meta_key'                   => '_lotzwoo_shelf_life',
                'shortcode'                  => 'lotzwoo_shelf_life',
                'heading_option_key'         => 'heading_shelf_life_field',
                'field_type'                 => 'textarea',
                'settings_label'             => __('Haltbarkeit', 'lotzapp-for-woocommerce'),
                'settings_description'       => __('Fuegt WooCommerce Produkten ein Textfeld fuer Haltbarkeitsangaben hinzu.', 'lotzapp-for-woocommerce'),
                'product_field_label'        => __('Haltbarkeit', 'lotzapp-for-woocommerce'),
                'product_field_description'  => __('Freitextfeld fuer Haltbarkeitsangaben, sichtbar wenn die Option aktiviert ist.', 'lotzapp-for-woocommerce'),
                'variation_field_label'      => __('Haltbarkeit', 'lotzapp-for-woocommerce'),
                'variation_field_description'=> __('Freitextfeld fuer Haltbarkeitsangaben pro Variante.', 'lotzapp-for-woocommerce'),
                'sanitize_callback'          => 'sanitize_textarea_field',
                'textarea_rows'              => 3,
                'legacy_filters'             => ['lotzwoo_product_shelf_life_shortcode_output'],
            ],
            'storage' => [
                'slug'                       => 'storage',
                'option_key'                 => 'enable_storage_field',
                'meta_key'                   => '_lotzwoo_storage',
                'shortcode'                  => 'lotzwoo_storage',
                'heading_option_key'         => 'heading_storage_field',
                'field_type'                 => 'textarea',
                'settings_label'             => __('Lagerung', 'lotzapp-for-woocommerce'),
                'settings_description'       => __('Fuegt WooCommerce Produkten ein Textfeld fuer Lagerungshinweise hinzu.', 'lotzapp-for-woocommerce'),
                'product_field_label'        => __('Lagerung', 'lotzapp-for-woocommerce'),
                'product_field_description'  => __('Freitextfeld fuer Lagerungshinweise, sichtbar wenn die Option aktiviert ist.', 'lotzapp-for-woocommerce'),
                'variation_field_label'      => __('Lagerung', 'lotzapp-for-woocommerce'),
                'variation_field_description'=> __('Freitextfeld fuer Lagerungshinweise pro Variante.', 'lotzapp-for-woocommerce'),
                'sanitize_callback'          => 'sanitize_textarea_field',
                'textarea_rows'              => 3,
                'legacy_filters'             => ['lotzwoo_product_storage_shortcode_output'],
            ],
            'origin' => [
                'slug'                       => 'origin',
                'option_key'                 => 'enable_origin_field',
                'meta_key'                   => '_lotzwoo_origin',
                'shortcode'                  => 'lotzwoo_origin',
                'heading_option_key'         => 'heading_origin_field',
                'field_type'                 => 'textarea',
                'settings_label'             => __('Herkunft (Gesamt)', 'lotzapp-for-woocommerce'),
                'settings_description'       => __('Fuegt WooCommerce Produkten ein Textfeld fuer Herkunftsangaben hinzu.', 'lotzapp-for-woocommerce'),
                'product_field_label'        => __('Herkunft (Gesamt)', 'lotzapp-for-woocommerce'),
                'product_field_description'  => __('Freitextfeld fuer zusammengefasste Herkunftsangaben, sichtbar wenn die Option aktiviert ist.', 'lotzapp-for-woocommerce'),
                'variation_field_label'      => __('Herkunft (Gesamt)', 'lotzapp-for-woocommerce'),
                'variation_field_description'=> __('Freitextfeld fuer zusammengefasste Herkunftsangaben pro Variante.', 'lotzapp-for-woocommerce'),
                'sanitize_callback'          => 'sanitize_textarea_field',
                'textarea_rows'              => 3,
            ],
            'origin_born_in' => [
                'slug'                       => 'origin_born_in',
                'option_key'                 => 'enable_origin_born_in_field',
                'meta_key'                   => '_lotzwoo_origin_born_in',
                'shortcode'                  => 'lotzwoo_origin_born_in',
                'heading_option_key'         => 'heading_origin_born_in_field',
                'field_type'                 => 'textarea',
                'settings_label'             => __('Herkunft (Geboren in)', 'lotzapp-for-woocommerce'),
                'settings_description'       => __('Fuegt WooCommerce Produkten ein Textfeld fuer Angaben zum Geburtsland hinzu.', 'lotzapp-for-woocommerce'),
                'product_field_label'        => __('Geboren in', 'lotzapp-for-woocommerce'),
                'product_field_description'  => __('Freitextfeld fuer Angaben zum Geburtsland, sichtbar wenn die Option aktiviert ist.', 'lotzapp-for-woocommerce'),
                'variation_field_label'      => __('Geboren in', 'lotzapp-for-woocommerce'),
                'variation_field_description'=> __('Freitextfeld fuer Angaben zum Geburtsland pro Variante.', 'lotzapp-for-woocommerce'),
                'sanitize_callback'          => 'sanitize_textarea_field',
                'textarea_rows'              => 3,
            ],
            'origin_raised_in' => [
                'slug'                       => 'origin_raised_in',
                'option_key'                 => 'enable_origin_raised_in_field',
                'meta_key'                   => '_lotzwoo_origin_raised_in',
                'shortcode'                  => 'lotzwoo_origin_raised_in',
                'heading_option_key'         => 'heading_origin_raised_in_field',
                'field_type'                 => 'textarea',
                'settings_label'             => __('Herkunft (Aufgezogen in)', 'lotzapp-for-woocommerce'),
                'settings_description'       => __('Fuegt WooCommerce Produkten ein Textfeld fuer Angaben zum Aufzuchtland hinzu.', 'lotzapp-for-woocommerce'),
                'product_field_label'        => __('Aufgezogen in', 'lotzapp-for-woocommerce'),
                'product_field_description'  => __('Freitextfeld fuer Angaben zum Aufzuchtland, sichtbar wenn die Option aktiviert ist.', 'lotzapp-for-woocommerce'),
                'variation_field_label'      => __('Aufgezogen in', 'lotzapp-for-woocommerce'),
                'variation_field_description'=> __('Freitextfeld fuer Angaben zum Aufzuchtland pro Variante.', 'lotzapp-for-woocommerce'),
                'sanitize_callback'          => 'sanitize_textarea_field',
                'textarea_rows'              => 3,
            ],
            'origin_slaughtered_in' => [
                'slug'                       => 'origin_slaughtered_in',
                'option_key'                 => 'enable_origin_slaughtered_in_field',
                'meta_key'                   => '_lotzwoo_origin_slaughtered_in',
                'shortcode'                  => 'lotzwoo_origin_slaughtered_in',
                'heading_option_key'         => 'heading_origin_slaughtered_in_field',
                'field_type'                 => 'textarea',
                'settings_label'             => __('Herkunft (Geschlachtet in)', 'lotzapp-for-woocommerce'),
                'settings_description'       => __('Fuegt WooCommerce Produkten ein Textfeld fuer Angaben zum Schlachtland hinzu.', 'lotzapp-for-woocommerce'),
                'product_field_label'        => __('Geschlachtet in', 'lotzapp-for-woocommerce'),
                'product_field_description'  => __('Freitextfeld fuer Angaben zum Schlachtland, sichtbar wenn die Option aktiviert ist.', 'lotzapp-for-woocommerce'),
                'variation_field_label'      => __('Geschlachtet in', 'lotzapp-for-woocommerce'),
                'variation_field_description'=> __('Freitextfeld fuer Angaben zum Schlachtland pro Variante.', 'lotzapp-for-woocommerce'),
                'sanitize_callback'          => 'sanitize_textarea_field',
                'textarea_rows'              => 3,
            ],
            'origin_cut_in' => [
                'slug'                       => 'origin_cut_in',
                'option_key'                 => 'enable_origin_cut_in_field',
                'meta_key'                   => '_lotzwoo_origin_cut_in',
                'shortcode'                  => 'lotzwoo_origin_cut_in',
                'heading_option_key'         => 'heading_origin_cut_in_field',
                'field_type'                 => 'textarea',
                'settings_label'             => __('Herkunft (Zerlegt in)', 'lotzapp-for-woocommerce'),
                'settings_description'       => __('Fuegt WooCommerce Produkten ein Textfeld fuer Angaben zum Zerlegebetrieb hinzu.', 'lotzapp-for-woocommerce'),
                'product_field_label'        => __('Zerlegt in', 'lotzapp-for-woocommerce'),
                'product_field_description'  => __('Freitextfeld fuer Angaben zum Zerlegebetrieb, sichtbar wenn die Option aktiviert ist.', 'lotzapp-for-woocommerce'),
                'variation_field_label'      => __('Zerlegt in', 'lotzapp-for-woocommerce'),
                'variation_field_description'=> __('Freitextfeld fuer Angaben zum Zerlegebetrieb pro Variante.', 'lotzapp-for-woocommerce'),
                'sanitize_callback'          => 'sanitize_textarea_field',
                'textarea_rows'              => 3,
            ],
            'storage_temperature' => [
                'slug'                       => 'storage_temperature',
                'option_key'                 => 'enable_storage_temperature_field',
                'meta_key'                   => '_lotzwoo_storage_temperature',
                'shortcode'                  => 'lotzwoo_storage_temperature',
                'heading_option_key'         => 'heading_storage_temperature_field',
                'field_type'                 => 'textarea',
                'settings_label'             => __('Lagertemperatur', 'lotzapp-for-woocommerce'),
                'settings_description'       => __('Fuegt WooCommerce Produkten ein Textfeld fuer Lagertemperaturen hinzu.', 'lotzapp-for-woocommerce'),
                'product_field_label'        => __('Lagertemperatur', 'lotzapp-for-woocommerce'),
                'product_field_description'  => __('Freitextfeld fuer Lagertemperaturen, sichtbar wenn die Option aktiviert ist.', 'lotzapp-for-woocommerce'),
                'variation_field_label'      => __('Lagertemperatur', 'lotzapp-for-woocommerce'),
                'variation_field_description'=> __('Freitextfeld fuer Lagertemperaturen pro Variante.', 'lotzapp-for-woocommerce'),
                'sanitize_callback'          => 'sanitize_textarea_field',
                'textarea_rows'              => 2,
            ],
            'alcohol_by_volume' => [
                'slug'                       => 'alcohol_by_volume',
                'option_key'                 => 'enable_alcohol_by_volume_field',
                'meta_key'                   => '_lotzwoo_alcohol_by_volume',
                'shortcode'                  => 'lotzwoo_alcohol_by_volume',
                'heading_option_key'         => 'heading_alcohol_by_volume_field',
                'field_type'                 => 'number',
                'settings_label'             => __('Alkoholgehalt (Vol%)', 'lotzapp-for-woocommerce'),
                'settings_description'       => __('Aktiviert ein Zahlenfeld fuer den Alkoholgehalt (eine Nachkommastelle).', 'lotzapp-for-woocommerce'),
                'product_field_label'        => __('Alkoholgehalt (Vol%)', 'lotzapp-for-woocommerce'),
                'product_field_description'  => __('Alkoholgehalt in Vol% (eine Nachkommastelle).', 'lotzapp-for-woocommerce'),
                'variation_field_label'      => __('Alkoholgehalt (Vol%)', 'lotzapp-for-woocommerce'),
                'variation_field_description'=> __('Alkoholgehalt in Vol% fuer diese Variante (eine Nachkommastelle).', 'lotzapp-for-woocommerce'),
                'sanitize_callback'          => [self::class, 'sanitize_alcohol_by_volume'],
                'number_attributes'          => [
                    'step' => '0.1',
                    'min'  => '0',
                ],
            ],
            'nutrition' => [
                'slug'                       => 'nutrition',
                'option_key'                 => 'enable_nutrition_field',
                'meta_key'                   => '_lotzwoo_nutrition',
                'shortcode'                  => 'lotzwoo_nutrition',
                'heading_option_key'         => 'heading_nutrition_field',
                'field_type'                 => 'textarea',
                'settings_label'             => __('Naehrwertangaben', 'lotzapp-for-woocommerce'),
                'settings_description'       => __('Fuegt WooCommerce Produkten ein Textfeld fuer detaillierte Naehrwertangaben hinzu.', 'lotzapp-for-woocommerce'),
                'product_field_label'        => __('Naehrwertangaben', 'lotzapp-for-woocommerce'),
                'product_field_description'  => __('Freitextfeld fuer detaillierte Naehrwertangaben, sichtbar wenn die Option aktiviert ist.', 'lotzapp-for-woocommerce'),
                'variation_field_label'      => __('Naehrwertangaben', 'lotzapp-for-woocommerce'),
                'variation_field_description'=> __('Freitextfeld fuer detaillierte Naehrwertangaben pro Variante.', 'lotzapp-for-woocommerce'),
                'sanitize_callback'          => 'sanitize_textarea_field',
                'textarea_rows'              => 5,
            ],
            'nutrition_short' => [
                'slug'                       => 'nutrition_short',
                'option_key'                 => 'enable_nutrition_short_field',
                'meta_key'                   => '_lotzwoo_nutrition_short',
                'shortcode'                  => 'lotzwoo_nutrition_short',
                'heading_option_key'         => 'heading_nutrition_short_field',
                'field_type'                 => 'textarea',
                'settings_label'             => __('Naehrwertangaben (Kurz)', 'lotzapp-for-woocommerce'),
                'settings_description'       => __('Fuegt WooCommerce Produkten ein Textfeld fuer kurze Naehrwertangaben hinzu.', 'lotzapp-for-woocommerce'),
                'product_field_label'        => __('Naehrwertangaben (Kurz)', 'lotzapp-for-woocommerce'),
                'product_field_description'  => __('Freitextfeld fuer kurze Naehrwertangaben, sichtbar wenn die Option aktiviert ist.', 'lotzapp-for-woocommerce'),
                'variation_field_label'      => __('Naehrwertangaben (Kurz)', 'lotzapp-for-woocommerce'),
                'variation_field_description'=> __('Freitextfeld fuer kurze Naehrwertangaben pro Variante.', 'lotzapp-for-woocommerce'),
                'sanitize_callback'          => 'sanitize_textarea_field',
                'textarea_rows'              => 3,
            ],
            'vegan_label' => [
                'slug'                       => 'vegan_label',
                'option_key'                 => 'enable_vegan_label_field',
                'meta_key'                   => '_lotzwoo_vegan_label',
                'shortcode'                  => 'lotzwoo_vegan_label',
                'heading_option_key'         => 'heading_vegan_label_field',
                'field_type'                 => 'checkbox',
                'settings_label'             => __('Als vegan kennzeichnen', 'lotzapp-for-woocommerce'),
                'settings_description'       => __('Blendet ein Ja/Nein-Vegan-Label ein.', 'lotzapp-for-woocommerce'),
                'product_field_label'        => __('Als vegan kennzeichnen', 'lotzapp-for-woocommerce'),
                'product_field_description'  => __('Kennzeichnet dieses Produkt als vegan.', 'lotzapp-for-woocommerce'),
                'variation_field_label'      => __('Variante als vegan kennzeichnen', 'lotzapp-for-woocommerce'),
                'variation_field_description'=> __('Kennzeichnet diese Variante als vegan.', 'lotzapp-for-woocommerce'),
                'display_true_label'         => __('Ja', 'lotzapp-for-woocommerce'),
            ],
            'organic_label' => [
                'slug'                       => 'organic_label',
                'option_key'                 => 'enable_organic_label_field',
                'meta_key'                   => '_lotzwoo_organic_label',
                'shortcode'                  => 'lotzwoo_organic_label',
                'heading_option_key'         => 'heading_organic_label_field',
                'field_type'                 => 'checkbox',
                'settings_label'             => __('Als Bio kennzeichnen', 'lotzapp-for-woocommerce'),
                'settings_description'       => __('Blendet ein Ja/Nein-Bio-Label ein.', 'lotzapp-for-woocommerce'),
                'product_field_label'        => __('Als Bio kennzeichnen', 'lotzapp-for-woocommerce'),
                'product_field_description'  => __('Kennzeichnet dieses Produkt als Bio.', 'lotzapp-for-woocommerce'),
                'variation_field_label'      => __('Variante als Bio kennzeichnen', 'lotzapp-for-woocommerce'),
                'variation_field_description'=> __('Kennzeichnet diese Variante als Bio.', 'lotzapp-for-woocommerce'),
                'display_true_label'         => __('Ja', 'lotzapp-for-woocommerce'),
            ],
            'organic_cert_number' => [
                'slug'                       => 'organic_cert_number',
                'option_key'                 => 'enable_organic_cert_number_field',
                'meta_key'                   => '_lotzwoo_organic_cert_number',
                'shortcode'                  => 'lotzwoo_organic_cert_number',
                'field_type'                 => 'textarea',
                'heading_option_key'         => 'heading_organic_cert_number_field',
                'settings_label'             => __('Bio-Zertifizierungsnummer', 'lotzapp-for-woocommerce'),
                'settings_description'       => __('Aktiviert das Bio-Zertifizierungsnummer-Feld im Produkt.', 'lotzapp-for-woocommerce'),
                'product_field_label'        => __('Bio-Zertifizierungsnummer', 'lotzapp-for-woocommerce'),
                'product_field_description'  => __('Zertifizierungsnummer des Bio-Produkts.', 'lotzapp-for-woocommerce'),
                'variation_field_label'      => __('Bio-Zertifizierungsnummer', 'lotzapp-for-woocommerce'),
                'variation_field_description'=> __('Zertifizierungsnummer fuer diese Variante.', 'lotzapp-for-woocommerce'),
                'sanitize_callback'          => 'sanitize_textarea_field',
                'textarea_rows'              => 2,
            ],
            'organic_origin' => [
                'slug'                       => 'organic_origin',
                'option_key'                 => 'enable_organic_origin_field',
                'meta_key'                   => '_lotzwoo_organic_origin',
                'shortcode'                  => 'lotzwoo_organic_origin',
                'field_type'                 => 'textarea',
                'heading_option_key'         => 'heading_organic_origin_field',
                'settings_label'             => __('Bio-Herkunft', 'lotzapp-for-woocommerce'),
                'settings_description'       => __('Aktiviert das Bio-Herkunft-Feld im Produkt.', 'lotzapp-for-woocommerce'),
                'product_field_label'        => __('Bio-Herkunft', 'lotzapp-for-woocommerce'),
                'product_field_description'  => __('Beschreibe die Herkunft des Bio-Produkts.', 'lotzapp-for-woocommerce'),
                'variation_field_label'      => __('Bio-Herkunft', 'lotzapp-for-woocommerce'),
                'variation_field_description'=> __('Beschreibe die Herkunft dieser Variante.', 'lotzapp-for-woocommerce'),
                'sanitize_callback'          => 'sanitize_textarea_field',
                'textarea_rows'              => 3,
            ],
            'deposit' => [
                'slug'                       => 'deposit',
                'option_key'                 => 'enable_deposit_field',
                'meta_key'                   => '_lotzwoo_deposit',
                'shortcode'                  => 'lotzwoo_deposit',
                'field_type'                 => 'number',
                'heading_option_key'         => 'heading_deposit_field',
                'settings_label'             => __('Pfandbetrag', 'lotzapp-for-woocommerce'),
                'settings_description'       => __('Aktiviert das Pfandbetragsfeld im Produkt.', 'lotzapp-for-woocommerce'),
                'product_field_label'        => __('Pfandbetrag', 'lotzapp-for-woocommerce'),
                'product_field_description'  => __('Pfandbetrag fuer dieses Produkt (zwei Nachkommastellen).', 'lotzapp-for-woocommerce'),
                'variation_field_label'      => __('Pfandbetrag', 'lotzapp-for-woocommerce'),
                'variation_field_description'=> __('Pfandbetrag fuer diese Variante (zwei Nachkommastellen).', 'lotzapp-for-woocommerce'),
                'sanitize_callback'          => [self::class, 'sanitize_deposit_amount'],
            ],
            'withdrawal_exclusion' => [
                'slug'                       => 'withdrawal_exclusion',
                'option_key'                 => 'enable_withdrawal_exclusion_field',
                'meta_key'                   => '_lotzwoo_withdrawal_exclusion',
                'shortcode'                  => 'lotzwoo_withdrawal_exclusion',
                'field_type'                 => 'checkbox',
                'heading_option_key'         => 'heading_withdrawal_exclusion_field',
                'settings_label'             => __('Widerrufsausschluss', 'lotzapp-for-woocommerce'),
                'settings_description'       => __('Aktiviert einen Hinweis auf Widerrufsausschluss.', 'lotzapp-for-woocommerce'),
                'product_field_label'        => __('Widerrufsausschluss aktivieren', 'lotzapp-for-woocommerce'),
                'product_field_description'  => __('Kennzeichnet dieses Produkt als vom Widerruf ausgeschlossen.', 'lotzapp-for-woocommerce'),
                'variation_field_label'      => __('Widerrufsausschluss aktivieren', 'lotzapp-for-woocommerce'),
                'variation_field_description'=> __('Kennzeichnet diese Variante als vom Widerruf ausgeschlossen.', 'lotzapp-for-woocommerce'),
                'display_true_label'         => __('Vom Widerruf ausgeschlossen', 'lotzapp-for-woocommerce'),
            ],
            'age_restriction' => [
                'slug'                       => 'age_restriction',
                'option_key'                 => 'enable_age_restriction_field',
                'meta_key'                   => '_lotzwoo_age_restriction',
                'shortcode'                  => 'lotzwoo_age_restriction',
                'field_type'                 => 'number',
                'heading_option_key'         => 'heading_age_restriction_field',
                'settings_label'             => __('Altersbeschraenkung', 'lotzapp-for-woocommerce'),
                'settings_description'       => __('Aktiviert ein Zahlenfeld fuer Altersbeschraenkungen (Ganzzahl).', 'lotzapp-for-woocommerce'),
                'product_field_label'        => __('Altersbeschraenkung', 'lotzapp-for-woocommerce'),
                'product_field_description'  => __('Mindestalter (Ganzzahl).', 'lotzapp-for-woocommerce'),
                'variation_field_label'      => __('Altersbeschraenkung', 'lotzapp-for-woocommerce'),
                'variation_field_description'=> __('Mindestalter fuer diese Variante (Ganzzahl).', 'lotzapp-for-woocommerce'),
                'sanitize_callback'          => [self::class, 'sanitize_age_restriction'],
                'number_attributes'          => [
                    'step' => '1',
                    'min'  => '0',
                ],
            ],
        ];
    }

    /**
     * @return array<string, int>
     */
    public static function option_defaults(): array
    {
        $defaults = [];
        $template_defaults = [
            'heading_base_price_field'         => '€ {{value}}',
            'heading_storage_temperature_field'=> '{{value}} °C',
            'heading_alcohol_by_volume_field'  => 'vol. {{value}} %',
            'heading_deposit_field'            => '€ {{value}}',
            'heading_age_restriction_field'    => 'Ab {{value}} Jahren',
        ];
        foreach (self::all() as $field) {
            $defaults[$field['option_key']] = 0;
            if (!empty($field['heading_option_key'])) {
                $key = $field['heading_option_key'];
                $defaults[$key] = $template_defaults[$key] ?? self::TEMPLATE_PLACEHOLDER;
            }
        }
        return $defaults;
    }

    /**
     * Sanitize deposit amount to a string with two decimals and minimum zero.
     */
    public static function sanitize_deposit_amount($value): string
    {
        return self::sanitize_numeric_value($value, 2, 0.0);
    }

    public static function sanitize_base_price($value): string
    {
        return self::sanitize_numeric_value($value, 2, 0.0);
    }

    public static function sanitize_alcohol_by_volume($value): string
    {
        return self::sanitize_numeric_value($value, 1, 0.0);
    }

    /**
     * @param mixed $value
     */
    public static function sanitize_numeric_value($value, int $decimals, ?float $min = null, ?float $max = null): string
    {
        $number = self::normalize_numeric($value);
        if ($number === null) {
            return '';
        }

        if ($min !== null && $number < $min) {
            $number = $min;
        }

        if ($max !== null && $number > $max) {
            $number = $max;
        }

        return number_format($number, max(0, $decimals), '.', '');
    }

    /**
     * @param mixed $value
     */
    private static function normalize_numeric($value): ?float
    {
        if (is_array($value)) {
            $value = implode('', $value);
        }

        if (is_string($value)) {
            $value = str_replace([' ', ','], ['', '.'], $value);
            if ($value === '') {
                return null;
            }
        }

        if (!is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}
