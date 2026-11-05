<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Reads and writes the custom additional field definitions that each listing
 can have (like "Favorite Color" or "Favorite Song"). Handles both Enthusiast
 schema variants: the unprefixed format (comma-separated bare names in the
 `owned.additional` column) and the standard prefixed format (pipe-delimited
 or PHP-serialized definitions in `enth_fanlistings.additional_info`). Also
 manages adding and dropping the corresponding columns on member tables.
*/

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Config;
use Luster\Core\Database;
use Luster\Models\AdditionalField;
use Luster\Support\FieldColumnMap;
use RuntimeException;

/**
 * Parses additional field definitions from the listing registry table.
 *
 * Two schema variants are supported, driven by 'enth_table_prefix':
 *
 *   ''       → Unprefixed Enthusiast 3 ('owned' table):
 *              Column: 'additional'
 *              Format: bare field name(s), comma-separated — e.g. 'favorite'
 *                      or 'favorite_movie'. Labels are synthesised from the
 *                      field name. No type/required metadata is stored.
 *                      The actual member table column is the bare name (no prefix).
 *
 *   'enth_'  → Standard Enthusiast ('enth_fanlistings' table):
 *              Column: 'additional_info'
 *              Format: pipe-separated: name,Label,type,required|name2,...
 *                      or legacy PHP-serialized array.
 *                      The actual member table column is 'additional_{name}'.
 */
final class FieldRepo
{
    private string $table;
    private string $infoCol;
    private string $pkCol;
    private bool   $isOwnedSchema;

    public function __construct(
        private readonly Database $db,
        private readonly Config   $config,
    ) {
        $prefix = (string) $this->config->get('enth_table_prefix', '');

        if ($prefix !== '' && !preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) {
            throw new RuntimeException(
                "Invalid enth_table_prefix '{$prefix}': only [a-zA-Z0-9_] allowed."
            );
        }

        $this->isOwnedSchema = ($prefix === '');

        $rawTable        = $this->isOwnedSchema ? 'owned' : 'enth_fanlistings';
        $this->table     = $this->db->validatePrefix($rawTable);
        $this->infoCol   = $this->isOwnedSchema ? 'additional'   : 'additional_info';
        $this->pkCol     = $this->isOwnedSchema ? 'listingid'    : 'id';
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Returns the parsed field definitions for one listing.
     *
     * @return AdditionalField[]
     */
    public function getFieldsForListing(int $id): array
    {
        $stmt = $this->db->prepare(
            "SELECT {$this->infoCol} AS field_info FROM {$this->table}
             WHERE {$this->pkCol} = :id"
        );
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();

        if ($row === false || $row['field_info'] === null || $row['field_info'] === '') {
            return [];
        }

        return $this->parseFieldInfo((string) $row['field_info']);
    }

    /**
     * Shared parsing entry point for a single listing's raw field-info column value.
     * Tries the serialized-array format first (written by saveFields(), and by any
     * import source that preserves type/label/options), falling back to legacy
     * Enthusiast string-format parsing (bare comma-separated names or pipe-delimited
     * definitions) only when the value isn't actually a serialized array.
     *
     * @return AdditionalField[]
     */
    private function parseFieldInfo(string $rawInfo): array
    {
        $raw = trim($rawInfo);

        if ($raw === '') {
            return [];
        }

        // Try unserialize first — handles data written by saveFields() as well
        // as any legacy PHP-serialized Enthusiast additional_info values.
        $decoded = @unserialize($raw, ['allowed_classes' => false]);
        if (is_array($decoded)) {
            return $this->parseSerializedFormat($raw);
        }

        // Fall back to legacy Enthusiast string-format parsing
        // (comma-separated bare names or pipe-delimited definitions).
        return $this->parseAdditionalInfo($raw);
    }

    /**
     * Returns parsed field definitions for every fanlisting, keyed by listing ID.
     *
     * @return array<int, AdditionalField[]>
     */
    public function getAllFields(): array
    {
        $stmt = $this->db->prepare(
            "SELECT {$this->pkCol} AS listing_id, {$this->infoCol} AS field_info
             FROM {$this->table}"
        );
        $stmt->execute();

        $result = [];

        foreach ($stmt->fetchAll() as $row) {
            $id           = (int) $row['listing_id'];
            $result[$id]  = $this->parseFieldInfo((string) ($row['field_info'] ?? ''));
        }

        return $result;
    }

    /**
     * Serializes a fields array and persists it to the listing registry row.
     *
     * @param array<mixed> $fields
     */
    public function saveFields(int $listingId, array $fields): void
    {
        $stmt = $this->db->prepare(
            "UPDATE {$this->table}
             SET {$this->infoCol} = :info
             WHERE {$this->pkCol} = :id"
        );
        $stmt->execute(['info' => serialize($fields), 'id' => $listingId]);
    }

    /**
     * Which column of the listing's member table holds each field's answers, read
     * fresh from the database. See FieldColumnMap for the rules.
     *
     * @param AdditionalField[] $fields Every definition on the listing.
     * @return array<string, ?string> Field name => its column, or null when there's nowhere safe.
     * @throws \InvalidArgumentException if $dbprefix fails validation.
     * @throws RuntimeException if the table's columns can't be read.
     */
    public function fieldColumns(string $dbprefix, array $fields): array
    {
        $table = $this->memberTable($dbprefix);

        if ($fields === []) {
            return [];
        }

        return FieldColumnMap::resolve(
            $fields,
            array_column($this->db->tableColumns($table), 'name'),
            $this->isOwnedSchema,
        );
    }

    /**
     * The fields that can't save answers, for the Fields lists. It's only a label,
     * so a failed read gives none and never breaks the page. Saves do their own
     * strict check with fieldColumns().
     *
     * @param AdditionalField[] $fields Every definition on the listing.
     * @return list<string> Field names.
     */
    public function fieldsWithoutColumn(string $dbprefix, array $fields): array
    {
        try {
            $columns = $this->fieldColumns($dbprefix, $fields);
        } catch (RuntimeException | \InvalidArgumentException) {
            return [];
        }

        return array_map('strval', array_keys(array_filter($columns, static fn(?string $column): bool => $column === null)));
    }

    /**
     * Adds the column for a new field to the listing's member table: the bare
     * name on the unprefixed schema, additional_{$fieldName} on the standard one.
     *
     * Both $dbprefix and $fieldName are validated against /^[a-zA-Z0-9_]+$/
     * before being interpolated into the DDL statement.
     *
     * @throws \InvalidArgumentException if either argument fails validation.
     */
    public function addColumn(string $dbprefix, string $fieldName, string $options = ''): void
    {
        $table = $this->memberTable($dbprefix);

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $fieldName)) {
            throw new \InvalidArgumentException(
                "Invalid field name '{$fieldName}': only [a-zA-Z0-9_] allowed."
            );
        }

