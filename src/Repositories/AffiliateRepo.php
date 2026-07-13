<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Manages affiliates in lstr_affiliates. Affiliates can be scoped to a
 specific listing or to the collective (fanlisting_id IS NULL). Supports
 a full pending/approved/rejected workflow for affiliate applications,
 paginated admin views with scope and status filtering, drag-and-drop
 sort ordering, link checking with redirect-URL application, reciprocal
 link verification, bulk email recipient queries, and duplicate detection
 for incoming applications.
*/

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Database;
use Luster\Models\Affiliate;

/**
 * Data access for the lstr_affiliates table.
 *
 * An affiliate can be scoped to a specific listing (fanlisting_id = N) or
 * to the collective as a whole (fanlisting_id IS NULL). Pending affiliates
 * (pending = 1) are affiliate applications awaiting admin review; approved
 * affiliates have pending = 0. The rejected state is a soft-delete: rejected
 * rows remain with pending = 2 so the admin can distinguish "never applied"
 * from "applied and declined".
 *
 * All queries use PDO prepared statements; no raw values are ever interpolated
 * into SQL except pre-validated orderBy fragments built by the controller.
 */
final class AffiliateRepo
{
    public function __construct(private readonly Database $db) {}

    // -----------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------

    /**
     * Returns a paginated page of all affiliates.
     *
     * @param string $orderBy Pre-validated "column DIR" fragment (e.g. "name ASC").
     * @return array{items: Affiliate[], total: int, pages: int}
     */
    public function getAll(int $perPage = 10, int $page = 1, string $orderBy = 'id ASC'): array
    {
        $offset = ($page - 1) * $perPage;

        $countStmt = $this->db->prepare('SELECT COUNT(*) FROM lstr_affiliates WHERE pending = 0');
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->prepare(
            "SELECT * FROM lstr_affiliates WHERE pending = 0 ORDER BY {$orderBy} LIMIT :limit OFFSET :offset"
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
     * Returns a paginated page of collective affiliates (fanlisting_id IS NULL).
     *
     * @param string $orderBy Pre-validated "column DIR" fragment.
     * @return array{items: Affiliate[], total: int, pages: int}
     */
    public function getCollective(int $perPage = 10, int $page = 1, string $orderBy = 'id ASC'): array
    {
        $offset = ($page - 1) * $perPage;

        $countStmt = $this->db->prepare(
            'SELECT COUNT(*) FROM lstr_affiliates WHERE fanlisting_id IS NULL AND pending = 0'
        );
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->prepare(
            "SELECT * FROM lstr_affiliates
             WHERE fanlisting_id IS NULL AND pending = 0
             ORDER BY {$orderBy}
             LIMIT :limit OFFSET :offset"
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
     * Returns a paginated page of affiliates for a specific fanlisting.
     *
     * @param string $orderBy Pre-validated "column DIR" fragment.
     * @return array{items: Affiliate[], total: int, pages: int}
     */
    public function getByListing(int $listingId, int $perPage = 10, int $page = 1, string $orderBy = 'id ASC'): array
    {
        $offset = ($page - 1) * $perPage;

        $countStmt = $this->db->prepare(
            'SELECT COUNT(*) FROM lstr_affiliates WHERE fanlisting_id = :fid AND pending = 0'
        );
        $countStmt->execute(['fid' => $listingId]);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->prepare(
            "SELECT * FROM lstr_affiliates
             WHERE fanlisting_id = :fid AND pending = 0
             ORDER BY {$orderBy}
             LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue(':fid',    $listingId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit',  $perPage,   \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,    \PDO::PARAM_INT);
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
            'SELECT COUNT(*) FROM lstr_affiliates WHERE fanlisting_id = :fid AND active = 1 AND pending = 0'
        );
        $countStmt->execute(['fid' => $listingId]);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_affiliates
             WHERE fanlisting_id = :fid AND active = 1 AND pending = 0
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
            'SELECT COUNT(*) FROM lstr_affiliates WHERE fanlisting_id IS NULL AND active = 1 AND pending = 0'
        );
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_affiliates
             WHERE fanlisting_id IS NULL AND active = 1 AND pending = 0
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
    public function search(string $q, ?int $listingId, int $perPage = 10, int $page = 1, string $orderBy = 'id ASC'): array
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

        $where = '(name LIKE :q1 OR url LIKE :q2 OR email LIKE :q3) AND pending = 0' . $fidClause;

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
             ORDER BY {$orderBy}
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

    /**
     * Returns all affiliate IDs matching a scope and optional search term.
     * Used by the bulk handler when "select all across pages" is triggered.
     *
     * $scope: 'collective' | 'all' | '{listingId}'
     *
     * @return int[]
     */
    public function getAllIdsByFilter(string $scope, string $search): array
    {
        $fid = match (true) {
            $scope === 'collective'             => null,
            $scope === '' || $scope === 'all'   => -1,
            default                             => (int) $scope,
        };

        if ($search !== '') {
            $result = $this->search($search, $fid, PHP_INT_MAX, 1);
            return array_map(static fn(Affiliate $a): int => $a->id, $result['items']);
        }

        if ($fid === null) {
            $stmt = $this->db->prepare('SELECT id FROM lstr_affiliates WHERE fanlisting_id IS NULL AND pending = 0');
            $stmt->execute();
        } elseif ($fid === -1) {
            $stmt = $this->db->prepare('SELECT id FROM lstr_affiliates WHERE pending = 0');
            $stmt->execute();
        } else {
            $stmt = $this->db->prepare('SELECT id FROM lstr_affiliates WHERE fanlisting_id = :fid AND pending = 0');
            $stmt->execute(['fid' => $fid]);
        }

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN, 0));
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
            'sort_order'    => isset($data['sort_order']) ? max(1, (int) $data['sort_order']) : 1,
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
        $status  = in_array($data['status'] ?? '', ['pending', 'approved', 'rejected'], true)
            ? $data['status']
            : null;
        $pending = $status === 'pending' ? 1 : 0;
        $active  = $status === 'rejected' ? 0 : ($status === 'pending' ? 0 : null);

        $setClauses = [
            'name       = :name',
            'url        = :url',
            'email      = :email',
            'image      = :image',
            'sort_order = :sort_order',
        ];
        $params = [
            'name'       => (string) $data['name'],
            'url'        => (string) $data['url'],
            'email'      => isset($data['email']) ? (string) $data['email'] : null,
            'image'      => isset($data['image']) ? (string) $data['image'] : null,
            'sort_order' => max(1, (int) ($data['sort_order'] ?? 1)),
            'id'         => $id,
        ];

        if ($status !== null) {
            $setClauses[] = 'status  = :status';
            $setClauses[] = 'pending = :pending';
            $params['status']  = $status;
            $params['pending'] = $pending;
        }

        // Explicit active override — forced off for rejected/pending; otherwise use supplied value
        $activeOverride = isset($data['active']) ? (int) (bool) $data['active'] : null;
        if ($active !== null) {
            // Status rule takes precedence (rejected/pending always inactive)
            $setClauses[] = 'active = :active';
            $params['active'] = $active;
        } elseif ($activeOverride !== null) {
            $setClauses[] = 'active = :active';
            $params['active'] = $activeOverride;
        }

        $stmt = $this->db->prepare(
            'UPDATE lstr_affiliates SET ' . implode(', ', $setClauses) . ' WHERE id = :id'
        );

        return $stmt->execute($params);
    }

    /** Updates only the image column, leaving every other field untouched. */
    public function updateImage(int $id, ?string $image): void
    {
        $this->db->prepare('UPDATE lstr_affiliates SET image = :image WHERE id = :id')
            ->execute(['image' => $image, 'id' => $id]);
    }

    /**
     * Returns the next available sort_order for a given scope (1-based).
     * Returns 1 when no affiliates exist in that scope yet.
     */
    public function getNextSortOrder(?int $fanlistingId): int
    {
        if ($fanlistingId === null) {
            $stmt = $this->db->prepare(
                'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM lstr_affiliates WHERE fanlisting_id IS NULL'
            );
            $stmt->execute();
        } else {
            $stmt = $this->db->prepare(
                'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM lstr_affiliates WHERE fanlisting_id = :fid'
            );
            $stmt->execute(['fid' => $fanlistingId]);
        }

        return max(1, (int) $stmt->fetchColumn());
    }

    /**
     * Reassigns sort_order 1, 2, 3… to every affiliate in the given scope,
     * ordered by their current sort_order then name. Closes any gaps and
     * normalises values that start above 1.
     *
     * Pass null for the collective scope; pass a listing ID for a per-listing scope.
     */
    public function repackSortOrder(?int $fanlistingId): void
    {
        if ($fanlistingId === null) {
            $sel = $this->db->prepare(
                'SELECT id FROM lstr_affiliates
                 WHERE fanlisting_id IS NULL
                 ORDER BY sort_order ASC, name ASC'
            );
            $sel->execute();
        } else {
            $sel = $this->db->prepare(
                'SELECT id FROM lstr_affiliates
                 WHERE fanlisting_id = :fid
                 ORDER BY sort_order ASC, name ASC'
            );
            $sel->execute(['fid' => $fanlistingId]);
        }

        $ids = $sel->fetchAll(\PDO::FETCH_COLUMN);
        if (empty($ids)) {
            return;
        }

        $upd = $this->db->prepare(
            'UPDATE lstr_affiliates SET sort_order = :pos WHERE id = :id'
        );
        foreach ($ids as $i => $id) {
            $upd->execute(['pos' => $i + 1, 'id' => (int) $id]);
        }
    }

    /**
     * Returns all affiliate IDs for a scope+status, ordered by sort_order ASC, name ASC.
     * Used by the reorder endpoint to splice a page slice into the global order.
     *
     * Pass $listingId = null for collective (fanlisting_id IS NULL),
     * or a positive int for a specific listing, or -1 to include all scopes.
     *
     * @return int[]
     */
    public function getIdsByScope(?int $listingId, string $status): array
    {
        $conditions = [];
        $params     = [];

        if ($listingId === null) {
            $conditions[] = 'fanlisting_id IS NULL';
        } elseif ($listingId > 0) {
            $conditions[] = 'fanlisting_id = :fid';
            $params['fid'] = $listingId;
        }

        $statusMap = [
            'approved' => "status = 'approved' AND active = 1",
            'inactive' => "status = 'approved' AND active = 0",
            'rejected' => "status = 'rejected'",
            'pending'  => "status = 'pending'",
            'all'      => "status != 'rejected'",
        ];
        if (isset($statusMap[$status])) {
            $conditions[] = $statusMap[$status];
        }

        $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $stmt  = $this->db->prepare(
            "SELECT id FROM lstr_affiliates {$where} ORDER BY sort_order ASC, name ASC"
        );
        $stmt->execute($params);
        return array_map('intval', array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'id'));
    }

    /**
     * Assigns sort_order 1, 2, 3… to the given affiliate IDs in the order
     * they are provided. Used by the drag-and-drop reorder endpoint.
     *
     * @param int[] $orderedIds
     */
    public function setSortOrders(array $orderedIds): void
    {
        $upd = $this->db->prepare(
            'UPDATE lstr_affiliates SET sort_order = :pos WHERE id = :id'
        );
        foreach ($orderedIds as $i => $id) {
            $upd->execute(['pos' => $i + 1, 'id' => (int) $id]);
        }
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

    /**
     * Sets active = 1 or 0 for multiple affiliates by ID.
     */
    public function bulkSetActive(array $ids, bool $active): int
    {
        if (empty($ids)) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "UPDATE lstr_affiliates SET active = ? WHERE id IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$active ? 1 : 0], array_values($ids)));
        return $stmt->rowCount();
    }

    /**
     * Deletes multiple affiliates by ID.
     */
    public function bulkDelete(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "DELETE FROM lstr_affiliates WHERE id IN ({$placeholders})"
        );
        $stmt->execute(array_values($ids));
        return $stmt->rowCount();
    }

    /**
     * Bulk-approves pending applications by looping through each ID so that
     * each affiliate receives a correct next sort_order in its scope.
     * Returns the number of rows successfully approved.
     */
    public function bulkApprove(array $ids): int
    {
        $count = 0;
        foreach ($ids as $id) {
            if ($this->approve((int) $id)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Bulk re-approves rejected affiliates by looping through each ID so that
     * each receives a correct next sort_order in its scope.
     */
    public function bulkReApprove(array $ids): int
    {
        $count = 0;
        foreach ($ids as $id) {
            if ($this->reApprove((int) $id)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Bulk-rejects pending applications in a single UPDATE.
     * Returns the number of rows affected.
     */
    public function bulkReject(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "UPDATE lstr_affiliates
             SET status = 'rejected', pending = 0, active = 0
             WHERE id IN ({$placeholders}) AND status = 'pending'"
        );
        $stmt->execute(array_values($ids));
        return $stmt->rowCount();
    }

    // -----------------------------------------------------------------------
    // Affiliate applications (pending queue)
    // -----------------------------------------------------------------------

    /**
     * Returns all pending applications, optionally scoped to one listing.
     * Pass null to get all pending across every listing.
     *
     * @return Affiliate[]
     */
    public function getPending(?int $listingId = null): array
    {
        if ($listingId === null) {
            $stmt = $this->db->prepare(
                'SELECT * FROM lstr_affiliates WHERE pending = 1
                 ORDER BY fanlisting_id ASC, created_at ASC'
            );
            $stmt->execute();
        } else {
            $stmt = $this->db->prepare(
                'SELECT * FROM lstr_affiliates WHERE pending = 1 AND fanlisting_id = :fid
                 ORDER BY created_at ASC'
            );
            $stmt->execute(['fid' => $listingId]);
        }

        return array_map(static fn(array $r) => Affiliate::fromRow($r), $stmt->fetchAll());
    }

    /**
     * Returns pending affiliates filtered by scope.
     *
     * 'all'         — all pending applications (same as getPending())
     * 'collective'  — only collective scope (fanlisting_id IS NULL)
     * '{int}'       — only applications for listing #{int}
     *
     * @return Affiliate[]
     */
    public function getPendingForScope(string $scope): array
    {
        if ($scope === 'collective') {
            $stmt = $this->db->prepare(
                'SELECT * FROM lstr_affiliates
                 WHERE pending = 1 AND fanlisting_id IS NULL
                 ORDER BY created_at ASC'
            );
            $stmt->execute();
        } elseif ($scope !== '' && $scope !== 'all' && ctype_digit($scope)) {
            $stmt = $this->db->prepare(
                'SELECT * FROM lstr_affiliates
                 WHERE pending = 1 AND fanlisting_id = :lid
                 ORDER BY created_at ASC'
            );
            $stmt->execute(['lid' => (int) $scope]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT * FROM lstr_affiliates WHERE pending = 1
                 ORDER BY fanlisting_id ASC, created_at ASC'
            );
            $stmt->execute();
        }

        return array_map(static fn(array $r) => Affiliate::fromRow($r), $stmt->fetchAll());
    }

    /**
     * Returns the IDs of pending affiliate applications that share a URL with an
     * already-active affiliate in the same scope.
     *
     * Used to surface duplicate-URL warnings on the pending review page.
     *
     * @return int[]
     */
    public function getPendingIdsWithUrlConflict(): array
    {
        $stmt = $this->db->prepare(
            'SELECT DISTINCT p.id
             FROM lstr_affiliates p
             JOIN lstr_affiliates a
                ON a.url     = p.url
               AND a.pending = 0
               AND a.active  = 1
               AND (
                   (p.fanlisting_id IS NULL     AND a.fanlisting_id IS NULL)
                   OR
                   (p.fanlisting_id IS NOT NULL AND a.fanlisting_id = p.fanlisting_id)
               )
             WHERE p.pending = 1'
        );
        $stmt->execute();

        return array_map(static fn(array $r) => (int) $r['id'], $stmt->fetchAll());
    }

    /**
     * Returns the total number of pending affiliate applications across all listings.
     * Used by the dashboard to surface the attention banner.
     */
    public function getPendingCount(): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM lstr_affiliates WHERE status = 'pending'");
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Inserts a new affiliate application (pending = 1, status = 'pending', active = 0).
     * Returns the new primary key.
     *
     * @param array{fanlisting_id: ?int, name: string, url: string, email?: ?string, image?: ?string, application_message?: ?string} $data
     */
    public function insertPending(array $data): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO lstr_affiliates
                (fanlisting_id, name, url, email, image, sort_order, active, status, pending, application_message)
             VALUES
                (:fanlisting_id, :name, :url, :email, :image, 1, 0, 'pending', 1, :application_message)"
        );

        $stmt->execute([
            'fanlisting_id'       => $data['fanlisting_id'] !== null ? (int) $data['fanlisting_id'] : null,
            'name'                => (string) $data['name'],
            'url'                 => (string) $data['url'],
            'email'               => isset($data['email'])               ? (string) $data['email']               : null,
            'image'               => isset($data['image'])               ? (string) $data['image']               : null,
            'application_message' => isset($data['application_message']) ? (string) $data['application_message'] : null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Approves a pending application: sets status='approved', clears pending flag,
     * activates the row, and assigns the next sort_order in its scope.
     */
    public function approve(int $id): bool
    {
        $aff = $this->getById($id);
        if ($aff === null || $aff->status !== 'pending') {
            return false;
        }

        $sortOrder = $this->getNextSortOrder($aff->listingId);

        $stmt = $this->db->prepare(
            "UPDATE lstr_affiliates
             SET status     = 'approved',
                 pending    = 0,
                 active     = 1,
                 sort_order = :sort
             WHERE id = :id AND status = 'pending'"
        );

        return $stmt->execute(['sort' => $sortOrder, 'id' => $id]);
    }

    /**
     * Rejects a pending application: sets status='rejected', clears pending flag,
     * deactivates the row. The row is retained for record-keeping.
     */
    public function reject(int $id): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE lstr_affiliates
             SET status  = 'rejected',
                 pending = 0,
                 active  = 0
             WHERE id = :id AND status = 'pending'"
        );
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Re-approves a previously rejected affiliate: sets status='approved', active=1.
     */
    public function reApprove(int $id): bool
    {
        $aff = $this->getById($id);
        if ($aff === null || $aff->status !== 'rejected') {
            return false;
        }

        $sortOrder = $this->getNextSortOrder($aff->listingId);

        $stmt = $this->db->prepare(
            "UPDATE lstr_affiliates
             SET status     = 'approved',
                 active     = 1,
                 sort_order = :sort
             WHERE id = :id AND status = 'rejected'"
        );

        return $stmt->execute(['sort' => $sortOrder, 'id' => $id]);
    }

    /**
     * Returns paginated affiliates for the admin tabbed view.
     * $tab: 'all' | 'pending' | 'approved' | 'rejected'
     * $scope: 'all' | 'collective' | listing ID as string
     */
    public function getAdminPaginated(
        string $tab,
        string $scope,
        string $search,
        int    $perPage,
        int    $page,
        string $orderBy = 'name ASC'
    ): array {
        $conditions = [];
        $params     = [];

        if ($tab === 'inactive') {
            $conditions[]    = "status = 'approved'";
            $conditions[]    = 'active = 0';
        } elseif ($tab === 'approved') {
            $conditions[]    = "status = 'approved'";
            $conditions[]    = 'active = 1';
        } elseif ($tab !== 'all') {
            $conditions[]    = 'status = :status';
            $params['status'] = $tab;
        }

        if ($scope === 'collective') {
            $conditions[] = 'fanlisting_id IS NULL';
        } elseif ($scope !== '' && $scope !== 'all' && ctype_digit($scope)) {
            $conditions[] = 'fanlisting_id = :fid';
            $params['fid'] = (int) $scope;
        }

        if ($search !== '') {
            $conditions[] = '(name LIKE :s1 OR url LIKE :s2 OR email LIKE :s3)';
            $params['s1'] = '%' . $search . '%';
            $params['s2'] = '%' . $search . '%';
            $params['s3'] = '%' . $search . '%';
        }

        $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM lstr_affiliates {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $pages  = $total > 0 ? (int) ceil($total / $perPage) : 1;
        $offset = ($page - 1) * $perPage;

        $stmt = $this->db->prepare(
            "SELECT * FROM lstr_affiliates {$where} ORDER BY {$orderBy} LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit',  $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  \PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => array_map([Affiliate::class, 'fromRow'], $stmt->fetchAll(\PDO::FETCH_ASSOC)),
            'total' => $total,
            'pages' => $pages,
        ];
    }

    /**
     * Returns per-status counts for the admin tab badges.
     * Optionally scoped to a listing (scope = 'all'|'collective'|listing ID string).
     */
    public function getAdminTabCounts(string $scope, string $search): array
    {
        $conditions = [];
        $params     = [];

        if ($scope === 'collective') {
            $conditions[] = 'fanlisting_id IS NULL';
        } elseif ($scope !== '' && $scope !== 'all' && ctype_digit($scope)) {
            $conditions[] = 'fanlisting_id = :fid';
            $params['fid'] = (int) $scope;
        }

        if ($search !== '') {
            $conditions[] = '(name LIKE :s1 OR url LIKE :s2 OR email LIKE :s3)';
            $params['s1'] = '%' . $search . '%';
            $params['s2'] = '%' . $search . '%';
            $params['s3'] = '%' . $search . '%';
        }

        $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $stmt = $this->db->prepare(
            "SELECT
                COUNT(*)                                           AS total,
                SUM(status = 'pending')                           AS cnt_pending,
                SUM(status = 'approved' AND active = 1)           AS cnt_approved,
                SUM(status = 'approved' AND active = 0)           AS cnt_inactive,
                SUM(status = 'rejected')                          AS cnt_rejected
             FROM lstr_affiliates {$where}"
        );
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return [
            'all'      => (int) ($row['total']        ?? 0),
            'pending'  => (int) ($row['cnt_pending']   ?? 0),
            'approved' => (int) ($row['cnt_approved']  ?? 0),
            'inactive' => (int) ($row['cnt_inactive']  ?? 0),
            'rejected' => (int) ($row['cnt_rejected']  ?? 0),
        ];
    }

    /**
     * Returns a map of scope → total affiliate count (all statuses).
     * Keys: 0 = collective (fanlisting_id IS NULL), or a listing ID int.
     * Used to show counts next to scope filter options.
     *
     * @return array<int, int>
     */
    public function getScopeCounts(): array
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(fanlisting_id, 0) AS scope_id, COUNT(*) AS cnt
             FROM lstr_affiliates
             GROUP BY fanlisting_id'
        );
        $stmt->execute();

        $counts = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $counts[(int) $row['scope_id']] = (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * Checks whether an affiliate application for this listing/collective scope
     * already exists (either as a live affiliate or a pending application).
     *
     * Matches on URL only (not email) so the same applicant can submit multiple
     * different sites under one email address. Rejected applications don't count,
     * so a previously-rejected site can be resubmitted. Pass null for $listingId
     * to check the collective scope (fanlisting_id IS NULL).
     */
    public function isDuplicateApplication(?int $listingId, string $url): bool
    {
        if ($listingId === null) {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM lstr_affiliates
                 WHERE fanlisting_id IS NULL AND url = :url AND status != 'rejected'"
            );
            $stmt->execute(['url' => $url]);
        } else {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM lstr_affiliates
                 WHERE fanlisting_id = :fid AND url = :url AND status != 'rejected'"
            );
            $stmt->execute(['fid' => $listingId, 'url' => $url]);
        }

        return (int) $stmt->fetchColumn() > 0;
    }

    // -----------------------------------------------------------------------
    // Link checking
    // -----------------------------------------------------------------------

    /**
     * Returns affiliate rows for the link-checker page, ordered collective-first then name.
     *
     * $listingId = null  → all affiliates (no fanlisting_id filter)
     * $listingId = 0     → collective only (fanlisting_id IS NULL)
     * $listingId > 0     → specific listing only
     *
     * @return Affiliate[]
     */
    public function getAllForLinkCheck(?int $listingId = null): array
    {
        [$where, $params] = $this->buildListingFilter('pending = 0', $listingId);

        $stmt = $this->db->prepare(
            "SELECT * FROM lstr_affiliates WHERE {$where} ORDER BY (fanlisting_id IS NULL) DESC, name ASC"
        );
        $stmt->execute($params);

        return array_map(static fn(array $r) => Affiliate::fromRow($r), $stmt->fetchAll());
    }

    /**
     * @param string  $orderBy      Pre-validated "col DIR[, col DIR]" fragment.
     * @param ?string $statusFilter 'ok'|'redirect'|'dead'|'unchecked'|null (null = all)
     * @param ?string $search       Name substring filter (null = all)
     * @return array{items: Affiliate[], total: int}
     */
    public function paginateForLinkCheck(?int $listingId, int $perPage, int $page, string $orderBy = 'name ASC', ?string $statusFilter = null, ?string $search = null, ?string $reciprocalFilter = null): array
    {
        [$where, $params] = $this->buildListingFilter('pending = 0', $listingId);

        if ($statusFilter === 'unchecked') {
            $where .= " AND link_status = 'unchecked'";
        } elseif (in_array($statusFilter, ['ok', 'redirect', 'dead'], true)) {
            $where .= ' AND link_status = :ls_filter';
            $params[':ls_filter'] = $statusFilter;
        }

        if ($reciprocalFilter === 'found') {
            $where .= " AND reciprocal_status = 'found'";
        } elseif ($reciprocalFilter === 'missing') {
            $where .= " AND reciprocal_status = 'missing'";
        }

        if ($search !== null && $search !== '') {
            $where .= ' AND name LIKE :lc_search';
            $params[':lc_search'] = '%' . $search . '%';
        }

        $offset = ($page - 1) * $perPage;

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM lstr_affiliates WHERE {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->prepare(
            "SELECT * FROM lstr_affiliates WHERE {$where} ORDER BY {$orderBy} LIMIT :lim OFFSET :off"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset,  \PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => array_map(static fn(array $r) => Affiliate::fromRow($r), $stmt->fetchAll()),
            'total' => $total,
        ];
    }

    /**
     * @return array{ok:int,dead:int,redirect:int,unchecked:int}
     */
    public function getLinkStats(?int $listingId = null): array
    {
        [$where, $params] = $this->buildListingFilter('pending = 0', $listingId);
        $stmt = $this->db->prepare(
            "SELECT COALESCE(link_status, 'unchecked') AS s, COUNT(*) AS cnt FROM lstr_affiliates WHERE {$where} GROUP BY s"
        );
        $stmt->execute($params);
        $out = ['ok' => 0, 'dead' => 0, 'redirect' => 0, 'unchecked' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $s = $row['s'];
            if (isset($out[$s])) { $out[$s] = (int) $row['cnt']; }
            else { $out['unchecked'] += (int) $row['cnt']; }
        }
        return $out;
    }

    /**
     * @return array{found:int,missing:int,unchecked:int}
     */
    public function getReciprocalStats(?int $listingId = null): array
    {
        [$where, $params] = $this->buildListingFilter('pending = 0', $listingId);
        $stmt = $this->db->prepare(
            "SELECT COALESCE(reciprocal_status, 'unchecked') AS s, COUNT(*) AS cnt FROM lstr_affiliates WHERE {$where} GROUP BY s"
        );
        $stmt->execute($params);
        $out = ['found' => 0, 'missing' => 0, 'unchecked' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $s = $row['s'];
            if (isset($out[$s])) { $out[$s] = (int) $row['cnt']; }
            else { $out['unchecked'] += (int) $row['cnt']; }
        }
        return $out;
    }

    /**
     * Returns all affiliates with a given link_status, optionally scoped to a listing.
     *
     * $listingId = null  → all affiliates
     * $listingId = 0     → collective only (fanlisting_id IS NULL)
     * $listingId > 0     → specific listing only
     *
     * @return Affiliate[]
     */
    public function getByLinkStatus(string $status, ?int $listingId = null): array
    {
        [$where, $params] = $this->buildListingFilter('link_status = :status', $listingId);
        $params['status'] = $status;

        $stmt = $this->db->prepare(
            "SELECT * FROM lstr_affiliates WHERE {$where} ORDER BY (fanlisting_id IS NULL) DESC, name ASC"
        );
        $stmt->execute($params);

        return array_map(static fn(array $r) => Affiliate::fromRow($r), $stmt->fetchAll());
    }

    /**
     * Builds a WHERE fragment + params array for an optional fanlisting_id scope.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildListingFilter(string $base, ?int $listingId): array
    {
        if ($listingId === 0) {
            return [$base . ' AND fanlisting_id IS NULL', []];
        }
        if ($listingId !== null) {
            return [$base . ' AND fanlisting_id = :fid', ['fid' => $listingId]];
        }
        return [$base, []];
    }

    /**
     * Applies the stored redirect URL to the affiliate's url field and resets
     * link-check state so the new URL is re-validated on the next check run.
     *
     * Only acts on records with link_status = 'redirect' and a non-empty
     * link_redirect_url — the new URL comes entirely from the DB, never from
     * a browser-supplied value.
     *
     * reciprocal_status and reciprocal_checked_at are intentionally left
     * untouched: the affiliate likely still links back; the next check run
     * will update reciprocal state for the new URL if needed.
     *
     * @return bool  true if a row was updated, false if nothing matched.
     */
    public function applyRedirectUrl(int $id): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE lstr_affiliates
             SET url               = link_redirect_url,
                 link_status       = 'unchecked',
                 link_redirect_url = NULL,
                 link_checked_at   = NULL
             WHERE id              = :id
               AND link_status     = 'redirect'
               AND link_redirect_url IS NOT NULL
               AND link_redirect_url <> ''"
        );
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Records the outcome of a single URL check for an affiliate row.
     *
     * Stores the resolved link_status and, when the URL redirected, the final
     * destination URL so the admin can apply it later via applyRedirectUrl().
     */
    public function updateLinkStatus(int $id, string $status, ?string $redirectUrl, ?string $now = null): void
    {
        $stmt = $this->db->prepare(
            'UPDATE lstr_affiliates
             SET link_status       = :status,
                 link_redirect_url = :redir,
                 link_checked_at   = :now
             WHERE id = :id'
        );
        $stmt->execute(['status' => $status, 'redir' => $redirectUrl, 'now' => $now ?? date('Y-m-d H:i:s'), 'id' => $id]);
    }

    /**
     * Records the outcome of a reciprocal-link check for an affiliate row.
     *
     * A reciprocal link check fetches the affiliate's page and looks for a
     * back-link to this collective. The status is 'ok' when found, 'not_found'
     * when the page loaded but had no back-link, or 'dead' on HTTP failure.
     */
    public function updateReciprocalStatus(int $id, string $status, ?string $now = null): void
    {
        $stmt = $this->db->prepare(
            'UPDATE lstr_affiliates
             SET reciprocal_status     = :status,
                 reciprocal_checked_at = :now
             WHERE id = :id'
        );
        $stmt->execute(['status' => $status, 'now' => $now ?? date('Y-m-d H:i:s'), 'id' => $id]);
    }

    /**
     * Resets link-check and reciprocal-check state for every affiliate row.
     *
     * Call this before starting a fresh check run so stale results from the
     * previous run do not persist alongside newly checked rows.
     */
    public function resetLinkStatus(): void
    {
        $this->db->prepare(
            "UPDATE lstr_affiliates
             SET link_status           = 'unchecked',
                 link_checked_at       = NULL,
                 link_redirect_url     = NULL,
                 reciprocal_status     = 'unchecked',
                 reciprocal_checked_at = NULL"
        )->execute();
    }

    // -----------------------------------------------------------------------
    // Bulk email recipients
    // -----------------------------------------------------------------------

    /**
     * Returns active non-pending affiliates that have an email address, for
     * the given scope. Deduplicates by email (first row wins, ordered by id).
     *
     * @param 'all'|'collective'|int $scope
     * @return array<int, array{name: string, email: string, url: string, listing_id: int|null}>
     */
    public function getEmailRecipients(string|int $scope): array
    {
        if ($scope === 'all') {
            $stmt = $this->db->prepare(
                "SELECT name, email, url, fanlisting_id
                 FROM lstr_affiliates
                 WHERE active = 1 AND pending = 0
                   AND email IS NOT NULL AND TRIM(email) != ''
                 ORDER BY id ASC"
            );
            $stmt->execute();
        } elseif ($scope === 'collective') {
            $stmt = $this->db->prepare(
                "SELECT name, email, url, fanlisting_id
                 FROM lstr_affiliates
                 WHERE active = 1 AND pending = 0
                   AND email IS NOT NULL AND TRIM(email) != ''
                   AND fanlisting_id IS NULL
                 ORDER BY id ASC"
            );
            $stmt->execute();
        } else {
            $stmt = $this->db->prepare(
                "SELECT name, email, url, fanlisting_id
                 FROM lstr_affiliates
                 WHERE active = 1 AND pending = 0
                   AND email IS NOT NULL AND TRIM(email) != ''
                   AND fanlisting_id = :fid
                 ORDER BY id ASC"
            );
            $stmt->execute(['fid' => (int) $scope]);
        }

        $rows   = $stmt->fetchAll();
        $seen   = [];
        $result = [];

        foreach ($rows as $row) {
            $emailKey = strtolower(trim((string) $row['email']));
            if (isset($seen[$emailKey])) {
                continue;
            }
            $seen[$emailKey] = true;
            $result[] = [
                'name'       => (string) $row['name'],
                'email'      => trim((string) $row['email']),
                'url'        => (string) $row['url'],
                'listing_id' => $row['fanlisting_id'] !== null ? (int) $row['fanlisting_id'] : null,
            ];
        }

        return $result;
    }

    /**
     * Returns email details for a specific set of affiliate IDs, keyed by ID.
     *
     * Used when the admin selects individual affiliate rows for a targeted email
     * rather than using the scope selector (all / collective / per-listing).
     * Rows without an email address are excluded.
     *
     * @param  int[]   $ids
     * @return array<int, array{id: int, name: string, email: string, url: string, listing_id: int|null}>
     */
    public function getEmailRecipientsByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT id, name, email, url, fanlisting_id
             FROM lstr_affiliates
             WHERE id IN ({$placeholders})
               AND email IS NOT NULL AND TRIM(email) != ''
             ORDER BY id ASC"
        );
        $stmt->execute(array_values($ids));

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $id = (int) $row['id'];
            $result[$id] = [
                'id'         => $id,
                'name'       => (string) $row['name'],
                'email'      => trim((string) $row['email']),
                'url'        => (string) ($row['url'] ?? ''),
                'listing_id' => $row['fanlisting_id'] !== null ? (int) $row['fanlisting_id'] : null,
            ];
        }
        return $result;
    }
}
