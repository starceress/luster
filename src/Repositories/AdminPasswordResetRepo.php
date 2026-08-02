<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Handles admin-panel password reset tokens stored in lstr_password_resets.
 Only the SHA-256 hash of each token is persisted; expired and already-used
 tokens are cleaned up opportunistically on lookup. One active token per
 admin user is enforced by deleting previous tokens before creating a new one.
*/

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

    /**
     * Inserts a new reset token row, storing the SHA-256 hash and expiry time.
     *
     * The caller is responsible for calling deleteByUser() first to enforce
     * the one-token-per-user policy.
     */
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

    /**
     * Marks a token as consumed so it cannot be reused, even if it has not expired yet.
     */
    public function markUsed(string $tokenHash): void
    {
        $this->db->prepare(
            'UPDATE lstr_password_resets SET used = 1 WHERE token_hash = :hash'
        )->execute(['hash' => $tokenHash]);
    }

    /**
     * Atomically marks a token as used and returns its row, gated on
     * `used = 0 AND expires_at > NOW()` in a single UPDATE. Only the caller
     * whose UPDATE actually matches a row (rowCount() === 1) may treat the
     * token as valid, so two concurrent requests presenting the same raw
     * token can never both succeed - matches the pattern used by
     * CollectiveUpdateTokenRepo::verifyAndConsume().
     *
     * @return array{id:int,admin_user_id:int,expires_at:string}|null
     */
    public function verifyAndConsume(string $tokenHash): ?array
    {
        $this->deleteExpired();

        $upd = $this->db->prepare(
            'UPDATE lstr_password_resets
             SET used = 1
             WHERE token_hash = :hash AND used = 0 AND expires_at > NOW()'
        );
        $upd->execute(['hash' => $tokenHash]);

        if ($upd->rowCount() !== 1) {
            return null;
        }

        $sel = $this->db->prepare(
            'SELECT id, admin_user_id, expires_at FROM lstr_password_resets WHERE token_hash = :hash'
        );
        $sel->execute(['hash' => $tokenHash]);
        $row = $sel->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * Removes all reset tokens for an admin user.
     *
     * Call this before create() to enforce the one-active-token-per-user rule,
     * and on successful password reset to invalidate any remaining tokens.
     */
    public function deleteByUser(int $adminUserId): void
    {
        $this->db->prepare(
            'DELETE FROM lstr_password_resets WHERE admin_user_id = :uid'
        )->execute(['uid' => $adminUserId]);
    }

    /**
     * Deletes all expired or already-used reset tokens across all admin users.
     *
     * Called opportunistically inside findValidByToken() to keep the table clean
     * without a separate scheduled job.
     */
    public function deleteExpired(): void
    {
        $this->db->prepare(
            'DELETE FROM lstr_password_resets WHERE expires_at <= NOW() OR used = 1'
        )->execute();
    }
}
