<?php

declare(strict_types=1);

namespace Luster\Models;

/**
 * Represents an admin account from lstr_admin_users.
 *
 * The password property holds a bcrypt/Argon2id hash — never a plaintext
 * value. lastLogin is null until the user logs in for the first time.
 */
final class AdminUser
{
    public function __construct(
        public readonly int     $id,
        public readonly string  $username,
        public readonly string  $email,
        public readonly string  $password,
        public readonly int     $failedAttempts,
        public readonly ?string $lastLogin,
        public readonly string  $createdAt,
        public readonly string  $updatedAt,
    ) {}

    /**
     * Hydrates an AdminUser from a PDO FETCH_ASSOC row.
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id:             (int)    $row['id'],
            username:       (string) $row['username'],
            email:          (string) $row['email'],
            password:       (string) $row['password'],
            failedAttempts: (int)    ($row['failed_attempts'] ?? 0),
            lastLogin:      $row['last_login'] !== null ? (string) $row['last_login'] : null,
            createdAt:      (string) ($row['created_at'] ?? ''),
            updatedAt:      (string) ($row['updated_at'] ?? ''),
        );
    }
}
