<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Keeps everything that depends on who's at a member's address in step. Legacy
 member tables have no id, so an address is the only thing a sign-in, a queued
 request or a removal can point at, and any of them could land after that address
 has passed to someone else. Holding this lock from the check through the last
 write means they can't.
*/

declare(strict_types=1);

namespace Luster\Support;

use Luster\Core\Database;
use LogicException;

/**
 * Named database locks, one per listing and address.
 *
 * GET_LOCK() rather than SELECT ... FOR UPDATE: a row lock can't cover a row that
 * doesn't exist yet, and a MyISAM member table has none.
 *
 *  - Every lock comes first. Callers work out all the addresses they'll touch, and
 *    only then use up a credential, re-read a member or write anything, so a busy
 *    address can't leave a job half done.
 *  - All or nothing. Anything but a clear yes from GET_LOCK() releases what's held
 *    and throws MemberAddressBusy before the work starts.
 *  - After getting them all, each is checked as still ours: older MySQL drops the
 *    first lock when it takes a second, and both calls still answer 1.
 *  - Nested calls can't add locks or release early. The second GET_LOCK() of a name
 *    stacks, so one release wouldn't free it. A nested call for what's already held
 *    just does the work, a nested call for anything else is a bug and throws, and
 *    only the outermost call releases.
 *
 * Send mail after run() returns, not inside it: a slow mail server would make other
 * requests for that address time out. Take these before any row lock.
 */
final class MemberAddressLock
{
    private const TIMEOUT_SECONDS = 5;

    /** @var list<string> The lock names this request holds right now. */
    private array $held = [];

    /** @param string $installId Keeps two installs on one server apart: GET_LOCK() is server-wide. */
    public function __construct(
        private readonly Database $db,
        private readonly string $installId,
    ) {}

    /**
     * Runs $work with every address locked.
     *
     * @template T
     * @param list<array{0: int, 1: string}> $addresses Listing id and email pairs. Repeats are fine.
     * @param callable(): T                  $work
     * @return T
     * @throws MemberAddressBusy if a lock can't be got. $work hasn't run.
     * @throws LogicException    if a nested call asks for an address that isn't held.
     */
    public function run(array $addresses, callable $work): mixed
    {
        $names = $this->namesFor($addresses);

        if ($this->held !== []) {
            if (array_diff($names, $this->held) !== []) {
                throw new LogicException('A nested address lock asked for an address the outer one does not hold.');
            }
            return $work();
        }

        $this->acquire($names);
        try {
            return $work();
        } finally {
            $this->release();
        }
    }

    /**
     * True when every address is already locked by this request.
     *
     * @param list<array{0: int, 1: string}> $addresses
     */
    public function holds(array $addresses): bool
    {
        return array_diff($this->namesFor($addresses), $this->held) === [];
    }

    /** The lock's name. Capitals don't matter, as they don't to the member table. */
    public function nameFor(int $listingId, string $email): string
    {
        $address = mb_strtolower(trim($email), 'UTF-8');

        return 'lstr_m_' . substr(hash('sha256', $this->installId . '|' . $listingId . '|' . $address), 0, 40);
    }

    /**
     * @param list<array{0: int, 1: string}> $addresses
     * @return list<string> Deduped and sorted, so two requests can't wait on each other.
     */
    private function namesFor(array $addresses): array
    {
        $names = [];
        foreach ($addresses as [$listingId, $email]) {
            $names[$this->nameFor($listingId, $email)] = true;
        }
        $names = array_keys($names);
        sort($names);

        return $names;
    }

    /**
     * @param list<string> $names
     * @throws MemberAddressBusy
     */
    private function acquire(array $names): void
    {
        try {
            foreach ($names as $name) {
                if (!self::isYes($this->ask('SELECT GET_LOCK(?, ?)', [$name, self::TIMEOUT_SECONDS]))) {
                    throw new MemberAddressBusy('Timed out waiting for an address lock.');
                }
                $this->held[] = $name;
            }
            foreach ($names as $name) {
                if (!self::isYes($this->ask('SELECT IS_USED_LOCK(?) = CONNECTION_ID()', [$name]))) {
                    throw new MemberAddressBusy('An address lock was not held after acquiring it.');
                }
            }
        } catch (MemberAddressBusy $e) {
            $this->release();
            throw $e;
        } catch (\Throwable $e) {
            error_log('[Luster] Address lock failed: ' . $e->getMessage());
            $this->release();
            throw new MemberAddressBusy('Could not get an address lock.', 0, $e);
        }
    }

    private function release(): void
    {
        foreach (array_reverse($this->held) as $name) {
            try {
                $this->ask('SELECT RELEASE_LOCK(?)', [$name]);
            } catch (\Throwable $e) {
                // The server frees it when this connection ends.
                error_log('[Luster] Address lock release failed: ' . $e->getMessage());
            }
        }
        $this->held = [];
    }

    /** @param list<string|int> $params */
    private function ask(string $sql, array $params): mixed
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $answer = $stmt->fetchColumn();
        $stmt->closeCursor();

        return $answer;
    }

    /** Exactly 1. A timeout answers 0, an error or a free lock NULL. */
    private static function isYes(mixed $answer): bool
    {
        return $answer !== null && $answer !== false && (int) $answer === 1;
    }
}
