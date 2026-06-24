<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Immutable value object representing a single KIM member row. Each member
 is associated with one fanlisting and tracks their name, email, URL,
 hashed password, pending/granted status, and sign-up date. The fromRow()
 factory hydrates an instance from an associative database row.
*/

declare(strict_types=1);

namespace Luster\Addons\Kim;

/**
 * Immutable value object for a row in lstr_kim_members.
 *
 * Represents one fan's interest in adopting a specific fanlisting.
 * Hydrate via KimMember::fromRow(); all fields are read-only after construction.
 */
final class KimMember
{
    public function __construct(
        public readonly int    $id,
        public readonly int    $fanlistingId,
        public readonly string $name,
        public readonly string $email,
        public readonly string $url,
        public readonly string $password,
        public readonly bool   $pending,
        public readonly bool   $granted,
        public readonly string $addedAt,
    ) {}

    /** Hydrates an instance from an associative database row. */
    public static function fromRow(array $row): self
    {
        return new self(
            id:           (int)    $row['id'],
            fanlistingId: (int)    $row['fanlisting_id'],
            name:         (string) $row['name'],
            email:        (string) $row['email'],
            url:          (string) ($row['url'] ?? ''),
            password:     (string) $row['password'],
            pending:      (bool)   $row['pending'],
            granted:      (bool)   ($row['granted'] ?? false),
            addedAt:      (string) ($row['added_at'] ?? ''),
        );
    }
}
