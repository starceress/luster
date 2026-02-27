<?php

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Database;
use Luster\Models\JoinedFanlisting;

/**
 * Data access for the lstr_joined table.
 *
 * Tracks fanlistings that the site owner has joined (as a member), grouped by
 * category. All queries use prepared statements.
 */
final class JoinedFanlistingRepo
{
    public function __construct(private readonly Database $db) {}

    // -----------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------

    /**
     * Returns all joined fanlistings, sorted by category then sort_order then name.
     *
     * @return JoinedFanlisting[]
     */
    public function getAll(): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_joined
             ORDER BY category ASC, sort_order ASC, name ASC'
        );
        $stmt->execute();

        return array_map(static fn(array $r) => JoinedFanlisting::fromRow($r), $stmt->fetchAll());
    }

    /**
     * Returns joined fanlistings filtered by category.
     *
     * @return JoinedFanlisting[]
     */
    public function getByCategory(string $category): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_joined
             WHERE category = :category
             ORDER BY sort_order ASC, name ASC'
        );
        $stmt->execute(['category' => $category]);

        return array_map(static fn(array $r) => JoinedFanlisting::fromRow($r), $stmt->fetchAll());
    }

    /**
     * Returns all distinct, non-empty category values sorted alphabetically.
     *
     * @return string[]
     */
    public function getCategories(): array
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT category FROM lstr_joined
             WHERE category != ''
             ORDER BY category ASC"
        );
        $stmt->execute();

        return array_column($stmt->fetchAll(), 'category');
    }

    /**
     * Returns a single joined fanlisting by primary key, or null if not found.
     */
    public function getById(int $id): ?JoinedFanlisting
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_joined WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row !== false ? JoinedFanlisting::fromRow($row) : null;
    }

    // -----------------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------------

    /**
     * Inserts a new joined fanlisting and returns the new primary key.
     *
     * @param array{name: string, url: string, image?: ?string, category?: string, date_joined?: ?string, active?: bool, sort_order?: int} $data
     */
    public function insert(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO lstr_joined
                (name, url, image, category, date_joined, active, sort_order)
             VALUES
                (:name, :url, :image, :category, :date_joined, :active, :sort_order)'
        );

        $stmt->execute([
            'name'        => (string) $data['name'],
            'url'         => (string) $data['url'],
            'image'       => isset($data['image'])       && $data['image']       !== null ? (string) $data['image']   : null,
            'category'    => (string) ($data['category']    ?? ''),
            'date_joined' => isset($data['date_joined']) && $data['date_joined'] !== null ? (string) $data['date_joined'] : null,
            'active'      => isset($data['active'])      ? (int) (bool) $data['active'] : 1,
            'sort_order'  => (int) ($data['sort_order']  ?? 0),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Replaces all editable fields on an existing row.
     *
     * @param array{name: string, url: string, image: ?string, category: string, date_joined: ?string, active: bool, sort_order: int} $data
     */
    public function update(int $id, array $data): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE lstr_joined
             SET name        = :name,
                 url         = :url,
                 image       = :image,
                 category    = :category,
                 date_joined = :date_joined,
                 active      = :active,
                 sort_order  = :sort_order
             WHERE id = :id'
        );

        return $stmt->execute([
            'name'        => (string) $data['name'],
            'url'         => (string) $data['url'],
            'image'       => isset($data['image'])       && $data['image']       !== null ? (string) $data['image']       : null,
            'category'    => (string) ($data['category']    ?? ''),
            'date_joined' => isset($data['date_joined']) && $data['date_joined'] !== null ? (string) $data['date_joined'] : null,
            'active'      => (int) (bool) ($data['active'] ?? true),
            'sort_order'  => (int) ($data['sort_order']  ?? 0),
            'id'          => $id,
        ]);
    }

    /**
     * Deletes a joined fanlisting by primary key.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM lstr_joined WHERE id = :id'
        );

        return $stmt->execute(['id' => $id]);
    }

    /**
     * Toggles the active flag.
     */
    public function toggleActive(int $id): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE lstr_joined SET active = 1 - active WHERE id = :id'
        );

        return $stmt->execute(['id' => $id]);
    }
}
