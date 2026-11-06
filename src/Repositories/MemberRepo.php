<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Reads and writes fan members from per-listing member tables. Each listing
 has its own table whose name comes from the listing's dbtable/dbprefix
 value. Handles both Enthusiast schema variants (unprefixed "pending" column
 vs. standard "approved" column), paginated and filtered queries, country
 breakdowns, newest-member lookups, status transitions (approve/reject/
 pending), and dynamic column insertion for additional fields.
*/

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Config;
use Luster\Core\Database;
use Luster\Core\SiteClock;
use Luster\Models\Member;
use PDO;
use RuntimeException;

/**
 * Data access for per-fanlisting member tables.
 *
 * The approved/pending WHERE clause varies by Enthusiast schema variant,
 * driven by 'enth_table_prefix' in config:
 *
 *   ''       → unprefixed Enthusiast 3: member tables use 'pending' column
 *              where pending=0 means approved, pending=1 means queued.
 *
 *   'enth_'  → standard Enthusiast: member tables use 'approved' column
 *              where approved=1 means approved, approved=0 means queued.
 *
 * In both cases, the member table name comes from Listing::$dbprefix
 * (mapped from 'dbtable' in the owned schema). It is validated via
 * Database::validatePrefix() before SQL interpolation.
 */
final class MemberRepo
{
    private string $approvedWhere;

    private string $pendingWhere;

    /** SQL fragment that selects rejected members (unprefixed schema only). */
    private string $rejectedWhere;

    /**
     * When true, append 'members' to the dbprefix to get the table name
     * (standard enth_ schema: prefix 'fl_music_' → table 'fl_music_members').
     * When false, the dbprefix IS the full table name (unprefixed schema).
     */
    private bool $appendMembers;

    /** @var array<string, array<string, array{type: string, chars: ?int, bytes: ?int}>> */
    private array $columnWidthCache = [];

    public function __construct(
        private readonly Database $db,
        private readonly Config   $config,
    ) {
        $prefix = (string) $this->config->get('enth_table_prefix', '');

        if ($prefix === '') {
            // Unprefixed Enthusiast 3: dbtable column IS the full table name,
            // and pending=0 means the member IS approved.
            $this->approvedWhere = 'pending = 0';
            $this->pendingWhere  = 'pending = 1';
            $this->rejectedWhere = 'pending = 2';
            $this->appendMembers = false;
        } else {
            // Standard Enthusiast: dbprefix + 'members' = table name,
            // and approved=1 means the member IS approved.
            $this->approvedWhere = 'approved = 1';
            $this->pendingWhere  = 'approved = 0';
            $this->rejectedWhere = '1 = 0'; // no rejected state in standard schema
            $this->appendMembers = true;
        }
    }

    /**
     * True when this install's schema has a genuine third "rejected" state
     * (unprefixed Enthusiast 3, pending=2). The standard enth_ schema has no
     * such column — reject() falls back to permanently deleting the member,
     * since there's nowhere to persist a rejected status.
     */
    public function supportsRejectedState(): bool
    {
        return !$this->appendMembers;
    }

    /**
     * Resolves the actual member table name from the dbprefix/dbtable value.
     *
     * Standard schema:   'fl_music_'  → validates 'fl_music_members'
     * Unprefixed schema: 'huntrix'    → validates 'huntrix'
     */
    private function resolveTable(string $dbprefix): string
    {
        $raw = $this->appendMembers ? $dbprefix . 'members' : $dbprefix;

        return $this->db->validatePrefix($raw);
    }

    // -----------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------

    /**
     * Returns all approved members for a listing, ordered by name.
     *
     * @return Member[]
     */
    public function getApproved(string $prefix): array
    {
        $p    = $this->resolveTable($prefix);
        $stmt = $this->db->prepare(
            "SELECT * FROM {$p} WHERE {$this->approvedWhere} ORDER BY name ASC"
        );
        $stmt->execute();

        return array_map(static fn(array $r) => Member::fromRow($r), $stmt->fetchAll());
    }

