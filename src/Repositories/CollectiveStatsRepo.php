<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Computes collective-wide (site-level) statistics by aggregating data
 across all owned listings, joined listings, and their member tables.
 Provides owned/joined status counts, total approved/pending member
 tallies, distinct country counts, newest and random listing picks,
 growth rate calculations, category breakdowns, and network-level
 counts (TFL/TAFL). Handles both Enthusiast schema variants.
*/

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Config;
use Luster\Core\Database;
use Luster\Models\Listing;
use Luster\Models\JoinedListing;

/**
 * Aggregated statistics for the whole collective (site-wide).
 *
 * Covers owned listings, joined listings, member totals across all
 * listing member tables, affiliates, growth rates, newest/random listings,
 * and category breakdown. Handles both Enthusiast schema variants transparently.
 *
 * Schema variants (driven by 'enth_table_prefix' config key):
 *   ''      → 'owned' table; status int (2=active,1=upcoming,0=inactive);
 *             member tables addressed by dbtable directly
 *   'enth_' → 'enth_fanlistings' table; upcoming/trouble bool cols;
 *             member tables = dbprefix + 'members'
 */
final class CollectiveStatsRepo
{
    private string $ownedTable;
    private string $ownedPk;
    private string $ownedPrefixCol;  // 'dbtable' or 'dbprefix'
    private bool   $isOwnedSchema;
    private bool   $appendMembers;

    /** SQL expression yielding 1 for approved members, else 0. */
    private string $approvedExpr;
    /** SQL expression yielding 1 for pending members, else 0. */
    private string $pendingExpr;

    /** WHERE predicate for approved joined listings (active, not pending/upcoming). */
    private const JOINED_APPROVED_WHERE = "active = 1 AND status NOT IN ('upcoming', 'pending')";

    public function __construct(
        private readonly Database $db,
        private readonly Config   $config,
    ) {
        $prefix = (string) $this->config->get('enth_table_prefix', '');

        $this->isOwnedSchema = ($prefix === '');

        $rawTable            = $this->isOwnedSchema ? 'owned' : $prefix . 'listings';
        $this->ownedTable    = $this->db->validatePrefix($rawTable);
        $this->ownedPk       = $this->isOwnedSchema ? 'listingid' : 'id';
        $this->ownedPrefixCol = $this->isOwnedSchema ? 'dbtable' : 'dbprefix';
        $this->appendMembers  = !$this->isOwnedSchema;

        if ($this->isOwnedSchema) {
            $this->approvedExpr = 'pending = 0';
            $this->pendingExpr  = 'pending = 1';
        } else {
            $this->approvedExpr = 'approved = 1';
            $this->pendingExpr  = 'approved = 0';
        }
    }

    // -----------------------------------------------------------------------
    // Owned listing counts
    // -----------------------------------------------------------------------

