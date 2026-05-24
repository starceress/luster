<?php

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Database;
use Luster\Models\Category;

/**
 * Data access for the lstr_categories table.
 *
 * Requires lstr_categories to exist — run public/migrate.php (Migration 7) first.
 * Migration 7 also adds category_id to lstr_joined.
 */
final class CategoryRepo
{
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
     * @return array<string, mixed>|null
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

    public function getCustomSources(): array
    {
        return $this->getAllCustomNetworkNames();
    }

    public function update(int $id, string $name, string $source = 'custom'): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE lstr_categories SET name = :name, source = :source WHERE id = :id'
        );
        $stmt->execute(['name' => $name, 'source' => $source, 'id' => $id]);
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
        $stmt->execute(['source' => $source]);

        return array_map(
            static fn(array $r) => ['id' => (int) $r['id'], 'name' => (string) $r['name']],
            $stmt->fetchAll(),
        );
    }

    /**
     * Returns all distinct custom network names, ordered alphabetically.
     *
     * @return string[]
     */
    public function getCustomNetworks(): array
    {
        return $this->getAllCustomNetworkNames();
    }

    /**
     * Returns every custom network name regardless of how it was created.
     *
     * Two storage patterns exist in lstr_categories:
     *   A) Network registration rows: source='custom', name=<network name>
     *      (created by the Categories admin page and JoinedController)
     *   B) Category rows: source=<network name>, name=<category name>
     *      (created by findOrCreate in ListingController)
     *
     * Both are unified here so callers always see the complete list.
     *
     * @return string[]
     */
    private function getAllCustomNetworkNames(): array
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT source AS net FROM lstr_categories
              WHERE source NOT IN ('tfl', 'tafl', 'custom')
              ORDER BY source ASC"
        );
        $stmt->execute();

        return array_column($stmt->fetchAll(), 'net');
    }

    /**
     * Returns all categories grouped by their custom network name.
     * Result: ['NetworkName' => [['id' => int, 'name' => string], ...], ...]
     * Only networks that have actual category rows (source = network name) are included.
     *
     * @return array<string, array<int, array{id: int, name: string}>>
     */
    public function getCustomCats(): array
    {
        $stmt = $this->db->prepare(
            "SELECT source, id, name FROM lstr_categories
             WHERE source NOT IN ('tfl', 'tafl', 'custom')
             ORDER BY source ASC, name ASC"
        );
        $stmt->execute();

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $net = (string) $row['source'];
            $result[$net][] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
        }
        return $result;
    }

    /**
     * Inserts a custom network name if it does not already exist.
     */
    public function addCustomNetwork(string $name): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO lstr_categories (name, source)
             SELECT :name, 'custom'
             FROM DUAL
             WHERE NOT EXISTS (
                 SELECT 1 FROM lstr_categories
                 WHERE name = :name AND source = 'custom'
             )"
        );
        $stmt->execute(['name' => $name]);
    }

    // -----------------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------------

    /**
     * Inserts a new category and returns its auto-incremented id.
     */
    public function add(string $name, string $source): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO lstr_categories (name, source) VALUES (:name, :source)'
        );
        $stmt->execute(['name' => $name, 'source' => $source]);

        return (int) $this->db->lastInsertId();
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

    public function findBySourceAndName(string $source, string $name): ?object {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_categories WHERE source=:source AND name=:name LIMIT 1'
        );
        $stmt->execute(['source'=>$source,'name'=>$name]);
        $row = $stmt->fetch();
        return $row ? Category::fromRow((array)$row) : null;
    }

    public function findOrCreate(string $source, string $name): int {
        $existing = $this->findBySourceAndName($source, $name);
        if ($existing !== null) {
            return $existing->id;
        }
        $stmt = $this->db->prepare(
            'INSERT INTO lstr_categories (name, source) VALUES (:name, :source)'
        );
        $stmt->execute(['name' => $name, 'source' => $source]);
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

        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO lstr_categories (name, source) VALUES (:name, :source)'
        );

        $inserted = 0;
        foreach ($seeds as $source => $names) {
            foreach ($names as $name) {
                $stmt->execute(['name' => $name, 'source' => $source]);
                $inserted += $stmt->rowCount();
            }
        }

        return $inserted;
    }
}
