<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Stores and retrieves per-listing template revision history. Each save of a
 per-listing template records the previous value here (up to MAX_REVISIONS
 entries per template key per listing), enabling rollback to any recent version.
*/

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Database;
use PDO;

final class TemplateRevisionRepo
{
    /** Maximum revisions kept per (fanlisting_id, template_key) pair. */
    public const MAX_REVISIONS = 5;

    /**
     * Sentinel fanlisting_id used for collective templates.
     * Collective templates have no listing row, so 0 is used as the scope key.
     * The lstr_template_revisions table has no FK on fanlisting_id, so 0 is safe.
     */
    public const COLLECTIVE_ID = 0;

    /**
     * Sentinel fanlisting_id used for the site-wide "Global Templates"
     * defaults (Admin -> Owned Listings -> Global Templates). Like collective
     * templates, these apply everywhere rather than to one listing row, so
     * they need their own scope key distinct from COLLECTIVE_ID. fanlisting_id
     * is INT UNSIGNED, so this uses the column's max value — a real listing's
     * autoincrement id will never reach it.
     */
    public const GLOBAL_DEFAULTS_ID = 4294967295;

    public function __construct(private readonly Database $db) {}

    /**
     * Records the OLD value of a template before it is overwritten. Prunes
     * history to MAX_REVISIONS entries after inserting.
     *
     * Silently no-ops if the `lstr_template_revisions` table does not exist
     * (pre-Migration-95 install) so callers do not need a try/catch.
     */
    public function record(int $listingId, string $key, ?string $oldValue, int $adminId): void
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO `lstr_template_revisions`
                 (fanlisting_id, template_key, value, saved_by_admin_id)
                 VALUES (:lid, :key, :val, :admin)'
            );
            $stmt->execute([
                'lid'   => $listingId,
                'key'   => $key,
                'val'   => $oldValue,
                'admin' => $adminId,
            ]);

            $this->prune($listingId, $key);
        } catch (\PDOException) {
            // Table absent (pre-migration) — ignore silently.
        }
    }

    /**
     * Records the old values for multiple template keys at once. Only records
     * keys whose old value differs from the incoming new value (normalized).
     *
     * @param array<string, ?string> $oldValues  ['template_key' => oldValue, ...]
     * @param array<string, ?string> $newValues  ['template_key' => newValue, ...]
     */
    public function recordChanged(int $listingId, array $oldValues, array $newValues, int $adminId): void
    {
        $normalize = static fn(?string $v): string => $v === null ? '' : trim(str_replace("\r\n", "\n", $v));

        foreach ($oldValues as $key => $oldValue) {
            $newValue = $newValues[$key] ?? null;
            if ($normalize($oldValue) !== $normalize($newValue)) {
                $this->record($listingId, $key, $oldValue, $adminId);
            }
        }
    }

    /**
     * Returns the last MAX_REVISIONS revisions for the given listing/key,
     * newest first. Each entry has: id, value, saved_at, saved_by_admin_id.
     *
     * Returns an empty array if the table does not exist.
     *
     * @return list<array{id: int, value: string|null, saved_at: string, saved_by_admin_id: int|null}>
     */
    public function getHistory(int $listingId, string $key): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT id, value, saved_at, saved_by_admin_id
                 FROM `lstr_template_revisions`
                 WHERE fanlisting_id = :lid AND template_key = :key
                 ORDER BY saved_at DESC, id DESC
                 LIMIT ' . self::MAX_REVISIONS
            );
            $stmt->execute(['lid' => $listingId, 'key' => $key]);
            $rows = $stmt->fetchAll();

            return array_map(static fn(array $r): array => [
                'id'                  => (int) $r['id'],
                'value'               => $r['value'],
                'saved_at'            => (string) $r['saved_at'],
                'saved_by_admin_id'   => $r['saved_by_admin_id'] !== null ? (int) $r['saved_by_admin_id'] : null,
            ], $rows ?: []);
        } catch (\PDOException) {
            return [];
        }
    }

    /**
     * Returns a single revision by ID, or null if not found.
     *
     * @return array{id: int, fanlisting_id: int, template_key: string, value: string|null, saved_at: string}|null
     */
    public function getById(int $id): ?array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT id, fanlisting_id, template_key, value, saved_at
                 FROM `lstr_template_revisions` WHERE id = :id'
            );
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();

            if ($row === false) {
                return null;
            }

            return [
                'id'            => (int) $row['id'],
                'fanlisting_id' => (int) $row['fanlisting_id'],
                'template_key'  => (string) $row['template_key'],
                'value'         => $row['value'],
                'saved_at'      => (string) $row['saved_at'],
            ];
        } catch (\PDOException) {
            return null;
        }
    }

    /**
     * Deletes all revisions for a specific template key within a listing.
     */
    public function deleteByKey(int $listingId, string $key): void
    {
        try {
            $stmt = $this->db->prepare(
                'DELETE FROM `lstr_template_revisions` WHERE fanlisting_id = :lid AND template_key = :key'
            );
            $stmt->execute(['lid' => $listingId, 'key' => $key]);
        } catch (\PDOException) {
            // Ignore if table absent.
        }
    }

    /**
     * Deletes all revisions for a listing (used when the listing is deleted).
     */
    public function deleteForListing(int $listingId): void
    {
        try {
            $stmt = $this->db->prepare(
                'DELETE FROM `lstr_template_revisions` WHERE fanlisting_id = :lid'
            );
            $stmt->execute(['lid' => $listingId]);
        } catch (\PDOException) {
            // Ignore if table absent.
        }
    }

    /** Prunes old revisions keeping the newest MAX_REVISIONS. */
    private function prune(int $listingId, string $key): void
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM `lstr_template_revisions`
             WHERE fanlisting_id = :lid AND template_key = :key
             ORDER BY saved_at DESC, id DESC
             LIMIT 9999 OFFSET ' . self::MAX_REVISIONS
        );
        $stmt->execute(['lid' => $listingId, 'key' => $key]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $del = $this->db->prepare("DELETE FROM `lstr_template_revisions` WHERE id IN ({$placeholders})");
            $del->execute($ids);
        }
    }
}
