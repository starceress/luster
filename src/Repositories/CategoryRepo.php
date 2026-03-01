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
    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_categories WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
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

    /**
     * Deletes a category by id and NULLs out any lstr_joined rows that
     * reference it, preserving the joined fanlisting records.
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
}
