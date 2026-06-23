<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Value object for a fanlisting from the listing registry table. This is
 the central model — every listing has a title, subject, URL, email, and
 a member table reference (dbprefix). The fromRow() factory handles both
 Enthusiast schema variants transparently: the standard enth_fanlistings
 layout and the unprefixed "owned" table layout, mapping their different
 column names and status representations into a single consistent object.
*/

declare(strict_types=1);

namespace Luster\Models;

/**
 * Represents a single listing from the listing registry table.
 *
 * Supports two Enthusiast schema variants:
 *
 *   Standard (prefixed, enth_table_prefix = 'enth_'):
 *     Table: enth_fanlistings
 *     Columns: id, name, subject, dbprefix, email, url, category,
 *              additional_info, upcoming (bool), trouble (bool), listingtype
 *
 *   Unprefixed Enthusiast 3 (enth_table_prefix = ''):
 *     Table: owned
 *     Columns: listingid, title, subject, dbtable, email, url, catid (|N| fmt),
 *              additional (bare field names), status (2=active,1=upcoming,0=inactive),
 *              listingtype
 *
 * fromRow() transparently handles both; the $dbprefix property always holds
 * the member table name (whether from dbprefix or dbtable).
 * The $additionalInfo property holds the raw field definition string
 * (full pipe-delimited definitions OR bare field names — FieldRepo parses it).
 */
final class Listing
{
    public function __construct(
        public readonly int     $id,
        public readonly string  $title,
        public readonly string  $listingtype,
        public readonly int     $category,
        public readonly string  $subject,
        public readonly string  $url,
        public readonly string  $dbprefix,
        public readonly string  $email,
        public readonly string  $additionalInfo,
        public readonly bool    $upcoming,
        public readonly bool    $trouble,
        /** Date the listing opened — from joined/opened/since column if present. */
        public readonly ?string $joined = null,
        /** Opened date as a dedicated property (same source as $joined). */
        public readonly ?string $opened = null,
        /** Luster category assignment from lstr_categories (nullable). */
        public readonly ?int    $categoryId = null,
        /** Raw catid/category string from the registry row (pipe-delimited or int). */
        public readonly string  $catid = '',
    ) {}

    /**
     * Hydrates a Listing from a PDO FETCH_ASSOC row.
     *
     * Works with both the standard enth_fanlistings layout and the
     * unprefixed Enthusiast 3 'owned' table layout.
     */
    public static function fromRow(array $row): self
    {
        // Primary key: listingid (owned) or id (enth_fanlistings)
        $id = (int) ($row['listingid'] ?? $row['id'] ?? 0);

        // Display title: title (owned) or name (enth_fanlistings)
        $title = (string) ($row['title'] ?? $row['name'] ?? '');

        // Member table reference: dbtable (owned) or dbprefix (enth_fanlistings)
        $dbprefix = (string) ($row['dbtable'] ?? $row['dbprefix'] ?? '');

        // Additional field definitions: additional (owned) or additional_info (enth_)
        $additionalInfo = (string) ($row['additional'] ?? $row['additional_info'] ?? '');

        // Status:
        //   owned uses status int: 2=active, 1=upcoming, 0=inactive
        //   enth_fanlistings uses separate upcoming/trouble bool columns
        if (array_key_exists('status', $row)) {
            $status   = (int) $row['status'];
            $upcoming = ($status === 1);
            $trouble  = ($status === 0);
        } else {
            $upcoming = (bool) ($row['upcoming'] ?? false);
            $trouble  = (bool) ($row['trouble']  ?? false);
        }

        // Category: catid (owned, stored as |N|N2| pipe format) or category (enth_, int)
        $catRaw   = $row['category'] ?? $row['catid'] ?? '0';
        $category = self::parseCategoryId((string) $catRaw);

        $joinedRaw = $row['joined'] ?? $row['opened'] ?? $row['since'] ?? null;

        return new self(
            id:             $id,
            title:          $title,
            listingtype:    (string) ($row['listingtype'] ?? 'listing'),
            category:       $category,
            subject:        (string) ($row['subject']     ?? ''),
            url:            (string) ($row['url']         ?? ''),
            dbprefix:       $dbprefix,
            email:          (string) ($row['email']       ?? ''),
            additionalInfo: $additionalInfo,
            upcoming:       $upcoming,
            trouble:        $trouble,
            joined:         $joinedRaw !== null ? (string) $joinedRaw : null,
            opened:         $joinedRaw !== null ? (string) $joinedRaw : null,
            categoryId:     isset($row['category_id']) && $row['category_id'] !== null
                                ? (int) $row['category_id']
                                : null,
            catid:          (string) $catRaw,
        );
    }

    // -----------------------------------------------------------------------

    /**
     * Extracts the first numeric category ID from either an integer or the
     * pipe-delimited |N|N2| format used by Enthusiast 3's 'owned' table.
     */
    private static function parseCategoryId(string $raw): int
    {
        if (is_numeric($raw)) {
            return (int) $raw;
        }

        // Pipe-delimited: |8| or |8|31| — take first ID
        if (preg_match('/\|(\d+)\|/', $raw, $m)) {
            return (int) $m[1];
        }

        return 0;
    }
}
