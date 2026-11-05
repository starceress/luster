<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 The one way to change a member's email address. Queued requests, link checks and
 reset links point at a member by their address with no foreign key, so they have
 to move with it. The member is written last and read back, and if anything goes
 wrong this works out what really happened instead of assuming.
*/

declare(strict_types=1);

namespace Luster\Support;

use Luster\Core\Application;
use Luster\Core\Config;
use Luster\Core\Database;
use Luster\Repositories\FanPasswordResetRepo;
use Luster\Repositories\MemberLinkCheckRepo;
use Luster\Repositories\MemberRepo;
use Luster\Repositories\PendingUpdateRepo;
use RuntimeException;

/**
 * Links go first, then requests move, then the member, read back, COMMIT, then link checks.
 *
 * Why it's shaped like this:
 *
 *  - Reset links go before the transaction starts, each in its own statement. Inside
 *    it, a rollback would bring them back, and if a MyISAM member kept the change they'd
 *    be left at an address someone else can get. See MemberAddressHandover.
 *  - Rollback can't prove a write did nothing. A MyISAM member table keeps a change
 *    even when the transaction around it rolls back. So after a failure at or after
 *    the member write, the member is read again and compared with the copy from
 *    before. Identical means "failed", and anything else means "unconfirmed".
 *  - Only the read-back counts as proof. MemberRepo::update() returning true only
 *    means the statement ran.
 *  - Nothing carries on after an error inside the transaction. On MariaDB, carrying
 *    on after a deadlock and then committing keeps the statements after the error
 *    and silently loses the ones before it.
 *  - A transaction that can't be closed ends the database work for this request.
 *    Anything else on this connection would read its uncommitted changes, and anything
 *    written would be lost with it when the request ends. Callers stop at "unresolved".
 *  - Link checks run after COMMIT, because they're allowed to fail.
 *
 * Callers hold the address locks for the old and new addresses before calling this.
 */
final class MemberEmailRekey
{
    public const DONE        = 'done';
    public const FAILED      = 'failed';
    public const UNCONFIRMED = 'unconfirmed';
    public const UNRESOLVED  = 'unresolved';

