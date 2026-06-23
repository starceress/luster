<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Value object for a listing category from the lstr_categories table.
 Categories organize owned and joined listings into groups like "Music",
 "Anime", or "Movies". Each category has a source indicating where it
 came from (TFL seed, TAFL seed, or user-created), an auto-generated
 URL slug, and an optional parent_id for one-level nesting.
*/

declare(strict_types=1);

namespace Luster\Models;

/**
 * Represents a single category row from lstr_categories.
 *
 * source indicates where the category originated:
 *   'tfl'    — imported from The Fanlistings
 *   'tafl'   — imported from The Anime Fanlistings
 *   'custom' — user-defined
 *
 * slug is auto-generated from name on insert (Migration 55+).
 * parentId is NULL for top-level categories; max depth is 1.
 */
final class Category
{
    public function __construct(
        public readonly int     $id,
        public readonly string  $name,
        public readonly string  $slug,
        public readonly ?int    $parentId,
        public readonly string  $source,
        public readonly string  $createdAt,
    ) {}

    /**
     * Hydrates a Category from a PDO FETCH_ASSOC row.
     * Gracefully handles rows from pre-Migration-55 schemas that lack slug/parent_id.
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id:        (int)    $row['id'],
            name:      (string) $row['name'],
            slug:      (string) ($row['slug']      ?? ''),
            parentId:  isset($row['parent_id']) && $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            source:    (string) ($row['source']     ?? 'custom'),
            createdAt: (string) ($row['created_at'] ?? ''),
        );
    }
}
