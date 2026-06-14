<?php

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Database;

/**
 * Data access for the lstr_pending_updates table.
 *
 * Stores member update requests that are awaiting admin approval.
 * Requires Migration 21 (public/migrate.php) to have been run.
 */
final class PendingUpdateRepo
{
    public function __construct(private readonly Database $db) {}

    // -----------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------

    /**
     * Returns all pending updates, newest first.
     *
     * @return array<int, object{id:int, fanlisting_id:int, member_email:string, changes:array, submitted_at:string}>
     */
    public function getAll(): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_pending_updates ORDER BY submitted_at DESC'
        );
        $stmt->execute();

        return array_map(
            static fn(array $r) => (object) [
                'id'            => (int)    $r['id'],
                'fanlisting_id' => (int)    $r['fanlisting_id'],
                'member_email'  => (string) $r['member_email'],
                'changes'       => (array)  (json_decode((string) $r['changes'], true) ?? []),
                'submitted_at'  => (string) $r['submitted_at'],
            ],
            $stmt->fetchAll(),
        );
    }

    /**
     * Returns all pending updates for a specific fanlisting, newest first.
     *
     * @return array<int, object>
     */
    public function getByListing(int $listingId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_pending_updates
             WHERE fanlisting_id = :fid
             ORDER BY submitted_at DESC'
        );
        $stmt->execute(['fid' => $listingId]);

        return array_map(
            static fn(array $r) => (object) [
                'id'            => (int)    $r['id'],
                'fanlisting_id' => (int)    $r['fanlisting_id'],
                'member_email'  => (string) $r['member_email'],
                'changes'       => (array)  (json_decode((string) $r['changes'], true) ?? []),
                'submitted_at'  => (string) $r['submitted_at'],
            ],
            $stmt->fetchAll(),
        );
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

        if ($row === false) {
            return null;
        }

        return (object) [
            'id'            => (int)    $row['id'],
            'fanlisting_id' => (int)    $row['fanlisting_id'],
            'member_email'  => (string) $row['member_email'],
            'changes'       => (array)  (json_decode((string) $row['changes'], true) ?? []),
            'submitted_at'  => (string) $row['submitted_at'],
        ];
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

        $updates = array_map(
            static fn(array $r) => (object) [
                'id'            => (int)    $r['id'],
                'fanlisting_id' => (int)    $r['fanlisting_id'],
                'member_email'  => (string) $r['member_email'],
                'changes'       => (array)  (json_decode((string) $r['changes'], true) ?? []),
                'submitted_at'  => (string) $r['submitted_at'],
            ],
            $stmt->fetchAll(),
        );

        return ['updates' => $updates, 'total' => $total, 'pages' => $pages];
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

        $updates = array_map(
            static fn(array $r) => (object) [
                'id'            => (int)    $r['id'],
                'fanlisting_id' => (int)    $r['fanlisting_id'],
                'member_email'  => (string) $r['member_email'],
                'changes'       => (array)  (json_decode((string) $r['changes'], true) ?? []),
                'submitted_at'  => (string) $r['submitted_at'],
            ],
            $stmt->fetchAll(),
        );

        return ['updates' => $updates, 'total' => $total, 'pages' => $pages];
    }

    // -----------------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------------

    /**
     * Inserts a new pending update and returns the auto-incremented ID.
     *
     * @param array<string, mixed> $changes  Final resolved field values (no password).
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
     * Deletes a pending update by primary key (used by both approve and reject).
     */
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
            $id          = (int) $r['id'];
            $result[$id] = (object) [
                'id'            => $id,
                'fanlisting_id' => (int)    $r['fanlisting_id'],
                'member_email'  => (string) $r['member_email'],
                'changes'       => (array)  (json_decode((string) $r['changes'], true) ?? []),
                'submitted_at'  => (string) $r['submitted_at'],
            ];
        }

        return $result;
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM lstr_pending_updates WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
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
}
