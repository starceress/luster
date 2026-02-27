<?php

declare(strict_types=1);

namespace Luster\Models;

/**
 * Represents a single fan from a member table.
 *
 * Supports two Enthusiast schema variants:
 *
 *   Standard (prefixed, enth_table_prefix = 'enth_'):
 *     Columns: id, name, email, url, country, showurl, password, approved,
 *              added, updated, additional_{fieldname} (prefixed additional cols)
 *     Approved: approved = 1
 *
 *   Unprefixed Enthusiast 3 (enth_table_prefix = ''):
 *     Columns: email, name, country, url, pending, password, showemail,
 *              showurl, added — plus bare additional field columns (no prefix)
 *     No id column (email is effective PK); no updated column
 *     Approved: pending = 0
 *
 * fromRow() handles both transparently.
 * The $additionalFields map is always keyed by bare field name regardless of schema.
 */
final class Member
{
    /**
     * Column names that belong to the standard member table schema in both
     * schema variants. Any column NOT in this list is treated as an additional
     * field (used for the unprefixed schema where extras lack the additional_
     * prefix).
     */
    private const STANDARD_COLS = [
        'id', 'email', 'name', 'country', 'url',
        'pending', 'approved',
        'password', 'showurl', 'showemail',
        'added', 'updated',
    ];

    /**
     * @param array<string,string> $additionalFields  Dynamic field values keyed by field name.
     */
    public function __construct(
        public readonly int    $id,
        public readonly string $name,
        public readonly string $email,
        public readonly string $url,
        public readonly string $country,
        public readonly bool   $showurl,
        public readonly string $password,
        public readonly bool   $approved,
        public readonly string $added,
        public readonly string $updated,
        public readonly array  $additionalFields,
    ) {}

    /**
     * Hydrates a Member from a PDO FETCH_ASSOC row.
     *
     * Standard schema: additional fields have the 'additional_' prefix.
     * Unprefixed schema: additional fields are bare column names; any column
     * not in STANDARD_COLS is treated as an additional field.
     */
    public static function fromRow(array $row): self
    {
        $additionalFields = [];

        foreach ($row as $key => $value) {
            // Standard enth_ schema: additional_ prefix
            if (str_starts_with($key, 'additional_')) {
                $additionalFields[substr($key, strlen('additional_'))] = (string) $value;
                continue;
            }

            // Unprefixed schema: bare column names for additional fields
            if (!in_array($key, self::STANDARD_COLS, true)) {
                $additionalFields[$key] = (string) $value;
            }
        }

        // Approved status:
        //   Standard enth_: approved = 1  → approved
        //   Unprefixed:     pending  = 0  → approved (inverted semantics)
        if (array_key_exists('approved', $row)) {
            $approved = (bool) $row['approved'];
        } elseif (array_key_exists('pending', $row)) {
            $approved = !(bool) $row['pending'];
        } else {
            $approved = false;
        }

        return new self(
            id:               (int)    ($row['id']       ?? 0),   // no id col in unprefixed schema
            name:             (string) ($row['name']     ?? ''),
            email:            (string) ($row['email']    ?? ''),
            url:              (string) ($row['url']      ?? ''),
            country:          (string) ($row['country']  ?? ''),
            showurl:          (bool)   ($row['showurl']  ?? false),
            password:         (string) ($row['password'] ?? ''),
            approved:         $approved,
            added:            (string) ($row['added']    ?? ''),
            updated:          (string) ($row['updated']  ?? ''),  // empty string if absent
            additionalFields: $additionalFields,
        );
    }
}
