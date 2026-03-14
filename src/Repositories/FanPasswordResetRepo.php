<?php

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Database;

/**
 * Manages fan password reset tokens stored in lstr_fan_password_resets.
 *
 * Security model:
 *  - Only the SHA-256 hash of the raw token is stored, never the token itself.
 *  - Token lookup uses hash('sha256', $rawToken) on the caller side.
 *  - Expired tokens are cleaned up opportunistically on lookup.
 *  - Existing tokens for an email+fanlisting are deleted before creating a new one.
 */
final class FanPasswordResetRepo
{
    public function __construct(private readonly Database $db) {}

    /**
     * Stores a new reset token. Call deleteByEmail() first to enforce one-token-at-a-time.
     */
    public function create(int $listingId, string $email, string $tokenHash, string $expiresAt): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO lstr_fan_password_resets
                (fanlisting_id, email, token_hash, expires_at)
             VALUES (:fanlisting_id, :email, :token_hash, :expires_at)'
        );
        $stmt->execute([
            'fanlisting_id' => $listingId,
            'email'         => $email,
            'token_hash'    => $tokenHash,
            'expires_at'    => $expiresAt,
        ]);
    }

    /**
     * Returns the token row if found and not yet expired, null otherwise.
     * Also deletes expired rows for this listing as a side effect.
     *
     * @return array{id:int, fanlisting_id:int, email:string, expires_at:string}|null
     */
    public function findValidByToken(string $tokenHash): ?array
    {
        // Opportunistic cleanup of expired tokens
        $this->deleteExpired();

        $stmt = $this->db->prepare(
            'SELECT id, fanlisting_id, email, expires_at
             FROM lstr_fan_password_resets
             WHERE token_hash = :token_hash
               AND expires_at > NOW()'
        );
        $stmt->execute(['token_hash' => $tokenHash]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * Deletes all existing reset tokens for this email + listing.
     * Call before creating a new token to enforce one-at-a-time.
     */
    public function deleteByEmail(int $listingId, string $email): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM lstr_fan_password_resets
             WHERE fanlisting_id = :fanlisting_id AND email = :email'
        );
        $stmt->execute(['fanlisting_id' => $listingId, 'email' => $email]);
    }

    /**
     * Deletes a specific token after it has been successfully consumed.
     */
    public function deleteByToken(string $tokenHash): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM lstr_fan_password_resets WHERE token_hash = :token_hash'
        );
        $stmt->execute(['token_hash' => $tokenHash]);
    }

    /**
     * Removes all expired tokens across all listings.
     */
    public function deleteExpired(): void
    {
        $this->db->prepare('DELETE FROM lstr_fan_password_resets WHERE expires_at <= NOW()')->execute();
    }
}
