<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Works out which column of a member table holds each additional field's
 answers. It's the one answer to that question, so reading a member, saving a
 member and deleting a field can't disagree. Works from a list of definitions
 and a list of column names, and never touches the database itself.
*/

declare(strict_types=1);

namespace Luster\Support;

use Luster\Models\AdditionalField;

/**
 * Maps additional-field definitions to the columns that belong to them.
 *
 * On the standard schema a field's column is additional_{name}. On the unprefixed
 * schema it's the bare name, or additional_{name} for a table an import made that
 * way. A definition never gets a column it can't prove is its own:
 *
 *  - A field named like a built-in column (a "core word", any case) never gets
 *    that column on the unprefixed schema, because the column holds real member
 *    data. It can still use additional_{name}.
 *  - A column is claimed by the field named exactly that first. A field falling
 *    back to additional_{name} only gets it if nobody else claims it, so
 *    deleting stale "fave" can't take the column of a field called
 *    "additional_fave".
 *  - Two definitions claiming one column (MySQL column names ignore case, so
 *    "Fave" and "fave" do) get nothing: neither can prove it's theirs.
 *
 * Null means there's nowhere safe for that field's answers. Column names come
 * back in their real spelling, whatever the definition's capitals are.
 */
final class FieldColumnMap
{
    /** The real columns of an unprefixed member table. */
    public const CORE_COLUMNS = [
        'email', 'name', 'country', 'url', 'pending', 'password', 'showemail', 'showurl', 'added',
    ];

    private function __construct() {}

    /**
     * @param AdditionalField[] $fields      Every definition on the listing, not just the one being asked about.
     * @param array<int|string> $columnNames The member table's real columns.
     * @param bool              $bareLayout  True on the unprefixed schema.
     * @return array<string, ?string> Field name => its column, or null when there's nowhere safe.
     */
    public static function resolve(array $fields, array $columnNames, bool $bareLayout): array
    {
        // Lowercase name => real spelling. A column like "123" is an int here, so it's cast.
        $realName = [];
        foreach ($columnNames as $column) {
            $realName[strtolower((string) $column)] = (string) $column;
        }

        $names = [];
        foreach ($fields as $field) {
            $names[(string) $field->name] = true;
        }

        // Each field's wanted column, lowercase, and how many fields want each one.
        $exact = [];
        if ($bareLayout) {
            foreach (array_keys($names) as $name) {
                $wanted = strtolower((string) $name);
                if (!in_array($wanted, self::CORE_COLUMNS, true) && isset($realName[$wanted])) {
                    $exact[(string) $name] = $wanted;
                }
            }
        }
        $exactClaims = array_count_values(array_map('strval', $exact));

        $fallback = [];
        foreach (array_keys($names) as $name) {
            $wanted = 'additional_' . strtolower((string) $name);
            if (!isset($exact[$name]) && isset($realName[$wanted]) && !isset($exactClaims[$wanted])) {
                $fallback[(string) $name] = $wanted;
            }
        }
        $fallbackClaims = array_count_values($fallback);

        $map = [];
        foreach (array_keys($names) as $name) {
            $name = (string) $name;
            $map[$name] = null;
            if (isset($exact[$name])) {
                if ($exactClaims[$exact[$name]] === 1) {
                    $map[$name] = $realName[$exact[$name]];
                }
            } elseif (isset($fallback[$name]) && $fallbackClaims[$fallback[$name]] === 1) {
                $map[$name] = $realName[$fallback[$name]];
            }
        }

        return $map;
    }
}