        // Unprefixed (Enthusiast 3) schema: column is the bare field name.
        // Standard (enth_) schema: column is additional_{fieldName}.
        $colName = $this->isOwnedSchema ? $fieldName : 'additional_' . $fieldName;

        // Backticked: MySQL only accepts a digits-only name (Enthusiast allows a
        // field called "123") as a quoted identifier.
        $this->db->pdo()->exec(
            "ALTER TABLE `{$table}` ADD COLUMN `{$colName}` TEXT NULL"
        );
    }

    /**
     * Drops a field's column from the member table. $column is the real column,
     * from fieldColumns(), which is what decides that a column belongs to a field.
     *
     * This refuses by itself too, whoever calls it: never a built-in column on the
     * unprefixed schema, and never anything without the additional_ prefix on the
     * standard one, where every other column is a built-in.
     *
     * Both $dbprefix and $column are validated against /^[a-zA-Z0-9_]+$/ before
     * being interpolated into the DDL statement.
     *
     * @throws \InvalidArgumentException if either fails validation, or $column is a built-in.
     */
    public function dropColumn(string $dbprefix, string $column): void
    {
        $table = $this->memberTable($dbprefix);

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            throw new \InvalidArgumentException(
                "Invalid column name '{$column}': only [a-zA-Z0-9_] allowed."
            );
        }

        $lower   = strtolower($column);
        $builtIn = $this->isOwnedSchema
            ? in_array($lower, FieldColumnMap::CORE_COLUMNS, true)
            : !str_starts_with($lower, 'additional_');
        if ($builtIn) {
            throw new \InvalidArgumentException("'{$column}' is a built-in column, so it can't be dropped.");
        }

        // Backticked, like addColumn(): a digits-only name must be quoted.
        $this->db->pdo()->exec("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
    }

    /**
     * The real member table for a listing's dbprefix, the same way
     * MemberRepo::resolveTable() works it out: the value itself on the unprefixed
     * schema, {dbprefix}members on the standard one. An empty dbprefix is refused
     * before "members" is added, so it can't name a table called just "members".
     *
     * @throws \InvalidArgumentException if $dbprefix fails validation.
     */
    private function memberTable(string $dbprefix): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $dbprefix)) {
            throw new \InvalidArgumentException(
                "Invalid prefix '{$dbprefix}': only [a-zA-Z0-9_] allowed."
            );
        }

        return $this->isOwnedSchema ? $dbprefix : $dbprefix . 'members';
    }

    // -----------------------------------------------------------------------
    // Parsing
    // -----------------------------------------------------------------------

    /**
     * Dispatches to the appropriate parser.
     *
     * @return AdditionalField[]
     */
    private function parseAdditionalInfo(string $raw): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return [];
        }

        if ($this->isOwnedSchema) {
            // Unprefixed Enthusiast 3: simple comma-separated field names
            return $this->parseSimpleNames($raw);
        }

        // Standard Enthusiast: PHP-serialized or pipe-delimited full definitions
        if (str_starts_with($raw, 'a:')) {
            return $this->parseSerializedFormat($raw);
        }

        return $this->parsePipeFormat($raw);
    }

    /**
     * Parses the Enthusiast 3 'owned.additional' format: bare field name(s).
     *
     * Examples:  'favorite'          → one field
     *            'favorite_movie'    → one field
     *            'fave_song,reading' → two fields
     *
     * Labels are synthesised: underscores→spaces, title-cased.
     * Type defaults to 'text', required defaults to false.
     *
     * @return AdditionalField[]
     */
    private function parseSimpleNames(string $raw): array
    {
        $fields = [];

        foreach (explode(',', $raw) as $name) {
            $name = trim($name);

            if ($name === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
                continue;
            }

            // Derive a readable label from the field name
            $label = ucwords(str_replace('_', ' ', $name));

            $fields[] = new AdditionalField(
                name:     $name,
                label:    $label,
                type:     'text',
                required: false,
            );
        }

        return $fields;
    }

    /**
     * Parses the pipe-delimited Enthusiast field format.
     *
     * Format: name,Label,type,required|name2,...
     *
     * @return AdditionalField[]
     */
    private function parsePipeFormat(string $raw): array
    {
        $fields = [];

        foreach (explode('|', $raw) as $fieldStr) {
            $fieldStr = trim($fieldStr);

            if ($fieldStr === '') {
                continue;
            }

            $parts = explode(',', $fieldStr, 4);

            if (count($parts) < 2) {
                // Single token with no comma — treat as a bare field name
                $name = trim($parts[0]);
                if ($name !== '' && preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
                    $fields[] = new AdditionalField(
                        name:     $name,
                        label:    ucwords(str_replace('_', ' ', $name)),
                        type:     'text',
                        required: false,
                    );
                }
                continue;
            }

            $name     = trim($parts[0]);
            $label    = trim($parts[1]);
            $type     = trim($parts[2] ?? 'text');
            $required = (bool) (int) trim($parts[3] ?? '0');

            if (!preg_match('/^[a-zA-Z0-9_]+$/', $name) || $name === '') {
                continue;
            }

            $fields[] = new AdditionalField(
                name:     $name,
                label:    $label,
                type:     self::typeAsRead($type, ['text', 'textarea', 'select', 'radio']),
                required: $required,
            );
        }

        return $fields;
    }

    /**
     * Parses a PHP-serialized additional_info string (legacy standard Enthusiast).

     *
     * unserialize() is called with allowed_classes=false so no arbitrary
     * object can be instantiated from the stored string.
     *
     * @return AdditionalField[]
     */
    private function parseSerializedFormat(string $raw): array
    {
        $data = @unserialize($raw, ['allowed_classes' => false]);

        if (!is_array($data)) {
            return [];
        }

        $fields = [];

        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }

            $name     = (string) ($item['name']     ?? $item[0] ?? '');
            $label    = (string) ($item['label']    ?? $item[1] ?? '');
            $type     = (string) ($item['type']     ?? $item[2] ?? 'text');
            $required = (bool)   (int) ($item['required'] ?? $item[3] ?? 0);
            $options  = (string) ($item['options']  ?? '');

            if (!preg_match('/^[a-zA-Z0-9_]+$/', $name) || $name === '') {
                continue;
            }

            $fields[] = new AdditionalField(
                name:     $name,
                label:    $label,
                type:     self::typeAsRead($type, ['text', 'dropdown', 'checkbox', 'textarea', 'select', 'radio']),
                required: $required,
                options:  $options,
            );
        }

        return $fields;
    }

    /**
     * A stored type as the rest of the code knows it. Enthusiast calls a dropdown
     * "select", so that's turned into "dropdown" here and only one word gets any
     * further. What's stored isn't rewritten by reading it.
     *
     * @param list<string> $known
     */
    private static function typeAsRead(string $type, array $known): string
    {
        if (!in_array($type, $known, true)) {
            return 'text';
        }

        return $type === 'select' ? 'dropdown' : $type;
    }
}
