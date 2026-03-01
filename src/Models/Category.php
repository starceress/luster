<?php

declare(strict_types=1);

namespace Luster\Models;

/**
 * Represents a single category row from lstr_categories.
 *
 * source indicates where the category originated:
 *   'tfl'    — imported from The Fanlistings
 *   'tafl'   — imported from The Anime Fanlistings
 *   'custom' — user-defined
 */
final class Category
{
    public function __construct(
        public readonly int     $id,
        public readonly string  $name,
        public readonly ?int    $parentId,
        public readonly string  $source,
        public readonly string  $createdAt,
    ) {}

    /**
     * Hydrates a Category from a PDO FETCH_ASSOC row.
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id:        (int)    $row['id'],
            name:      (string) $row['name'],
            parentId:  $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            source:    (string) ($row['source']     ?? 'custom'),
            createdAt: (string) ($row['created_at'] ?? ''),
        );
    }
}
