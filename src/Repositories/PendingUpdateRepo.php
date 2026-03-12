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
    public function getByFanlisting(int $fanlistingId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_pending_updates
             WHERE fanlisting_id = :fid
             ORDER BY submitted_at DESC'
        );
        $stmt->execute(['fid' => $fanlistingId]);

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
     * Returns the total count of pending updates across all fanlistings.
     */
    public function countAll(): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM lstr_pending_updates');
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Returns the count of pending updates for a specific fanlisting.
     */
    public function countByFanlisting(int $fanlistingId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM lstr_pending_updates WHERE fanlisting_id = :fid'
        );
        $stmt->execute(['fid' => $fanlistingId]);

        return (int) $stmt->fetchColumn();
    }

    // -----------------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------------

    /**
     * Inserts a new pending update and returns the auto-incremented ID.
     *
     * @param array<string, mixed> $changes  Final resolved field values (no password).
     */
    public function insert(int $fanlistingId, string $email, array $changes): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO lstr_pending_updates (fanlisting_id, member_email, changes)
             VALUES (:fid, :email, :changes)'
        );
        $stmt->execute([
            'fid'     => $fanlistingId,
            'email'   => $email,
            'changes' => json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Deletes a pending update by primary key (used by both approve and reject).
     */
    public function delete(int $id): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM lstr_pending_updates WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * Deletes all pending updates for a given member email across all fanlistings.
     * Called when a member is deleted from the system.
     */
    public function deleteByEmail(string $email): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM lstr_pending_updates WHERE member_email = :email'
        );
        $stmt->execute(['email' => $email]);
    }
}