    /**
     * Returns all pending (queued) members.
     *
     * @param string $sort  'name' | 'email' | 'country'
     * @param string $dir   'asc' | 'desc'
     * @return Member[]
     */
    public function getPending(string $prefix, string $sort = 'name', string $dir = 'asc'): array
    {
        $p     = $this->resolveTable($prefix);
        $sort  = in_array($sort, ['name', 'email', 'country'], true) ? $sort : 'name';
        $dir   = $dir === 'desc' ? 'DESC' : 'ASC';
        $order = $sort !== 'name' ? "{$sort} {$dir}, name ASC" : "name {$dir}";

        $stmt = $this->db->prepare(
            "SELECT * FROM {$p} WHERE {$this->pendingWhere} ORDER BY {$order}"
        );
        $stmt->execute();

        return array_map(static fn(array $r) => Member::fromRow($r), $stmt->fetchAll());
    }

    /**
     * Returns a single member by numeric primary key (standard schema only).
     *
     * In the unprefixed Enthusiast 3 schema, member tables have no id column
     * — use getByEmail() instead.
     */
    public function getById(string $prefix, int $id): ?Member
    {
        $p    = $this->resolveTable($prefix);
        $stmt = $this->db->prepare(
            "SELECT * FROM {$p} WHERE id = :id"
        );
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();

        return $row !== false ? Member::fromRow($row) : null;
    }

    /**
     * Returns a single member by email address (works in both schema variants).
     */
    public function getByEmail(string $prefix, string $email): ?Member
    {
        $p    = $this->resolveTable($prefix);
        $stmt = $this->db->prepare(
            "SELECT * FROM {$p} WHERE email = :email LIMIT 1"
        );
        $stmt->execute(['email' => $email]);

        $row = $stmt->fetch();

        return $row !== false ? Member::fromRow($row) : null;
    }

