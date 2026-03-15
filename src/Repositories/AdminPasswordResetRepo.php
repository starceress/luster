<?php

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Database;

/**
 * Manages admin password reset tokens in lstr_password_resets.
 *
 * Security model:
 *  - Only the SHA-256 hash of the raw token is stored, never the token itself.
 *  - Expired and used tokens are cleaned up opportunistically.
 *  - One active token per admin user enforced by deleteByUser() before create().
 */
final class AdminPasswordResetRepo
{
    public function __construct(private readonly Database $db) {}

    public function create(int $adminUserId, string $tokenHash, string $expiresAt): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO lstr_password_resets (admin_user_id, token_hash, expires_at)
             VALUES (:uid, :hash, :expires)'
        );
        $stmt->execute(['uid' => $adminUserId, 'hash' => $tokenHash, 'expires' => $expiresAt]);
    }

    /**
     * Returns the token row if valid (not expired, not used), null otherwise.
     *
     * @return array{id:int,admin_user_id:int,expires_at:string}|null
     */
    public function findValidByToken(string $tokenHash): ?array
    {
        $this->deleteExpired();

        $stmt = $this->db->prepare(
            'SELECT id, admin_user_id, expires_at
             FROM lstr_password_resets
             WHERE token_hash = :hash
               AND expires_at > NOW()
               AND used = 0
             LIMIT 1'
        );
        $stmt->execute(['hash' => $tokenHash]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public function markUsed(string $tokenHash): void
    {
        $this->db->prepare(
            'UPDATE lstr_password_resets SET used = 1 WHERE token_hash = :hash'
        )->execute(['hash' => $tokenHash]);
    }

    public function deleteByUser(int $adminUserId): void
    {
        $this->db->prepare(
            'DELETE FROM lstr_password_resets WHERE admin_user_id = :uid'
        )->execute(['uid' => $adminUserId]);
    }

    public function deleteExpired(): void
    {
        $this->db->prepare(
            'DELETE FROM lstr_password_resets WHERE expires_at <= NOW() OR used = 1'
        )->execute();
    }
}
