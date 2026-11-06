<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Combines an Enthusiast source listing's fields with a Luster listing's existing
 definitions, for an Overwrite. Fields already there keep their name and everything
 the source didn't really supply, fields only Luster has stay, and a new field can
 never take a column from one that's already there.
*/

declare(strict_types=1);

namespace Luster\Import;

use Luster\Models\AdditionalField;
use Luster\Support\FieldColumnMap;

/**
 * Works from a listing's stored definitions and its table's columns, and writes nothing.
 *
 * Adding a definition can change who owns a column: with `x` reading `additional_x`,
 * adding a field called `additional_x` leaves `x` with nowhere and hands its answers to
 * the newcomer. So each newcomer is tried against the map first, and only joins if
 * every existing field keeps exactly the column it had and the newcomer gets its own.
 * Names without the additional_ prefix go first, so source order can't decide who wins.
 */
final class EnthusiastFieldMerge
{
    /** The field types FieldRepo understands. Anything else would read back as text. */
    private const KNOWN_TYPES = ['text', 'dropdown', 'checkbox', 'textarea', 'select', 'radio'];

    private function __construct() {}

    /**
     * @param list<string> $columns The target table's real columns.
     * @return array{
     *   definitions: list<array<string, mixed>>,
     *   added: list<array{name: string, column: string}>,
     *   notes: list<string>
     * } Definitions in stored form. A column in 'added' may not exist yet.
     */
    public static function merge(string $targetRaw, EnthusiastSourceFields $source, array $columns): array
    {
        $definitions = self::parseStored($targetRaw);
        $byName      = [];
        foreach ($definitions as $at => $definition) {
            $byName[strtolower((string) $definition['name'])] = $at;
        }

        $added = [];
        $notes = [];
        $seen  = [];

        $ordered = $source->fields;
        usort($ordered, static fn(array $a, array $b): int
            => (int) str_starts_with(strtolower($a['name']), 'additional_') <=> (int) str_starts_with(strtolower($b['name']), 'additional_'));

        foreach ($ordered as $field) {
            $key         = strtolower($field['name']);
            $seen[$key]  = true;

            if (isset($byName[$key])) {
                $at = $byName[$key];
                foreach (self::suppliedChanges($definitions[$at], $field) as $property => $value) {
                    $definitions[$at][$property] = $value;
                }
                continue;
            }

            if (!self::bindsCleanly($definitions, $columns, $field['name'], $field['column'])) {
                $notes[] = "Field \"{$field['name']}\" wasn't added, and its answers weren't copied: its column would change which answers another field on this listing shows.";
                continue;
            }

            $definitions[]   = self::newDefinition($field);
            $byName[$key]    = array_key_last($definitions);
            $added[]         = ['name' => $field['name'], 'column' => $field['column']];
            $columns         = self::withColumn($columns, $field['column']);
        }

        foreach ($definitions as $definition) {
            $name = (string) $definition['name'];
            if (!isset($seen[strtolower($name)])) {
                $notes[] = "Field \"{$name}\" was kept: it's on this listing but not in the source, so it's either gone from there or was added here.";
            }
        }

        return ['definitions' => $definitions, 'added' => $added, 'notes' => $notes];
    }

    /**
     * The fields a new listing can really have, by the same rules as an existing one.
     * If they all fit, what the source said is kept exactly as it was.
     *
     * @param list<string> $columns Columns already there, if a table's been left over.
     */
    public static function planNewListing(EnthusiastSourceFields $source, array $columns): EnthusiastSourceFields
    {
        $merged = self::merge('', $source, $columns);

        return count($merged['added']) === count($source->fields)
            ? $source
            : $source->keeping(array_column($merged['added'], 'name'), $merged['notes']);
    }

    /**
     * True when $name can have $column without any field on the listing changing what it
     * reads, and $name really ends up with that column.
     *
     * @param list<array<string, mixed>> $definitions
     * @param list<string> $columns
     */
    public static function bindsCleanly(array $definitions, array $columns, string $name, string $column): bool
    {
        $before = self::map($definitions, $columns);

        $withNew = $definitions;
        $already = false;
        foreach ($definitions as $definition) {
            $already = $already || strcasecmp((string) $definition['name'], $name) === 0;
        }
        if (!$already) {
            $withNew[] = ['name' => $name];
        }
        $after = self::map($withNew, self::withColumn($columns, $column));

        foreach ($before as $existing => $bound) {
            if ($bound !== null && strcasecmp((string) $existing, $name) !== 0 && $after[$existing] !== $bound) {
                return false;
            }
            if ($bound === null && strcasecmp((string) $existing, $name) !== 0 && ($after[$existing] ?? null) !== null) {
                return false;
            }
        }

        return isset($after[$name]) && strcasecmp((string) $after[$name], $column) === 0;
    }