    /**
     * Returns the raw database row for a member by email, or null if not found.
     *
     * Unlike getByEmail(), this skips Member::fromRow() hydration and returns the
     * plain associative array as fetched, including any additional field columns.
     */
    public function getRawRow(string $prefix, string $email): ?array
    {
        $p    = $this->resolveTable($prefix);
        $stmt = $this->db->prepare("SELECT * FROM {$p} WHERE email = :email LIMIT 1");
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * getRawRow() with the row locked, for use inside a transaction. A MyISAM table
     * takes no row lock from this, so there it's the address lock that keeps other
     * writers out.
     */
    public function lockRawRow(string $prefix, string $email): ?array
    {
        $p    = $this->resolveTable($prefix);
        $stmt = $this->db->prepare("SELECT * FROM {$p} WHERE email = :email LIMIT 1" . $this->db->forUpdate());
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Each column of a listing's member table with its type and text limits, read from
     * the database. Real member tables are often narrower than the forms allow, and on a
     * non-strict server a longer value is silently cut. Kept for this object's life. A
     * failed read throws and isn't kept, and callers must refuse the write rather than
     * fall back to anything.
     *
     * @return array<string, array{type: string, chars: ?int, bytes: ?int, charset: ?string, charset_bytes: ?int}> By real column name.
     * @throws \RuntimeException if the columns can't be read.
     */
    public function columnWidths(string $prefix): array
    {
        $table = $this->resolveTable($prefix);

        if (!isset($this->columnWidthCache[$table])) {
            $widths = [];
            foreach ($this->db->tableColumns($table) as $column) {
                $widths[$column['name']] = [
                    'type'          => $column['type'],
                    'chars'         => $column['chars'],
                    'bytes'         => $column['bytes'],
                    'charset'       => $column['charset'],
                    'charset_bytes' => $column['charset_bytes'],
                ];
            }
            $this->columnWidthCache[$table] = $widths;
        }

        return $this->columnWidthCache[$table];
    }

    /**
     * Which of these values won't fit their column. CHAR and VARCHAR limits are in
     * characters and TEXT limits are in bytes, as the column stores them, which isn't
     * always how PHP holds them. Any other type, and a column that isn't listed, has no
     * text limit to check.
     *
     * @param array<string, array{type: string, chars: ?int, bytes: ?int, charset?: ?string, charset_bytes?: ?int}> $widths From columnWidths().
     * @param array<int|string, mixed> $values Column => value.
     * @return list<string> The columns that are too long.
     */
    public static function tooLong(array $widths, array $values): array
    {
        // MySQL column names ignore case, so a table's "Email" is still the email column.
        $widths  = array_change_key_case($widths, CASE_LOWER);
        $tooLong = [];
        foreach ($values as $column => $value) {
            $column = (string) $column;
            $width  = $widths[strtolower($column)] ?? null;
            if ($width === null) {
                continue;
            }

            $text = (string) $value;
            if (in_array($width['type'], ['char', 'varchar'], true)) {
                if ($width['chars'] !== null && mb_strlen($text, 'UTF-8') > $width['chars']) {
                    $tooLong[] = $column;
                }
            } elseif (str_ends_with($width['type'], 'text')) {
                if ($width['bytes'] !== null && self::storedBytes($text, $width['charset'] ?? null, $width['charset_bytes'] ?? null) > $width['bytes']) {
                    $tooLong[] = $column;
                }
            }
        }

        return $tooLong;
    }

    /**
     * How many bytes this text takes in a column with this character set. PHP's text is
     * UTF-8, so that's exact, and so is a set with one byte a character, like latin1,
     * whatever each one converts to. For any other set it's the most it could take, so
     * a value it can't be sure of is refused rather than cut short.
     */
    private static function storedBytes(string $text, ?string $charset, ?int $charsetBytes): int
    {
        if ($charset === null || $charsetBytes === null || str_starts_with($charset, 'utf8')) {
            return strlen($text);
        }

        return mb_strlen($text, 'UTF-8') * $charsetBytes;
    }

    /**
     * Counts pending (queued) members for a listing.
     */
    public function countPending(string $prefix): int
    {
        $p = $this->resolveTable($prefix);

        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM {$p} WHERE {$this->pendingWhere}"
            );
            $stmt->execute();

            return (int) $stmt->fetchColumn();
        } catch (\PDOException $e) {
            if (strpos($e->getMessage(), "doesn't exist") !== false) {
                return 0;
            }
            throw $e;
        }
    }

    /**
     * Counts approved members for a listing.
     */
    public function countApproved(string $prefix): int
    {
        $p = $this->resolveTable($prefix);

        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM {$p} WHERE {$this->approvedWhere}"
            );
            $stmt->execute();

