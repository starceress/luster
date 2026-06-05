<?php

declare(strict_types=1);

namespace Luster\Addons\Wishlist;

use Luster\Core\Database;

final class WishlistRepo
{
    public function __construct(private readonly Database $db) {}

    /**
     * Returns wishlist entries ordered by sort_order ASC, id ASC.
     * Optionally filtered by type, active status, category, and a search term.
     *
     * @return Wishlist[]
     */
    public function getAll(
        ?string $type       = null,
        bool    $activeOnly = false,
        string  $search     = '',
        string  $category   = '',
        string  $sort       = 'sort_order',
        string  $dir        = 'asc',
    ): array {
        $where  = [];
        $params = [];

        if ($type !== null && $type !== 'all') {
            $where[]        = 'type = :type';
            $params['type'] = $type;
        }

        if ($activeOnly) {
            $where[] = 'active = 1';
        }

        if ($search !== '') {
            $where[]           = '(subject LIKE :search OR description LIKE :search2)';
            $params['search']  = '%' . $search . '%';
            $params['search2'] = '%' . $search . '%';
        }

        if ($category !== '') {
            $where[]            = 'category = :category';
            $params['category'] = $category;
        }

        $allowedCols = ['subject', 'category', 'type', 'added_at', 'sort_order'];
        $sortCol     = in_array($sort, $allowedCols, true) ? $sort : 'subject';
        $sortDir     = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';

        $sql = 'SELECT * FROM lstr_wishlist';
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= " ORDER BY {$sortCol} {$sortDir}, id ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return array_map(
            static fn(array $row) => Wishlist::fromRow($row),
            $stmt->fetchAll(),
        );
    }

    /**
     * @return array{items: Wishlist[], total: int}
     */
    public function paginate(
        ?string $type       = null,
        bool    $activeOnly = false,
        string  $search     = '',
        string  $category   = '',
        string  $sort       = 'subject',
        string  $dir        = 'asc',
        int     $perPage    = 10,
        int     $page       = 1,
        ?bool   $active     = null,
    ): array {
        $where  = [];
        $params = [];

        if ($type !== null && $type !== 'all') {
            $where[]        = 'type = :type';
            $params['type'] = $type;
        }
        if ($activeOnly) {
            $where[] = 'active = 1';
        } elseif ($active !== null) {
            $where[]          = 'active = :active';
            $params['active'] = $active ? 1 : 0;
        }
        if ($search !== '') {
            $where[]           = '(subject LIKE :search OR description LIKE :search2)';
            $params['search']  = '%' . $search . '%';
            $params['search2'] = '%' . $search . '%';
        }
        if ($category !== '') {
            $where[]            = 'category = :category';
            $params['category'] = $category;
        }

        $allowedCols = ['subject', 'category', 'type', 'added_at', 'sort_order'];
        $sortCol     = in_array($sort, $allowedCols, true) ? $sort : 'subject';
        $sortDir     = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';

        $whereClause = !empty($where) ? ' WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM lstr_wishlist{$whereClause}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = max(0, ($page - 1) * $perPage);

        $stmt = $this->db->prepare(
            "SELECT * FROM lstr_wishlist{$whereClause} ORDER BY {$sortCol} {$sortDir}, id ASC LIMIT :lim OFFSET :off"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset,  \PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => array_map(static fn(array $row) => Wishlist::fromRow($row), $stmt->fetchAll()),
            'total' => $total,
        ];
    }

    public function getById(int $id): ?Wishlist
    {
        $stmt = $this->db->prepare('SELECT * FROM lstr_wishlist WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row !== false ? Wishlist::fromRow($row) : null;
    }

    public function countAll(?string $type = null, bool $activeOnly = false): int
    {
        $where  = [];
        $params = [];

        if ($type !== null && $type !== 'all') {
            $where[]        = 'type = :type';
            $params['type'] = $type;
        }

        if ($activeOnly) {
            $where[] = 'active = 1';
        }

        $sql = 'SELECT COUNT(*) FROM lstr_wishlist';
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Returns counts per type as [type => count] for all four types.
     *
     * @return array<string, int>
     */
    public function countByType(): array
    {
        $stmt = $this->db->prepare(
            "SELECT type, COUNT(*) AS cnt FROM lstr_wishlist GROUP BY type"
        );
        $stmt->execute();

        $counts = ['wanted' => 0, 'top' => 0, 'granted' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $counts[(string) $row['type']] = (int) $row['cnt'];
        }

        return $counts;
    }

    public function countHidden(): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM lstr_wishlist WHERE active = 0');
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Returns all distinct non-empty category values, sorted alphabetically.
     *
     * @return string[]
     */
    public function getCategories(): array
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT category FROM lstr_wishlist
              WHERE category != ''
              ORDER BY category ASC"
        );
        $stmt->execute();

        return array_column($stmt->fetchAll(), 'category');
    }

    /**
     * Returns a map of category string → entry count.
     *
     * @return array<string, int>
     */
    public function getCategoryCounts(): array
    {
        $stmt = $this->db->prepare(
            "SELECT category, COUNT(*) AS cnt
             FROM lstr_wishlist
             WHERE category != ''
             GROUP BY category"
        );
        $stmt->execute();

        $counts = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $counts[(string) $row['category']] = (int) $row['cnt'];
        }

        return $counts;
    }

    public function insert(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO lstr_wishlist
                 (subject, url, image, category, type, description, added_at, sort_order, active)
             VALUES
                 (:subject, :url, :image, :category, :type, :description, :added_at, :sort_order, :active)'
        );
        $stmt->execute([
            'subject'     => (string) ($data['subject']     ?? ''),
            'url'         => (string) ($data['url']         ?? ''),
            'image'       => isset($data['image']) && $data['image'] !== '' ? (string) $data['image'] : null,
            'category'    => (string) ($data['category']    ?? ''),
            'type'        => (string) ($data['type']        ?? 'wanted'),
            'description' => isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
            'added_at'    => isset($data['added_at']) && $data['added_at'] !== '' ? (string) $data['added_at'] : date('Y-m-d'),
            'sort_order'  => (int)    ($data['sort_order']  ?? 0),
            'active'      => (int)    ($data['active']      ?? true),
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE lstr_wishlist SET
                 subject     = :subject,
                 url         = :url,
                 image       = :image,
                 category    = :category,
                 type        = :type,
                 description = :description,
                 added_at    = :added_at,
                 sort_order  = :sort_order,
                 active      = :active
             WHERE id = :id'
        );
        $stmt->execute([
            'id'          => $id,
            'subject'     => (string) ($data['subject']     ?? ''),
            'url'         => (string) ($data['url']         ?? ''),
            'image'       => isset($data['image']) && $data['image'] !== '' ? (string) $data['image'] : null,
            'category'    => (string) ($data['category']    ?? ''),
            'type'        => (string) ($data['type']        ?? 'wanted'),
            'description' => isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
            'added_at'    => isset($data['added_at']) && $data['added_at'] !== '' ? (string) $data['added_at'] : null,
            'sort_order'  => (int)    ($data['sort_order']  ?? 0),
            'active'      => (int)    ($data['active']      ?? true),
        ]);
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM lstr_wishlist WHERE id = :id')->execute(['id' => $id]);
    }

    public function setType(int $id, string $type): void
    {
        $this->db->prepare('UPDATE lstr_wishlist SET type = :type WHERE id = :id')
                 ->execute(['type' => $type, 'id' => $id]);
    }

    public function toggleActive(int $id): bool
    {
        $this->db->prepare(
            'UPDATE lstr_wishlist SET active = 1 - active WHERE id = :id'
        )->execute(['id' => $id]);

        $entry = $this->getById($id);
        return $entry !== null && $entry->active;
    }

    /**
     * Returns all IDs of the given type in sort_order ASC, id ASC order.
     *
     * @return int[]
     */
    public function getAllIdsForType(string $type): array
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM lstr_wishlist WHERE type = :type ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['type' => $type]);

        return array_map('intval', array_column($stmt->fetchAll(), 'id'));
    }

    /** @param int[] $ids */
    public function reorder(array $ids): void
    {
        $stmt = $this->db->prepare(
            'UPDATE lstr_wishlist SET sort_order = :order WHERE id = :id'
        );
        foreach ($ids as $order => $id) {
            $stmt->execute(['order' => $order, 'id' => (int) $id]);
        }
    }
}
