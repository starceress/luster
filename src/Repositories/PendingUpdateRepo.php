<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Manages pending member update requests in lstr_pending_updates. When a
 listing has update queuing enabled, fan-submitted profile changes are
 stored here as JSON instead of being applied immediately. The admin can
 then review, approve, or reject each pending update from the admin panel.
 Password changes are never queued -- they are applied right away.
*/

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Database;

/**
 * Data access for the lstr_pending_updates table.
 *
 * Stores member update requests that are awaiting admin approval.
 * Requires Migration 21 (public/migrate.php) to have been run.
 *
 * review_state (Migration 110) says whether a request has been stopped for the owner
 * to look at. A hold proves nothing about who's who: it stops an approval and shows a
 * reason, and rejecting the request is the only way to clear it.
 */
final class PendingUpdateRepo
{
    /** Not a hold. Queued before removals took requests with them, so the date check is stricter. */
    public const QUEUED_BEFORE_CHECKS = 'queued_before_checks';

    public const WRITE_UNCONFIRMED    = 'write_unconfirmed';
    public const ADDRESS_MOVED        = 'address_moved';
    public const MAY_BE_OTHER_MEMBER  = 'may_be_other_member';
    public const JOINED_AFTER_REQUEST = 'joined_after_request';

    /** The review_state values that stop an approval. */
    public const HOLDS = [
        self::WRITE_UNCONFIRMED,
        self::ADDRESS_MOVED,
        self::MAY_BE_OTHER_MEMBER,
        self::JOINED_AFTER_REQUEST,
    ];

    /** The only core member columns a queued request can change. */
    public const CORE_KEYS = ['name', 'email', 'url', 'country', 'showurl'];

    /** Remembered once it's true. A failed read isn't remembered, and never counts as "no". */
    private bool $reviewStateExists = false;

    public function __construct(private readonly Database $db) {}

    /**
     * One row as the rest of the code sees it.
     *
     * Bad JSON isn't turned into "no changes": malformed says so, and the row is
     * kept for the owner to reject. A table with no review_state yet (Migration 110
     * hasn't run) gives every row the stricter date check.
     *
     * @param array<string, mixed> $r
     */
    private static function hydrate(array $r): object
    {
        $changes = json_decode((string) $r['changes'], true);

        $reviewState = self::QUEUED_BEFORE_CHECKS;
        if (array_key_exists('review_state', $r)) {
            $reviewState = $r['review_state'] !== null ? (string) $r['review_state'] : null;
        }

        return (object) [
            'id'            => (int)    $r['id'],
            'fanlisting_id' => (int)    $r['fanlisting_id'],
            'member_email'  => (string) $r['member_email'],
            'changes'       => is_array($changes) ? self::tidied($changes) : [],
            'malformed'     => !is_array($changes),
            'submitted_at'  => (string) $r['submitted_at'],
            'review_state'  => $reviewState,
        ];
    }

    /**
     * The core values with their keys in lower case and no spaces round them, tidied here
     * once so the address that's locked, the text that's checked, the screen and the saved
     * value can't disagree. The forms trim these already, so only an older or imported
     * row has any to tidy. A field answer has no rules to pass, so it stays as written.
     *
     * @param array<int|string, mixed> $changes
     * @return array<int|string, mixed>
     */
    private static function tidied(array $changes): array
    {
        foreach ($changes as $key => $value) {
            $core = strtolower((string) $key);
            if (in_array($core, self::CORE_KEYS, true)) {
                unset($changes[$key]);
                $changes[$core] = is_string($value) ? trim($value) : $value;
            }
        }

        return $changes;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<object>
     */
    private static function hydrateAll(array $rows): array
    {
        return array_map(self::hydrate(...), $rows);
    }

    /** True when this request has been stopped for the owner to look at. */
    public static function isHeld(object $row): bool
    {
        return in_array($row->review_state ?? null, self::HOLDS, true);
    }

    // -----------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------

    /**
     * Returns all pending updates, newest first.
     *
     * @return list<object>
     */
    public function getAll(): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_pending_updates ORDER BY submitted_at DESC'
        );
        $stmt->execute();

