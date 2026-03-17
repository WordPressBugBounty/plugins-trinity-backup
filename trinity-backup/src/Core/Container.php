<?php

declare(strict_types=1);

namespace TrinityBackup\Core;

if (!\defined('ABSPATH')) {
    exit;
}

use RuntimeException;

final class Container
{
    /** @var array<string, callable> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (!isset($this->factories[$id])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message, not output.
            throw new RuntimeException('Service not found: ' . $id);
        }

        $this->instances[$id] = ($this->factories[$id])($this);

        return $this->instances[$id];
    }
}
