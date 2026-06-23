<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Manages the per-listing code size definitions in lstr_code_sizes. Each row
 represents a named size group like "50x50" or "88x31" that codes can be
 assigned to. Sizes are scoped to individual listings and support custom
 sort ordering for display purposes.
*/

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Database;

/**
 * Data access for the lstr_code_sizes table.
 *
 * Each row defines a named size group (e.g. "50×50", "88×31") for a listing's
 * codes. Codes are linked to sizes via lstr_codes.size_id (nullable FK).
 * Requires Migration 30 to run before use.
 */
final class CodeSizesRepo
{
    public function __construct(private readonly Database $db) {}

    // -----------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------

    /**
     * Returns all sizes for a listing, ordered by sort_order then id.
     *
     * @return object[]
     */
    public function getByListing(int $listingId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, fanlisting_id, label, width, height, sort_order
             FROM lstr_code_sizes
             WHERE fanlisting_id = :fid
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['fid' => $listingId]);

        return array_map(static fn(array $r) => (object) [
            'id'        => (int)    $r['id'],
            'listingId' => (int)    $r['fanlisting_id'],
            'label'     => (string) $r['label'],
            'width'     => $r['width']  !== null ? (int) $r['width']  : null,
            'height'    => $r['height'] !== null ? (int) $r['height'] : null,
            'sortOrder' => (int)    $r['sort_order'],
        ], $stmt->fetchAll());
    }

    /**
     * Returns a single size by ID, or null if not found.
     */
    public function getById(int $id): ?object
    {
        $stmt = $this->db->prepare(
            'SELECT id, fanlisting_id, label, width, height, sort_order
             FROM lstr_code_sizes WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return (object) [
            'id'        => (int)    $row['id'],
            'listingId' => (int)    $row['fanlisting_id'],
            'label'     => (string) $row['label'],
            'width'     => $row['width']  !== null ? (int) $row['width']  : null,
            'height'    => $row['height'] !== null ? (int) $row['height'] : null,
            'sortOrder' => (int)    $row['sort_order'],
        ];
    }

    /**
     * Returns the highest sort_order value for a listing (0 if none).
     */
    public function getMaxSortOrder(int $listingId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(sort_order), 0) FROM lstr_code_sizes WHERE fanlisting_id = :fid'
        );
        $stmt->execute(['fid' => $listingId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Returns how many codes currently reference a given size.
     */
    public function countCodesForSize(int $sizeId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM lstr_codes WHERE size_id = :sid'
        );
        $stmt->execute(['sid' => $sizeId]);
        return (int) $stmt->fetchColumn();
    }

    // -----------------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------------

    /**
     * Inserts a new size and returns its new ID.
     */
    public function insert(
        int     $listingId,
        string  $label,
        ?int    $width,
        ?int    $height,
        int     $sortOrder,
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO lstr_code_sizes (fanlisting_id, label, width, height, sort_order)
             VALUES (:fid, :label, :width, :height, :sort)'
        );
        $stmt->execute([
            'fid'    => $listingId,
            'label'  => $label,
            'width'  => $width,
            'height' => $height,
            'sort'   => $sortOrder,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Updates label and dimensions for a size. Sort order is preserved.
     */
    public function update(int $id, string $label, ?int $width, ?int $height): void
    {
        $stmt = $this->db->prepare(
            'UPDATE lstr_code_sizes
             SET label = :label, width = :width, height = :height
             WHERE id = :id'
        );
        $stmt->execute([
            'label'  => $label,
            'width'  => $width,
            'height' => $height,
            'id'     => $id,
        ]);
    }

    /**
     * Deletes a size. Any codes linked to it will have size_id set to NULL
     * via the ON DELETE SET NULL foreign key.
     */
    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM lstr_code_sizes WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
