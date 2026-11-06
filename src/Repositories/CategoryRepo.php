<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Manages listing categories in lstr_categories. Categories are organized by
 source (TFL, TAFL, or custom) and support one level of parent-child nesting.
 Includes slug generation for URL-safe category links, seed health checking
 against the canonical TFL/TAFL category lists, and idempotent backfill for
 missing seed categories. Also handles the multi-category junction table
 (lstr_listing_categories) and provides paginated, filterable queries for
 the admin categories page.
*/

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Database;
use Luster\Models\Category;

/**
 * Data access for the lstr_categories table.
 *
 * Requires lstr_categories to exist — run public/migrate.php (Migration 7) first.
 * Migration 55 added the slug column and unique index on (source, slug).
 */
final class CategoryRepo
{
    /** lstr_categories.source is VARCHAR(50). */
    public const SOURCE_MAX_LENGTH = 50;

    /** lstr_categories.name is VARCHAR(150). */
    public const NAME_MAX_LENGTH = 150;

    public function __construct(private readonly Database $db) {}

    // -----------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------

    /**
     * Returns all categories ordered alphabetically.
     *
     * @return Category[]
     */
    public function getAll(): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_categories ORDER BY name ASC'
        );
        $stmt->execute();

        return array_map(
            static fn(array $r) => Category::fromRow($r),
            $stmt->fetchAll(),
        );
    }

    /**
     * Returns a single category by primary key, or null if not found.
     *
     * @return object|null  stdClass with all DB columns
     */
    public function getById(int $id): ?object
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_categories WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_OBJ);

        return $row !== false ? $row : null;
    }

    /**
     * Returns a paginated, filtered slice of categories for the admin categories page.
     *
     * @param string $source  Source filter: 'tfl', 'tafl', 'custom', or '' for all
     * @param string $search  Substring search on category name (ignored when $catName is set)
     * @param string $sort    Column to sort by: 'id', 'source', or 'name'
     * @param string $catName Exact name match — used to jump straight to a single category
     * @return array{items: object[], total: int, pages: int}
     */
    public function paginate(int $perPage, int $page, string $source = '', string $search = '', string $sort = 'source', string $dir = 'asc', string $catName = ''): array
    {
        $where  = '1=1';
        $params = [];

        if ($source !== '') {
            $where            .= ' AND source = :source';
            $params['source']  = $source;
        }

        if ($catName !== '') {
            $where             .= ' AND name = :cat_name';
            $params['cat_name'] = $catName;
        } elseif ($search !== '') {
            $where            .= ' AND name LIKE :search';
            $params['search']  = '%' . $search . '%';
        }

        $dir   = $dir === 'desc' ? 'DESC' : 'ASC';
        $order = match (in_array($sort, ['id', 'source', 'name'], true) ? $sort : 'source') {
            'id'   => "id {$dir}",
            'name' => "name {$dir}",
            default => "source {$dir}, name ASC",
        };

        $countStmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM lstr_categories WHERE $where");
        foreach ($params as $k => $v) {
            $countStmt->bindValue($k, $v);
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();
        $offset = ($page - 1) * $perPage;

        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM lstr_categories WHERE $where ORDER BY {$order} LIMIT :lim OFFSET :off"
        );

        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }

        $stmt->bindValue('lim', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue('off', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => $stmt->fetchAll(\PDO::FETCH_OBJ),
            'total' => $total,
            'pages' => $total > 0 ? (int) ceil($total / $perPage) : 0,
        ];
    }

    /**
     * Returns all category IDs matching an optional source and search term.
     * Used by the bulk handler when "select all across pages" is triggered.
     *
     * @return int[]
     */
    public function getAllIdsByFilter(string $source, string $search): array
    {
        $where  = '1=1';
        $params = [];

        if ($source !== '') {
            $where           .= ' AND source = :source';
            $params['source'] = $source;
        }

        if ($search !== '') {
            $where           .= ' AND name LIKE :search';
            $params['search'] = '%' . $search . '%';
        }

        $stmt = $this->db->pdo()->prepare("SELECT id FROM lstr_categories WHERE {$where}");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN, 0));
    }

    /**
     * Returns every source other than TFL and TAFL, including 'custom', exactly as
     * stored. The admin categories filter matches on these values. The Add and Edit
     * pickers use getCustomNetworks() instead, which gives the names they show.
     *
     * @return string[]
     */
    public function getCustomSources(): array
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT source AS net FROM lstr_categories
              WHERE source NOT IN ('tfl', 'tafl')
              ORDER BY source ASC"
        );
        $stmt->execute();

        return array_column($stmt->fetchAll(), 'net');
    }

    /**
     * Returns a map of category_id → listing count from lstr_listing_categories.
     * Gracefully returns [] if the junction table does not exist (pre-migration 51).
     *
     * @return array<int, int>
     */
    public function getListingCountsByCategoryId(): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT category_id, COUNT(*) AS cnt
                 FROM lstr_listing_categories
                 GROUP BY category_id'
            );
            $stmt->execute();
            $counts = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $counts[(int) $row['category_id']] = (int) $row['cnt'];
            }
            return $counts;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Updates a category's name and source. When $slug is omitted, the
     * existing slug is kept as-is rather than re-derived from the new name
     * (so existing links continue to work after a rename) — but it is still
     * re-validated via uniqueSlug() against the (possibly new) $source, since
     * a source change alone can collide with another category's existing
     * slug under uq_cat_slug (source, slug). A collision is resolved with a
     * numeric suffix (e.g. "books" → "books-2"), the same as when an
     * explicit slug is passed in.
     *
     * To set a parent or change the slug, pass the optional parameters.
     * Max depth is 1: parentId must itself have parent_id = NULL.
     *
     * @throws \InvalidArgumentException when parentId would exceed max depth
     */
    public function update(int $id, string $name, string $source = 'custom', ?string $slug = null, ?int $parentId = null): void
    {
        if ($parentId !== null && !$this->isValidParent($parentId)) {
            throw new \InvalidArgumentException('Parent category must be a top-level category (max depth 1).');
        }

        if ($this->nameExists($name, $source, $id)) {
            throw new \InvalidArgumentException('A category named "' . $name . '" already exists for this network.');
        }

        // Even when the caller isn't changing the slug, the slug must still be
        // re-validated against uq_cat_slug (source, slug) — the source itself
        // may be changing (e.g. moving a custom category into TFL), and the
        // existing slug could already belong to a different category under
        // the new source. Re-running it through uniqueSlug() (excluding this
        // row) resolves that the same way an explicit slug change would,
        // instead of letting the UNIQUE constraint throw a raw PDOException.
        if ($slug === null) {
            $current = $this->getById($id);
            $slug    = (string) ($current?->slug ?? $this->slugify($name));
        }
        $slug = $this->uniqueSlug($this->slugify($slug), $source, $id);

        $stmt = $this->db->pdo()->prepare(
            'UPDATE lstr_categories SET name = :name, source = :source, slug = :slug, parent_id = :pid WHERE id = :id'
        );
        $stmt->execute(['name' => $name, 'source' => $source, 'slug' => $slug, 'pid' => $parentId, 'id' => $id]);
    }

    /**
     * Returns all categories for a given source, ordered alphabetically.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function getBySource(string $source): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, name FROM lstr_categories
             WHERE source = :source
             ORDER BY name ASC'
        );
        $stmt->execute(['source' => self::storedSource($source)]);

        return array_map(
            static fn(array $r) => ['id' => (int) $r['id'], 'name' => (string) $r['name']],
            $stmt->fetchAll(),
        );
    }

    /**
     * Returns the network names the Add and Edit pickers offer besides TFL and TAFL,
     * ordered alphabetically. Custom is one of them once a category is in it, because
     * "use Custom" is what Add Category does by default and what the importers save.
     *
     * Getting a network's categories for one of these names goes through getBySource(),
     * which finds Custom's under the lowercase name they're stored with.
     *
     * @return string[]
     */
    public function getCustomNetworks(): array
    {
        $networks = array_map(
            static fn(string $source): string => self::storedSource($source) === 'custom' ? 'Custom' : $source,
            $this->getCustomSources(),
        );

        return array_values(array_unique($networks));
    }

    /**
     * The forms say TFL, TAFL and Custom, and the table stores those three in lowercase.
     * Any other network keeps the name it was given.
     */
    private static function storedSource(string $source): string
    {
        $lower = strtolower(trim($source));

        return in_array($lower, ['tfl', 'tafl', 'custom'], true) ? $lower : $source;
    }

    /**
     * Returns a flat, depth-aware list of all custom categories suitable for
     * populating a <select>. Top-level categories appear first; their children
     * immediately follow with an em-dash prefix and depth=1.
     *
     * Only custom-source categories (source = 'custom') are included, since
     * TFL/TAFL categories are network-managed and do not support nesting.
     *
     * @return array<int, array{id: int, name: string, indent_name: string, depth: int, parent_id: int|null}>
     */
    public function buildIndentedList(): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, name, parent_id FROM lstr_categories
             WHERE source = 'custom'
             ORDER BY COALESCE(parent_id, id) ASC, parent_id IS NOT NULL ASC, name ASC"
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $topLevel = [];
        $children = [];
        foreach ($rows as $r) {
            if ($r['parent_id'] === null) {
                $topLevel[] = $r;
            } else {
                $children[(int) $r['parent_id']][] = $r;
            }
        }

        $result = [];
        foreach ($topLevel as $parent) {
            $pid = (int) $parent['id'];
            $result[] = [
                'id'          => $pid,
                'name'        => (string) $parent['name'],
                'indent_name' => (string) $parent['name'],
                'depth'       => 0,
                'parent_id'   => null,
            ];
            foreach ($children[$pid] ?? [] as $child) {
                $result[] = [
                    'id'          => (int) $child['id'],
                    'name'        => (string) $child['name'],
                    'indent_name' => '— ' . $child['name'],
                    'depth'       => 1,
                    'parent_id'   => $pid,
                ];
            }
        }

        return $result;
    }

    // -----------------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------------

    /**
     * Inserts a new category. Auto-generates a unique slug from the name.
     * Max depth is 1: parentId must itself have parent_id = NULL.
     *
     * @throws \InvalidArgumentException when parentId would exceed max depth
     */
    public function add(string $name, string $source, ?int $parentId = null): int
    {
        if ($parentId !== null && !$this->isValidParent($parentId)) {
            throw new \InvalidArgumentException('Parent category must be a top-level category (max depth 1).');
        }

        if ($this->nameExists($name, $source)) {
            throw new \InvalidArgumentException('A category named "' . $name . '" already exists for this network.');
        }

        $slug = $this->uniqueSlug($this->slugify($name), $source);

        $stmt = $this->db->prepare(
            'INSERT INTO lstr_categories (name, slug, source, parent_id) VALUES (:name, :slug, :source, :pid)'
        );
        $stmt->execute(['name' => $name, 'slug' => $slug, 'source' => $source, 'pid' => $parentId]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Checks whether a category with the given (source, name) pair already
     * exists — the same pair `uq_categories_source_name` enforces at the DB
     * level. Pre-checking lets add()/update() raise a friendly
     * \InvalidArgumentException instead of letting a raw PDOException from
     * the unique constraint reach the controller as a 500.
     */
    private function nameExists(string $name, string $source, ?int $excludeId = null): bool
    {
        $sql = 'SELECT 1 FROM lstr_categories WHERE source = :source AND name = :name';
        $params = ['source' => $source, 'name' => $name];
        if ($excludeId !== null) {
            $sql .= ' AND id != :id';
            $params['id'] = $excludeId;
        }
        $stmt = $this->db->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Deletes a category by id.
     *
     * Any lstr_joined rows that reference the deleted category are also nulled.
     */
    public function delete(int $id): void
    {
        $stmtNull = $this->db->prepare(
            'UPDATE lstr_joined SET category_id = NULL WHERE category_id = :id'
        );
        $stmtNull->execute(['id' => $id]);

        $stmtDel = $this->db->prepare(
            'DELETE FROM lstr_categories WHERE id = :id'
        );
        $stmtDel->execute(['id' => $id]);
    }

    /**
     * Returns all category IDs that have the given name (across all sources).
     *
     * @return int[]
     */
    public function findIdsByName(string $name): array
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM lstr_categories WHERE name = :name'
        );
        $stmt->execute(['name' => $name]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Finds a category by its source and exact name, or returns null when not found.
     *
     * The (source, name) pair has a UNIQUE index (Migration 43), so at most one
     * row is returned. Used by the importer and findOrCreate() to avoid duplicates.
     */
    public function findBySourceAndName(string $source, string $name): ?object
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_categories WHERE source = :source AND name = :name LIMIT 1'
        );
        $stmt->execute(['source' => self::storedSource($source), 'name' => $name]);
        $row = $stmt->fetch();
        return $row ? Category::fromRow((array) $row) : null;
    }

    /**
     * Finds an existing category by source+name or creates one with an auto slug.
     */
    public function findOrCreate(string $source, string $name): int
    {
        $source   = self::storedSource($source);
        $existing = $this->findBySourceAndName($source, $name);
        if ($existing !== null) {
            return $existing->id;
        }

        $slug = $this->uniqueSlug($this->slugify($name), $source);
        $stmt = $this->db->prepare(
            'INSERT INTO lstr_categories (name, slug, source) VALUES (:name, :slug, :source)'
        );
        $stmt->execute(['name' => $name, 'slug' => $slug, 'source' => $source]);
        return (int) $this->db->lastInsertId();
    }

    // -----------------------------------------------------------------------
    // Seed health
    // -----------------------------------------------------------------------

    /**
     * Returns expected vs. present counts for TFL and TAFL seed categories.
     *
     * @return array{tfl_expected: int, tfl_present: int, tafl_expected: int, tafl_present: int}
     */
    public function seedHealth(): array
    {
        $tflNames  = \Luster\Support\CategorySeedCatalog::tfl();
        $taflNames = \Luster\Support\CategorySeedCatalog::tafl();

        $checkStmt = $this->db->prepare(
            'SELECT COUNT(*) FROM lstr_categories WHERE source = :source AND name = :name'
        );

        $tflPresent = 0;
        foreach ($tflNames as $name) {
            $checkStmt->execute(['source' => 'tfl', 'name' => $name]);
            if ((int) $checkStmt->fetchColumn() > 0) {
                $tflPresent++;
            }
        }

        $taflPresent = 0;
        foreach ($taflNames as $name) {
            $checkStmt->execute(['source' => 'tafl', 'name' => $name]);
            if ((int) $checkStmt->fetchColumn() > 0) {
                $taflPresent++;
            }
        }

        return [
            'tfl_expected'  => count($tflNames),
            'tfl_present'   => $tflPresent,
            'tafl_expected' => count($taflNames),
            'tafl_present'  => $taflPresent,
        ];
    }

    /**
     * Inserts any missing TFL and optionally TAFL seed rows.
     *
     * Safe to call repeatedly — never overwrites existing rows.
     *
     * @return int Number of rows inserted
     */
    public function backfillSeeds(bool $includeTfl = true, bool $includeTafl = true): int
    {
        $seeds = [];
        if ($includeTfl) {
            $seeds['tfl'] = \Luster\Support\CategorySeedCatalog::tfl();
        }
        if ($includeTafl) {
            $seeds['tafl'] = \Luster\Support\CategorySeedCatalog::tafl();
        }

        $inserted = 0;
        foreach ($seeds as $source => $names) {
            foreach ($names as $name) {
                $slug  = $this->uniqueSlug($this->slugify($name), $source);
                $stmt  = $this->db->prepare(
                    'INSERT IGNORE INTO lstr_categories (name, slug, source) VALUES (:name, :slug, :source)'
                );
                $stmt->execute(['name' => $name, 'slug' => $slug, 'source' => $source]);
                $inserted += $stmt->rowCount();
            }
        }

        return $inserted;
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Converts a display name to a URL-safe slug.
     * Non-alphanumeric runs become hyphens; result is lowercase, max 90 chars.
     *
     * Public and static so import sources and other write paths can generate
     * slugs without needing a full CategoryRepo instance.
     */
    public static function slugify(string $name): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $name), '-'));
        return substr($slug !== '' ? $slug : 'category', 0, 90);
    }

    /**
     * Returns a slug that is unique within (source, slug) for lstr_categories.
     * If $base already exists for the source, appends -2, -3, … until unique.
     * Pass $excludeId to allow the current row's own slug without triggering a collision.
     */
    private function uniqueSlug(string $base, string $source, ?int $excludeId = null): string
    {
        try {
            $sql    = 'SELECT id FROM lstr_categories WHERE source = :source AND slug = :slug';
            $params = ['source' => $source, 'slug' => $base];
            if ($excludeId !== null) {
                $sql .= ' AND id != :eid';
                $params['eid'] = $excludeId;
            }
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            if ($stmt->fetch() === false) {
                return $base;
            }
            $n = 2;
            do {
                $candidate    = $base . '-' . $n++;
                $params['slug'] = $candidate;
                $stmt->execute($params);
            } while ($stmt->fetch() !== false);
            return $candidate;
        } catch (\Throwable) {
            // slug column may not exist pre-migration-55; return base as best-effort
            return $base;
        }
    }

    /**
     * Returns true when $parentId refers to a top-level category (parent_id IS NULL).
     * This enforces max depth = 1.
     */
    private function isValidParent(int $parentId): bool
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT parent_id FROM lstr_categories WHERE id = :id LIMIT 1'
            );
            $stmt->execute(['id' => $parentId]);
            $row = $stmt->fetch();
            if ($row === false) {
                return false;  // proposed parent doesn't exist
            }
            return $row['parent_id'] === null;
        } catch (\Throwable) {
            return true;  // column absent pre-migration; allow
        }
    }
}
