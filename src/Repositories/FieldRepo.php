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
     * Adds an additional_{$fieldName} TEXT NULL column to the member table.
     *
     * Both $prefix and $fieldName are validated against /^[a-zA-Z0-9_]+$/
     * before being interpolated into the DDL statement.
     *
     * @throws \InvalidArgumentException if either argument fails validation.
     */
    public function addColumn(string $prefix, string $fieldName, string $options = ''): void
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) {
            throw new \InvalidArgumentException(
                "Invalid prefix '{$prefix}': only [a-zA-Z0-9_] allowed."
            );
        }

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $fieldName)) {
            throw new \InvalidArgumentException(
                "Invalid field name '{$fieldName}': only [a-zA-Z0-9_] allowed."
            );
        }

        // Unprefixed (Enthusiast 3) schema: column is the bare field name.
        // Standard (enth_) schema: column is additional_{fieldName}.
        $colName = $this->isOwnedSchema ? $fieldName : 'additional_' . $fieldName;

        $this->db->pdo()->exec(
            "ALTER TABLE {$prefix} ADD COLUMN {$colName} TEXT NULL"
        );
    }

    /**
     * Drops the additional_{$fieldName} column from the member table.
     *
     * Both $prefix and $fieldName are validated against /^[a-zA-Z0-9_]+$/
     * before being interpolated into the DDL statement.
     *
     * @throws \InvalidArgumentException if either argument fails validation.
     */
    public function dropColumn(string $prefix, string $fieldName): void
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) {
            throw new \InvalidArgumentException(
                "Invalid prefix '{$prefix}': only [a-zA-Z0-9_] allowed."
            );
        }

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $fieldName)) {
            throw new \InvalidArgumentException(
                "Invalid field name '{$fieldName}': only [a-zA-Z0-9_] allowed."
            );
        }

        if ($this->isOwnedSchema) {
            // Unprefixed schema: bare column name.
            // For backwards compatibility also try the legacy additional_{name} form
            // in case the column was created before this bug was fixed.
            $pdo = $this->db->pdo();
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = :tbl
                   AND COLUMN_NAME  = :col"
            );
            $stmt->execute(['tbl' => $prefix, 'col' => $fieldName]);
            if ((int) $stmt->fetchColumn() > 0) {
                $pdo->exec("ALTER TABLE {$prefix} DROP COLUMN {$fieldName}");
            } else {
                // Fall back to the old bugged name so pre-fix columns can still be removed
                $pdo->exec("ALTER TABLE {$prefix} DROP COLUMN additional_{$fieldName}");
            }
        } else {
            $this->db->pdo()->exec(
                "ALTER TABLE {$prefix} DROP COLUMN additional_{$fieldName}"
            );
        }
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
                type:     in_array($type, ['text', 'textarea', 'select', 'radio'], true) ? $type : 'text',
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
                type:     in_array($type, ['text', 'dropdown', 'checkbox', 'textarea', 'select', 'radio'], true) ? $type : 'text',
                required: $required,
                options:  $options,
            );
        }

        return $fields;
    }
}
