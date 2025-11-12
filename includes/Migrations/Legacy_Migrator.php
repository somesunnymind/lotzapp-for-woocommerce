<?php

namespace Lotzwoo\Migrations;

use Lotzwoo\Plugin;
use Lotzwoo\Settings\Repository;

if (!defined('ABSPATH')) {
    exit;
}

class Legacy_Migrator
{
    private const FLAG_OPTION = 'lotzwoo_legacy_migration_done';

    private Repository $repository;

    public function __construct(Repository $repository)
    {
        $this->repository = $repository;
    }

    public function run(): void
    {
        $new_options    = get_option($this->repository->option_name(), null);
        $legacy_option  = Plugin::legacy_option_name();
        $legacy_options = $legacy_option ? get_option($legacy_option, null) : null;

        if ((!is_array($new_options) || empty($new_options)) && is_array($legacy_options) && !empty($legacy_options)) {
            $merged = array_merge(Plugin::defaults(), $legacy_options);
            update_option($this->repository->option_name(), $merged);
        }

        $buffer_id = 0;

        if (is_array($legacy_options) && !empty($legacy_options['buffer_product_id'])) {
            $buffer_id = (int) $legacy_options['buffer_product_id'];
        }

        if (!$buffer_id) {
            $buffer_id = (int) Plugin::opt('buffer_product_id', 0);
        }

        if ($buffer_id > 0) {
            $legacy_meta = Plugin::legacy_buffer_meta_key();
            $legacy_flag = $legacy_meta ? get_post_meta($buffer_id, $legacy_meta, true) : '';

            if ($legacy_flag === 'yes') {
                update_post_meta($buffer_id, '_lotzwoo_is_buffer_product', 'yes');
                if ($legacy_meta) {
                    delete_post_meta($buffer_id, $legacy_meta);
                }
            }
        }

        update_option(self::FLAG_OPTION, time());
    }

    public function maybe_run(): void
    {
        $done = (int) get_option(self::FLAG_OPTION, 0);
        if ($done > 0) {
            return;
        }

        $this->run();
    }
}

