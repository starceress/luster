<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 The main listing registry repository. Reads and writes listings from
 either the unprefixed "owned" table or the standard "enth_fanlistings"
 table, depending on the Enthusiast schema variant in use. Handles
 status filtering, category assignment (including the multi-category
 junction table), member-table prefix renaming, stale-listing detection,
 and latest/random listing queries for the public embeds.
*/

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Config;
use Luster\Core\Database;
use Luster\Models\Listing;
use RuntimeException;

/**
 * Data access for the listing registry table.
 *
 * The exact table name and column layout are determined by the
 * 'enth_table_prefix' config key:
 *
 *   ''       → table 'owned'  (Enthusiast 3 unprefixed install)
 *              PK column:   listingid
 *              Status col:  status (int: 2=active, 1=upcoming, 0=inactive)
 *
 *   'enth_'  → table 'enth_fanlistings'  (standard prefixed install)
 *              PK column:   id
 *              Status cols: upcoming (bool), trouble (bool)
 *
 * The Listing model's fromRow() transparently handles both layouts.
 */
final class ListingRepo
{
    private string $table;
    private string $pkCol;
    private bool   $isOwnedSchema;

    public function __construct(
        private readonly Database $db,
        private readonly Config   $config,
    ) {
        $prefix = (string) $this->config->get('enth_table_prefix', '');

        if ($prefix !== '' && !preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) {
            throw new RuntimeException(
                "Invalid enth_table_prefix '{$prefix}': only [a-zA-Z0-9_] allowed."
            );
        }

        $this->isOwnedSchema = ($prefix === '');

        // Build and validate the table name
        $rawTable    = $this->isOwnedSchema ? 'owned' : $prefix . 'listings';
        $this->table = $this->db->validatePrefix($rawTable);
        $this->pkCol = $this->isOwnedSchema ? 'listingid' : 'id';
    }

    // -----------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------

