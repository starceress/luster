<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Issues and consumes single-use, action-bound, short-lived opaque tokens
 that let the public member update/delete forms recover from a lost PHP
 session (broken cookies, session GC, restrictive shared hosting) without
 falling back to a long-lived replayable identity token. Designed generally
 (allowed actions are an internal allowlist, not a DB enum) so future
 consumers like KIM update/delete can reuse it after their own security
 review — only the update/delete flow is wired up today.
*/

declare(strict_types=1);

namespace Luster\Core;

use Luster\Repositories\MemberFormSessionRepo;

/**
 * Issues and validates single-use member form session tokens.
 *
 * A token is bound to one listing, one member email, and one allowed action
 * ('update' or 'delete'). It expires after a short TTL and is marked used
 * immediately on a successful, fully-matching consume() — a second attempt
 * with the same raw token always fails, even within the TTL window.
 */
final class MemberFormSessionService
{
    private const ALLOWED_ACTIONS = ['update', 'delete'];
    private const TTL_SECONDS     = 1800; // 30 minutes

    public function __construct(private readonly MemberFormSessionRepo $repo) {}

    /**
     * Generates a new token for $listingId + $email + $action and returns the
     * raw token (only its hash is persisted). Returns null when $action is
     * not in the allowlist.
     */
    public function issue(int $listingId, string $email, string $action): ?string
    {
        if (!in_array($action, self::ALLOWED_ACTIONS, true)) {
            return null;
        }

        $rawToken  = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = (new \DateTimeImmutable())
            ->modify('+' . self::TTL_SECONDS . ' seconds')
            ->format('Y-m-d H:i:s');

        $this->repo->create($listingId, $email, $action, $tokenHash, $expiresAt);

        return $rawToken;
    }

    /**
     * Validates $rawToken against $listingId and $action. On a full match
     * (not expired, not already used, correct listing, correct action),
     * marks the token used and returns the bound member email. Returns null
     * on any failure — the token is left untouched (not consumed) so a
     * legitimate retry within the TTL is still possible.
     */
    public function consume(int $listingId, string $rawToken, string $action): ?string
    {
        if ($rawToken === '' || !in_array($action, self::ALLOWED_ACTIONS, true)) {
            return null;
        }

        $tokenHash = hash('sha256', $rawToken);

        $row = $this->repo->findValidByTokenHash($tokenHash);
        if ($row === null) {
            return null;
        }

        if ((int) $row['fanlisting_id'] !== $listingId || (string) $row['allowed_action'] !== $action) {
            return null;
        }

        // Atomically claim the token - closes the race where two concurrent
        // requests presenting the same raw token both pass the checks above
        // before either has consumed it. A listing/action mismatch above
        // leaves the token untouched (retry-able); only a genuine match
        // reaches this consuming call.
        $consumed = $this->repo->verifyAndConsume($tokenHash);

        return $consumed !== null ? (string) $consumed['member_email'] : null;
    }
}
