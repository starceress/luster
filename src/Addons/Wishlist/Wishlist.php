<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Immutable value object representing a single wishlist entry from the
 lstr_wishlist table. Holds the subject, URL, optional image, category,
 type (wanted/top/granted), description, date added, sort order, and
 active status. The fromRow() factory hydrates from a database row and
 typeLabel() returns the human-readable type name.
*/

declare(strict_types=1);

namespace Luster\Addons\Wishlist;

/**
 * Immutable value object for a row in lstr_wishlist.
 *
 * Represents one item on the site owner's fanlisting wishlist.
 * Hydrate via Wishlist::fromRow(); all fields are read-only after construction.
 */
final class Wishlist
{
    public function __construct(
        public readonly int     $id,
        public readonly string  $subject,
        public readonly string  $url,
        public readonly ?string $image,
        public readonly string  $category,
        public readonly string  $type,
        public readonly ?string $description,
        public readonly ?string $addedAt,
        public readonly int     $sortOrder,
        public readonly bool    $active,
    ) {}

    /** Hydrates an instance from an associative database row. */
    public static function fromRow(array $row): self
    {
        return new self(
            id:          (int)    $row['id'],
            subject:     (string) ($row['subject']     ?? ''),
            url:         (string) ($row['url']         ?? ''),
            image:       isset($row['image']) && $row['image'] !== '' ? (string) $row['image'] : null,
            category:    (string) ($row['category']    ?? ''),
            type:        (string) ($row['type']        ?? 'wanted'),
            description: isset($row['description']) && $row['description'] !== '' ? (string) $row['description'] : null,
            addedAt:     isset($row['added_at'])  && $row['added_at']  !== '' ? (string) $row['added_at']  : null,
            sortOrder:   (int)    ($row['sort_order']  ?? 0),
            active:      (bool)   ($row['active']      ?? true),
        );
    }

    /** Returns the human-readable label for the entry type. */
    public function typeLabel(): string
    {
        return match ($this->type) {
            'top'     => 'Top Wanted',
            'granted' => 'Granted',
            default   => 'Wanted',
        };
    }
}
