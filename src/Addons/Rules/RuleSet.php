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
        public readonly string $body,
        public readonly bool   $enabled,
        public readonly int    $version,
        public readonly string $updatedAt,
        public readonly string $gateType  = 'checkbox',
        public readonly string $gateLabel = '',
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

    /**
     * The gate label to display, falling back to a type-appropriate default
     * when no custom label has been set.
     */
    public function effectiveGateLabel(): string
    {
        if ($this->gateLabel !== '') {
            return $this->gateLabel;
        }

        return $this->gateType === 'button'
            ? 'I agree — proceed to join form'
            : 'I have read and agree to the rules above.';
    }
}
