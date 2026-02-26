<?php

declare(strict_types=1);

namespace Luster\Core;

use RuntimeException;

/**
 * Loads config/app.php and provides a simple key-based accessor.
 *
 * The configuration file must return a plain PHP array.
 */
final class Config
{
    private array $data;

    public function __construct(string $configPath)
    {
        if (!is_file($configPath)) {
            throw new RuntimeException("Configuration file not found: {$configPath}");
        }

        $data = require $configPath;

        if (!is_array($data)) {
            throw new RuntimeException("Configuration file must return an array: {$configPath}");
        }

        $this->data = $data;
    }

    /**
     * Retrieve a configuration value.
     *
     * @param  mixed $default Returned when the key does not exist.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Retrieve a required configuration value; throws if absent.
     */
    public function require(string $key): mixed
    {
        if (!array_key_exists($key, $this->data)) {
            throw new RuntimeException("Required configuration key missing: {$key}");
        }

        return $this->data[$key];
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /** Returns the entire configuration array (read-only copy). */
    public function all(): array
    {
        return $this->data;
    }
}
