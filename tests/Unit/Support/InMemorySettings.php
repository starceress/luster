<?php

declare(strict_types=1);

namespace Luster\Tests\Unit\Support;

use Luster\Repositories\SiteSettingsRepo;

/**
 * In-memory stub for SiteSettingsRepo.
 *
 * Overrides get/set/setStrict to use a PHP array so tests don't need a database
 * connection. All other methods inherited from SiteSettingsRepo are unused
 * in UpdateChecker and are not overridden.
 */
final class InMemorySettings extends SiteSettingsRepo
{
    /** @var array<string, string|null> */
    private array $store = [];

    public function __construct()
    {
        // Intentionally bypass parent constructor — no Database needed.
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return array_key_exists($key, $this->store) ? $this->store[$key] : $default;
    }

    public function set(string $key, ?string $value): void
    {
        $this->store[$key] = $value;
    }

    public function setStrict(string $key, ?string $value): void
    {
        $this->store[$key] = $value;
    }
}
