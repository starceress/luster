<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Value object for a fan/member from a per-listing member table. Each
 listing has its own member table, and this model normalizes the two
 Enthusiast schema variants into one object. Custom field answers (like
 "Favorite song") come from answers(), which works out which column belongs
 to which field. Approval status is derived from either the inverted
 "pending" column (unprefixed) or the "approved" column (standard schema).
*/

declare(strict_types=1);

namespace Luster\Models;

use Luster\Support\FieldColumnMap;

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
 * fromRow() handles both transparently. A row with a pending column is the
 * unprefixed schema, so there id, approved and updated can only be custom columns.
 */
final class Member
{
    /** The built-in columns of the standard schema. Anything else is an extra column. */
    private const STANDARD_COLS = [
        'id', 'email', 'name', 'country', 'url',
        'pending', 'approved',
        'password', 'showurl', 'showemail',
        'added', 'updated',
    ];

    /**
     * @param bool                 $approved     True when the member is publicly visible (approved=1
     *                                           in enth_ schema, pending=0 in unprefixed schema).
     * @param bool                 $rejected     True when the member was explicitly rejected
     *                                           (pending=2 in the unprefixed schema; always false
     *                                           in the standard enth_ schema which has no rejected state).
     * @param array<string,string> $extraColumns Every non-built-in column, under its real name and
     *                                           untouched. For a field's answer, use answers().
     * @param bool                 $bareLayout   True on the unprefixed schema, where a field's column
     *                                           can be its bare name.
     */
    public function __construct(
        public readonly int    $id,
        public readonly string $name,
        public readonly string $email,
        public readonly string $url,
        public readonly string $country,
        public readonly bool   $showurl,
        /** Argon2id hash (or legacy MD5 hash for pre-Luster members). Never a plaintext value. */
        public readonly string $password,
        public readonly bool   $approved,
        public readonly bool   $rejected,
        public readonly string $added,
        public readonly string $updated,
        public readonly array  $extraColumns,
        public readonly bool   $bareLayout = true,
    ) {}

    /**
     * Hydrates a Member from a PDO FETCH_ASSOC row.
     *
     * Nothing here guesses what an extra column means: answers() does that, from
     * the listing's field definitions.
     */
    public static function fromRow(array $row): self
    {
        $hasPending  = array_key_exists('pending', $row);
        $hasApproved = array_key_exists('approved', $row);
        $builtIn     = $hasPending ? FieldColumnMap::CORE_COLUMNS : self::STANDARD_COLS;

        $extraColumns = [];
        foreach ($row as $key => $value) {
            // A column named like "123" (Enthusiast allows it) is an int key here.
            $key = (string) $key;
            if (!in_array(strtolower($key), $builtIn, true)) {
                $extraColumns[$key] = (string) $value;
            }
        }

        // Approved status:
        //   Unprefixed:     pending  = 0  → approved (inverted semantics)
        //   Standard enth_: approved = 1  → approved
        $rejected = false;
        if ($hasPending) {
            $pendingVal = (int) $row['pending'];
            $rejected   = $pendingVal === 2;
            $approved   = $pendingVal === 0;
        } elseif ($hasApproved) {
            $approved = (bool) $row['approved'];
        } else {
            $approved = false;
        }

        return new self(
            id:           $hasPending ? 0 : (int) ($row['id'] ?? 0),
            name:         (string) ($row['name']     ?? ''),
            email:        (string) ($row['email']    ?? ''),
            url:          (string) ($row['url']      ?? ''),
            country:      (string) ($row['country']  ?? ''),
            showurl:      (bool)   ($row['showurl']  ?? false),
            password:     (string) ($row['password'] ?? ''),
            approved:     $approved,
            rejected:     $rejected,
            added:        (string) ($row['added']    ?? ''),
            updated:      $hasPending ? '' : (string) ($row['updated'] ?? ''),
            extraColumns: $extraColumns,
            bareLayout:   $hasPending || !$hasApproved,
        );
    }

    /**
     * This member's answer to each field, blank for a field with no column of its
     * own. It uses the same rules as saving (FieldColumnMap), from the row's own
     * columns, so it needs no query and can't fail.
     *
     * @param AdditionalField[] $fields Every definition on the listing.
     * @return array<string, string> Field name => answer.
     */
    public function answers(array $fields): array
    {
        $answers = [];
        foreach (FieldColumnMap::resolve($fields, array_keys($this->extraColumns), $this->bareLayout) as $name => $column) {
            $answers[$name] = $column !== null ? ($this->extraColumns[$column] ?? '') : '';
        }

        return $answers;
    }
}
