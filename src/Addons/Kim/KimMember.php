<?php

declare(strict_types=1);

namespace Luster\Addons\Kim;

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
