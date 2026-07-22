<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Manages previous-owner credits in lstr_listing_previous_owners. A previous
 owner is someone who owned a listing before its current owner — most useful
 for Adopted Out listings, but available on any listing. Rows are kept in
 manual display order via sort_order. Requires Migration 102 to run before use.
*/

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Database;

final class PreviousOwnersRepo
{
    public function __construct(private readonly Database $db) {}

    /**
     * Returns all previous owners for a listing, in display order.
     *
     * Every method on this repo swallows a missing-table error and no-ops
     * instead of throwing: an install that has pulled the new application
     * code but not yet run public/migrate/ (Migration 102) must not crash
     * the public Stats embed, the admin edit page, or listing deletion —
     * the feature just behaves as if no previous owners are set until the
     * migration runs. Matches the existing "silent if not installed" try/
     * catch pattern already used for addon tables in
     * ListingController::purgeListingData().
     *
     * @return array<int, array{owner_name: string, owner_url: string}>
     */
    public function getForListing(int $fanlistingId): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT owner_name, owner_url
                 FROM lstr_listing_previous_owners
                 WHERE fanlisting_id = :fid
                 ORDER BY sort_order ASC, id ASC'
            );
            $stmt->execute(['fid' => $fanlistingId]);

            return array_map(static fn(array $r): array => [
                'owner_name' => (string) $r['owner_name'],
                'owner_url'  => (string) $r['owner_url'],
            ], $stmt->fetchAll());
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Replaces all previous-owner rows for a listing atomically.
     *
     * Rows where both owner_name and owner_url are blank (after trimming) are
     * dropped. sort_order is assigned from array position, so the order $rows
     * arrives in (i.e. the order the admin arranged them in the form) is what
     * gets preserved on the next read.
     *
     * @param array<int, array{owner_name: string, owner_url: string}> $rows
     */
    public function replaceForListing(int $fanlistingId, array $rows): void
    {
        $clean = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row['owner_name'] ?? ''));
            $url  = trim((string) ($row['owner_url']  ?? ''));
            if ($name === '' && $url === '') {
                continue;
            }
            $clean[] = ['owner_name' => $name, 'owner_url' => $url];
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'DELETE FROM lstr_listing_previous_owners WHERE fanlisting_id = :fid'
            )->execute(['fid' => $fanlistingId]);

            if ($clean !== []) {
                $stmt = $pdo->prepare(
                    'INSERT INTO lstr_listing_previous_owners (fanlisting_id, owner_name, owner_url, sort_order)
                     VALUES (:fid, :name, :url, :sort)'
                );
                foreach ($clean as $i => $row) {
                    $stmt->execute([
                        'fid'  => $fanlistingId,
                        'name' => $row['owner_name'],
                        'url'  => $row['owner_url'],
                        'sort' => $i,
                    ]);
                }
            }

            $pdo->commit();
        } catch (\Throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Table not migrated yet — no-op rather than crash the save.
        }
    }

    /**
     * Deletes all previous-owner rows for a listing. Called during listing
     * deletion cleanup (ListingController::purgeListingData()).
     */
    public function deleteForListing(int $fanlistingId): void
    {
        try {
            $stmt = $this->db->prepare(
                'DELETE FROM lstr_listing_previous_owners WHERE fanlisting_id = :fid'
            );
            $stmt->execute(['fid' => $fanlistingId]);
        } catch (\Throwable) {
            // Table not migrated yet — nothing to clean up.
        }
    }
}
