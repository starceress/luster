<?php

declare(strict_types=1);

namespace Luster\Models;

/**
 * Represents a single row from lstr_joined.
 *
 * Tracks fanlistings the site owner has joined (as opposed to running).
 */
final class JoinedFanlisting
{
    public function __construct(
        public readonly int     $id,
        public readonly string  $name,
        public readonly string  $url,
        public readonly ?string $image,
        public readonly string  $category,
        public readonly ?string $dateJoined,
        public readonly bool    $active,
        public readonly int     $sortOrder,
        public readonly string  $createdAt,
    ) {}

    /**
     * Hydrates a JoinedFanlisting from a PDO FETCH_ASSOC row.
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id:         (int)    $row['id'],
            name:       (string) $row['name'],
            url:        (string) $row['url'],
            image:      isset($row['image']) && $row['image'] !== null ? (string) $row['image'] : null,
            category:   (string) ($row['category'] ?? ''),
            dateJoined: isset($row['date_joined']) && $row['date_joined'] !== null ? (string) $row['date_joined'] : null,
            active:     (bool)   ($row['active'] ?? true),
            sortOrder:  (int)    ($row['sort_order'] ?? 0),
            createdAt:  (string) ($row['created_at'] ?? ''),
        );
    }
}
