<?php

declare(strict_types=1);

namespace Luster\Core;

use DateTimeImmutable;

/**
 * Database-backed rate limiter.
 *
 * Tracks attempt counts in lstr_rate_limits using a (identifier, action) pair.
 * 'identifier' is typically the requester's IP address.
 *
 * Predefined action limits (enforced by hitDefault()):
 *   admin_login  — 5 attempts per 15 minutes (900 s)
 *   public_join  — 10 attempts per hour (3600 s)
 */
final class RateLimit
{
    public const ACTION_ADMIN_LOGIN   = 'admin_login';
    public const ACTION_PUBLIC_JOIN   = 'public_join';
    public const ACTION_PUBLIC_UPDATE = 'public_update';
    public const ACTION_PUBLIC_REMOVE = 'public_remove';

    private const DEFAULTS = [
        self::ACTION_ADMIN_LOGIN   => ['max' => 5,  'window' => 900],
        self::ACTION_PUBLIC_JOIN   => ['max' => 10, 'window' => 3600],
        self::ACTION_PUBLIC_UPDATE => ['max' => 10, 'window' => 3600],
        self::ACTION_PUBLIC_REMOVE => ['max' => 10, 'window' => 3600],
    ];

    public function __construct(private readonly Database $db) {}

    /**
     * Records a single attempt for the given identifier + action.
     *
     * If the cumulative attempt count reaches $maxAttempts, the identifier
     * is blocked for $windowSeconds from the current moment.
     *
     * @param int $maxAttempts  Maximum attempts before blocking.
     * @param int $windowSeconds Block duration in seconds once the limit is hit.
     */
    public function hit(string $identifier, string $action, int $maxAttempts, int $windowSeconds): void
    {
        $now    = new DateTimeImmutable();
        $nowStr = $now->format('Y-m-d H:i:s');

        // Upsert: insert with attempts=1, or increment on duplicate key
        $stmt = $this->db->prepare(
            'INSERT INTO lstr_rate_limits (identifier, action, attempts, last_attempt, blocked_until)
             VALUES (:identifier, :action, 1, :now, NULL)
             ON DUPLICATE KEY UPDATE
               attempts     = attempts + 1,
               last_attempt = :now2'
        );

        $stmt->execute([
            'identifier' => $identifier,
            'action'     => $action,
            'now'        => $nowStr,
            'now2'       => $nowStr,
        ]);

        // Fetch the updated count to decide whether to set a block
        $stmt2 = $this->db->prepare(
            'SELECT attempts FROM lstr_rate_limits
             WHERE identifier = :identifier AND action = :action'
        );
        $stmt2->execute(['identifier' => $identifier, 'action' => $action]);
        $row = $stmt2->fetch();

        if ($row !== false && (int) $row['attempts'] >= $maxAttempts) {
            $blockedUntil = $now->modify("+{$windowSeconds} seconds")->format('Y-m-d H:i:s');

            $stmt3 = $this->db->prepare(
                'UPDATE lstr_rate_limits SET blocked_until = :blocked_until
                 WHERE identifier = :identifier AND action = :action'
            );
            $stmt3->execute([
                'blocked_until' => $blockedUntil,
                'identifier'    => $identifier,
                'action'        => $action,
            ]);
        }
    }

    /**
     * Convenience wrapper: uses the predefined limit for a known action.
     * Falls back to 10/hr for unknown actions.
     */
    public function hitDefault(string $identifier, string $action): void
    {
        $limits = self::DEFAULTS[$action] ?? ['max' => 10, 'window' => 3600];
        $this->hit($identifier, $action, $limits['max'], $limits['window']);
    }

    /**
     * Returns true if the identifier is currently blocked for this action.
     *
     * Automatically clears expired blocks so the attempt counter resets.
     */
    public function isBlocked(string $identifier, string $action): bool
    {
        $stmt = $this->db->prepare(
            'SELECT blocked_until FROM lstr_rate_limits
             WHERE identifier = :identifier AND action = :action'
        );
        $stmt->execute(['identifier' => $identifier, 'action' => $action]);
        $row = $stmt->fetch();

        if ($row === false || $row['blocked_until'] === null) {
            return false;
        }

        $blockedUntil = new DateTimeImmutable((string) $row['blocked_until']);
        $now          = new DateTimeImmutable();

        if ($now >= $blockedUntil) {
            // Block has expired — clean up so the counter resets on the next hit
            $this->clear($identifier, $action);
            return false;
        }

        return true;
    }

    /**
     * Returns the block expiry time, or null if the identifier is not blocked.
     */
    public function blockedUntil(string $identifier, string $action): ?DateTimeImmutable
    {
        $stmt = $this->db->prepare(
            'SELECT blocked_until FROM lstr_rate_limits
             WHERE identifier = :identifier AND action = :action'
        );
        $stmt->execute(['identifier' => $identifier, 'action' => $action]);
        $row = $stmt->fetch();

        if ($row === false || $row['blocked_until'] === null) {
            return null;
        }

        $blockedUntil = new DateTimeImmutable((string) $row['blocked_until']);
        $now          = new DateTimeImmutable();

        return $now >= $blockedUntil ? null : $blockedUntil;
    }

    /**
     * Clears all rate limit data for this identifier + action.
     * Call on successful authentication to reset the failure counter.
     */
    public function clear(string $identifier, string $action): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM lstr_rate_limits
             WHERE identifier = :identifier AND action = :action'
        );
        $stmt->execute(['identifier' => $identifier, 'action' => $action]);
    }
}
