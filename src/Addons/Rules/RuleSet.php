<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Immutable value object representing a per-listing rule set. Holds the
 rule body text, enabled state, gate type (checkbox or button), gate
 label, and a version number that increments whenever the body changes.
 The fingerprint() method produces a stable identity string used by the
 session to track whether a fan has agreed to the current version.
*/

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
            ? 'Proceed'
            : 'Proceed';
    }
}
