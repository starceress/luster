<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Handles single-use member form session tokens stored in
 lstr_member_form_sessions. These let public update/delete forms recover
 from a lost PHP session without falling back to a long-lived replayable
 token. Only the SHA-256 hash of each token is persisted, never the raw
 token itself. Expired rows are cleaned up opportunistically on lookup.
*/

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Database;

/**
 * Manages single-use member form session tokens in lstr_member_form_sessions.
 *
 * Security model:
 *  - Only the SHA-256 hash of the raw token is stored, never the token itself.
 *  - Token lookup uses hash('sha256', $rawToken) on the caller side.
 *  - Tokens are single-use: markUsed() must be called immediately on
 *    successful consumption so a second attempt with the same token fails.
 *  - Expired rows are cleaned up opportunistically on lookup.
 */
final class MemberFormSessionRepo
{
    public function __construct(private readonly Database $db) {}

    public function create(
        int    $listingId,
        string $email,
        string $allowedAction,
        string $tokenHash,
        string $expiresAt,
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO lstr_member_form_sessions
                (fanlisting_id, member_email, allowed_action, token_hash, expires_at)
             VALUES (:fanlisting_id, :member_email, :allowed_action, :token_hash, :expires_at)'
        );
        $stmt->execute([
            'fanlisting_id'  => $listingId,
            'member_email'   => $email,
            'allowed_action' => $allowedAction,
            'token_hash'     => $tokenHash,
            'expires_at'     => $expiresAt,
        ]);
    }

    /**
     * Returns the token row if found, not expired, and not yet used — null
     * otherwise. Also opportunistically deletes expired rows as a side effect.
     *
     * @return array{id:int, fanlisting_id:int, member_email:string, allowed_action:string}|null
     */
    public function findValidByTokenHash(string $tokenHash): ?array
    {
        $this->deleteExpired();

        $stmt = $this->db->prepare(
            'SELECT id, fanlisting_id, member_email, allowed_action
             FROM lstr_member_form_sessions
             WHERE token_hash = :token_hash
               AND used_at IS NULL
               AND expires_at > NOW()'
        );
        $stmt->execute(['token_hash' => $tokenHash]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * Marks a token as used so it cannot be consumed a second time.
     */
    public function markUsed(int $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE lstr_member_form_sessions SET used_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * Atomically marks a token as used and returns its row, gated on
     * `used_at IS NULL AND expires_at > NOW()` in a single UPDATE. Only the
     * caller whose UPDATE actually matches a row (rowCount() === 1) may treat
     * the token as valid, so two concurrent requests presenting the same raw
     * token can never both succeed.
     *
     * @return array{id:int, fanlisting_id:int, member_email:string, allowed_action:string}|null
     */
    public function verifyAndConsume(string $tokenHash): ?array
    {
        $this->deleteExpired();

        $upd = $this->db->prepare(
            'UPDATE lstr_member_form_sessions
             SET used_at = NOW()
             WHERE token_hash = :token_hash AND used_at IS NULL AND expires_at > NOW()'
        );
        $upd->execute(['token_hash' => $tokenHash]);

        if ($upd->rowCount() !== 1) {
            return null;
        }

        $sel = $this->db->prepare(
            'SELECT id, fanlisting_id, member_email, allowed_action
             FROM lstr_member_form_sessions
             WHERE token_hash = :token_hash'
        );
        $sel->execute(['token_hash' => $tokenHash]);
        $row = $sel->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * Removes all expired rows across all listings.
     */
    public function deleteExpired(): void
    {
        $this->db->prepare('DELETE FROM lstr_member_form_sessions WHERE expires_at <= NOW()')->execute();
    }
}
