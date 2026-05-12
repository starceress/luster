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
            'sort_order' => max(1, (int) ($data['sort_order'] ?? 1)),
            'id'         => $id,
        ]);
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
     * Returns the total number of pending affiliate applications across all listings.
     * Used by the dashboard to surface the attention banner.
     */
    public function getPendingCount(): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM lstr_affiliates WHERE pending = 1');
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Inserts a new affiliate application (pending = 1, active = 0).
     * Returns the new primary key.
     *
     * @param array{fanlisting_id: int, name: string, url: string, email?: ?string, image?: ?string, application_message?: ?string} $data
     */
    public function insertPending(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO lstr_affiliates
                (fanlisting_id, name, url, email, image, sort_order, active, pending, application_message)
             VALUES
                (:fanlisting_id, :name, :url, :email, :image, 1, 0, 1, :application_message)'
        );

        $stmt->execute([
            'fanlisting_id'       => (int) $data['fanlisting_id'],
            'name'                => (string) $data['name'],
            'url'                 => (string) $data['url'],
            'email'               => isset($data['email'])               ? (string) $data['email']               : null,
            'image'               => isset($data['image'])               ? (string) $data['image']               : null,
            'application_message' => isset($data['application_message']) ? (string) $data['application_message'] : null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Approves a pending application: clears pending flag, activates the row,
     * and assigns the next sort_order in its scope.
     */
    public function approve(int $id): bool
    {
        $aff = $this->getById($id);
        if ($aff === null || !$aff->pending) {
            return false;
        }

        $sortOrder = $this->getNextSortOrder($aff->listingId);

        $stmt = $this->db->prepare(
            'UPDATE lstr_affiliates
             SET pending    = 0,
                 active     = 1,
                 sort_order = :sort
             WHERE id = :id AND pending = 1'
        );

        return $stmt->execute(['sort' => $sortOrder, 'id' => $id]);
    }

    /**
     * Rejects a pending application by deleting the row.
     * Only removes the row if it is still pending.
     */
    public function reject(int $id): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM lstr_affiliates WHERE id = :id AND pending = 1'
        );
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Checks whether an affiliate application for this listing already exists
     * (either as a live affiliate or an in-queue pending application) by URL or email.
     * Used to prevent duplicate applications before inserting.
     */
    public function isDuplicateApplication(int $listingId, string $url, string $email): bool
    {
        if ($email !== '') {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM lstr_affiliates
                 WHERE fanlisting_id = :fid AND (url = :url OR email = :email)'
            );
            $stmt->execute(['fid' => $listingId, 'url' => $url, 'email' => $email]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM lstr_affiliates
                 WHERE fanlisting_id = :fid AND url = :url'
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
}