    private readonly MemberRepo $memberRepo;
    private readonly PendingUpdateRepo $pendingRepo;

    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
    ) {
        $this->memberRepo  = new MemberRepo($db, $config);
        $this->pendingRepo = new PendingUpdateRepo($db);
    }

    public static function for(Application $app): self
    {
        return new self($app->getDatabase(), $app->getConfig());
    }

    /**
     * Changes a member's address, with whatever else $changes holds, from a form.
     *
     * An unconfirmed change holds every request at the old address, because rollback
     * has just put them back there and they can't be trusted to reach the right person.
     *
     * @param array<string, mixed> $changes Must include the new 'email'.
     * @return array{outcome: string, held: int} outcome is DONE, FAILED, UNCONFIRMED or
     *         UNRESOLVED. On UNRESOLVED, do no more database work in this request.
     */
    public function run(int $listingId, string $dbprefix, string $email, array $changes): array
    {
        if ($this->transactionIsOpen()) {
            return ['outcome' => self::UNRESOLVED, 'held' => 0];
        }

        $result = $this->attempt($listingId, $dbprefix, $email, $changes);

        return $this->transactionIsOpen() ? ['outcome' => self::UNRESOLVED, 'held' => 0] : $result;
    }

    /**
     * True when a transaction is still open on this connection. Between jobs that only
     * happens when one couldn't be closed.
     */
    public function transactionIsOpen(): bool
    {
        return $this->db->pdo()->inTransaction();
    }

    /**
     * @param array<string, mixed> $changes
     * @return array{outcome: string, held: int}
     */
    private function attempt(int $listingId, string $dbprefix, string $email, array $changes): array
    {
        $newEmail = (string) ($changes['email'] ?? '');
        $before   = null;
        $writing  = false;

        try {
            $this->clearTheWay($listingId, $email, $newEmail);

            $this->db->beginTransaction();

            $before = $this->memberRepo->lockRawRow($dbprefix, $email);
            if ($before === null) {
                throw new RuntimeException('The member was not at this address.');
            }

            $this->pendingRepo->renameMemberEmail($listingId, $email, $newEmail);

            $writing = true;
            $this->writeAndCommit($dbprefix, $email, $changes);
        } catch (\Throwable $e) {
            error_log('[Luster] Changing a member\'s address failed for listing #' . $listingId . ': ' . $e->getMessage());

            if (!$writing || $before === null) {
                $this->rollBack();
                return ['outcome' => self::FAILED, 'held' => 0];
            }

            if ($this->rollBackAndCompare($dbprefix, $email, $before) === self::FAILED) {
                return ['outcome' => self::FAILED, 'held' => 0];
            }

            return [
                'outcome' => self::UNCONFIRMED,
                'held'    => $this->transactionIsOpen() ? 0 : $this->pendingRepo->holdAddress($listingId, $email, PendingUpdateRepo::WRITE_UNCONFIRMED),
            ];
        }

        $this->moveLinkCheck($listingId, $email, $newEmail);

        return ['outcome' => self::DONE, 'held' => 0];
    }

    /**
     * Gets both addresses ready, before any transaction and with both address locks held.
     * The new address was free, so anything left there can't be this member's, unless
     * it's only a change of capitals, when it's the same mailbox and nobody new. A reset
     * link was issued to the old address and can't be renamed.
     *
     * @throws \RuntimeException if anything can't be cleared. Don't go on to write the member.
     */
    public function clearTheWay(int $listingId, string $oldEmail, string $newEmail): void
    {
        if (!self::sameAddress($oldEmail, $newEmail)) {
            (new MemberAddressHandover($this->db))->clear($listingId, $newEmail);
        }

        (new FanPasswordResetRepo($this->db))->deleteByEmail($listingId, $oldEmail);
    }

    /**
     * Writes the member in the caller's open transaction, reads them back, and commits.
     * Anything that fails or doesn't match throws. The caller then uses rollBackAndCompare().
     *
     * @param array<string, mixed> $changes Column => value.
     * @return array<string, mixed> The member's row as read back.
     * @throws \Throwable
     */
    public function writeAndCommit(string $dbprefix, string $email, array $changes): array
    {
        if (!$this->memberRepo->update($dbprefix, $email, $changes)) {
            throw new RuntimeException('Nothing was left to write to the member.');
        }

        $finalEmail = isset($changes['email']) ? (string) $changes['email'] : $email;
        $row        = $this->memberRepo->lockRawRow($dbprefix, $finalEmail);
        if ($row === null) {
            throw new RuntimeException('The member was not there when read back.');
        }

        foreach ($changes as $column => $value) {
            $column = (string) $column;
            // update() skips a column the table doesn't have, so there's nothing to compare.
            if (array_key_exists($column, $row) && (string) $row[$column] !== (string) $value) {
                throw new RuntimeException("The member's {$column} did not match when read back.");
            }
        }

        $this->db->commit();

        return $row;
    }

    /**
     * Works out what a failure at or after the member write really did: FAILED if the
     * member is exactly as they were, UNCONFIRMED otherwise, or if there's no telling.
     *
     * @param array<string, mixed> $before The member's whole row, read before the write.
     */
    public function rollBackAndCompare(string $dbprefix, string $email, array $before): string
    {
        $this->rollBack();

        // A read inside a transaction that's still open would only see our own changes.
        if ($this->transactionIsOpen()) {
            return self::UNCONFIRMED;
        }

        try {
            return $this->memberRepo->getRawRow($dbprefix, $email) === $before ? self::FAILED : self::UNCONFIRMED;
        } catch (\Throwable $e) {
            error_log('[Luster] Re-reading a member after a failed write failed: ' . $e->getMessage());
            return self::UNCONFIRMED;
        }
    }

    /**
     * Rolls back if a transaction is open. A failure here is logged, never thrown, and
     * leaves it open, which transactionIsOpen() reports for the caller to stop at.
     */
    public function rollBack(): void
    {
        try {
            if ($this->transactionIsOpen()) {
                $this->db->rollBack();
            }
        } catch (\Throwable $e) {
            error_log('[Luster] Rolling back a member write failed: ' . $e->getMessage());
        }
    }

    /**
     * Moves the link-check row after COMMIT. If it fails, it's logged, the change
     * stands, and the next Link Checker sync rebuilds that row.
     */
    public function moveLinkCheck(int $listingId, string $oldEmail, string $newEmail): void
    {
        try {
            (new MemberLinkCheckRepo($this->db, $this->config))->renameMemberEmail($listingId, $oldEmail, $newEmail);
        } catch (\Throwable $e) {
            error_log('[Luster] Moving a link check to a new address failed for listing #' . $listingId . ': ' . $e->getMessage());
        }
    }

    /** True when two spellings are one address. Capitals don't matter, as they don't to the member table. */
    public static function sameAddress(string $a, string $b): bool
    {
        return mb_strtolower(trim($a), 'UTF-8') === mb_strtolower(trim($b), 'UTF-8');
    }
}
