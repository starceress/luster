<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Manages short-lived collective update tokens stored in
 lstr_collective_update_tokens. Used by the collective-update embed to
 verify email ownership before allowing cross-listing membership updates.
 Only the SHA-256 hash of each raw token is persisted.
*/

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Database;

/**
 * Manages collective update verification tokens.
 *
 * Security model:
 *  - Only the SHA-256 hash of the raw token is stored.
 *  - Tokens are single-use: markUsed() sets used_at and findValid() filters
 *    these out, preventing replay.
 *  - Tokens expire after 60 minutes.
 *  - One active token per email: create() deletes any prior token before inserting.
 */
final class CollectiveUpdateTokenRepo
{
    private const TTL_SECONDS = 3600;

    public function __construct(private readonly Database $db) {}

    /**
     * Creates a new collective update token for the given email.
     * Removes any existing token for the same email first to enforce one-at-a-time.
     * Returns the raw token (caller must deliver this to the user; only the hash is stored).
     */
    public function create(string $email): string
    {
        $this->deleteByEmail($email);

        $rawToken  = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = (new \DateTimeImmutable())
            ->modify('+' . self::TTL_SECONDS . ' seconds')
            ->format('Y-m-d H:i:s');

        $stmt = $this->db->prepare(
            'INSERT INTO lstr_collective_update_tokens (token_hash, email, expires_at)
             VALUES (:token_hash, :email, :expires_at)'
        );
        $stmt->execute([
            'token_hash' => $tokenHash,
            'email'      => $email,
            'expires_at' => $expiresAt,
        ]);

        return $rawToken;
    }

    /**
     * Finds a valid (non-expired, unused) token by raw token value.
     * Opportunistically removes expired tokens on each call.
     *
     * @return array{id: int, email: string, expires_at: string}|null
     */
    public function findValid(string $rawToken): ?array
    {
        $this->deleteExpired();

        $stmt = $this->db->prepare(
            'SELECT id, email, expires_at
             FROM lstr_collective_update_tokens
             WHERE token_hash = :hash
               AND used_at IS NULL
               AND expires_at > NOW()'
        );
        $stmt->execute(['hash' => hash('sha256', $rawToken)]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * Atomically marks a token as consumed and returns the associated email.
     * Uses a single UPDATE with used_at IS NULL + expires_at > NOW() so two
     * concurrent POST submissions cannot both succeed on the same token.
     * Returns the email on success, null if the token is invalid/expired/already used.
     */
    public function verifyAndConsume(string $rawToken): ?string
    {
        $hash = hash('sha256', $rawToken);

        $upd = $this->db->prepare(
            'UPDATE lstr_collective_update_tokens
             SET used_at = NOW()
             WHERE token_hash = :hash AND used_at IS NULL AND expires_at > NOW()'
        );
        $upd->execute(['hash' => $hash]);

        if ($upd->rowCount() !== 1) {
            return null;
        }

        $sel = $this->db->prepare(
            'SELECT email FROM lstr_collective_update_tokens WHERE token_hash = :hash'
        );
        $sel->execute(['hash' => $hash]);
        $email = $sel->fetchColumn();

        return $email !== false ? (string) $email : null;
    }

    /**
     * Marks a token as consumed, preventing replay.
     */
    public function markUsed(int $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE lstr_collective_update_tokens SET used_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    public function deleteByEmail(string $email): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM lstr_collective_update_tokens WHERE email = :email'
        );
        $stmt->execute(['email' => $email]);
    }

    public function deleteExpired(): void
    {
        $this->db->prepare(
            'DELETE FROM lstr_collective_update_tokens WHERE expires_at <= NOW()'
        )->execute();
    }
}