    /**
     * Stored definitions as a list of arrays, keeping any detail a serialized value has.
     * A comma list gets what FieldRepo would give it, so a merged list reads the same.
     *
     * @return list<array<string, mixed>>
     */
    public static function parseStored(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        set_error_handler(static fn(): bool => true);
        try {
            $decoded = unserialize($raw, ['allowed_classes' => false]);
        } finally {
            restore_error_handler();
        }

        $out = [];
        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $name = (string) ($item['name'] ?? $item[0] ?? '');
                if (preg_match('/^[a-zA-Z0-9_]+$/', $name) !== 1) {
                    continue;
                }
                $out[] = [
                    'name'     => $name,
                    'label'    => (string) ($item['label'] ?? $item[1] ?? ''),
                    'type'     => (string) ($item['type'] ?? $item[2] ?? 'text'),
                    'required' => (bool) (int) ($item['required'] ?? $item[3] ?? 0),
                    'options'  => (string) ($item['options'] ?? ''),
                ];
            }
            return $out;
        }

        foreach (explode(',', $raw) as $name) {
            $name = trim($name);
            if (preg_match('/^[a-zA-Z0-9_]+$/', $name) === 1) {
                $out[] = [
                    'name'     => $name,
                    'label'    => ucwords(str_replace('_', ' ', $name)),
                    'type'     => 'text',
                    'required' => false,
                    'options'  => '',
                ];
            }
        }

        return $out;
    }

    /**
     * Which column each definition reads, the same way the rest of Luster works it out.
     *
     * @param list<array<string, mixed>> $definitions
     * @param list<string> $columns
     * @return array<string, ?string>
     */
    public static function map(array $definitions, array $columns): array
    {
        $fields = array_map(
            static fn(array $definition): AdditionalField => new AdditionalField((string) $definition['name'], '', 'text', false),
            $definitions,
        );

        return FieldColumnMap::resolve($fields, $columns, true);
    }

    /**
     * @param array<string, mixed> $definition
     * @param array{label: ?string, type: ?string, required: ?bool, options: ?string} $field
     * @return array<string, mixed> Only the properties the source really supplied that differ.
     */
    private static function suppliedChanges(array $definition, array $field): array
    {
        $changes = [];
        if ($field['label'] !== null && $field['label'] !== (string) ($definition['label'] ?? '')) {
            $changes['label'] = $field['label'];
        }
        if ($field['type'] !== null
            && in_array($field['type'], self::KNOWN_TYPES, true)
            && self::typeAsRead($field['type']) !== self::typeAsRead((string) ($definition['type'] ?? 'text'))
        ) {
            $changes['type'] = $field['type'];
        }
        if ($field['required'] !== null && $field['required'] !== (bool) ($definition['required'] ?? false)) {
            $changes['required'] = $field['required'];
        }
        if ($field['options'] !== null && $field['options'] !== '' && $field['options'] !== (string) ($definition['options'] ?? '')) {
            $changes['options'] = $field['options'];
        }
        return $changes;
    }

    /**
     * @param array{name: string, label: ?string, type: ?string, required: ?bool, options: ?string} $field
     * @return array<string, mixed>
     */
    private static function newDefinition(array $field): array
    {
        return [
            'name'     => $field['name'],
            'label'    => $field['label'] ?? ucwords(str_replace('_', ' ', $field['name'])),
            'type'     => $field['type'] ?? 'text',
            'required' => $field['required'] ?? false,
            'options'  => $field['options'] ?? '',
        ];
    }

    /** Enthusiast calls a dropdown "select", and they're one thing here. */
    private static function typeAsRead(string $type): string
    {
        return $type === 'select' ? 'dropdown' : $type;
    }

    /**
     * @param list<string> $columns
     * @return list<string>
     */
    private static function withColumn(array $columns, string $column): array
    {
        foreach ($columns as $existing) {
            if (strcasecmp($existing, $column) === 0) {
                return $columns;
            }
        }
        $columns[] = $column;
        return $columns;
    }
}
