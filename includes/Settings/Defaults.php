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
            'price_prefix'             => 'Ca. ',
            'total_prefix'             => 'Ca. ',
            'buffer_product_id'        => 0,
            'image_management_page_id' => 0,
            'menu_planning_page_id'    => 0,
            'menu_planning_frequency'  => 'weekly',
            'menu_planning_monthday'   => 1,
            'menu_planning_weekday'    => 'monday',
            'menu_planning_time'       => '07:00',
            'menu_planning_show_backend_links' => 0,
            'locked_fields'            => [],
            'meta_key'                 => '_ca_is_estimated',
            'show_range_note'          => 1,
        ];

        foreach (Field_Registry::option_defaults() as $option_key => $value) {
            $defaults[$option_key] = $value;
        }

        return $defaults;
    }
}
