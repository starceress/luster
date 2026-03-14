<?php

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Database;
use Luster\Models\Affiliate;

/**
 * Data access for the lstr_affiliates table.
 *
 * Affiliates are linked to a specific listing via listing_id, which
 * corresponds to enth_listings.id. All queries use prepared statements.
 */
final class AffiliateRepo
{
    public function __construct(private readonly Database $db) {}

    // -----------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------

    /**
     * Returns a paginated page of all affiliates, sorted by sort_order then name.
     *
     * @return array{items: Affiliate[], total: int, pages: int}
     */
    public function getAll(int $perPage = 10, int $page = 1): array
    {
        $offset = ($page - 1) * $perPage;

        $countStmt = $this->db->prepare('SELECT COUNT(*) FROM lstr_affiliates');
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_affiliates ORDER BY sort_order ASC, name ASC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':limit',  $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  \PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => array_map(static fn(array $r) => Affiliate::fromRow($r), $stmt->fetchAll()),
            'total' => $total,
            'pages' => $total > 0 ? (int) ceil($total / $perPage) : 1,
        ];
    }

    /**
     * Returns a single affiliate by primary key, or null if not found.
     */
    public function getById(int $id): ?Affiliate
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_affiliates WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row !== false ? Affiliate::fromRow($row) : null;
    }

    /**
     * Returns a paginated page of collective affiliates (fanlisting_id IS NULL),
     * sorted by sort_order then name.
     *
     * @return array{items: Affiliate[], total: int, pages: int}
     */
    public function getCollective(int $perPage = 10, int $page = 1): array
    {
        $offset = ($page - 1) * $perPage;

        $countStmt = $this->db->prepare(
            'SELECT COUNT(*) FROM lstr_affiliates WHERE fanlisting_id IS NULL'
        );
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_affiliates
             WHERE fanlisting_id IS NULL
             ORDER BY sort_order ASC, name ASC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':limit',  $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  \PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => array_map(static fn(array $r) => Affiliate::fromRow($r), $stmt->fetchAll()),
            'total' => $total,
            'pages' => $total > 0 ? (int) ceil($total / $perPage) : 1,
        ];
    }

    /**
     * Returns a paginated page of affiliates for a specific fanlisting,
     * sorted by sort_order then name.
     *
     * @return array{items: Affiliate[], total: int, pages: int}
     */
    public function getByListing(int $listingId, int $perPage = 10, int $page = 1): array
    {
        $offset = ($page - 1) * $perPage;

        $countStmt = $this->db->prepare(
            'SELECT COUNT(*) FROM lstr_affiliates WHERE fanlisting_id = :fid'
        );
        $countStmt->execute(['fid' => $listingId]);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_affiliates
             WHERE fanlisting_id = :fid
             ORDER BY sort_order ASC, name ASC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':fid',    $listingId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit',  $perPage,      \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,       \PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => array_map(static fn(array $r) => Affiliate::fromRow($r), $stmt->fetchAll()),
            'total' => $total,
            'pages' => $total > 0 ? (int) ceil($total / $perPage) : 1,
        ];
    }

    /**
     * Returns a paginated page of active affiliates for a specific fanlisting
     * (active = 1), sorted by sort_order then name.
     *
     * @return array{items: Affiliate[], total: int, pages: int}
     */
    public function getActiveByListing(int $listingId, int $perPage = 50, int $page = 1): array
    {
        $offset = ($page - 1) * $perPage;

        $countStmt = $this->db->prepare(
            'SELECT COUNT(*) FROM lstr_affiliates WHERE fanlisting_id = :fid AND active = 1'
        );
        $countStmt->execute(['fid' => $listingId]);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_affiliates
             WHERE fanlisting_id = :fid AND active = 1
             ORDER BY sort_order ASC, name ASC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':fid',    $listingId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit',  $perPage,      \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,       \PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => array_map(static fn(array $r) => Affiliate::fromRow($r), $stmt->fetchAll()),
            'total' => $total,
            'pages' => $total > 0 ? (int) ceil($total / $perPage) : 1,
        ];
    }

    /**
     * Returns a paginated page of active collective affiliates
     * (fanlisting_id IS NULL AND active = 1), sorted by sort_order then name.
     *
     * @return array{items: Affiliate[], total: int, pages: int}
     */
    public function getActiveCollective(int $perPage = 50, int $page = 1): array
    {
        $offset = ($page - 1) * $perPage;

        $countStmt = $this->db->prepare(
            'SELECT COUNT(*) FROM lstr_affiliates WHERE fanlisting_id IS NULL AND active = 1'
        );
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_affiliates
             WHERE fanlisting_id IS NULL AND active = 1
             ORDER BY sort_order ASC, name ASC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':limit',  $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  \PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => array_map(static fn(array $r) => Affiliate::fromRow($r), $stmt->fetchAll()),
            'total' => $total,
            'pages' => $total > 0 ? (int) ceil($total / $perPage) : 1,
        ];
    }

    /**
     * Full-text search across name, url, and email, with optional listing scope.
     *
     * $listingId === null  → only collective affiliates (fanlisting_id IS NULL)
     * $listingId === -1   → all affiliates regardless of fanlisting
     * $listingId > 0      → only that specific fanlisting
     *
     * @return array{items: Affiliate[], total: int, pages: int}
     */
    public function search(string $q, ?int $listingId, int $perPage = 10, int $page = 1): array
    {
        $offset  = ($page - 1) * $perPage;
        $pattern = '%' . $q . '%';

        // Build the listing clause — hardcoded SQL fragment, no user data interpolated
        if ($listingId === null) {
            $fidClause = ' AND fanlisting_id IS NULL';
        } elseif ($listingId === -1) {
            $fidClause = '';
        } else {
            $fidClause = ' AND fanlisting_id = :fid';
        }

        $where = '(name LIKE :q1 OR url LIKE :q2 OR email LIKE :q3)' . $fidClause;

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM lstr_affiliates WHERE {$where}");
        $countStmt->bindValue(':q1', $pattern, \PDO::PARAM_STR);
        $countStmt->bindValue(':q2', $pattern, \PDO::PARAM_STR);
        $countStmt->bindValue(':q3', $pattern, \PDO::PARAM_STR);
        if ($listingId !== null && $listingId !== -1) {
            $countStmt->bindValue(':fid', $listingId, \PDO::PARAM_INT);
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->prepare(
            "SELECT * FROM lstr_affiliates
             WHERE {$where}
             ORDER BY sort_order ASC, name ASC
             LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue(':q1',     $pattern, \PDO::PARAM_STR);
        $stmt->bindValue(':q2',     $pattern, \PDO::PARAM_STR);
        $stmt->bindValue(':q3',     $pattern, \PDO::PARAM_STR);
        if ($listingId !== null && $listingId !== -1) {
            $stmt->bindValue(':fid', $listingId, \PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit',  $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  \PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => array_map(static fn(array $r) => Affiliate::fromRow($r), $stmt->fetchAll()),
            'total' => $total,
            'pages' => $total > 0 ? (int) ceil($total / $perPage) : 1,
        ];
    }

    // -----------------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------------

    /**
     * Inserts a new affiliate and returns the new primary key.
     *
     * Required keys: name, url.
     * Optional keys: fanlisting_id (null = collective), email, image, sort_order.
     *
     * @param array{fanlisting_id?: ?int, name: string, url: string, email?: ?string, image?: ?string, sort_order?: int} $data
     */
    public function insert(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO lstr_affiliates
                (fanlisting_id, name, url, email, image, sort_order)
             VALUES
                (:fanlisting_id, :name, :url, :email, :image, :sort_order)'
        );

        $fid = isset($data['fanlisting_id']) && $data['fanlisting_id'] !== null
            ? (int) $data['fanlisting_id']
            : null;

        $stmt->execute([
            'fanlisting_id' => $fid,
            'name'          => (string) $data['name'],
            'url'           => (string) $data['url'],
            'email'         => isset($data['email'])      ? (string) $data['email']      : null,
            'image'         => isset($data['image'])      ? (string) $data['image']      : null,
            'sort_order'    => isset($data['sort_order']) ? (int)    $data['sort_order'] : 0,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Replaces all editable fields on an existing affiliate row.
     *
     * All five editable fields (name, url, email, image, sort_order) must be
     * present in $data even if unchanged, so the row stays consistent.
     *
     * @param array{name: string, url: string, email: ?string, image: ?string, sort_order: int} $data
     */
    public function update(int $id, array $data): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE lstr_affiliates
             SET name       = :name,
                 url        = :url,
                 email      = :email,
                 image      = :image,
                 sort_order = :sort_order
             WHERE id = :id'
        );

        return $stmt->execute([
            'name'       => (string) $data['name'],
            'url'        => (string) $data['url'],
            'email'      => isset($data['email']) ? (string) $data['email'] : null,
            'image'      => isset($data['image']) ? (string) $data['image'] : null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'id'         => $id,
        ]);
    }

    /**
     * Toggles the active flag on an affiliate.
     * Requires the `active` column (run public/migrate.php first).
     */
    public function toggle(int $id): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE lstr_affiliates SET active = 1 - active WHERE id = :id'
        );

        return $stmt->execute(['id' => $id]);
    }

    /**
     * Deletes an affiliate by primary key.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM lstr_affiliates WHERE id = :id'
        );

        return $stmt->execute(['id' => $id]);
    }
}
