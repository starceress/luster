<?php

declare(strict_types=1);

namespace Luster\Models;

/**
 * Represents a single parsed additional field definition from
 * enth_fanlistings.additional_info.
 *
 * The $name corresponds to the suffix after "additional_" in the member
 * table column (e.g. "fave_song" → column "additional_fave_song").
 * Template token: {fan_fave_song}.
 */
final class AdditionalField
{
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $type,
        public readonly bool   $required,
    ) {}
}
