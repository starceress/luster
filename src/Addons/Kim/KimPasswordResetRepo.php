<?php

declare(strict_types=1);

namespace Luster\Addons\Kim;

use Luster\Core\Database;

/**
 * Manages KIM password reset tokens stored in lstr_kim_password_resets.
 *
 * Security model:
 *  - Only the SHA-256 hash of the raw token is stored, never the token itself.
 *  - Token lookup uses hash('sha256', $rawToken) on the caller side.
 *  - Expired tokens are cleaned up opportunistically on lookup.
 *  - Existing tokens for a member are deleted before creating a new one.
 */
final class KimPasswordResetRepo
{
    public function __construct(private readonly Database $db) {}

    /**
     * Stores a new reset token. Call deleteByMember() first to enforce one-token-at-a-time.
     */
    public function create(int $kimMemberId, string $tokenHash, string $expiresAt): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO lstr_kim_password_resets
                (kim_member_id, token_hash, expires_at)
             VALUES (:kim_member_id, :token_hash, :expires_at)'
        );
        $stmt->execute([
            'kim_member_id' => $kimMemberId,
            'token_hash'    => $tokenHash,
            'expires_at'    => $expiresAt,
        ]);
    }

    /**
     * Returns the token row if found and not yet expired, null otherwise.
     * Also deletes expired rows as a side effect.
     *
     * @return array{id: int, kim_member_id: int, expires_at: string}|null
     */
    public function findValidByToken(string $tokenHash): ?array
    {
        $this->deleteExpired();

        $stmt = $this->db->prepare(
            'SELECT id, kim_member_id, expires_at
             FROM lstr_kim_password_resets
             WHERE token_hash = :token_hash
               AND expires_at > NOW()'
        );
        $stmt->execute(['token_hash' => $tokenHash]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * Deletes all existing reset tokens for this KIM member.
     * Call before creating a new token to enforce one-at-a-time.
     */
    public function deleteByMember(int $kimMemberId): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM lstr_kim_password_resets WHERE kim_member_id = :kim_member_id'
        );
        $stmt->execute(['kim_member_id' => $kimMemberId]);
    }

    /**
     * Deletes a specific token after it has been successfully consumed.
     */
    public function deleteByToken(string $tokenHash): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM lstr_kim_password_resets WHERE token_hash = :token_hash'
        );
        $stmt->execute(['token_hash' => $tokenHash]);
    }

    /**
     * Removes all expired tokens.
     */
    public function deleteExpired(): void
    {
        $this->db->prepare(
            'DELETE FROM lstr_kim_password_resets WHERE expires_at <= NOW()'
        )->execute();
    }
}