            return (int) $stmt->fetchColumn();
        } catch (\PDOException $e) {
            if (strpos($e->getMessage(), "doesn't exist") !== false) {
                return 0;
            }
            throw $e;
        }
    }

    /**
     * Returns approved member count and distinct non-empty country count for
     * one listing in a single query. Safe to call even if the table is empty.
     *
     * Returns ['approved' => 0, 'country_count' => 0] on any error so that a
     * missing or misconfigured member table never breaks the collective embed.
     *
     * @return array{approved: int, country_count: int}
     */
    public function getMemberStats(string $prefix): array
    {
        try {
            $p    = $this->resolveTable($prefix);
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) AS approved,
                        COUNT(DISTINCT CASE WHEN country != '' THEN country END) AS country_count
                 FROM `{$p}`
                 WHERE {$this->approvedWhere}"
            );
            $stmt->execute();
            $row = $stmt->fetch();
            return [
                'approved'      => (int) ($row['approved']      ?? 0),
                'country_count' => (int) ($row['country_count'] ?? 0),
            ];
        } catch (\Exception) {
            return ['approved' => 0, 'country_count' => 0];
        }
    }

    /**
     * Counts approved members added on or after $since (Y-m-d or Y-m-d H:i:s).
     */
    public function countApprovedSince(string $prefix, string $since): int
    {
        $p = $this->resolveTable($prefix);

        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM {$p} WHERE {$this->approvedWhere} AND added >= ?"
            );
            $stmt->execute([$since]);

            return (int) $stmt->fetchColumn();
        } catch (\PDOException $e) {
            if (strpos($e->getMessage(), "doesn't exist") !== false) {
                return 0;
            }
            throw $e;
        }
    }

    /**
     * Full-text search across name, email, and URL (all members).
     *
     * @return Member[]
     */
    public function search(string $prefix, string $term): array
    {
        $p    = $this->resolveTable($prefix);
        $stmt = $this->db->prepare(
            "SELECT * FROM {$p}
             WHERE name LIKE :t1 OR email LIKE :t2 OR url LIKE :t3
             ORDER BY name ASC"
        );
        $like = '%' . $term . '%';
        $stmt->execute(['t1' => $like, 't2' => $like, 't3' => $like]);

        return array_map(static fn(array $r) => Member::fromRow($r), $stmt->fetchAll());
    }

    /**
     * Returns all emails for a listing matching the given status and optional search term.
     * Used by the bulk handler when "select all across pages" is triggered.
     *
     * @return string[]
     */
    public function getAllEmailsByFilter(string $prefix, string $status, string $search): array
    {
        if ($search !== '') {
            $members = $this->search($prefix, $search);
            return array_map(static fn(Member $m): string => $m->email, $members);
        }

        $p     = $this->resolveTable($prefix);
        $where = match ($status) {
            'pending'  => "WHERE {$this->pendingWhere}",
            'rejected' => "WHERE {$this->rejectedWhere}",
            'all'      => '',
            default    => "WHERE {$this->approvedWhere}",
        };

        $stmt = $this->db->prepare("SELECT email FROM {$p} {$where}");
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_COLUMN, 0);
    }

    /**
     * Returns a paginated slice of members filtered by status with sorting support.
     *
     * @param string $status  'approved' | 'pending' | 'rejected' | 'all'
     * @param string $sort    'name' | 'email' | 'country' | 'status'
     * @param string $dir     'asc' | 'desc'
     * @return array{members: Member[], total: int, pages: int}
     */
    public function paginateByStatus(
        string $prefix,
        string $status  = 'approved',
        int    $page    = 1,
        int    $perPage = 25,
        string $sort    = 'name',
        string $dir     = 'asc',
    ): array {
        $p      = $this->resolveTable($prefix);
        $offset = max(0, ($page - 1) * $perPage);

        $where = match ($status) {
            'pending'  => "WHERE {$this->pendingWhere}",
            'rejected' => "WHERE {$this->rejectedWhere}",
            'all'      => '',
            default    => "WHERE {$this->approvedWhere}",
        };

        $sort = in_array($sort, ['name', 'email', 'country', 'status'], true) ? $sort : 'name';
        $dir  = $dir === 'desc' ? 'DESC' : 'ASC';

        if ($sort === 'status') {
            // Schema-appropriate status column; ascending = approved first.
            if ($this->appendMembers) {
                $statusDir = $dir === 'ASC' ? 'DESC' : 'ASC'; // approved=1, so flip
                $order = "approved {$statusDir}, name ASC";
            } else {
                $order = "pending {$dir}, name ASC"; // pending=0 = approved, ASC = approved first
            }
        } elseif ($sort !== 'name') {
            $order = "{$sort} {$dir}, name ASC";
        } else {
            $order = "name {$dir}";
        }

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM {$p} {$where}");
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->prepare(
            "SELECT * FROM {$p} {$where}
             ORDER BY {$order}
             LIMIT :lim OFFSET :off"
        );
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset,  PDO::PARAM_INT);
        $stmt->execute();

        $members = array_map(static fn(array $r) => Member::fromRow($r), $stmt->fetchAll());

        return [
            'members' => $members,
            'total'   => $total,
            'pages'   => $total > 0 ? (int) ceil($total / $perPage) : 0,
        ];
    }

    /**
     * Returns a paginated slice of approved (or all) members.
     *
     * @param string $sort  'name' (default) or 'country' (country ASC, name ASC)
     * @return array{members: Member[], total: int, pages: int}
     */
    public function paginate(
        string $prefix,
        int    $page,
        int    $perPage,
        bool   $approvedOnly = true,
        string $sort = 'name',
    ): array {
        $p      = $this->resolveTable($prefix);
        $offset = max(0, ($page - 1) * $perPage);
        $where  = $approvedOnly ? "WHERE {$this->approvedWhere}" : '';
        $order  = match ($sort) {
            'country' => 'country ASC, name ASC',
            'added'   => 'added ASC',
            default   => 'name ASC',
        };

        $countStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM {$p} {$where}"
        );
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->prepare(
            "SELECT * FROM {$p} {$where}
             ORDER BY {$order}
             LIMIT :lim OFFSET :off"
        );
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset,  PDO::PARAM_INT);
        $stmt->execute();

        $members = array_map(static fn(array $r) => Member::fromRow($r), $stmt->fetchAll());

        return [
            'members' => $members,
            'total'   => $total,
            'pages'   => $total > 0 ? (int) ceil($total / $perPage) : 0,
        ];
    }

    /**
     * Returns each distinct country with the count of approved members in it.
     *
     * Only countries with at least one approved member are included.
     * Results are sorted alphabetically by country name.
     *
     * @return array<int, array{country: string, fan_count: int}>
     */
    public function getCountrySummary(string $prefix): array
    {
        $p    = $this->resolveTable($prefix);
        $stmt = $this->db->prepare(
            "SELECT country, COUNT(*) AS fan_count
             FROM {$p}
             WHERE {$this->approvedWhere} AND country != ''
             GROUP BY country
             ORDER BY country ASC"
        );
        $stmt->execute();

        return array_map(
            static fn(array $r) => [
                'country'   => (string) $r['country'],
                'fan_count' => (int)    $r['fan_count'],
            ],
            $stmt->fetchAll()
        );
    }

    /**
     * Returns a paginated slice of approved members from a single country.
     *
     * Members are ordered by name within the country.
     *
     * @return array{members: Member[], total: int, pages: int}
     */
    public function paginateByCountry(
        string $prefix,
        string $country,
        int    $page,
        int    $perPage,
    ): array {
        $p      = $this->resolveTable($prefix);
        $offset = max(0, ($page - 1) * $perPage);

        $countStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM {$p}
             WHERE {$this->approvedWhere} AND country = :country"
        );
        $countStmt->execute(['country' => $country]);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->prepare(
            "SELECT * FROM {$p}
             WHERE {$this->approvedWhere} AND country = :country
             ORDER BY name ASC
             LIMIT :lim OFFSET :off"
        );
        $stmt->bindValue(':country', $country);
        $stmt->bindValue(':lim',     $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off',     $offset,  PDO::PARAM_INT);
        $stmt->execute();

        $members = array_map(static fn(array $r) => Member::fromRow($r), $stmt->fetchAll());

        return [
            'members' => $members,
            'total'   => $total,
            'pages'   => $total > 0 ? (int) ceil($total / $perPage) : 0,
        ];
    }

    /**
     * Returns the N most recently added approved members (name + url).
     *
     * Intended for stats display — avoids fetching full member rows.
     * URL is only included when the member has opted in via showurl.
     *
     * $limit > 0 returns the N most recent approved members. $limit <= 0
     * ("show all") does NOT mean every approved member the listing has ever
     * had — it means every approved member who joined on the most recent
     * join date, so a wave of same-day approvals shows together instead of
     * the entire membership history.
     *
     * @return array<int, array{name: string, url: string}>
     */
    public function getNewestMembers(string $prefix, int $limit): array
    {
        $p = $this->resolveTable($prefix);

        if ($limit > 0) {
            $stmt = $this->db->prepare(
                "SELECT name, url, showurl FROM {$p}
                 WHERE {$this->approvedWhere}
                 ORDER BY added DESC
                 LIMIT :limit"
            );
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $latest = $this->getMostRecentApprovedDate($prefix);

            if ($latest === null) {
                return [];
            }

            $stmt = $this->db->prepare(
                "SELECT name, url, showurl FROM {$p}
                 WHERE {$this->approvedWhere} AND added = :latest
                 ORDER BY name ASC"
            );
            $stmt->execute(['latest' => $latest]);
        }

        return array_map(
            static fn(array $r) => [
                'name' => (string) $r['name'],
                'url'  => ((bool) $r['showurl'] && trim((string) $r['url']) !== '')
                    ? (string) $r['url']
                    : '',
            ],
            $stmt->fetchAll()
        );
    }

    /**
     * Returns the N most-recently-added members (any status), newest first.
     *
     * @return Member[]
     */
    public function getRecent(string $prefix, int $limit = 5): array
    {
        $p    = $this->resolveTable($prefix);
        $stmt = $this->db->prepare(
            "SELECT * FROM {$p} WHERE {$this->approvedWhere} ORDER BY added DESC LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn(array $r) => Member::fromRow($r), $stmt->fetchAll());
    }

    /**
     * Returns the MAX(added) date string for approved members, or null if none exist.
     */
    public function getMostRecentApprovedDate(string $prefix): ?string
    {
        $p    = $this->resolveTable($prefix);
        $stmt = $this->db->prepare(
            "SELECT MAX(added) AS latest FROM {$p} WHERE {$this->approvedWhere}"
        );
        $stmt->execute();
        $val = $stmt->fetchColumn();

        return ($val !== false && $val !== null) ? (string) $val : null;
    }

    // -----------------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------------

    /**
     * Inserts a new member row and returns the new primary key (or 0 if the
     * table has no auto-increment id, as in the unprefixed schema).
     *
     * $data keys must be valid SQL column names (validated against [a-zA-Z0-9_]).
     *
     * A new member's email is saved as hidden unless $data says otherwise, because
     * an older table's own default says "show" and nobody agreed to that. Luster's
     * public pages never print an address either way; this keeps the table honest
     * for anything else that reads it.
     */
    public function insert(string $prefix, array $data): int
    {
        $p = $this->resolveTable($prefix);

        if (!isset($data['showemail']) && $this->hasShowEmailColumn($p)) {
            $data['showemail'] = 0;
        }

        $columns      = [];
        $placeholders = [];
        $params       = [];

        foreach ($data as $col => $value) {
            // A field named like "123" (Enthusiast allows it) is an int key here.
            $col = (string) $col;
            $this->validateColumnName($col);
            $columns[]           = "`{$col}`";
            $placeholders[]      = ':c_' . $col;
            $params['c_' . $col] = $value;
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $p,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $this->db->lastInsertId();
    }

    private function hasShowEmailColumn(string $table): bool
    {
        foreach ($this->db->tableColumns($table) as $column) {
            if (strtolower($column['name']) === 'showemail') {
                return true;
            }
        }

        return false;
    }

    /**
     * The status columns for a member who just joined through the public form.
     * Every new join waits for approval (there's no auto-approve), so this is
     * always the pending state for this install's schema:
     *
     *   Unprefixed schema: pending=1.
     *   Standard schema:   approved=0, plus the updated timestamp that schema
     *                      stamps on every write.
     *
     * @return array<string, int|string>
     */
    public function pendingJoinColumns(): array
    {
        if ($this->approvedWhere === 'pending = 0') {
            return ['pending' => 1];
        }

        return ['approved' => 0, 'updated' => SiteClock::now($this->config)];
    }

    /**
     * The status columns for a member an admin adds as already approved (Add
     * Member), the counterpart of pendingJoinColumns():
     *
     *   Unprefixed schema: pending=0.
     *   Standard schema:   approved=1, plus the updated timestamp.
     *
     * @return array<string, int|string>
     */
    public function approvedColumns(): array
    {
        if ($this->approvedWhere === 'pending = 0') {
            return ['pending' => 0];
        }

        return ['approved' => 1, 'updated' => SiteClock::now($this->config)];
    }

    /**
     * Marks a pending member as approved.
     *
     * Standard schema: sets approved=1.
     * Unprefixed schema: sets pending=0.
     */
    public function approve(string $prefix, string $email): bool
    {
        $p = $this->resolveTable($prefix);

        if ($this->approvedWhere === 'pending = 0') {
            $stmt = $this->db->prepare(
                "UPDATE {$p} SET pending = 0 WHERE email = :email"
            );
        } else {
            $stmt = $this->db->prepare(
                "UPDATE {$p} SET approved = 1, updated = NOW() WHERE email = :email"
            );
        }

        return $stmt->execute(['email' => $email]);
    }

    /**
     * Sets a member back to pending status by email.
     */
    public function setPending(string $prefix, string $email): void
    {
        $p    = $this->resolveTable($prefix);
        $stmt = $this->db->prepare(
            "UPDATE {$p} SET {$this->pendingWhere} WHERE email = :email"
        );
        $stmt->execute(['email' => $email]);
    }

    /**
     * Rejects a member, moving them to the rejected state.
     * Unprefixed schema: sets pending=2.
     * Standard schema: no rejected state — falls back to delete.
     */
    public function reject(string $prefix, string $email): bool
    {
        if (!$this->appendMembers) {
            $p    = $this->resolveTable($prefix);
            $stmt = $this->db->prepare("UPDATE {$p} SET pending = 2 WHERE email = :email");
            return $stmt->execute(['email' => $email]);
        }

        return $this->deleteByEmail($prefix, $email);
    }

    /**
     * Counts rejected members for a listing (unprefixed schema only).
     */
    public function countRejected(string $prefix): int
    {
        $p = $this->resolveTable($prefix);

        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM {$p} WHERE {$this->rejectedWhere}"
            );
            $stmt->execute();
            return (int) $stmt->fetchColumn();
        } catch (\PDOException $e) {
            if (strpos($e->getMessage(), "doesn't exist") !== false) {
                return 0;
            }
            throw $e;
        }
    }

    /**
     * Updates arbitrary columns on an existing member row identified by email.
     *
     * $data keys must be valid SQL column names (validated).
     *
     * True only means the statement ran: a member who isn't there affects no rows and
     * still returns true. Read the row back if it matters. False means nothing was left
     * to write, and no SQL ran.
     */
    public function update(string $prefix, string $email, array $data): bool
    {
        $p = $this->resolveTable($prefix);

        // Filter $data to columns that actually exist in the table, so stale
        // additional-field definitions in owned.additional never cause a crash.
        $existing = $this->getTableColumns($p);
        $data     = array_intersect_key($data, array_flip($existing));

        if ($data === []) {
            return false;
        }

        $setParts = [];
        $params   = [];

        foreach ($data as $col => $value) {
            // A field named like "123" (Enthusiast allows it) is an int key here.
            $col = (string) $col;
            $this->validateColumnName($col);
            $setParts[]          = "`{$col}` = :u_{$col}";
            $params['u_' . $col] = $value;
        }

        // updated timestamp only exists in standard enth_ schema
        $updatedClause = $this->approvedWhere === 'approved = 1' ? ', updated = NOW()' : '';

        $params['where_email'] = $email;

        $sql = sprintf(
            'UPDATE %s SET %s%s WHERE email = :where_email',
            $p,
            implode(', ', $setParts),
            $updatedClause
        );

        $stmt = $this->db->prepare($sql);

        return $stmt->execute($params);
    }

    /**
     * Verifies a member's password and silently upgrades stale hash algorithms.
     *
     * Handles two upgrade paths without touching the `updated` timestamp:
     *  - Legacy MD5 (len=32, all-hex): verified with hash_equals(), upgraded to Argon2id.
     *  - Any other non-Argon2id hash (e.g. bcrypt from a Fanatically import):
     *    verified with password_verify(), upgraded via password_needs_rehash().
     *
     * Returns true if the password was correct, false otherwise. The upgrade is
     * a best-effort side-effect and does not affect the return value.
     */
    public function verifyAndUpgrade(string $prefix, string $email, string $password, string $storedHash): bool
    {
        if (strlen($storedHash) === 32 && ctype_xdigit($storedHash)) {
            if (!hash_equals($storedHash, md5($password))) {
                return false;
            }
            $this->updatePassword($prefix, $email, password_hash($password, PASSWORD_ARGON2ID));
            return true;
        }

        $verified = password_verify($password, $storedHash);
        if ($verified && password_needs_rehash($storedHash, PASSWORD_ARGON2ID)) {
            $this->updatePassword($prefix, $email, password_hash($password, PASSWORD_ARGON2ID));
        }
        return $verified;
    }

    /**
     * Updates only the password column for a member.
     *
     * Does not touch the `updated` timestamp — use this for silent hash
     * rehashes (MD5 → Argon2id, bcrypt → Argon2id) that should not register
     * as a profile edit. Explicit password changes via the update form and
     * password-reset flows should continue to use update() so the timestamp
     * is bumped normally on enth_ installs.
     */
    public function updatePassword(string $prefix, string $email, string $hash): bool
    {
        $p    = $this->resolveTable($prefix);
        $stmt = $this->db->prepare(
            "UPDATE {$p} SET `password` = :hash WHERE email = :email"
        );
        return $stmt->execute(['hash' => $hash, 'email' => $email]);
    }

    /**
     * Deletes a member by numeric id (standard schema only).
     */
    public function delete(string $prefix, int $id): bool
    {
        $p    = $this->resolveTable($prefix);
        $stmt = $this->db->prepare(
            "DELETE FROM {$p} WHERE id = :id"
        );

        return $stmt->execute(['id' => $id]);
    }

    /**
     * Deletes a member by email address (works in both schema variants).
     */
    public function deleteByEmail(string $prefix, string $email): bool
    {
        $p    = $this->resolveTable($prefix);
        $stmt = $this->db->prepare(
            "DELETE FROM {$p} WHERE email = :email"
        );

        return $stmt->execute(['email' => $email]);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Returns the actual column names present in a member table.
     * Used to filter update data against stale field definitions.
     *
     * @return string[]
     */
    private function getTableColumns(string $table): array
    {
        return array_column($this->db->tableColumns($table), 'name');
    }

    /**
     * Validates a column name before SQL interpolation.
     *
     * @throws RuntimeException when $column contains characters outside [a-zA-Z0-9_]
     */
    private function validateColumnName(string $column): void
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            throw new RuntimeException(
                "Invalid column name: '{$column}'. Only [a-zA-Z0-9_] allowed."
            );
        }
    }

    /**
     * Returns the number of distinct non-empty countries across approved members in one listing.
     */
    public function countUniqueCountries(string $prefix): int
    {
        $table = $this->resolveTable($prefix);
        $stmt  = $this->db->prepare(
            "SELECT COUNT(DISTINCT country) FROM `{$table}` WHERE {$this->approvedWhere} AND country != ''"
        );
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Returns the distinct non-empty country names for approved members in one listing.
     * Used by the dashboard to aggregate a cross-listing unique-country count.
     *
     * @return string[]
     */
    public function getDistinctCountryNames(string $prefix): array
    {
        $table = $this->resolveTable($prefix);
        $stmt  = $this->db->prepare(
            "SELECT DISTINCT country FROM `{$table}` WHERE {$this->approvedWhere} AND country != ''"
        );
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Returns the MAX(added) timestamp across all members in the table, or null when
     * the table is empty. Used to determine when a listing was last active.
     */
    public function getLastUpdated(string $prefix): ?string
    {
        $table = $this->resolveTable($prefix);
        $stmt  = $this->db->prepare("SELECT MAX(added) FROM `{$table}`");
        $stmt->execute();
        $value = $stmt->fetchColumn();

        return $value ?: null;
    }
}
