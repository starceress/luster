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
 * Holds everything needed to authenticate an admin and display their
 * identity in the panel. The password field is always an Argon2id hash —
 * never a plaintext value. Use password_verify() to check submitted
 * credentials against it.
 *
 * failedAttempts is incremented on each bad login and reset to 0 on
 * success; it drives the brute-force lockout logic in AuthController.
 * lastLogin is null until the account has been used at least once.
 * displayName (added in Migration 46) is an optional vanity name shown
 * in the admin panel, distinct from the login username.
 */
final class AdminUser
{
    public function __construct(
        public readonly int     $id,
        public readonly string  $username,
        public readonly string  $email,
        /** Optional display name shown in the admin panel (Migration 46). */
        public readonly ?string $displayName,
        /** Argon2id password hash — never a plaintext value. */
        public readonly string  $password,
        /** Consecutive failed login attempts since the last successful login. */
        public readonly int     $failedAttempts,
        /** Timestamp of the last successful login; null if the account has never been used. */
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
