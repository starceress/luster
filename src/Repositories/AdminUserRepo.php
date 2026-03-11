<?php

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Database;
use Luster\Models\AdminUser;

/**
 * Data access for the lstr_admin_users table.
 *
 * Provides the minimum surface needed for authentication, password reset,
 * and login-attempt tracking. All queries use prepared statements.
 *
 * Note: the last_login and failed_attempts columns are created by the
 * installer (install.php). See AdminUser::fromRow() for field mapping.
 */
final class AdminUserRepo
{
    public function __construct(private readonly Database $db) {}

    // -----------------------------------------------------------------------
    // Lookups
    // -----------------------------------------------------------------------

    /**
     * Finds an admin user by primary key.
     */
    public function getById(int $id): ?AdminUser
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_admin_users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();

        return $row !== false ? AdminUser::fromRow($row) : null;
    }

    /**
     * Finds an admin user by username (case-insensitive at the DB collation
     * level — utf8mb4_unicode_ci handles this).
     */
    public function findByUsername(string $username): ?AdminUser
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_admin_users WHERE username = :username LIMIT 1'
        );
        $stmt->execute(['username' => $username]);

        $row = $stmt->fetch();

        return $row !== false ? AdminUser::fromRow($row) : null;
    }

    /**
     * Finds an admin user by email address.
     */
    public function findByEmail(string $email): ?AdminUser
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_admin_users WHERE email = :email LIMIT 1'
        );
        $stmt->execute(['email' => $email]);

        $row = $stmt->fetch();

        return $row !== false ? AdminUser::fromRow($row) : null;
    }

    // -----------------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------------

    /**
     * Updates the username and email for an admin user.
     */
    public function updateProfile(int $id, string $username, string $email): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE lstr_admin_users SET username = :username, email = :email WHERE id = :id'
        );

        return $stmt->execute(['username' => $username, 'email' => $email, 'id' => $id]);
    }

    /**
     * Replaces the stored password hash for an admin user.
     *
     * $passwordHash must already be hashed with password_hash(..., PASSWORD_ARGON2ID).
     */
    public function updatePassword(int $id, string $passwordHash): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE lstr_admin_users SET password = :password WHERE id = :id'
        );

        return $stmt->execute(['password' => $passwordHash, 'id' => $id]);
    }

    /**
     * Stamps the current server timestamp into last_login.
     */
    public function updateLastLogin(int $id): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE lstr_admin_users SET last_login = NOW() WHERE id = :id'
        );

        return $stmt->execute(['id' => $id]);
    }

    /**
     * Increments the failed_attempts counter by 1 (used after a bad password).
     */
    public function incrementFailedAttempts(int $id): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE lstr_admin_users
             SET failed_attempts = failed_attempts + 1
             WHERE id = :id'
        );

        return $stmt->execute(['id' => $id]);
    }

    /**
     * Resets the failed_attempts counter to 0 (called on successful login).
     */
    public function resetFailedAttempts(int $id): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE lstr_admin_users SET failed_attempts = 0 WHERE id = :id'
        );

        return $stmt->execute(['id' => $id]);
    }
}
