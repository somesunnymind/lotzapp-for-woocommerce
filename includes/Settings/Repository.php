<?php

namespace Lotzwoo\Settings;

if (!defined('ABSPATH')) {
    exit;
}

class Repository
{
    private const OPTION_NAME = 'lotzwoo_options';

    private Defaults $defaults;

    public function __construct(?Defaults $defaults = null)
    {
        $this->defaults = $defaults ?: new Defaults();
    }

    public function all(): array
    {
        $stored = get_option(self::OPTION_NAME, []);
        $base   = $this->defaults->all();

        if (!is_array($stored)) {
            return $base;
        }

        return array_merge($base, $stored);
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        $options = $this->all();
        return $options[$key] ?? $default;
    }

    public function update(array $data): void
    {
        $options = array_merge($this->all(), $data);
        update_option(self::OPTION_NAME, $options);
    }

    public function option_name(): string
    {
        return self::OPTION_NAME;
    }
}