        return self::hydrateAll($stmt->fetchAll());
    }

    /**
     * Returns all pending updates for a specific fanlisting, newest first.
     *
     * @return list<object>
     */
    public function getByListing(int $listingId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_pending_updates
             WHERE fanlisting_id = :fid
             ORDER BY submitted_at DESC'
        );
        $stmt->execute(['fid' => $listingId]);

        return self::hydrateAll($stmt->fetchAll());
    }

    /**
     * Returns a single pending update by its primary key, or null if not found.
     */
    public function getById(int $id): ?object
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_pending_updates WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row !== false ? self::hydrate($row) : null;
    }

    /**
     * getById() with the row locked, for use inside a transaction. A plain read in a
     * transaction can still find a row another approval has just used up, because it
     * reads the transaction's older snapshot. A locking read waits, then sees the truth.
     */
    public function lockById(int $id): ?object
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_pending_updates WHERE id = :id' . $this->db->forUpdate()
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row !== false ? self::hydrate($row) : null;
    }

    /**
     * Every request for this address in this listing, locked, for use inside a transaction.
     *
     * @return list<object>
     */
    public function lockAtAddress(int $listingId, string $email): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_pending_updates
             WHERE fanlisting_id = :fid AND member_email = :email' . $this->db->forUpdate()
        );
        $stmt->execute(['fid' => $listingId, 'email' => $email]);

        return self::hydrateAll($stmt->fetchAll());
    }

    /**
     * Every request for this address in this listing.
     *
     * @return list<object>
     */
    public function getAtAddress(int $listingId, string $email): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_pending_updates WHERE fanlisting_id = :fid AND member_email = :email'
        );
        $stmt->execute(['fid' => $listingId, 'email' => $email]);

        return self::hydrateAll($stmt->fetchAll());
    }

    /**
     * Request ids in the order a batch should apply them, oldest first, so the newest
     * valid request wins. For one listing, for chosen ids, or for everything.
     *
     * @param int[]|null $ids
     * @return list<int>
     */
    public function idsOldestFirst(?int $listingId = null, ?array $ids = null): array
    {
        $where  = '';
        $params = [];

        if ($ids !== null) {
            if ($ids === []) {
                return [];
            }
            $where  = ' WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params = array_values(array_map('intval', $ids));
        } elseif ($listingId !== null) {
            $where  = ' WHERE fanlisting_id = ?';
            $params = [$listingId];
        }

        $stmt = $this->db->prepare('SELECT id FROM lstr_pending_updates' . $where . ' ORDER BY submitted_at ASC, id ASC');
        $stmt->execute($params);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Returns the total count of pending updates across all listings.
     */
    public function countAll(): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM lstr_pending_updates');
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Returns the count of pending updates for a specific listing.
     */
    public function countByListing(int $listingId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM lstr_pending_updates WHERE fanlisting_id = :fid'
        );
        $stmt->execute(['fid' => $listingId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Returns a paginated slice of all pending updates, newest first.
     *
     * @return array{updates: array, total: int, pages: int}
     */
    public function paginateAll(int $page, int $perPage, string $orderBy = 'submitted_at DESC'): array
    {
        $total  = $this->countAll();
        $pages  = max(1, (int) ceil($total / $perPage));
        $page   = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        $allowedOrderBy = ['submitted_at DESC', 'submitted_at ASC', 'fanlisting_id ASC', 'fanlisting_id DESC'];
        if (!in_array($orderBy, $allowedOrderBy, true)) {
            $orderBy = 'submitted_at DESC';
        }

        $stmt = $this->db->prepare(
            "SELECT * FROM lstr_pending_updates ORDER BY {$orderBy} LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue(':limit',  $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  \PDO::PARAM_INT);
        $stmt->execute();

        return ['updates' => self::hydrateAll($stmt->fetchAll()), 'total' => $total, 'pages' => $pages];
    }

    /**
     * Returns a paginated slice of pending updates for a specific listing, newest first.
     *
     * @return array{updates: array, total: int, pages: int}
     */
    public function paginateByListing(int $listingId, int $page, int $perPage): array
    {
        $total  = $this->countByListing($listingId);
        $pages  = max(1, (int) ceil($total / $perPage));
        $page   = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_pending_updates
             WHERE fanlisting_id = :fid
             ORDER BY submitted_at DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':fid',    $listingId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit',  $perPage,   \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,    \PDO::PARAM_INT);
        $stmt->execute();

        return ['updates' => self::hydrateAll($stmt->fetchAll()), 'total' => $total, 'pages' => $pages];
    }

    // -----------------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------------

    /**
     * Inserts a new pending update and returns the auto-incremented ID.
     *
     * @param array<string, mixed> $changes  Final resolved field values. Password
     *                                         changes are applied immediately and
     *                                         never included here.
     */
    public function insert(int $listingId, string $email, array $changes): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO lstr_pending_updates (fanlisting_id, member_email, changes)
             VALUES (:fid, :email, :changes)'
        );
        $stmt->execute([
            'fid'     => $listingId,
            'email'   => $email,
            'changes' => json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Returns multiple pending updates by their IDs.
     *
     * @param  int[] $ids
     * @return array<int, object>  keyed by id
     */
    public function getByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt         = $this->db->prepare(
            "SELECT * FROM lstr_pending_updates WHERE id IN ({$placeholders})"
        );
        $stmt->execute(array_values($ids));

        $result = [];
        foreach ($stmt->fetchAll() as $r) {
            $result[(int) $r['id']] = self::hydrate($r);
        }

        return $result;
    }

    /**
     * Deletes a pending update by primary key.
     *
     * @return bool True when this call removed the row. False means something else
     *              got there first, so a rejection can say so instead of claiming it.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM lstr_pending_updates WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Deletes all pending updates for a given member email within one listing.
     * Called when that member permanently deletes their membership.
     */
    public function deleteByListingAndEmail(int $listingId, string $email): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM lstr_pending_updates WHERE fanlisting_id = :fid AND member_email = :email'
        );
        $stmt->execute(['fid' => $listingId, 'email' => $email]);
    }

    /**
     * Clears whatever's left at an address that's about to get a new member. It was
     * free, so a request there can't be theirs. A held request stays, because the
     * owner has been told to look at it. Call this while holding the address lock.
     */
    public function deleteStale(int $listingId, string $email): void
    {
        if (!$this->hasReviewState()) {
            // No column yet, so nothing can be held.
            $this->deleteByListingAndEmail($listingId, $email);
            return;
        }

        $placeholders = implode(',', array_fill(0, count(self::HOLDS), '?'));
        $stmt = $this->db->prepare(
            "DELETE FROM lstr_pending_updates
             WHERE fanlisting_id = ? AND member_email = ?
               AND (review_state IS NULL OR review_state NOT IN ({$placeholders}))"
        );
        $stmt->execute([$listingId, $email, ...self::HOLDS]);
    }

    /**
     * Re-keys any outstanding pending updates to a member's new email address.
     * Called when an admin changes a member's email from the Edit Member page,
     * so queued changes stay attached to the right person instead of becoming
     * orphaned under the old address.
     */
    public function renameMemberEmail(int $listingId, string $oldEmail, string $newEmail): void
    {
        $stmt = $this->db->prepare(
            'UPDATE lstr_pending_updates SET member_email = :new WHERE fanlisting_id = :fid AND member_email = :old'
        );
        $stmt->execute(['new' => $newEmail, 'fid' => $listingId, 'old' => $oldEmail]);
    }

    /**
     * Stops one request for the owner to look at. A request that's already held keeps
     * its first reason.
     *
     * @return int How many requests this marked, which is what the admin gets told.
     *             If the write fails or there's no column yet, it's logged and counts as none.
     */
    public function holdRow(int $id, string $state): int
    {
        return $this->mark('id = ?', [$id], $state);
    }

    /**
     * Stops every request at an address. See holdRow().
     */
    public function holdAddress(int $listingId, string $email, string $state): int
    {
        return $this->mark('fanlisting_id = ? AND member_email = ?', [$listingId, $email], $state);
    }

    /** @param list<int|string> $whereParams */
    private function mark(string $where, array $whereParams, string $state): int
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE lstr_pending_updates SET review_state = ?
                 WHERE {$where} AND (review_state IS NULL OR review_state = ?)"
            );
            $stmt->execute([$state, ...$whereParams, self::QUEUED_BEFORE_CHECKS]);

            return $stmt->rowCount();
        } catch (\Throwable $e) {
            error_log('[Luster] Could not hold a queued update: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Whether Migration 110 has added review_state yet.
     *
     * @throws \RuntimeException if the columns can't be read. That's never taken as "no".
     */
    private function hasReviewState(): bool
    {
        if (!$this->reviewStateExists) {
            $columns = array_column($this->db->tableColumns('lstr_pending_updates'), 'name');
            $this->reviewStateExists = in_array('review_state', $columns, true);
        }

        return $this->reviewStateExists;
    }
}
