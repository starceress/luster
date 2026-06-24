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
 * Parsed definition of a single custom field attached to a listing's member table.
 *
 * Listings can define extra fields ("Favorite song", "Favorite character", etc.)
 * beyond the built-in name/email/country set. FieldRepo reads the raw field
 * config string from the database and returns an array of these objects.
 * Templates loop over that array to build form inputs and member display rows
 * dynamically — field names are never hardcoded anywhere.
 *
 * In the standard Enthusiast schema the member-table column is named
 * "additional_{$name}" (e.g. "additional_fave_song"). In the unprefixed
 * schema the column is the bare $name. The corresponding embed template
 * token is "{fan_{$name}}" in both cases.
 */
final class AdditionalField
{
    /**
     * @param string $name     Bare field identifier used as the DB column suffix and
     *                         template token name (e.g. "fave_song").
     * @param string $label    Human-readable label shown in forms and admin views.
     * @param string $type     Field input type (e.g. "text", "select").
     * @param bool   $required Whether the field must be filled in on the join/update form.
     * @param string $options  Pipe-delimited option list for select fields; empty for text fields.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $type,
        public readonly bool   $required,
        public readonly string $options = '',
    ) {}
}