    /**
     * Returns the most recently added listing (highest primary key), or null
     * if no listings exist.
     */
    public function getNewest(): ?Listing
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->table} ORDER BY {$this->pkCol} DESC LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->fetch();
        return $row !== false ? Listing::fromRow($row) : null;
    }

    /**
     * Returns every listing ordered by listing number, newest first.
     *
     * @return Listing[]
     */
    public function getAll(): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->table} ORDER BY {$this->pkCol} DESC"
        );
        $stmt->execute();

        return array_map(
            static fn(array $row) => Listing::fromRow($row),
            $stmt->fetchAll()
        );
    }

    /**
     * Returns a single listing by primary key, or null if not found.
     */
    public function getById(int $id): ?Listing
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->table} WHERE {$this->pkCol} = :id"
        );
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();

        return $row !== false ? Listing::fromRow($row) : null;
    }

    /**
     * Returns aggregate status counts across all listings.
     *
     * @return array{total: int, upcoming: int, trouble: int}
     */
    public function getStats(): array
    {
        if ($this->isOwnedSchema) {
            // 'owned' table: status int (2=active, 1=upcoming, 0=inactive)
            $stmt = $this->db->prepare(
                "SELECT
                    COUNT(*)          AS total,
                    SUM(status = 1)   AS upcoming,
                    SUM(status = 0)   AS trouble
                 FROM {$this->table}"
            );
        } else {
            // Standard enth_fanlistings: separate bool cols
            $stmt = $this->db->prepare(
                "SELECT
                    COUNT(*)          AS total,
                    SUM(upcoming = 1) AS upcoming,
                    SUM(trouble  = 1) AS trouble
                 FROM {$this->table}"
            );
        }

        $stmt->execute();
        $row = $stmt->fetch();

        return [
            'total'    => (int) ($row['total']    ?? 0),
            'upcoming' => (int) ($row['upcoming'] ?? 0),
            'trouble'  => (int) ($row['trouble']  ?? 0),
        ];
    }

    /**
     * Returns Closed/Adopted Out counts from lstr_listing_lifecycle. These
     * listings are already included in getStats()'s 'trouble' count (the
     * legacy inactive representation), so this is additive context, not a
     * replacement — subtract both from 'trouble' to get the true pending count.
     *
     * @return array{closed: int, adopted_out: int}
     */
    public function getLifecycleCounts(): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT
                    SUM(lifecycle_status = 'closed')      AS closed,
                    SUM(lifecycle_status = 'adopted_out') AS adopted_out
                 FROM lstr_listing_lifecycle"
            );
            $stmt->execute();
            $row = $stmt->fetch();

            return [
                'closed'      => (int) ($row['closed']      ?? 0),
                'adopted_out' => (int) ($row['adopted_out'] ?? 0),
            ];
        } catch (\Throwable) {
            // Table not migrated yet (Migration 93) — no archived listings exist.
            return ['closed' => 0, 'adopted_out' => 0];
        }
    }

    // -----------------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------------

    /**
     * Updates the user-editable metadata on a listing.
     *
     * Only touches columns that Luster is allowed to change (title/name, url,
     * email, status). Subject, dbtable, catid, and listingtype are managed by
     * Enthusiast and must not be overwritten.
     */
    public function updateMeta(
        int    $id,
        string $title,
        string $url,
        string $email,
        bool   $upcoming,
        bool   $trouble,
    ): bool {
        if ($this->isOwnedSchema) {
            $status = $upcoming ? 1 : ($trouble ? 0 : 2);

            $stmt = $this->db->prepare(
                "UPDATE {$this->table}
                 SET title = :title, url = :url, email = :email, status = :status
                 WHERE {$this->pkCol} = :id"
            );

            return $stmt->execute([
                'title'  => $title,
                'url'    => $url,
                'email'  => $email,
                'status' => $status,
                'id'     => $id,
            ]);
        }

        $stmt = $this->db->prepare(
            "UPDATE {$this->table}
             SET name = :name, url = :url, email = :email,
                 upcoming = :upcoming, trouble = :trouble
             WHERE {$this->pkCol} = :id"
        );

        return $stmt->execute([
            'name'     => $title,
            'url'      => $url,
            'email'    => $email,
            'upcoming' => (int) $upcoming,
            'trouble'  => (int) $trouble,
            'id'       => $id,
        ]);
    }

    /**
     * Updates the category assignment and listing metadata for a listing.
     *
     * Writes the primary category_id column (backward-compat with Enthusiast
     * catid) and, when $categoryIds is provided, replaces all rows in the
     * lstr_listing_categories junction table (Migration 51). Passing null for
     * $categoryIds leaves the junction table untouched.
     */
    public function update(int $id, ?int $categoryId, string $subject = '', ?string $opened = null, string $catid = '', ?array $categoryIds = null): void
    {
        // The standard schema (enth_fanlistings) has no catid column.
        // The owned schema (unprefixed) has opened added by Migration 52.
        if ($this->isOwnedSchema) {
            $stmt = $this->db->prepare(
                "UPDATE {$this->table}
                 SET category_id = :category_id,
                     subject     = :subject,
                     opened      = :opened,
                     catid       = :catid
                 WHERE {$this->pkCol} = :id"
            );
            $stmt->execute([
                'category_id' => $categoryId,
                'subject'     => $subject,
                'opened'      => $opened,
                'catid'       => $catid,
                'id'          => $id,
            ]);
        } else {
            $stmt = $this->db->prepare(
                "UPDATE {$this->table}
                 SET category_id = :category_id,
                     subject     = :subject,
                     opened      = :opened
                 WHERE {$this->pkCol} = :id"
            );
            $stmt->execute([
                'category_id' => $categoryId,
                'subject'     => $subject,
                'opened'      => $opened,
                'id'          => $id,
            ]);
        }

        if ($categoryIds !== null) {
            try {
                $this->saveCategories($id, $categoryIds);
            } catch (\PDOException) {
                // Junction table not yet created (pre-migration 51) — skip
            }
        }
    }

    /**
     * Deletes a listing record from the registry table.
     *
     * Note: this removes only the registry row. The member table referenced
     * by dbtable/dbprefix is left intact and must be dropped separately if
     * desired. Enthusiast manages member table lifecycle.
     */
    public function delete(int $id): void
    {
        $stmt = $this->db->prepare(
            "DELETE FROM {$this->table} WHERE {$this->pkCol} = :id"
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * Renames the member table and updates the registry row to match.
     *
     * Validates $newPrefix before touching anything. If the registry UPDATE
     * fails after the RENAME, attempts to RENAME the table back before
     * re-throwing the original exception.
     *
     * @throws \InvalidArgumentException if $newPrefix contains invalid characters
     * @throws \PDOException             on database failure
     */
    public function updateDbPrefix(int $id, string $oldPrefix, string $newPrefix): void
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $newPrefix)) {
            throw new \InvalidArgumentException(
                "Invalid dbprefix '{$newPrefix}': only [a-zA-Z0-9_] allowed."
            );
        }

        $validatedOld = $this->db->validatePrefix($oldPrefix);
        $validatedNew = $this->db->validatePrefix($newPrefix);

        // Step 1: rename the member table
        $this->db->pdo()->exec("RENAME TABLE `{$validatedOld}` TO `{$validatedNew}`");

        // Step 2: update the registry row — roll back the rename on failure
        $dbCol = $this->isOwnedSchema ? 'dbtable' : 'dbprefix';

        try {
            $stmt = $this->db->prepare(
                "UPDATE {$this->table} SET `{$dbCol}` = :new WHERE {$this->pkCol} = :id"
            );
            $stmt->execute(['new' => $newPrefix, 'id' => $id]);
        } catch (\PDOException $e) {
            try {
                $this->db->pdo()->exec("RENAME TABLE `{$validatedNew}` TO `{$validatedOld}`");
            } catch (\PDOException) {
                // Rollback failed — swallow and fall through to re-throw original
            }
            throw $e;
        }
    }

    /**
     * Updates the status flags for a listing.
     *
     * Standard schema: sets the upcoming and trouble boolean columns.
     * Owned schema: maps the booleans back to the status int
     *   (upcoming=true → 1, trouble=true → 0, neither → 2).
     */
    public function updateStatus(int $id, bool $upcoming, bool $trouble): bool
    {
        if ($this->isOwnedSchema) {
            $status = $upcoming ? 1 : ($trouble ? 0 : 2);

            $stmt = $this->db->prepare(
                "UPDATE {$this->table} SET status = :status WHERE {$this->pkCol} = :id"
            );

            return $stmt->execute(['status' => $status, 'id' => $id]);
        }

        $stmt = $this->db->prepare(
            "UPDATE {$this->table}
             SET upcoming = :upcoming, trouble = :trouble
             WHERE {$this->pkCol} = :id"
        );

        return $stmt->execute([
            'upcoming' => (int) $upcoming,
            'trouble'  => (int) $trouble,
            'id'       => $id,
        ]);
    }

    /**
     * Returns the display label for a source value.
     * tfl → TFL, tafl → TAFL, everything else → as stored.
     */
    private static function formatSource(string $source): string
    {
        return match (strtolower($source)) {
            'tfl'  => 'TFL',
            'tafl' => 'TAFL',
            default => $source,
        };
    }

    /**
     * Returns structured category items for display (network badge + name).
     * Each item: ['network' => 'TFL'|'TAFL'|custom, 'name' => string]
     *
     * @return array<int,array{network:string,name:string}>
     */
    public function getCatItems(string $catid, ?int $categoryId = null): array
    {
        $ids = array_filter(explode('|', $catid), fn($v) => $v !== '');
        if (!empty($ids)) {
            $ph = implode(',', array_fill(0, count($ids), '?'));

            try {
                $s = $this->db->prepare("SELECT catname FROM category WHERE catid IN ({$ph})");
                $s->execute(array_values($ids));
                $rawNames = $s->fetchAll(\PDO::FETCH_COLUMN);
                if ($rawNames) {
                    // Strip legacy Enthusiast "Anime/Manga: " subcategory prefix for display
                    $normalized = array_map(
                        fn($n) => (string) preg_replace('/^Anime\/Manga:\s*/i', '', (string) $n),
                        $rawNames
                    );
                    // Look up by both stripped and original names to handle pre/post-migration states
                    $lookupNames = array_values(array_unique(
                        array_merge($normalized, array_map('strval', $rawNames))
                    ));
                    $ph2 = implode(',', array_fill(0, count($lookupNames), '?'));
                    $ns  = $this->db->prepare(
                        "SELECT name, source FROM lstr_categories WHERE name IN ({$ph2}) ORDER BY source"
                    );
                    $ns->execute($lookupNames);
                    $srcMap = [];
                    foreach ($ns->fetchAll(\PDO::FETCH_ASSOC) as $sr) {
                        $srcMap[(string) $sr['name']] ??= self::formatSource((string) $sr['source']);
                    }
                    return array_map(static function (string $norm, $raw) use ($srcMap): array {
                        return [
                            'network' => $srcMap[$norm] ?? $srcMap[(string) $raw] ?? 'Custom',
                            'name'    => $norm,
                        ];
                    }, $normalized, $rawNames);
                }
            } catch (\PDOException) {}

            $s2 = $this->db->prepare(
                "SELECT source, name FROM lstr_categories WHERE id IN ({$ph}) ORDER BY name"
            );
            $s2->execute(array_values($ids));
            $rows = $s2->fetchAll(\PDO::FETCH_ASSOC);
            if ($rows) {
                return array_map(
                    fn($r) => ['network' => self::formatSource((string) $r['source']), 'name' => (string) $r['name']],
                    $rows
                );
            }
        }

        if ($categoryId !== null) {
            $s = $this->db->prepare('SELECT source, name FROM lstr_categories WHERE id = :id');
            $s->execute(['id' => $categoryId]);
            $r = $s->fetch();
            if ($r) {
                return [['network' => self::formatSource((string) $r['source']), 'name' => (string) $r['name']]];
            }
        }

        return [];
    }

    /**
     * Returns a comma-separated string of category names for a listing.
     *
     * Resolves in the same way as getCatItems(): tries Enthusiast catid pipe
     * format first, then falls back to the Luster category_id. Returns '—'
     * when no category can be resolved.
     */
    public function getCatNames(string $catid, ?int $categoryId = null): string
    {
        $items = $this->getCatItems($catid, $categoryId);
        if (empty($items)) {
            return '—';
        }
        return implode(', ', array_map(fn($c) => $c['name'], $items));
    }

    /**
     * Inserts a new listing into the registry and returns the new primary key.
     *
     * Also populates the lstr_listing_categories junction table when $categoryIds
     * is provided and non-empty (Migration 51 must have run).
     */
    public function insert(string $title, string $subject, string $url, string $email, int $status, ?string $image, ?int $categoryId, ?string $opened, string $catid = '', ?array $categoryIds = null): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO {$this->table}
             (title,subject,url,email,status,imagefile,category_id,opened,catid,additional,dbtable,dbserver,dbuser,dbpassword,dbdatabase)
             VALUES (:title,:subject,:url,:email,:status,:img,:cid,:opened,:catid,'','','','','','')"
        );
        $stmt->execute([
            'title'   => $title,
            'subject' => $subject,
            'url'     => $url,
            'email'   => $email,
            'status'  => $status,
            'img'     => $image,
            'cid'     => $categoryId,
            'opened'  => $opened,
            'catid'   => $catid,
        ]);
        $newId = (int) $this->db->lastInsertId();

        if ($categoryIds !== null && !empty($categoryIds)) {
            try {
                $this->saveCategories($newId, $categoryIds);
            } catch (\PDOException) {
                // Junction table not yet created (pre-migration 51) — skip
            }
        }

        return $newId;
    }

    /**
     * Generates a unique per-listing member table name from the subject,
     * creates it (Enthusiast-compatible schema, same shape as
     * ListingAdminImportSource::createMemberTable()), and points
     * owned.dbtable at it.
     *
     * Only meaningful for the unprefixed schema — the standard enth_
     * prefixed schema has no per-listing physical table, so this is a
     * no-op there. Without this, a listing created via the admin "Add
     * Listing" form has nowhere to store members and the join form fails.
     *
     * email/url column widths (255/500) must match JoinController's own
     * Validator maxLength() calls exactly — narrower columns here would
     * let a form-valid submission pass validation and then fail the
     * INSERT under strict MySQL. SQLite doesn't enforce VARCHAR(n) width
     * at all, so this can't be caught by the SQLite-backed test suite;
     * it has to be kept in sync by hand.
     */
    public function provisionMemberTable(int $listingId, string $subject): void
    {
        if (!$this->isOwnedSchema) {
            return;
        }

        $pdo  = $this->db->pdo();
        $slug = $this->generateTableSlug($pdo, $subject, $listingId);

        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `{$slug}` (
                  `email`     TEXT NOT NULL,
                  `name`      TEXT NOT NULL DEFAULT '',
                  `country`   TEXT NOT NULL DEFAULT '',
                  `url`       TEXT NOT NULL DEFAULT '',
                  `pending`   INTEGER NOT NULL DEFAULT 0,
                  `password`  TEXT NOT NULL DEFAULT '',
                  `showemail` INTEGER NOT NULL DEFAULT 1,
                  `showurl`   INTEGER NOT NULL DEFAULT 1,
                  `added`     TEXT NULL,
                  PRIMARY KEY (`email`)
                )"
            );
        } else {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `{$slug}` (
                  `email`     VARCHAR(255) NOT NULL,
                  `name`      VARCHAR(128) NOT NULL DEFAULT '',
                  `country`   VARCHAR(128) NOT NULL DEFAULT '',
                  `url`       VARCHAR(500) NOT NULL DEFAULT '',
                  `pending`   TINYINT(1)   NOT NULL DEFAULT 0,
                  `password`  VARCHAR(255) NOT NULL DEFAULT '',
                  `showemail` TINYINT(1)   NOT NULL DEFAULT 1,
                  `showurl`   TINYINT(1)   NOT NULL DEFAULT 1,
                  `added`     DATE         NULL,
                  PRIMARY KEY (`email`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }

        $stmt = $this->db->prepare("UPDATE {$this->table} SET dbtable = :slug WHERE {$this->pkCol} = :id");
        $stmt->execute(['slug' => $slug, 'id' => $listingId]);
    }

    /**
     * Builds a safe, unique table name from a listing subject: lowercased,
     * non [a-zA-Z0-9_] characters collapsed to underscores, capped at 40
     * characters, with a numeric suffix appended on collision against any
     * existing table. Falls back to 'listing' when the subject sanitizes
     * to nothing (e.g. a subject with no Latin/digit characters at all).
     */
    private function generateTableSlug(\PDO $pdo, string $subject, int $listingId): string
    {
        $base = strtolower((string) preg_replace('/[^a-zA-Z0-9_]+/', '_', $subject));
        $base = trim($base, '_');
        $base = substr($base, 0, 40);
        if ($base === '') {
            $base = 'listing';
        }

        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $existing = [];
        $rows = $driver === 'sqlite'
            ? $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(\PDO::FETCH_COLUMN)
            : $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($rows as $t) {
            $existing[$t] = true;
        }

        $slug = $base;
        $i = 2;
        while (isset($existing[$slug])) {
            $slug = $base . '_' . $i;
            $i++;
        }

        // Final safety net — must match Database::validatePrefix()'s pattern
        // exactly, since that's what every read/write against this table
        // will be checked against later.
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $slug)) {
            $slug = 'listing_' . $listingId;
        }

        return $slug;
    }

    /**
     * Searches listings by subject or title, returning results ordered by
     * listing number, newest first.
     *
     * Used by the admin nav search to find listings by keyword across both name
     * fields regardless of which Enthusiast schema variant is active.
     *
     * @return Listing[]
     */
    public function search(string $query): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->table}
             WHERE subject LIKE :q OR title LIKE :q2
             ORDER BY {$this->pkCol} DESC"
        );
        $stmt->execute(['q' => '%' . $query . '%', 'q2' => '%' . $query . '%']);

        return array_map(static fn(array $r) => Listing::fromRow($r), $stmt->fetchAll());
    }

    /**
     * Returns listings filtered by status and optional Luster category ID.
     *
     * Status values:
     *   'current'     — active/open listings (status=2 or upcoming=0 AND trouble=0)
     *   'upcoming'    — upcoming listings (status=1 or upcoming=1)
     *   'pending'     — pending/inactive listings (status=0 or trouble=1) that are NOT archived
     *   'closed'      — archived as Closed (lstr_listing_lifecycle.lifecycle_status = 'closed')
     *   'adopted_out' — archived as Adopted Out (lifecycle_status = 'adopted_out')
     *   'all'         — no status filter (combine with $excludePending to omit truly-pending listings)
     *
     * Closed/Adopted Out listings are layered on top of the legacy inactive
     * representation (status=0 / trouble=1) via lstr_listing_lifecycle — they
     * are excluded from plain 'pending' results so the two concepts don't
     * overlap. Returned Listing objects for 'closed'/'adopted_out' have their
     * lifecycle property set directly (every row matched the WHERE clause for
     * that exact status); other status values leave it null — callers that
     * need lifecycle on a mixed-status result set should batch-apply it via
     * ListingLifecycleRepo::getMapForListings() + Listing::withLifecycle().
     *
     * @param int|null $categoryId    Filter to a Luster category (lstr_categories.id), or null for all
     * @param bool     $excludePending When true and $status is 'all', listings that are
     *                                 inactive AND have no lifecycle row (i.e. genuinely
     *                                 pending, not archived) are omitted. Closed/Adopted Out
     *                                 listings share the same legacy inactive flag as pending
     *                                 but are archived, not pending, so they're kept — "All
     *                                 Listings" should include them (with their status badge),
     *                                 not silently drop them. Has no effect for other status values.
     * @return Listing[]
     */
    public function getFiltered(string $status = 'current', ?int $categoryId = null, bool $excludePending = false): array
    {
        $t  = $this->table;
        $pk = $this->pkCol;

        $notArchived   = "NOT EXISTS (SELECT 1 FROM lstr_listing_lifecycle ll WHERE ll.fanlisting_id = {$t}.{$pk})";
        $legacyPending = $this->isOwnedSchema ? 'status = 0' : 'trouble = 1';

        $params = [];

        if ($status === 'closed' || $status === 'adopted_out') {
            $statusWhere = "EXISTS (
                SELECT 1 FROM lstr_listing_lifecycle ll
                WHERE ll.fanlisting_id = {$t}.{$pk} AND ll.lifecycle_status = :lifecycle_status
            )";
            $params['lifecycle_status'] = $status;
        } elseif ($status === 'past') {
            // "Past Fanlistings" — a virtual view combining closed + adopted_out in one
            // query (not two separate fetches concatenated together), so results come
            // back already merged and sorted. Each row still carries its own real
            // lifecycle value below via the same per-row map the 'all' branch uses.
            $statusWhere = "EXISTS (
                SELECT 1 FROM lstr_listing_lifecycle ll
                WHERE ll.fanlisting_id = {$t}.{$pk} AND ll.lifecycle_status IN ('closed', 'adopted_out')
            )";
        } else {
            // These fragments are built from a controlled match on internal values — safe to interpolate
            $statusWhere = match (true) {
                $status === 'current'                => $this->isOwnedSchema ? 'status = 2'  : 'upcoming = 0 AND trouble = 0',
                $status === 'upcoming'               => $this->isOwnedSchema ? 'status = 1'  : 'upcoming = 1',
                $status === 'pending'                => "{$legacyPending} AND {$notArchived}",
                // Excludes genuinely-pending listings only — archived (closed/adopted_out)
                // listings share the same legacy inactive flag but have a lifecycle row,
                // so they're kept in "All Listings" rather than silently dropped.
                $status === 'all' && $excludePending => "NOT ({$legacyPending} AND {$notArchived})",
                default                              => '1 = 1',
            };
        }

        if ($categoryId !== null) {
            // Expand to include child categories (max depth=1) so clicking a parent
            // shows all listings in the parent and in any of its direct children.
            $catWhere = " AND EXISTS (
                SELECT 1 FROM lstr_listing_categories lc
                WHERE lc.fanlisting_id = {$t}.{$pk}
                  AND lc.category_id IN (
                      SELECT id FROM lstr_categories
                      WHERE id = :category_id OR parent_id = :category_id2
                  )
            )";
            $params['category_id']  = $categoryId;
            $params['category_id2'] = $categoryId;
        } else {
            $catWhere = '';
        }

        $orderCol = $this->isOwnedSchema ? 'title' : 'name';

        // $statusWhere references lstr_listing_lifecycle for 'pending' (via
        // $notArchived), 'closed', 'adopted_out', 'past', and now 'all' with
        // $excludePending (also via $notArchived) — on an install that hasn't run
        // Migration 93 yet, prepare() itself throws for any of those (Luster uses
        // native/server-side prepares, so the missing-table error surfaces there,
        // not just on execute()). 'closed'/'adopted_out'/'past' are entirely
        // defined by that table, so an empty result is correct when it's missing.
        // 'pending' and 'all'+excludePending are different — they're real legacy
        // data independent of the lifecycle feature — so retry with the plain
        // legacy predicate instead of hiding those listings. (A missing lifecycle
        // table also means no listing can be archived yet, so falling back to the
        // old plain-legacy exclusion for 'all' is correct, not just a compromise.)
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM {$this->table}
                 WHERE {$statusWhere}{$catWhere}
                 ORDER BY {$orderCol} ASC"
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
        } catch (\Throwable) {
            if ($status !== 'pending' && !($status === 'all' && $excludePending)) {
                return [];
            }
            $fallbackWhere = $status === 'pending'
                ? $legacyPending
                : ($this->isOwnedSchema ? 'status != 0' : 'trouble = 0');
            $stmt = $this->db->prepare(
                "SELECT * FROM {$this->table}
                 WHERE {$fallbackWhere}{$catWhere}
                 ORDER BY {$orderCol} ASC"
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
        }

        if ($status === 'closed' || $status === 'adopted_out') {
            return array_map(
                static fn(array $row) => Listing::fromRow($row)->withLifecycle($status),
                $rows
            );
        }

        if ($status === 'all' || $status === 'past') {
            $ids          = array_map(static fn(array $row) => (int) ($row['listingid'] ?? $row['id'] ?? 0), $rows);
            $lifecycleMap = (new ListingLifecycleRepo($this->db))->getMapForListings($ids);

            return array_map(
                static fn(array $row) => Listing::fromRow($row)->withLifecycle(
                    $lifecycleMap[(int) ($row['listingid'] ?? $row['id'] ?? 0)] ?? null
                ),
                $rows
            );
        }

        return array_map(
            static fn(array $row) => Listing::fromRow($row),
            $rows
        );
    }

    /**
     * Returns active listings whose admin-controlled last_updated date is older
     * than $days days, or has never been set. Used for the overdue email notification.
     *
     * @return Listing[]
     */
    public function getStaleListings(int $days): array
    {
        if ($days <= 0) {
            return [];
        }

        $t           = $this->table;
        $pk          = $this->pkCol;
        $activeWhere = $this->isOwnedSchema
            ? "{$t}.status = 2"
            : "{$t}.upcoming = 0 AND {$t}.trouble = 0";
        $orderCol    = $this->isOwnedSchema ? "{$t}.listingid" : "{$t}.name";

        // Compute threshold in PHP so the query stays portable (no MySQL-specific DATE_SUB).
        $threshold = date('Y-m-d', (int) strtotime("-{$days} days"));

        $stmt = $this->db->prepare(
            "SELECT {$t}.*
             FROM {$t}
             LEFT JOIN lstr_fanlisting_settings s ON s.fanlisting_id = {$t}.{$pk}
             WHERE {$activeWhere}
               AND (s.last_updated IS NULL OR s.last_updated < :threshold)
             ORDER BY {$orderCol} ASC"
        );
        $stmt->execute(['threshold' => $threshold]);

        return array_map(
            static fn(array $row) => Listing::fromRow($row),
            $stmt->fetchAll(),
        );
    }

    /**
     * Returns the N most recently opened active listings ordered by open date DESC.
     *
     * For both schemas, orders by `opened DESC, pk DESC`. The `opened` column
     * exists in enth_fanlistings and is added to `owned` by Luster migrations;
     * if it is absent (raw pre-migration Enthusiast install), falls back to
     * insertion order (pk DESC) via a caught PDOException.
     *
     * @return Listing[]
     */
    public function getLatestOpened(int $limit = 1): array
    {
        if ($limit <= 0) {
            return [];
        }

        $activeWhere = $this->isOwnedSchema
            ? 'status = 2'
            : 'upcoming = 0 AND trouble = 0';

        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM {$this->table}
                 WHERE {$activeWhere} AND opened IS NOT NULL AND opened != ''
                 ORDER BY opened DESC, {$this->pkCol} DESC
                 LIMIT :lim"
            );
            $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
            $stmt->execute();
        } catch (\PDOException) {
            // opened column absent (pre-migration owned table) — fall back to PK order
            $stmt = $this->db->prepare(
                "SELECT * FROM {$this->table}
                 WHERE {$activeWhere}
                 ORDER BY {$this->pkCol} DESC
                 LIMIT :lim"
            );
            $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
            $stmt->execute();
        }

        return array_map(
            static fn(array $row) => Listing::fromRow($row),
            $stmt->fetchAll(),
        );
    }

    /**
     * Batch-fetches structured category data for multiple listings from the junction table.
     *
     * Returns listingId → [['network' => 'TFL', 'name' => '...'], ...].
     * Every requested ID is present as a key (empty array = no categories assigned).
     * Returns null when the junction table does not yet exist (pre-migration 51),
     * so callers can fall back to getCatItems() without a separate existence check.
     *
     * @param  int[]  $listingIds
     * @return array<int,list<array{network:string,name:string}>>|null
     */
    public function getCategoryDataByListingIds(array $listingIds): ?array
    {
        if (empty($listingIds)) {
            return [];
        }
        try {
            $ph   = implode(',', array_fill(0, count($listingIds), '?'));
            $stmt = $this->db->prepare(
                "SELECT lc.fanlisting_id, c.source, c.name
                 FROM lstr_listing_categories lc
                 JOIN lstr_categories c ON c.id = lc.category_id
                 WHERE lc.fanlisting_id IN ({$ph})
                 ORDER BY lc.fanlisting_id, c.name"
            );
            $stmt->execute(array_values($listingIds));
            $result = array_fill_keys(array_map('intval', $listingIds), []);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $fid = (int) $row['fanlisting_id'];
                $result[$fid][] = [
                    'network' => self::formatSource((string) $row['source']),
                    'name'    => (string) $row['name'],
                ];
            }
            return $result;
        } catch (\PDOException) {
            return null; // Junction table absent (pre-migration 51)
        }
    }

    /**
     * Returns all Luster category IDs assigned to a listing via the junction table.
     *
     * @return int[]
     */
    public function getCategoryIdsForListing(int $listingId): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT category_id FROM lstr_listing_categories WHERE fanlisting_id = :id ORDER BY category_id ASC'
            );
            $stmt->execute(['id' => $listingId]);
            return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        } catch (\PDOException) {
            return [];
        }
    }

    /**
     * Replaces all junction-table category assignments for a listing.
     *
     * Deduplicates and validates input, then runs DELETE + INSERT atomically.
     */
    public function saveCategories(int $listingId, array $categoryIds): void
    {
        $unique = array_values(array_unique(
            array_filter(
                array_map('intval', $categoryIds),
                static fn(int $id): bool => $id > 0
            )
        ));

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'DELETE FROM lstr_listing_categories WHERE fanlisting_id = :id'
            )->execute(['id' => $listingId]);

            if ($unique !== []) {
                $stmt = $pdo->prepare(
                    'INSERT INTO lstr_listing_categories (fanlisting_id, category_id) VALUES (:fid, :cid)'
                );
                foreach ($unique as $catId) {
                    $stmt->execute(['fid' => $listingId, 'cid' => $catId]);
                }
            }

            $pdo->commit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Returns distinct Luster categories assigned to at least one fanlisting,
     * ordered parent-first then alphabetically.
     *
     * Each entry includes parent_id, parent_name, and listing_count (active only).
     *
     * @param string $status         Same values as getFiltered(): 'current','upcoming','pending','all'
     * @param bool   $excludePending When status='all', exclude genuinely-pending listings only —
     *                               Closed/Adopted Out listings share the same legacy inactive flag
     *                               but have a lifecycle row, so they're kept (with their status
     *                               badge) rather than silently dropped from category counts.
     * @return array<int, array{id: int, name: string, slug: string, source: string, parent_id: int|null, parent_name: string|null, listing_count: int}>
     */
    public function getCategoryList(string $status = 'current', bool $excludePending = false): array
    {
        // Mirrors getFiltered() exactly — only current-status listings count toward categories.
        // Archived listings (lstr_listing_lifecycle) share the same legacy flags as 'pending'
        // so the pending branch must exclude them; closed/adopted_out use EXISTS to target only
        // the relevant archived subset.
        $notArchived   = "NOT EXISTS (SELECT 1 FROM lstr_listing_lifecycle l WHERE l.fanlisting_id = lc.fanlisting_id)";
        $legacyPending = $this->isOwnedSchema ? 'f.status = 0' : 'f.trouble = 1';

        $active = match (true) {
            $status === 'current'                => $this->isOwnedSchema ? 'f.status = 2' : 'f.upcoming = 0 AND f.trouble = 0',
            $status === 'upcoming'              => $this->isOwnedSchema ? 'f.status = 1'  : 'f.upcoming = 1',
            $status === 'pending'               => "{$legacyPending} AND {$notArchived}",
            $status === 'closed'               => "{$legacyPending} AND EXISTS (SELECT 1 FROM lstr_listing_lifecycle l WHERE l.fanlisting_id = lc.fanlisting_id AND l.lifecycle_status = 'closed')",
            $status === 'adopted_out'          => "{$legacyPending} AND EXISTS (SELECT 1 FROM lstr_listing_lifecycle l WHERE l.fanlisting_id = lc.fanlisting_id AND l.lifecycle_status = 'adopted_out')",
            $status === 'past'                 => "{$legacyPending} AND EXISTS (SELECT 1 FROM lstr_listing_lifecycle l WHERE l.fanlisting_id = lc.fanlisting_id AND l.lifecycle_status IN ('closed', 'adopted_out'))",
            $status === 'all' && $excludePending => "NOT ({$legacyPending} AND {$notArchived})",
            default                             => '1 = 1',
        };
        $hasParentId = $this->categoryColumnExists('parent_id');
        $hasSlug     = $this->categoryColumnExists('slug');
        $tbl         = $this->table;
        $pk          = $this->pkCol;

        $slugSel   = $hasSlug ? 'c.slug,'  : "'' AS slug,";
        $slugGroup = $hasSlug ? ', c.slug' : '';

        // Builds the full query for a given $active clause — extracted so the
        // missing-lifecycle-table fallback below can rebuild it with a different
        // (legacy-only) clause for 'pending' without duplicating both branches.
        $buildSql = function (string $active) use ($hasParentId, $slugSel, $slugGroup, $tbl, $pk): string {
            if ($hasParentId) {
                return "SELECT c.id, c.name, {$slugSel} c.source, c.parent_id,
                               p.name AS parent_name,
                               COUNT(DISTINCT t.fid) AS listing_count
                        FROM (
                            SELECT lc.category_id AS id, lc.fanlisting_id AS fid
                            FROM lstr_listing_categories lc
                            JOIN {$tbl} f ON f.{$pk} = lc.fanlisting_id
                            WHERE {$active}

                            UNION ALL

                            SELECT par.id AS id, lc.fanlisting_id AS fid
                            FROM lstr_categories par
                            JOIN lstr_categories chi ON chi.parent_id = par.id
                            JOIN lstr_listing_categories lc ON lc.category_id = chi.id
                            JOIN {$tbl} f ON f.{$pk} = lc.fanlisting_id
                            WHERE {$active}
                        ) t
                        JOIN lstr_categories c ON c.id = t.id
                        LEFT JOIN lstr_categories p ON p.id = c.parent_id
                        GROUP BY c.id, c.name{$slugGroup}, c.source, c.parent_id, p.name
                        HAVING COUNT(DISTINCT t.fid) > 0
                        ORDER BY COALESCE(p.name, c.name) ASC, c.name ASC";
            }

            return "SELECT c.id, c.name, {$slugSel} c.source,
                           NULL AS parent_id, NULL AS parent_name,
                           COUNT(DISTINCT f.{$pk}) AS listing_count
                    FROM lstr_listing_categories lc
                    JOIN {$tbl} f ON f.{$pk} = lc.fanlisting_id AND {$active}
                    JOIN lstr_categories c ON c.id = lc.category_id
                    GROUP BY c.id, c.name{$slugGroup}, c.source
                    ORDER BY c.name ASC";
        };

        // UNION ALL derived table (when $hasParentId): counts include parent's own
        // listings PLUS listings assigned to any of the parent's direct children
        // (max depth=1). This means a parent category appears in the nav even if
        // it has no direct listings, and its count covers the full child set.
        $sql = $buildSql($active);

        // $active references lstr_listing_lifecycle for 'pending'/'closed'/
        // 'adopted_out'/'past'/'all'+excludePending — on an install that hasn't run
        // Migration 93 yet, prepare() itself throws (native/server-side prepares).
        // 'closed'/'adopted_out'/'past' are entirely defined by that table, so an
        // empty result is correct when it's missing. 'pending' and 'all'+excludePending
        // are real legacy data independent of the lifecycle feature (and a missing
        // table means nothing could be archived yet anyway), so rebuild and retry
        // with just the legacy predicate instead of hiding those categories/counts.
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
        } catch (\Throwable) {
            if ($status !== 'pending' && !($status === 'all' && $excludePending)) {
                return [];
            }
            $fallbackActive = $status === 'pending'
                ? $legacyPending
                : ($this->isOwnedSchema ? 'f.status != 0' : 'f.trouble = 0');
            $stmt = $this->db->prepare($buildSql($fallbackActive));
            $stmt->execute();
        }

        return array_map(
            static fn(array $r) => [
                'id'            => (int)    $r['id'],
                'name'          => (string) $r['name'],
                'slug'          => (string) ($r['slug'] ?? ''),
                'source'        => (string) $r['source'],
                'parent_id'     => $r['parent_id'] !== null ? (int) $r['parent_id'] : null,
                'parent_name'   => $r['parent_name'] !== null ? (string) $r['parent_name'] : null,
                'listing_count' => (int) $r['listing_count'],
            ],
            $stmt->fetchAll()
        );
    }

    /**
     * Returns true when the named column is present in lstr_categories.
     *
     * Used as a migration guard before including optional columns (slug,
     * parent_id) in the getCategoryList() query, so the listing page does
     * not break on databases where the relevant migration has not run yet.
     * Uses INFORMATION_SCHEMA on MySQL and PRAGMA table_info on SQLite (tests).
     */
    private function categoryColumnExists(string $column): bool
    {
        try {
            $pdo = $this->db->pdo();
            if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $stmt = $pdo->query('PRAGMA table_info(lstr_categories)');
                foreach ($stmt ? $stmt->fetchAll() : [] as $row) {
                    if (($row['name'] ?? '') === $column) {
                        return true;
                    }
                }
                return false;
            }

            $stmt = $pdo->prepare(
                'SELECT 1
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = :table
                   AND COLUMN_NAME = :column
                 LIMIT 1'
            );
            $stmt->execute(['table' => 'lstr_categories', 'column' => $column]);
            return $stmt->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
    }
}
