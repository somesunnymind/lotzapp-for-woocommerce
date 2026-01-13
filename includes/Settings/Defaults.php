<?php

namespace Lotzwoo\Settings;

use Lotzwoo\Field_Registry;

if (!defined('ABSPATH')) {
    exit;
}

class Defaults
{
    public function all(): array
    {
        $defaults = [
            'ca_prices_enabled'        => 1,
            'price_prefix'             => 'ca. ',
            'total_prefix'             => 'max. ',
            'price_display_single_enabled' => 1,
            'price_display_single_template' => '{{ca_prefix}}{{value}}',
            'price_display_single_regular_enabled' => 1,
            'price_display_single_regular_template' => '{{ca_prefix}}{{value}}',
            'price_display_single_sale_enabled' => 1,
            'price_display_single_sale_template' => '{{ca_prefix}}{{value}}',
            'price_display_variable_range_enabled' => 1,
            'price_display_variable_range_template' => '{{ca_prefix}}{{value}}',
            'price_display_variable_sale_enabled' => 1,
            'price_display_variable_sale_template' => '{{ca_prefix}}{{value}}',
            'price_display_variable_selection_enabled' => 1,
            'price_display_variable_selection_template' => '{{ca_prefix}}{{value}}',
            'price_display_grouped_enabled' => 1,
            'price_display_grouped_template' => '{{ca_prefix}}{{value}}',
            'price_display_cart_item_price_enabled' => 1,
            'price_display_cart_item_price_template' => '{{ca_prefix}}{{value}}',
            'price_display_cart_item_subtotal_enabled' => 1,
            'price_display_cart_item_subtotal_template' => '{{ca_prefix}}{{value}}',
            'price_display_cart_subtotal_enabled' => 1,
            'price_display_cart_subtotal_template' => '{{ca_prefix}}{{value}}',
            'price_display_cart_total_enabled' => 1,
            'price_display_cart_total_template' => '{{ca_prefix}}{{value}}',
            'price_display_order_total_enabled' => 1,
            'price_display_order_total_template' => '{{ca_prefix}}{{value}}',
            'price_display_custom_css' => '',
            'buffer_product_id'        => 0,
            'image_management_page_id' => 0,
            'menu_planning_enabled'    => 1,
            'menu_planning_page_id'    => 0,
            'menu_planning_frequency'  => 'weekly',
            'menu_planning_monthday'   => 1,
            'menu_planning_weekday'    => 'monday',
            'menu_planning_time'       => '07:00',
            'menu_planning_show_backend_links' => 0,
            'delivery_times'           => [],
            'locked_fields'            => [],
            'meta_key'                 => '_ca_is_estimated',
            'show_range_note'          => 1,
            'emails_tracking_enabled'  => 1,
            'emails_invoice_enabled'   => 1,
            'emails_tracking_template' => '<p>Hier geht\'s zur <strong>Sendungsverfolgung:</strong><br>{{value}}</p>',
        ];

        foreach (Field_Registry::option_defaults() as $option_key => $value) {
            $defaults[$option_key] = $value;
        }

        return $defaults;
    }
}
