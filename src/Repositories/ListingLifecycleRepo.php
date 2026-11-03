<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Data access for the listing lifecycle current-state table. A listing only
 has a row here while it is archived (Closed or Adopted Out); the row is
 removed entirely on reopen. The legacy_upcoming/legacy_trouble columns are
 a pre-archive snapshot of the same two booleans Listing::fromRow() already
 derives from either schema, so the snapshot/restore logic in
 ListingLifecycleService never needs to know which schema is in use.
*/

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Database;

/**
 * Manages lstr_listing_lifecycle rows (Closed / Adopted Out current state).
 */
final class ListingLifecycleRepo
{
    public function __construct(private readonly Database $db) {}

    /**
     * archived_at is null when the date is unknown (Migration 108); it's turned
     * into '' only for display (renderFields()), never before a save, so a re-save
     * can't quietly swap "unknown" for today.
     *
     * @return array{fanlisting_id:int, lifecycle_status:string, archived_at:?string,
     *     legacy_upcoming:bool, legacy_trouble:bool, adopted_to_name:?string,
     *     adopted_to_url:?string, archive_note:?string}|null
     */
    public function getByListingId(int $listingId): ?array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT fanlisting_id, lifecycle_status, archived_at, legacy_upcoming, legacy_trouble,
                        adopted_to_name, adopted_to_url, archive_note
                 FROM lstr_listing_lifecycle
                 WHERE fanlisting_id = :id'
            );
            $stmt->execute(['id' => $listingId]);
            $row = $stmt->fetch();
        } catch (\Throwable) {
            // Table not migrated yet (Migration 93) — no lifecycle data exists.
            return null;
        }

        if ($row === false) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * Batch lookup of lifecycle status for a set of listing IDs.
     *
     * @param int[] $listingIds
     * @return array<int,string> listing id => 'closed'|'adopted_out'
     */
    public function getMapForListings(array $listingIds): array
    {
        $listingIds = array_values(array_unique(array_map('intval', $listingIds)));
        if ($listingIds === []) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($listingIds), '?'));
            $stmt = $this->db->prepare(
                "SELECT fanlisting_id, lifecycle_status FROM lstr_listing_lifecycle
                 WHERE fanlisting_id IN ({$placeholders})"
            );
            $stmt->execute($listingIds);

            $map = [];
            foreach ($stmt->fetchAll() as $row) {
                $map[(int) $row['fanlisting_id']] = (string) $row['lifecycle_status'];
            }

            return $map;
        } catch (\Throwable) {
            // Table not migrated yet (Migration 93) — no lifecycle data exists.
            return [];
        }
    }

    /**
     * Creates or replaces the lifecycle row for $listingId.
     *
     * $archivedAt, the admin-editable "Closed On"/"Adopted On" date, has three
     * meanings:
     *  - omitted or '': keep whatever is stored, including an unknown date (so
     *    re-saving an archived listing without touching the date never resets it
     *    to today), or today when the listing has no lifecycle row yet;
     *  - null: the date is unknown, stored as NULL (needs Migration 108; see
     *    supportsUnknownDate(), which every caller that passes null checks first);
     *  - a Y-m-d date: stored as given.
     *
     * Deliberately has no missing-table guard, unlike every read method on
     * this class: this table is the source of truth for whether a listing
     * is archived at all, and callers (ListingController) unconditionally
     * flip the listing's registry status right after calling this. A
     * silently-swallowed failure here would leave a listing flagged
     * inactive with no lifecycle row and no archive metadata — a broken,
     * confusing state that's worse than the save just visibly failing.
     */
    public function upsert(
        int     $listingId,
        string  $lifecycleStatus,
        bool    $legacyUpcoming,
        bool    $legacyTrouble,
        ?string $adoptedToName = null,
        ?string $adoptedToUrl = null,
        ?string $archiveNote = null,
        ?string $archivedAt = '',
    ): void {
        if ($archivedAt === '') {
            $existing   = $this->getByListingId($listingId);
            $archivedAt = $existing !== null ? $existing['archived_at'] : date('Y-m-d');
        }

        $stmt = $this->db->prepare(
            'REPLACE INTO lstr_listing_lifecycle
                (fanlisting_id, lifecycle_status, archived_at, legacy_upcoming, legacy_trouble,
                 adopted_to_name, adopted_to_url, archive_note)
             VALUES (:id, :status, :archived_at, :up, :trouble, :name, :url, :note)'
        );
        $stmt->execute([
            'id'          => $listingId,
            'status'      => $lifecycleStatus,
            'archived_at' => $archivedAt,
            'up'          => (int) $legacyUpcoming,
            'trouble'     => (int) $legacyTrouble,
            'name'        => $adoptedToName,
            'url'         => $adoptedToUrl,
            'note'        => $archiveNote,
        ]);
    }

    /**
     * Removes the lifecycle row for $listingId. No-op when none exists —
     * including when the table itself doesn't exist yet (pre-Migration-93),
     * since reopening a listing on such an install has nothing to clean up.
     */
    public function delete(int $listingId): void
    {
        try {
            $this->db->prepare('DELETE FROM lstr_listing_lifecycle WHERE fanlisting_id = :id')
                ->execute(['id' => $listingId]);
        } catch (\Throwable) {
        }
    }

    /**
     * Whether a lifecycle row can hold an unknown date (archived_at NULL), which
     * Migration 108 allows. False when that can't be confirmed, so nothing tries
     * to store NULL and fails halfway through a save.
     */
    public function supportsUnknownDate(): bool
    {
        try {
            $pdo = $this->db->pdo();
            if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $st = $pdo->query('PRAGMA table_info(`lstr_listing_lifecycle`)');
                foreach ($st !== false ? $st->fetchAll(\PDO::FETCH_ASSOC) : [] as $column) {
                    if (($column['name'] ?? '') === 'archived_at') {
                        return (int) $column['notnull'] === 0;
                    }
                }
                return false;
            }

            $st = $pdo->query(
                "SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lstr_listing_lifecycle' AND COLUMN_NAME = 'archived_at'"
            );
            return $st !== false && $st->fetchColumn() === 'YES';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * A lifecycle row's archive details as Listing templates use them
     * (ListingRowRenderer): blank strings for anything missing, including an
     * unknown date. The live Owned/Past embed and the admin template preview
     * both use this, so they always render alike.
     *
     * @param array<string,mixed> $row From getByListingId()
     * @return array{adopted_to_name: string, adopted_to_url: string, archive_note: string, archived_at: string}
     */
    public static function renderFields(array $row): array
    {
        return [
            'adopted_to_name' => (string) ($row['adopted_to_name'] ?? ''),
            'adopted_to_url'  => (string) ($row['adopted_to_url']  ?? ''),
            'archive_note'    => (string) ($row['archive_note']    ?? ''),
            'archived_at'     => (string) ($row['archived_at']     ?? ''),
        ];
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): array
    {
        return [
            'fanlisting_id'    => (int) $row['fanlisting_id'],
            'lifecycle_status' => (string) $row['lifecycle_status'],
            'archived_at'      => $row['archived_at'] !== null ? (string) $row['archived_at'] : null,
            'legacy_upcoming'  => (bool) $row['legacy_upcoming'],
            'legacy_trouble'   => (bool) $row['legacy_trouble'],
            'adopted_to_name'  => $row['adopted_to_name'] !== null ? (string) $row['adopted_to_name'] : null,
            'adopted_to_url'   => $row['adopted_to_url']  !== null ? (string) $row['adopted_to_url']  : null,
            'archive_note'     => $row['archive_note']    !== null ? (string) $row['archive_note']    : null,
        ];
    }
}
