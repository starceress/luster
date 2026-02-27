<?php

declare(strict_types=1);

namespace Luster\Models;

/**
 * Represents a single affiliate row from lstr_affiliates.
 *
 * email and image are nullable — they may not be provided for every affiliate.
 */
final class Affiliate
{
    public function __construct(
        public readonly int     $id,
        public readonly int     $fanlistingId,
        public readonly string  $name,
        public readonly string  $url,
        public readonly ?string $email,
        public readonly ?string $image,
        public readonly int     $sortOrder,
        public readonly string  $createdAt,
        public readonly string  $updatedAt,
    ) {}

    /**
     * Hydrates an Affiliate from a PDO FETCH_ASSOC row.
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id:           (int)    $row['id'],
            fanlistingId: (int)    $row['fanlisting_id'],
            name:         (string) $row['name'],
            url:          (string) $row['url'],
            email:        $row['email'] !== null ? (string) $row['email'] : null,
            image:        $row['image'] !== null ? (string) $row['image'] : null,
            sortOrder:    (int)    ($row['sort_order'] ?? 0),
            createdAt:    (string) ($row['created_at'] ?? ''),
            updatedAt:    (string) ($row['updated_at'] ?? ''),
        );
    }
}
