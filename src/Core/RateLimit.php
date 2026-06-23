<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Database-backed rate limiter that protects public forms and admin login
 against brute-force abuse. Tracks attempt counts per (IP, action) pair in
 the lstr_rate_limits table and enforces configurable thresholds with
 time-based expiry windows. Predefined limits cover admin login, public
 join, update, reset, contact, and other form submission endpoints.
*/

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
    public const ACTION_PUBLIC_RESET  = 'public_reset';
    public const ACTION_ADMIN_FORGOT             = 'admin_forgot';
    public const ACTION_PUBLIC_CONTACT           = 'public_contact';
    public const ACTION_PUBLIC_ADOPT             = 'public_adopt';
    public const ACTION_PUBLIC_AFFILIATE_APPLY   = 'public_affiliate_apply';
    public const ACTION_PUBLIC_CODE_SUBMIT       = 'public_code_submit';

    private const DEFAULTS = [
        self::ACTION_ADMIN_LOGIN              => ['max' => 5,  'window' => 900],
        self::ACTION_PUBLIC_JOIN              => ['max' => 10, 'window' => 3600],
        self::ACTION_PUBLIC_UPDATE            => ['max' => 10, 'window' => 3600],
        self::ACTION_PUBLIC_RESET             => ['max' => 5,  'window' => 3600],
        self::ACTION_ADMIN_FORGOT             => ['max' => 3,  'window' => 3600],
        self::ACTION_PUBLIC_CONTACT           => ['max' => 5,  'window' => 3600],
        self::ACTION_PUBLIC_ADOPT             => ['max' => 5,  'window' => 3600],
        self::ACTION_PUBLIC_AFFILIATE_APPLY   => ['max' => 5,  'window' => 3600],
        self::ACTION_PUBLIC_CODE_SUBMIT       => ['max' => 5,  'window' => 3600],
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

        // Fetch any existing row for this identifier+action
        $sel = $this->db->prepare(
            'SELECT attempts, last_attempt FROM lstr_rate_limits
             WHERE identifier = :identifier AND action = :action'
        );
        $sel->execute(['identifier' => $identifier, 'action' => $action]);
        $existing = $sel->fetch();

        if ($existing === false) {
            // No row yet — insert with attempts=1
            $ins = $this->db->prepare(
                'INSERT INTO lstr_rate_limits (identifier, action, attempts, last_attempt, blocked_until)
                 VALUES (:identifier, :action, 1, :now, NULL)'
            );
            $ins->execute(['identifier' => $identifier, 'action' => $action, 'now' => $nowStr]);
            $currentAttempts = 1;
        } else {
            // Compare last_attempt to the window boundary in PHP so the logic
            // works on both MySQL and SQLite (avoids DB-dialect-specific SQL).
            $lastAttempt = new DateTimeImmutable((string) $existing['last_attempt']);
            $windowStart = $now->modify("-{$windowSeconds} seconds");

            if ($lastAttempt < $windowStart) {
                // Stale row — outside the window; reset counter and clear any
                // expired block so the next isBlocked() call sees a clean slate.
                $upd = $this->db->prepare(
                    'UPDATE lstr_rate_limits
                     SET attempts = 1, last_attempt = :now, blocked_until = NULL
                     WHERE identifier = :identifier AND action = :action'
                );
                $upd->execute(['now' => $nowStr, 'identifier' => $identifier, 'action' => $action]);
                $currentAttempts = 1;
            } else {
                // Within the window — increment
                $newAttempts = (int) $existing['attempts'] + 1;
                $upd = $this->db->prepare(
                    'UPDATE lstr_rate_limits
                     SET attempts = :attempts, last_attempt = :now
                     WHERE identifier = :identifier AND action = :action'
                );
                $upd->execute([
                    'attempts'   => $newAttempts,
                    'now'        => $nowStr,
                    'identifier' => $identifier,
                    'action'     => $action,
                ]);
                $currentAttempts = $newAttempts;
            }
        }

        // Block the identifier once the threshold is reached
        if ($currentAttempts >= $maxAttempts) {
            $blockedUntil = $now->modify("+{$windowSeconds} seconds")->format('Y-m-d H:i:s');
            $blk = $this->db->prepare(
                'UPDATE lstr_rate_limits SET blocked_until = :blocked_until
                 WHERE identifier = :identifier AND action = :action'
            );
            $blk->execute([
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
