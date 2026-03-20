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
     * Returns all categories, parents before children, then alphabetically.
     *
     * @return Category[]
     */
    public function getAll(): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_categories ORDER BY parent_id ASC, name ASC'
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

    public function paginate(int $perPage, int $page, string $source = '', string $search = '', string $sort = 'source', string $dir = 'asc'): array
    {
        $where  = '1=1';
        $params = [];

        if ($source !== '') {
            $where            .= ' AND source = :source';
            $params['source']  = $source;
        }

        if ($search !== '') {
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
        $stmt = $this->db->pdo()->query(
            "SELECT DISTINCT source FROM lstr_categories WHERE source NOT IN ('tfl', 'tafl') ORDER BY source"
        );

        return $stmt ? $stmt->fetchAll(\PDO::FETCH_COLUMN) : [];
    }

    public function update(int $id, string $name, ?int $parentId, string $source = 'custom'): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE lstr_categories SET name = :name, parent_id = :pid, source = :source WHERE id = :id'
        );
        $stmt->execute(['name' => $name, 'pid' => $parentId, 'source' => $source, 'id' => $id]);
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
        $stmt = $this->db->prepare(
            "SELECT DISTINCT name FROM lstr_categories
             WHERE source = 'custom'
             ORDER BY name ASC"
        );
        $stmt->execute();

        return array_column($stmt->fetchAll(), 'name');
    }

    /**
     * Inserts a custom network name if it does not already exist.
     */
    public function addCustomNetwork(string $name): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO lstr_categories (name, source, parent_id)
             SELECT :name, 'custom', NULL
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
    public function add(string $name, ?int $parentId, string $source): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO lstr_categories (name, parent_id, source)
             VALUES (:name, :parent_id, :source)'
        );
        $stmt->execute([
            'name'      => $name,
            'parent_id' => $parentId,
            'source'    => $source,
        ]);

        return (int) $this->db->lastInsertId();
    }

    // -----------------------------------------------------------------------
    // Hierarchy helpers
    // -----------------------------------------------------------------------

    /**
     * Returns all top-level categories (parent_id IS NULL), ordered by name.
     *
     * @return Category[]
     */
    public function getTopLevel(): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_categories WHERE parent_id IS NULL ORDER BY name ASC'
        );
        $stmt->execute();

        return array_map(
            static fn(array $r) => Category::fromRow($r),
            $stmt->fetchAll(),
        );
    }

    /**
     * Returns all direct children of a given parent, ordered by name.
     *
     * @return Category[]
     */
    public function getChildren(int $parentId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_categories WHERE parent_id = :pid ORDER BY name ASC'
        );
        $stmt->execute(['pid' => $parentId]);

        return array_map(
            static fn(array $r) => Category::fromRow($r),
            $stmt->fetchAll(),
        );
    }

    /**
     * Returns true if the given category has at least one child.
     */
    public function hasChildren(int $id): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM lstr_categories WHERE parent_id = :id'
        );
        $stmt->execute(['id' => $id]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Deletes a category by id.
     *
     * Child categories are promoted to top-level (parent_id set to NULL).
     * Any lstr_joined rows that reference the deleted category are also nulled.
     */
    public function delete(int $id): void
    {
        // Promote children to top-level
        $stmtPromote = $this->db->prepare(
            'UPDATE lstr_categories SET parent_id = NULL WHERE parent_id = :id'
        );
        $stmtPromote->execute(['id' => $id]);

        // Unlink joined listings referencing this category
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
            'INSERT INTO lstr_categories (name, parent_id, source) VALUES (:name, NULL, :source)'
        );
        $stmt->execute(['name' => $name, 'source' => $source]);
        return (int) $this->db->lastInsertId();
    }
}
