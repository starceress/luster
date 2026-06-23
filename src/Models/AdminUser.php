<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Value object for an admin account from the lstr_admin_users table.
 Holds the username, email, optional display name, and Argon2id password
 hash — never a plaintext password. Also tracks failed login attempts
 (for brute-force protection) and the last-login timestamp. Hydrated by
 AdminUserRepo and consumed by auth middleware and the settings page.
*/

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
        public readonly ?string $displayName,
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
            displayName:    isset($row['display_name']) && $row['display_name'] !== null ? (string) $row['display_name'] : null,
            password:       (string) $row['password'],
            failedAttempts: (int)    ($row['failed_attempts'] ?? 0),
            lastLogin:      $row['last_login'] !== null ? (string) $row['last_login'] : null,
            createdAt:      (string) ($row['created_at'] ?? ''),
            updatedAt:      (string) ($row['updated_at'] ?? ''),
        );
    }
}
