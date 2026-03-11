<?php

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Config;
use Luster\Core\Database;
use Luster\Models\Fanlisting;
use Luster\Models\JoinedFanlisting;

/**
 * Aggregated statistics for the whole collective (site-wide).
 *
 * Covers owned fanlistings, joined fanlistings, member totals across all
 * fanlisting member tables, affiliates, growth rates, newest/random listings,
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

    public function __construct(
        private readonly Database $db,
        private readonly Config   $config,
    ) {
        $prefix = (string) $this->config->get('enth_table_prefix', '');

        $this->isOwnedSchema = ($prefix === '');

        $rawTable            = $this->isOwnedSchema ? 'owned' : $prefix . 'fanlistings';
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
    // Owned fanlisting counts
    // -----------------------------------------------------------------------

    /**
     * Returns status counts for all owned fanlistings.
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

    // -----------------------------------------------------------------------
    // Joined fanlisting counts
    // -----------------------------------------------------------------------

    /**
     * Returns status counts for all joined fanlistings.
     *
     * Returns zeros if the lstr_joined table does not yet exist.
     *
     * @return array{total: int, active: int, upcoming: int, pending: int}
     */
    public function getJoinedCounts(): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*)                    AS total,
                        SUM(active = 1)             AS active,
                        SUM(status = 'upcoming')    AS upcoming,
                        SUM(status = 'pending')     AS pending
                 FROM lstr_joined"
            );
            $stmt->execute();
            $row = $stmt->fetch();

            return [
                'total'    => (int) ($row['total']    ?? 0),
                'active'   => (int) ($row['active']   ?? 0),
                'upcoming' => (int) ($row['upcoming'] ?? 0),
                'pending'  => (int) ($row['pending']  ?? 0),
            ];
        } catch (\Throwable) {
            return ['total' => 0, 'active' => 0, 'upcoming' => 0, 'pending' => 0];
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
    // Member totals across all fanlistings
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
    // Newest N listings
    // -----------------------------------------------------------------------

    /**
     * Returns the N most recently added active owned fanlistings.
     *
     * Sorted by opened date descending when available, then by primary key
     * descending as a fallback (insertion order).
     *
     * @return Fanlisting[]
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

        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->ownedTable}
             WHERE {$activeWhere}
             ORDER BY {$this->ownedPk} DESC
             LIMIT :n"
        );
        $stmt->bindValue(':n', $n, \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn(array $row) => Fanlisting::fromRow($row),
            $stmt->fetchAll()
        );
    }

    /**
     * Returns the N most recently added active joined fanlistings,
     * ordered by date_joined descending then by primary key descending.
     *
     * Returns an empty array if lstr_joined does not yet exist.
     *
     * @return JoinedFanlisting[]
     */
    public function getNewestJoined(int $n = 3): array
    {
        if ($n <= 0) {
            return [];
        }

        try {
            $stmt = $this->db->prepare(
                'SELECT * FROM lstr_joined
                 WHERE active = 1
                 ORDER BY date_joined DESC, id DESC
                 LIMIT :n'
            );
            $stmt->bindValue(':n', $n, \PDO::PARAM_INT);
            $stmt->execute();

            return array_map(
                static fn(array $row) => JoinedFanlisting::fromRow($row),
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
    public function getRandomOwned(): ?Fanlisting
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

        return $row !== false ? Fanlisting::fromRow($row) : null;
    }

    /**
     * Returns a single random active joined fanlisting, or null if none exist.
     *
     * Returns null if lstr_joined does not yet exist.
     */
    public function getRandomJoined(): ?JoinedFanlisting
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT * FROM lstr_joined
                 WHERE active = 1
                 ORDER BY RAND()
                 LIMIT 1'
            );
            $stmt->execute();
            $row = $stmt->fetch();

            return $row !== false ? JoinedFanlisting::fromRow($row) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    // -----------------------------------------------------------------------
    // Category breakdown
    // -----------------------------------------------------------------------

    /**
     * Returns all Luster categories with the count of active owned fanlistings
     * assigned to each, ordered by source then name.
     *
     * Entries with zero active fanlistings are included so site owners can see
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
     * Returns growth rates for owned fanlistings, joined fanlistings, and members.
     *
     * All rates are computed from the earliest known date and expressed as:
     *   - owned_per_year   — active owned fanlistings per year (2 decimal places)
     *   - joined_per_year  — active joined fanlistings per year (2 decimal places)
     *   - members_per_day  — total approved members per day (2 decimal places)
     *
     * Returns '0.00' for any rate that cannot be computed.
     *
     * @return array{owned_per_year: string, joined_per_year: string, members_per_day: string}
     */
    public function getGrowthRates(int $approvedTotal, int $activeOwned, int $activeJoined): array
    {
        // ── Oldest opened owned fanlisting ──────────────────────────────────
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

        // ── Oldest joined fanlisting ─────────────────────────────────────────
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
