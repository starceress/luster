<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Manages code categories and their assignments to individual codes. Code
 categories are per-listing, support one level of parent/child nesting,
 and are linked to codes through the lstr_code_category_map junction table
 (many-to-many). Provides CRUD for categories and set/add/get operations
 for the code-to-category assignments.
*/

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Database;

/**
 * Data access for lstr_code_categories and lstr_code_category_map.
 *
 * Categories are per-listing, support one level of parent/child nesting,
 * and have a many-to-many relationship with codes via the junction table.
 * Requires Migration 31 to run before use.
 */
final class CodeCategoriesRepo
{
    public function __construct(private readonly Database $db) {}

    // -----------------------------------------------------------------------
    // Categories
    // -----------------------------------------------------------------------

    /**
     * Returns all categories for a listing ordered by sort_order, id.
     *
     * @return object[]
     */
    public function getByListing(?int $listingId): array
    {
        $fidCond = $listingId === null ? 'fanlisting_id IS NULL' : 'fanlisting_id = :fid';
        $stmt = $this->db->prepare(
            "SELECT id, fanlisting_id, name, parent_id, sort_order
             FROM lstr_code_categories
             WHERE {$fidCond}
             ORDER BY sort_order ASC, id ASC"
        );
        $stmt->execute($listingId === null ? [] : ['fid' => $listingId]);

        return array_map(static fn(array $r) => (object) [
            'id'        => (int)    $r['id'],
            'listingId' => (int)    $r['fanlisting_id'],
            'name'      => (string) $r['name'],
            'parentId'  => $r['parent_id'] !== null ? (int) $r['parent_id'] : null,
            'sortOrder' => (int)    $r['sort_order'],
        ], $stmt->fetchAll());
    }

    /**
     * Returns a single category by ID, or null if not found.
     */
    public function getById(int $id): ?object
    {
        $stmt = $this->db->prepare(
            'SELECT id, fanlisting_id, name, parent_id, sort_order
             FROM lstr_code_categories WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return (object) [
            'id'        => (int)    $row['id'],
            'listingId' => (int)    $row['fanlisting_id'],
            'name'      => (string) $row['name'],
            'parentId'  => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            'sortOrder' => (int)    $row['sort_order'],
        ];
    }

    /**
     * Returns the highest sort_order value for a listing (0 if none).
     */
    public function getMaxSortOrder(int $listingId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(sort_order), 0) FROM lstr_code_categories WHERE fanlisting_id = :fid'
        );
        $stmt->execute(['fid' => $listingId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Returns how many codes are assigned to a category.
     */
    public function countCodesForCategory(int $categoryId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM lstr_code_category_map WHERE category_id = :cid'
        );
        $stmt->execute(['cid' => $categoryId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Inserts a new code category for a listing and returns its new primary key.
     *
     * Set $parentId to null for a top-level category. $sortOrder controls display
     * order within the listing; use getMaxSortOrder() to append at the end.
     */
    public function insert(int $listingId, string $name, ?int $parentId, int $sortOrder): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO lstr_code_categories (fanlisting_id, name, parent_id, sort_order)
             VALUES (:fid, :name, :parent_id, :sort)'
        );
        $stmt->execute([
            'fid'       => $listingId,
            'name'      => $name,
            'parent_id' => $parentId,
            'sort'      => $sortOrder,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Updates the name and parent of an existing code category.
     *
     * Set $parentId to null to make the category top-level.
     */
    public function update(int $id, string $name, ?int $parentId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE lstr_code_categories SET name = :name, parent_id = :parent_id WHERE id = :id'
        );
        $stmt->execute(['name' => $name, 'parent_id' => $parentId, 'id' => $id]);
    }

    /**
     * Deletes a category. Junction table rows are removed via ON DELETE CASCADE.
     * Child categories will have parent_id set to NULL via ON DELETE SET NULL.
     */
    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM lstr_code_categories WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    // -----------------------------------------------------------------------
    // Category assignments (junction table)
    // -----------------------------------------------------------------------

    /**
     * Returns a map of code_id → [category_id, ...] for all codes of a listing.
     * Used to avoid N+1 queries when loading the codes page.
     *
     * @return array<int, int[]>
     */
    public function getCategoryMapForListing(?int $listingId): array
    {
        $fidCond = $listingId === null ? 'c.fanlisting_id IS NULL' : 'c.fanlisting_id = :fid';
        $stmt = $this->db->prepare(
            "SELECT m.code_id, m.category_id
             FROM lstr_code_category_map m
             INNER JOIN lstr_codes c ON c.id = m.code_id
             WHERE {$fidCond}"
        );
        $stmt->execute($listingId === null ? [] : ['fid' => $listingId]);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['code_id']][] = (int) $row['category_id'];
        }
        return $map;
    }

    /**
     * Returns the category IDs assigned to a single code.
     *
     * @return int[]
     */
    public function getCategoriesForCode(int $codeId): array
    {
        $stmt = $this->db->prepare(
            'SELECT category_id FROM lstr_code_category_map WHERE code_id = :code_id ORDER BY category_id ASC'
        );
        $stmt->execute(['code_id' => $codeId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Additively assigns categories to a code without removing existing assignments.
     * Silently skips any IDs already assigned (INSERT IGNORE semantics).
     *
     * @param int[] $categoryIds
     */
    public function addCategories(int $codeId, array $categoryIds): void
    {
        if (empty($categoryIds)) {
            return;
        }

        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO lstr_code_category_map (code_id, category_id) VALUES (:code_id, :cat_id)'
        );
        foreach ($categoryIds as $catId) {
            $stmt->execute(['code_id' => $codeId, 'cat_id' => (int) $catId]);
        }
    }

    /**
     * Replaces all category assignments for a code with the given IDs.
     * Passing an empty array clears all assignments.
     *
     * @param int[] $categoryIds
     */
    public function setCategories(int $codeId, array $categoryIds): void
    {
        $this->db->prepare('DELETE FROM lstr_code_category_map WHERE code_id = :code_id')
            ->execute(['code_id' => $codeId]);

        if (empty($categoryIds)) {
            return;
        }

        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO lstr_code_category_map (code_id, category_id) VALUES (:code_id, :cat_id)'
        );
        foreach ($categoryIds as $catId) {
            $stmt->execute(['code_id' => $codeId, 'cat_id' => (int) $catId]);
        }
    }
}
