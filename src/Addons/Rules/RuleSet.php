<?php

declare(strict_types=1);

namespace Luster\Addons\Rules;

/**
 * Represents a per-listing rule set.
 */
final class RuleSet
{
    public function __construct(
        public readonly int    $id,
        public readonly int    $fanlistingId,
        public readonly string $title,
        public readonly string $body,
        public readonly bool   $enabled,
        public readonly int    $version,
        public readonly string $updatedAt,
    ) {}

    /**
     * Stable identity string stored in the session to represent fan agreement.
     * Encodes both the row ID (so delete→recreate creates a new identity even at
     * the same version number) and the version (so body edits also invalidate).
     */
    public function fingerprint(): string
    {
        return $this->id . ':' . $this->version;
    }
}
