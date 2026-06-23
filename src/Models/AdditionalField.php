<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Value object representing a single custom field definition for a listing's
 member table. Listings can define extra fields like "Favorite song" or
 "Favorite character" beyond the standard name/email/country. FieldRepo
 parses the raw field config from the database and returns an array of
 these objects, which templates then loop over to build form inputs and
 member display rows dynamically.
*/

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
        public readonly string $options = '',
    ) {}
}
