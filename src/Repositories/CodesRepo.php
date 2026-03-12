<?php

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Database;

/**
 * Data access for the lstr_codes table.
 *
 * Each row represents one uploaded code image for a fanlisting.
 * Requires Migration 22 to run before use.
 */
final class CodesRepo
{
    public function __construct(private readonly Database $db) {}

    // -----------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------

    /**
     * Returns all codes for a fanlisting, ordered by sort_order then id.
     *
     * @return object[]
     */
    public function getByFanlisting(int $fanlistingId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, fanlisting_id, filename, width, height, sort_order, created_at
             FROM lstr_codes
             WHERE fanlisting_id = :fid
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['fid' => $fanlistingId]);

        return array_map(static fn(array $r) => (object) [
            'id'           => (int)    $r['id'],
            'fanlistingId' => (int)    $r['fanlisting_id'],
            'filename'     => (string) $r['filename'],
            'width'        => (int)    $r['width'],
            'height'       => (int)    $r['height'],
            'sortOrder'    => (int)    $r['sort_order'],
            'createdAt'    => (string) $r['created_at'],
        ], $stmt->fetchAll());
    }

    /**
     * Returns a single code by ID, or null if not found.
     */
    public function getById(int $id): ?object
    {
        $stmt = $this->db->prepare(
            'SELECT id, fanlisting_id, filename, width, height, sort_order, created_at
             FROM lstr_codes WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return (object) [
            'id'           => (int)    $row['id'],
            'fanlistingId' => (int)    $row['fanlisting_id'],
            'filename'     => (string) $row['filename'],
            'width'        => (int)    $row['width'],
            'height'       => (int)    $row['height'],
            'sortOrder'    => (int)    $row['sort_order'],
            'createdAt'    => (string) $row['created_at'],
        ];
    }

    /**
     * Returns the total number of codes for a fanlisting.
     */
    public function countByFanlisting(int $fanlistingId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM lstr_codes WHERE fanlisting_id = :fid'
        );
        $stmt->execute(['fid' => $fanlistingId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Returns the highest sort_order value for a fanlisting (0 if none exist).
     */
    public function getMaxSortOrder(int $fanlistingId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(sort_order), 0) FROM lstr_codes WHERE fanlisting_id = :fid'
        );
        $stmt->execute(['fid' => $fanlistingId]);
        return (int) $stmt->fetchColumn();
    }

    // -----------------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------------

    /**
     * Inserts a new code record and returns its new ID.
     */
    public function insert(
        int    $fanlistingId,
        string $filename,
        int    $width,
        int    $height,
        int    $sortOrder,
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO lstr_codes (fanlisting_id, filename, width, height, sort_order)
             VALUES (:fid, :filename, :width, :height, :sort)'
        );
        $stmt->execute([
            'fid'      => $fanlistingId,
            'filename' => $filename,
            'width'    => $width,
            'height'   => $height,
            'sort'     => $sortOrder,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Deletes a single code record by ID.
     */
    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM lstr_codes WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Deletes all codes for a fanlisting (used when deleting the fanlisting itself).
     */
    public function deleteByFanlisting(int $fanlistingId): void
    {
        $stmt = $this->db->prepare('DELETE FROM lstr_codes WHERE fanlisting_id = :fid');
        $stmt->execute(['fid' => $fanlistingId]);
    }
}