    /**
     * Returns status counts for all owned listings.
     *
     * @return array{total: int, active: int, upcoming: int, trouble: int}
     */
    public function getOwnedCounts(): array
    {
        if ($this->isOwnedSchema) {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*)        AS total,
                        SUM(status = 2) AS active,
                        SUM(status = 1) AS upcoming,
                        SUM(status = 0) AS trouble
                 FROM {$this->ownedTable}"
            );
        } else {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*)                         AS total,
                        SUM(upcoming = 0 AND trouble = 0) AS active,
                        SUM(upcoming = 1)                AS upcoming,
                        SUM(trouble  = 1)                AS trouble
                 FROM {$this->ownedTable}"
            );
        }

        $stmt->execute();
        $row = $stmt->fetch();

        return [
            'total'    => (int) ($row['total']    ?? 0),
            'active'   => (int) ($row['active']   ?? 0),
            'upcoming' => (int) ($row['upcoming'] ?? 0),
            'trouble'  => (int) ($row['trouble']  ?? 0),
        ];
    }

    /**
     * Returns counts of active owned listings by network approval.
     *
     * @return array{tfl: int, tafl: int}
     */
    public function getOwnedNetworkCounts(): array
    {
        try {
            if ($this->isOwnedSchema) {
                $activeCond = 'o.status = 2';
            } else {
                $activeCond = 'o.upcoming = 0 AND o.trouble = 0';
            }
            $stmt = $this->db->prepare(
                "SELECT SUM(s.approved_by = 'tfl')  AS tfl,
                        SUM(s.approved_by = 'tafl') AS tafl
                 FROM {$this->ownedTable} o
                 INNER JOIN lstr_fanlisting_settings s
                    ON s.fanlisting_id = o.{$this->ownedPk}
                 WHERE {$activeCond}"
            );
            $stmt->execute();
            $row = $stmt->fetch();
            return [
                'tfl'  => (int) ($row['tfl']  ?? 0),
                'tafl' => (int) ($row['tafl'] ?? 0),
            ];
        } catch (\Throwable) {
            return ['tfl' => 0, 'tafl' => 0];
        }
    }

    /**
     * Returns counts of active joined listings by category network.
     *
     * Reads `categories_json` (preferred — stores network directly) and falls
     * back to the legacy `category` column matched against `lstr_categories`.
     * A listing is counted once per network it belongs to (not per category).
     *
     * @return array{tfl: int, tafl: int}
     */
    public function getJoinedNetworkCounts(): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT categories_json, category_id, category FROM lstr_joined WHERE active = 1'
            );
            $stmt->execute();

            $tfl  = 0;
            $tafl = 0;
            $legacyCatIds  = [];
            $legacyNames   = [];

            foreach ($stmt->fetchAll() as $row) {
                $json = $row['categories_json'] ?? null;
                if ($json !== null && $json !== '') {
                    $decoded = json_decode($json, true);
                    if (is_array($decoded)) {
                        $networks = [];
                        foreach ($decoded as $entry) {
                            $net = strtolower(trim((string) ($entry['network'] ?? '')));
                            if ($net !== '') {
                                $networks[$net] = true;
                            }
                        }
                        if (isset($networks['tfl']))  { $tfl++; }
                        if (isset($networks['tafl'])) { $tafl++; }
                        continue;
                    }
                }
                $catId = $row['category_id'] ?? null;
                if ($catId !== null && (int) $catId > 0) {
                    $legacyCatIds[] = (int) $catId;
                } else {
                    $cat = trim((string) ($row['category'] ?? ''));
                    if ($cat !== '') {
                        $legacyNames[] = $cat;
                    }
                }
            }

            $sourceById = [];
            if (!empty($legacyCatIds)) {
                $ph   = implode(',', array_fill(0, count($legacyCatIds), '?'));
                $cStmt = $this->db->prepare("SELECT id, source FROM lstr_categories WHERE id IN ({$ph})");
                $cStmt->execute($legacyCatIds);
                foreach ($cStmt->fetchAll() as $r) {
                    $sourceById[(int) $r['id']] = (string) $r['source'];
                }
                foreach ($legacyCatIds as $cid) {
                    $src = $sourceById[$cid] ?? '';
                    if ($src === 'tfl')  { $tfl++; }
                    if ($src === 'tafl') { $tafl++; }
                }
            }

            if (!empty($legacyNames)) {
                $ph   = implode(',', array_fill(0, count($legacyNames), '?'));
                $cStmt = $this->db->prepare("SELECT name, source FROM lstr_categories WHERE name IN ({$ph})");
                $cStmt->execute($legacyNames);
                $sourceByName = [];
                foreach ($cStmt->fetchAll() as $r) {
                    $sourceByName[(string) $r['name']] = (string) $r['source'];
                }
                foreach ($legacyNames as $name) {
                    $src = $sourceByName[$name] ?? '';
                    if ($src === 'tfl')  { $tfl++; }
                    if ($src === 'tafl') { $tafl++; }
                }
            }

            return ['tfl' => $tfl, 'tafl' => $tafl];
        } catch (\Throwable) {
            return ['tfl' => 0, 'tafl' => 0];
        }
    }

    // -----------------------------------------------------------------------
    // Joined listing counts
    // -----------------------------------------------------------------------

    /**
     * Returns status counts for all joined listings.
     *
     * `approved` counts rows that are active and not in an upcoming/pending state —
     * i.e. listings the site owner has actually joined and are publicly visible.
     * This is distinct from `active` (SUM(active = 1)) which includes all active rows
     * regardless of their status value.
     *
     * Returns zeros if the lstr_joined table does not yet exist.
     *
     * @return array{total: int, active: int, approved: int, upcoming: int, pending: int}
     */
    public function getJoinedCounts(): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*)                   AS total,
                        SUM(active = 1)            AS active,
                        SUM(" . self::JOINED_APPROVED_WHERE . ")  AS approved,
                        SUM(status = 'upcoming')   AS upcoming,
                        SUM(status = 'pending')    AS pending
                 FROM lstr_joined"
            );
            $stmt->execute();
            $row = $stmt->fetch();

            return [
                'total'    => (int) ($row['total']    ?? 0),
                'active'   => (int) ($row['active']   ?? 0),
                'approved' => (int) ($row['approved'] ?? 0),
                'upcoming' => (int) ($row['upcoming'] ?? 0),
                'pending'  => (int) ($row['pending']  ?? 0),
            ];
        } catch (\Throwable) {
            return ['total' => 0, 'active' => 0, 'approved' => 0, 'upcoming' => 0, 'pending' => 0];
        }
    }

    // -----------------------------------------------------------------------
    // Affiliate count
    // -----------------------------------------------------------------------

    /**
     * Returns the total number of active collective affiliates
     * (fanlisting_id IS NULL AND active = 1).
     *
     * Returns 0 if the table does not exist or the active column is absent.
     */
    public function getAffiliateCount(): int
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM lstr_affiliates WHERE active = 1'
            );
            $stmt->execute();
            return (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    // -----------------------------------------------------------------------
    // Member totals across all listings
    // -----------------------------------------------------------------------

    /**
     * Returns total approved and pending member counts across every owned
     * fanlisting's member table.
     *
     * Silently skips any member table that cannot be queried (missing, access
     * denied, etc.), so partial results are always returned rather than failing.
     *
     * @return array{approved: int, pending: int, total: int}
     */
    public function getMemberTotals(): array
    {
        $stmt = $this->db->prepare(
            "SELECT {$this->ownedPrefixCol} AS prefix FROM {$this->ownedTable}"
        );
        $stmt->execute();
        $prefixes = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $approved = 0;
        $pending  = 0;

        foreach ($prefixes as $rawPrefix) {
            $rawPrefix = (string) $rawPrefix;
            if ($rawPrefix === '') {
                continue;
            }

            try {
                $tableName = $this->appendMembers
                    ? $rawPrefix . 'members'
                    : $rawPrefix;

                $table = $this->db->validatePrefix($tableName);

                $s = $this->db->prepare(
                    "SELECT SUM({$this->approvedExpr}) AS approved,
                            SUM({$this->pendingExpr})  AS pending
                     FROM {$table}"
                );
                $s->execute();
                $row = $s->fetch();

                $approved += (int) ($row['approved'] ?? 0);
                $pending  += (int) ($row['pending']  ?? 0);
            } catch (\Throwable) {
                // Member table missing or inaccessible — skip silently
            }
        }

        return [
            'approved' => $approved,
            'pending'  => $pending,
            'total'    => $approved + $pending,
        ];
    }

    // -----------------------------------------------------------------------
    // Distinct country count across all listings
    // -----------------------------------------------------------------------

    /**
     * Returns the number of distinct countries represented across all approved
     * members in every owned listing's member table.
     *
     * Countries are compared case-insensitively so "USA" and "usa" count once.
     * Silently skips inaccessible member tables (same behaviour as getMemberTotals).
     */
    public function getDistinctCountryCount(): int
    {
        $stmt = $this->db->prepare(
            "SELECT {$this->ownedPrefixCol} AS prefix FROM {$this->ownedTable}"
        );
        $stmt->execute();
        $prefixes = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $countries = [];

        foreach ($prefixes as $rawPrefix) {
            $rawPrefix = (string) $rawPrefix;
            if ($rawPrefix === '') {
                continue;
            }

            try {
                $tableName = $this->appendMembers
                    ? $rawPrefix . 'members'
                    : $rawPrefix;

                $table = $this->db->validatePrefix($tableName);

                $s = $this->db->prepare(
                    "SELECT DISTINCT country FROM {$table}
                     WHERE {$this->approvedExpr} AND country != '' AND country IS NOT NULL"
                );
                $s->execute();

                foreach ($s->fetchAll(\PDO::FETCH_COLUMN) as $country) {
                    $country = trim((string) $country);
                    if ($country === '') {
                        continue;
                    }
                    $countries[strtolower($country)] = true;
                }
            } catch (\Throwable) {
                // Member table missing or inaccessible — skip silently
            }
        }

        return count($countries);
    }

    // -----------------------------------------------------------------------
    // Newest N listings
    // -----------------------------------------------------------------------

    /**
     * Returns the N most recently opened active owned listings.
     *
     * Sorted by opened date descending (NULLs sort last), then by primary key
     * descending as a tiebreaker/fallback (insertion order). If the `opened`
     * column is absent (raw pre-migration Enthusiast install), falls back to
     * plain primary-key order via a caught PDOException.
     *
     * @return Listing[]
     */
    public function getNewestOwned(int $n = 3): array
    {
        if ($n <= 0) {
            return [];
        }

        if ($this->isOwnedSchema) {
            $activeWhere = 'status = 2';
        } else {
            $activeWhere = 'upcoming = 0 AND trouble = 0';
        }

        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM {$this->ownedTable}
                 WHERE {$activeWhere}
                 ORDER BY opened DESC, {$this->ownedPk} DESC
                 LIMIT :n"
            );
            $stmt->bindValue(':n', $n, \PDO::PARAM_INT);
            $stmt->execute();
        } catch (\PDOException) {
            $stmt = $this->db->prepare(
                "SELECT * FROM {$this->ownedTable}
                 WHERE {$activeWhere}
                 ORDER BY {$this->ownedPk} DESC
                 LIMIT :n"
            );
            $stmt->bindValue(':n', $n, \PDO::PARAM_INT);
            $stmt->execute();
        }

        return array_map(
            static fn(array $row) => Listing::fromRow($row),
            $stmt->fetchAll()
        );
    }

    /**
     * Returns the N most recently added approved joined listings,
     * ordered by date_joined descending then by primary key descending.
     *
     * "Approved" means active and not pending/upcoming — same predicate as
     * getJoinedCounts()['approved']. Active-but-pending rows are excluded.
     *
     * Returns an empty array if lstr_joined does not yet exist.
     *
     * @return JoinedListing[]
     */
    public function getNewestJoined(int $n = 3): array
    {
        if ($n <= 0) {
            return [];
        }

        try {
            $stmt = $this->db->prepare(
                'SELECT * FROM lstr_joined
                 WHERE ' . self::JOINED_APPROVED_WHERE . '
                 ORDER BY date_joined DESC, id DESC
                 LIMIT :n'
            );
            $stmt->bindValue(':n', $n, \PDO::PARAM_INT);
            $stmt->execute();

            return array_map(
                static fn(array $row) => JoinedListing::fromRow($row),
                $stmt->fetchAll()
            );
        } catch (\Throwable) {
            return [];
        }
    }

    // -----------------------------------------------------------------------
    // Random listings
    // -----------------------------------------------------------------------

    /**
     * Returns a single random active owned fanlisting, or null if none exist.
     */
    public function getRandomOwned(): ?Listing
    {
        if ($this->isOwnedSchema) {
            $activeWhere = 'status = 2';
        } else {
            $activeWhere = 'upcoming = 0 AND trouble = 0';
        }

        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->ownedTable}
             WHERE {$activeWhere}
             ORDER BY RAND()
             LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->fetch();

        return $row !== false ? Listing::fromRow($row) : null;
    }

    /**
     * Returns a single random approved joined fanlisting, or null if none exist.
     *
     * "Approved" uses the same predicate as getJoinedCounts()['approved'] —
     * active rows that are not pending or upcoming.
     *
     * Returns null if lstr_joined does not yet exist.
     */
    public function getRandomJoined(): ?JoinedListing
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT * FROM lstr_joined
                 WHERE ' . self::JOINED_APPROVED_WHERE . '
                 ORDER BY RAND()
                 LIMIT 1'
            );
            $stmt->execute();
            $row = $stmt->fetch();

            return $row !== false ? JoinedListing::fromRow($row) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    // -----------------------------------------------------------------------
    // Last collective update
    // -----------------------------------------------------------------------

    /**
     * Returns the most recent `opened` date across all owned listings, or null
     * if no owned listing has an opened date set (or the column is absent on
     * a raw pre-migration Enthusiast install).
     *
     * This reflects the last time a new owned listing was added to the
     * collective, regardless of its current status.
     */
    public function getLastOwnedOpenedDate(): ?string
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT MAX(opened) FROM {$this->ownedTable}
                 WHERE opened IS NOT NULL AND opened != ''"
            );
            $stmt->execute();
            $value = $stmt->fetchColumn();

            return ($value !== false && $value !== null && $value !== '') ? (string) $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    // -----------------------------------------------------------------------
    // Category breakdown
    // -----------------------------------------------------------------------

    /**
     * Returns all Luster categories with the count of active owned listings
     * assigned to each, ordered by source then name.
     *
     * Entries with zero active listings are included so site owners can see
     * the full category list.
     *
     * Returns an empty array if lstr_categories does not yet exist.
     *
     * @return array<int, array{id: int, name: string, source: string, count: int}>
     */
    public function getOwnedCategoryBreakdown(): array
    {
        try {
            if ($this->isOwnedSchema) {
                $activeJoin = "f.status = 2";
            } else {
                $activeJoin = "f.upcoming = 0 AND f.trouble = 0";
            }

            $stmt = $this->db->prepare(
                "SELECT c.id, c.source, c.name,
                        COUNT(f.{$this->ownedPk}) AS listing_count
                 FROM lstr_categories c
                 LEFT JOIN {$this->ownedTable} f
                        ON f.category_id = c.id AND {$activeJoin}
                 GROUP BY c.id, c.source, c.name
                 ORDER BY c.source ASC, c.name ASC"
            );
            $stmt->execute();

            return array_map(static fn(array $row) => [
                'id'     => (int)    $row['id'],
                'name'   => (string) $row['name'],
                'source' => (string) $row['source'],
                'count'  => (int)    $row['listing_count'],
            ], $stmt->fetchAll());
        } catch (\Throwable) {
            return [];
        }
    }

    // -----------------------------------------------------------------------
    // Growth rates
    // -----------------------------------------------------------------------

    /**
     * Returns growth rates for owned listings, joined listings, and members.
     *
     * All rates are computed from the earliest known date and expressed as:
     *   - owned_per_year   — active owned listings per year (2 decimal places)
     *   - joined_per_year  — active joined listings per year (2 decimal places)
     *   - members_per_day  — total approved members per day (2 decimal places)
     *
     * Returns '0.00' for any rate that cannot be computed.
     *
     * @return array{owned_per_year: string, joined_per_year: string, members_per_day: string}
     */
    public function getGrowthRates(int $approvedTotal, int $activeOwned, int $activeJoined): array
    {
        // ── Oldest opened owned listing ──────────────────────────────────
        $ownedPerYear    = '0.00';
        $membersPerDay   = '0.00';

        try {
            $stmt = $this->db->prepare(
                "SELECT MIN(opened) AS earliest FROM {$this->ownedTable}
                 WHERE opened IS NOT NULL AND opened != ''"
            );
            $stmt->execute();
            $earliest = $stmt->fetchColumn();

            if ($earliest) {
                $ts   = strtotime((string) $earliest);
                $days = max(1, (int) round((time() - $ts) / 86400));
                $years = max(0.01, $days / 365.25);

                $ownedPerYear  = number_format($activeOwned / $years, 2);
                $membersPerDay = number_format($approvedTotal / $days, 2);
            }
        } catch (\Throwable) {}

        // ── Oldest joined listing ─────────────────────────────────────────
        $joinedPerYear = '0.00';

        try {
            $stmt = $this->db->prepare(
                "SELECT MIN(date_joined) AS earliest FROM lstr_joined
                 WHERE date_joined IS NOT NULL AND date_joined != '' AND active = 1"
            );
            $stmt->execute();
            $earliest = $stmt->fetchColumn();

            if ($earliest) {
                $ts    = strtotime((string) $earliest);
                $days  = max(1, (int) round((time() - $ts) / 86400));
                $years = max(0.01, $days / 365.25);

                $joinedPerYear = number_format($activeJoined / $years, 2);
            }
        } catch (\Throwable) {}

        return [
            'owned_per_year'  => $ownedPerYear,
            'joined_per_year' => $joinedPerYear,
            'members_per_day' => $membersPerDay,
        ];
    }
}
