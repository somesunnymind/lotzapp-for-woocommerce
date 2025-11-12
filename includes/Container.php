<?php

namespace Lotzwoo;

use Closure;
use RuntimeException;

if (!defined('ABSPATH')) {
    exit;
}

class Container
{
    /**
     * @var array<string, Closure>
     */
    private array $definitions = [];

    /**
     * @var array<string, mixed>
     */
    private array $instances = [];

    public function set(string $id, callable $factory): void
    {
        if (!$factory instanceof Closure) {
            $factory = Closure::fromCallable($factory);
        }

        $this->definitions[$id] = $factory;
    }

    /**
     * @template T
     *
     * @param class-string<T>|string $id
     * @return T|mixed
     */
    public function get(string $id)
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (!isset($this->definitions[$id])) {
            throw new RuntimeException(sprintf('Service "%s" is not registered in the container.', $id));
        }

        $factory = $this->definitions[$id];
        $service = $factory($this);

        $this->instances[$id] = $service;

        return $service;
    }

    public function has(string $id): bool
    {
        return isset($this->definitions[$id]) || array_key_exists($id, $this->instances);
    }
}

