<?php

declare(strict_types=1);

namespace Luster\Support;

use Luster\Core\Config;
use Luster\Core\Database;
use Luster\Models\Listing;

/**
 * Batch member statistics fetcher.
 *
 * Replaces N individual getMemberStats() calls with ceil(N/100) chunked
 * UNION ALL queries, one aggregate per member table arm. Each arm runs its
 * own COUNT(*) and COUNT(DISTINCT country) independently, so the query is
 * correct and still index-efficient despite combining multiple tables.
 */
final class MemberStatsService
{
    private string $approvedWhere;
    private bool   $appendMembers;

    public function __construct(
        private readonly Database $db,
        Config $config,
    ) {
        $prefix = (string) $config->get('enth_table_prefix', '');
        if ($prefix === '') {
            $this->approvedWhere = 'pending = 0';
            $this->appendMembers = false;
        } else {
            $this->approvedWhere = 'approved = 1';
            $this->appendMembers = true;
        }
    }

    /**
     * Returns member stats keyed by listing ID for the given listing set.
     *
     * Each value is ['approved' => int, 'country_count' => int].
     * Listings with inaccessible member tables return zeros rather than throwing.
     *
     * @param  Listing[] $listings
     * @return array<int, array{approved: int, country_count: int}>
     */
    public function forListings(array $listings): array
    {
        $result = [];
        if (empty($listings)) {
            return $result;
        }

        $chunks = array_chunk($listings, 100);
        foreach ($chunks as $chunk) {
            $arms  = [];
            $idMap = [];  // validated table name => listing id

            foreach ($chunk as $fl) {
                $raw = $this->appendMembers ? $fl->dbprefix . 'members' : $fl->dbprefix;
                if ($raw === '' || $raw === 'members') {
                    $result[$fl->id] = ['approved' => 0, 'country_count' => 0];
                    continue;
                }
                try {
                    $table = $this->db->validatePrefix($raw);
                } catch (\Throwable) {
                    $result[$fl->id] = ['approved' => 0, 'country_count' => 0];
                    continue;
                }
                $quoted         = $this->db->pdo()->quote($table);
                $idMap[$table]  = $fl->id;
                $arms[]         = "SELECT {$quoted} AS tbl,
                                          COUNT(*) AS approved,
                                          COUNT(DISTINCT CASE WHEN country != '' AND country IS NOT NULL THEN country END) AS country_count
                                   FROM `{$table}` WHERE {$this->approvedWhere}";
            }

            if (empty($arms)) {
                continue;
            }

            try {
                $sql  = implode("\nUNION ALL\n", $arms);
                $rows = $this->db->pdo()->query($sql);
                if ($rows !== false) {
                    foreach ($rows->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                        $tbl = (string) $row['tbl'];
                        if (isset($idMap[$tbl])) {
                            $result[$idMap[$tbl]] = [
                                'approved'      => (int) $row['approved'],
                                'country_count' => (int) $row['country_count'],
                            ];
                        }
                    }
                }
            } catch (\Throwable) {
                // UNION ALL failed — at least one table in the chunk is inaccessible.
                // Retry each table individually so one missing table doesn't zero the rest.
                foreach ($idMap as $tableName => $listingId) {
                    if (isset($result[$listingId])) {
                        continue;  // already resolved by a previous successful chunk
                    }
                    try {
                        $singleSql = "SELECT COUNT(*) AS approved,
                                             COUNT(DISTINCT CASE WHEN country != '' AND country IS NOT NULL THEN country END) AS country_count
                                      FROM `{$tableName}` WHERE {$this->approvedWhere}";
                        $singleRow = $this->db->pdo()->query($singleSql);
                        if ($singleRow !== false) {
                            $r = $singleRow->fetch(\PDO::FETCH_ASSOC);
                            $result[$listingId] = [
                                'approved'      => (int) ($r['approved'] ?? 0),
                                'country_count' => (int) ($r['country_count'] ?? 0),
                            ];
                        }
                    } catch (\Throwable) {
                        $result[$listingId] = ['approved' => 0, 'country_count' => 0];
                    }
                }
            }

            // Fill any chunk listings that had errors
            foreach ($idMap as $listingId) {
                if (!isset($result[$listingId])) {
                    $result[$listingId] = ['approved' => 0, 'country_count' => 0];
                }
            }
        }

        // Guarantee every input listing has an entry
        foreach ($listings as $fl) {
            $result[$fl->id] ??= ['approved' => 0, 'country_count' => 0];
        }

        return $result;
    }
}
