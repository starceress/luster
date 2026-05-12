<?php

declare(strict_types=1);

namespace Luster\Addons\Wishlist;

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

    public function typeLabel(): string
    {
        return match ($this->type) {
            'top'      => 'Top Wanted',
            'granted'  => 'Granted',
            'upcoming' => 'Upcoming',
            default    => 'Wanted',
        };
    }
}
