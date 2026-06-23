<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Manages fan graphic codes in lstr_codes. Each code is an uploaded image
 associated with a listing (or the collective when fanlisting_id is NULL),
 with optional size, donor, and submitter metadata. Supports an approval
 workflow (approved/pending/rejected), filtered queries for admin and
 public display, bulk status changes, drag-and-drop sort ordering, and
 per-listing or cross-listing paginated pending queues.
*/

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Database;

/**
 * Data access for the lstr_codes table.
 *
 * Each row represents one uploaded code image for a listing.
 * Requires Migration 22 (table), Migration 30 (size_id), Migration 31 (donor_id),
 * Migration 32 (status), Migration 33 (name), Migration 34 (submitter_name/email).
 */
final class CodesRepo
{
    /** Valid status values — matches the DB ENUM. */
    public const STATUSES = ['approved', 'pending', 'rejected'];

    public function __construct(private readonly Database $db) {}

    // -----------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------

    /**
     * Returns distinct size strings and donor names for codes in one listing.
     * Used to populate the per-listing filter dropdowns.
     *
     * @return array{sizes: string[], donors: string[]}
     */
    /**
     * Returns sizes and donor names (with counts) for a listing's codes.
     * Used to populate the filter dropdowns on the per-listing Codes page.
     *
     * @return array{sizes: array<array{value:string,count:int}>, donors: array<array{value:string,count:int}>}
     */
    public function getListingFilterOptions(?int $listingId, ?string $status = null): array
    {
        $fidCond      = $listingId === null ? 'fanlisting_id IS NULL' : 'fanlisting_id = :fid';
        $fidParams    = $listingId === null ? [] : ['fid' => $listingId];
        $statusClause = $status !== null ? " AND status = " . $this->db->pdo()->quote($status) : '';

        $sizeStmt = $this->db->prepare(
            "SELECT CONCAT(width, 'x', height) AS size, COUNT(*) AS cnt
             FROM lstr_codes WHERE {$fidCond} AND width > 0{$statusClause}
             GROUP BY width, height ORDER BY width ASC, height ASC"
        );
        $sizeStmt->execute($fidParams);
        $sizes = [];
        foreach ($sizeStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $sizes[] = ['value' => $row['size'], 'count' => (int) $row['cnt']];
        }

        $donorStmt = $this->db->prepare(
            "SELECT submitter_name, COUNT(*) AS cnt FROM lstr_codes
             WHERE {$fidCond} AND submitter_name IS NOT NULL AND submitter_name != ''{$statusClause}
             GROUP BY submitter_name ORDER BY submitter_name ASC"
        );
        $donorStmt->execute($fidParams);
        $donors = [];
        foreach ($donorStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $donors[] = ['value' => $row['submitter_name'], 'count' => (int) $row['cnt']];
        }

        return ['sizes' => $sizes, 'donors' => $donors];
    }

    /**
     * Returns codes for a listing with optional size/donor/search filters.
     *
     * @param array{size?:string,donor?:string,search?:string} $filters
     * @return object[]
     */
    public function getByListingFiltered(?int $listingId, ?string $status, array $filters): array
    {
        $conditions = [$listingId === null ? 'fanlisting_id IS NULL' : 'fanlisting_id = :fid'];
        $params     = $listingId === null ? [] : ['fid' => $listingId];

        if ($status !== null) {
            $conditions[]    = 'status = :status';
            $params['status'] = $status;
        }

        if (($filters['size'] ?? '') !== '') {
            $parts = explode('x', (string) $filters['size'], 2);
            if (count($parts) === 2 && ctype_digit($parts[0]) && ctype_digit($parts[1])) {
                $conditions[] = 'width = :sw AND height = :sh';
                $params['sw'] = (int) $parts[0];
                $params['sh'] = (int) $parts[1];
            }
        }

        if (($filters['donor'] ?? '') !== '') {
            $conditions[] = 'submitter_name = :donor';
            $params['donor'] = $filters['donor'];
        }

        if (($filters['search'] ?? '') !== '') {
            $conditions[] = 'submitter_name LIKE :search';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);
        $stmt  = $this->db->prepare(
            "SELECT id, fanlisting_id, name, filename, width, height, size_id, donor_id,
                    sort_order, status, submitter_name, submitter_email, submitter_url, created_at
             FROM lstr_codes {$where} ORDER BY sort_order ASC, id ASC"
        );
        $stmt->execute($params);

        return array_map(static fn(array $r) => (object) [
            'id'            => (int) $r['id'],
            'fanlistingId'  => (int) $r['fanlisting_id'],
            'name'          => $r['name'],
            'filename'      => (string) $r['filename'],
            'width'         => (int) $r['width'],
            'height'        => (int) $r['height'],
            'sizeId'        => isset($r['size_id'])  ? (int) $r['size_id']  : null,
            'donorId'       => isset($r['donor_id']) ? (int) $r['donor_id'] : null,
            'sortOrder'     => (int) $r['sort_order'],
            'status'        => (string) $r['status'],
            'submitterName' => $r['submitter_name'] ?? null,
            'submitterEmail'=> $r['submitter_email'] ?? null,
            'submitterUrl'  => $r['submitter_url']   ?? null,
            'createdAt'     => $r['created_at']      ?? null,
            // alias for template compatibility
            'submitter_name'  => $r['submitter_name']  ?? null,
            'submitter_email' => $r['submitter_email'] ?? null,
            'submitter_url'   => $r['submitter_url']   ?? null,
            'created_at'      => $r['created_at']      ?? null,
            'fanlisting_id'   => (int) $r['fanlisting_id'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Returns distinct submitter_name values recorded on a listing's codes,
     * ordered alphabetically. Used to populate the upload panel "Past Submitters"
     * optgroup so admins can attribute new uploads to an existing submitter.
     *
     * @return string[]
     */
    public function getSubmitterNamesForListing(?int $listingId): array
    {
        $fidCond = $listingId === null ? 'fanlisting_id IS NULL' : 'fanlisting_id = :fid';
        $stmt = $this->db->prepare(
            "SELECT DISTINCT submitter_name FROM lstr_codes
             WHERE {$fidCond} AND submitter_name IS NOT NULL AND submitter_name != ''
             ORDER BY submitter_name ASC"
        );
        $stmt->execute($listingId === null ? [] : ['fid' => $listingId]);
        return array_column($stmt->fetchAll(), 'submitter_name');
    }

    /**
     * Returns codes for a listing, ordered by sort_order then id.
     * Pass a status string to filter to that status only; null returns all.
     *
     * @return object[]
     */
    public function getByListing(?int $listingId, ?string $status = null): array
    {
        $fidCond = $listingId === null ? 'fanlisting_id IS NULL' : 'fanlisting_id = :fid';
        $sql    = "SELECT id, fanlisting_id, name, filename, width, height, size_id, donor_id, sort_order, status, submitter_name, submitter_email, submitter_url, created_at
                   FROM lstr_codes
                   WHERE {$fidCond}";
        $params = $listingId === null ? [] : ['fid' => $listingId];

        if ($status !== null) {
            $sql             .= ' AND status = :status';
            $params['status'] = $status;
        }

        $sql .= ' ORDER BY sort_order ASC, id ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return array_map(static fn(array $r) => (object) [
            'id'             => (int)    $r['id'],
            'listingId'      => (int)    $r['fanlisting_id'],
            'name'           => $r['name'] !== null ? (string) $r['name'] : null,
            'filename'       => (string) $r['filename'],
            'width'          => (int)    $r['width'],
            'height'         => (int)    $r['height'],
            'sizeId'         => $r['size_id']  !== null ? (int) $r['size_id']  : null,
            'donorId'        => $r['donor_id'] !== null ? (int) $r['donor_id'] : null,
            'sortOrder'      => (int)    $r['sort_order'],
            'status'         => (string) $r['status'],
            'submitterName'  => $r['submitter_name']  !== null ? (string) $r['submitter_name']  : null,
            'submitterEmail' => $r['submitter_email'] !== null ? (string) $r['submitter_email'] : null,
            'submitterUrl'   => $r['submitter_url']   !== null ? (string) $r['submitter_url']   : null,
            'createdAt'      => (string) $r['created_at'],
        ], $stmt->fetchAll());
    }

    /**
     * Returns a single code by ID, or null if not found.
     */
    public function getById(int $id): ?object
    {
        $stmt = $this->db->prepare(
            'SELECT id, fanlisting_id, name, filename, width, height, size_id, donor_id, sort_order, status, submitter_name, submitter_email, submitter_url, created_at
             FROM lstr_codes WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return (object) [
            'id'             => (int)    $row['id'],
            'listingId'      => (int)    $row['fanlisting_id'],
            'name'           => $row['name'] !== null ? (string) $row['name'] : null,
            'filename'       => (string) $row['filename'],
            'width'          => (int)    $row['width'],
            'height'         => (int)    $row['height'],
            'sizeId'         => $row['size_id']  !== null ? (int) $row['size_id']  : null,
            'donorId'        => $row['donor_id'] !== null ? (int) $row['donor_id'] : null,
            'sortOrder'      => (int)    $row['sort_order'],
            'status'         => (string) $row['status'],
            'submitterName'  => $row['submitter_name']  !== null ? (string) $row['submitter_name']  : null,
            'submitterEmail' => $row['submitter_email'] !== null ? (string) $row['submitter_email'] : null,
            'submitterUrl'   => $row['submitter_url']   !== null ? (string) $row['submitter_url']   : null,
            'createdAt'      => (string) $row['created_at'],
        ];
    }

    /**
     * Returns the total number of codes for a listing.
     */
    public function countByListing(?int $listingId): int
    {
        $fidCond = $listingId === null ? 'fanlisting_id IS NULL' : 'fanlisting_id = :fid';
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM lstr_codes WHERE {$fidCond}"
        );
        $stmt->execute($listingId === null ? [] : ['fid' => $listingId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Returns the number of pending codes for a listing.
     */
    public function countPendingByListing(?int $listingId): int
    {
        $fidCond = $listingId === null ? 'fanlisting_id IS NULL' : 'fanlisting_id = :fid';
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM lstr_codes WHERE {$fidCond} AND status = 'pending'"
        );
        $stmt->execute($listingId === null ? [] : ['fid' => $listingId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Returns a map of fanlisting_id → pending code count for all listings.
     *
     * @return array<int, int>
     */
    public function countPendingPerListing(): array
    {
        $stmt = $this->db->prepare(
            "SELECT fanlisting_id, COUNT(*) AS cnt FROM lstr_codes WHERE status = 'pending' GROUP BY fanlisting_id"
        );
        $stmt->execute();
        $counts = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $counts[(int) $row['fanlisting_id']] = (int) $row['cnt'];
        }
        return $counts;
    }

    /**
     * Returns approved code count per listing, keyed by fanlisting_id.
     * Used to populate the listing selector dropdown on the global Codes page.
     *
     * @return array<int, int>
     */
    public function countApprovedPerListing(): array
    {
        $stmt = $this->db->prepare(
            "SELECT fanlisting_id, COUNT(*) AS cnt FROM lstr_codes WHERE status = 'approved' GROUP BY fanlisting_id"
        );
        $stmt->execute();
        $counts = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $counts[(int) $row['fanlisting_id']] = (int) $row['cnt'];
        }
        return $counts;
    }

    /**
     * Returns total pending code count across ALL listings.
     * Used for the sidebar badge and the global Codes page.
     */
    public function countPendingAll(): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM lstr_codes WHERE status = 'pending'");
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * Returns sizes and donor names (with counts) for pending codes across all listings.
     * Used to populate the filter dropdowns on the global Codes page.
     *
     * @return array{sizes: array<array{value:string,count:int}>, donors: array<array{value:string,count:int}>}
     */
    public function getPendingFilterOptions(): array
    {
        $sizeStmt = $this->db->prepare(
            "SELECT CONCAT(width, 'x', height) AS size, COUNT(*) AS cnt
             FROM lstr_codes WHERE status = 'pending' AND width > 0
             GROUP BY width, height ORDER BY width ASC, height ASC"
        );
        $sizeStmt->execute();
        $sizes = [];
        foreach ($sizeStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $sizes[] = ['value' => $row['size'], 'count' => (int) $row['cnt']];
        }

        $donorStmt = $this->db->prepare(
            "SELECT submitter_name, COUNT(*) AS cnt FROM lstr_codes
             WHERE status = 'pending' AND submitter_name IS NOT NULL AND submitter_name != ''
             GROUP BY submitter_name ORDER BY submitter_name ASC"
        );
        $donorStmt->execute();
        $donors = [];
        foreach ($donorStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $donors[] = ['value' => $row['submitter_name'], 'count' => (int) $row['cnt']];
        }

        return ['sizes' => $sizes, 'donors' => $donors];
    }

    /**
     * Returns a paginated slice of pending codes across all listings with optional filters.
     * Filters: size (WxH string), donor (submitter_name), search (listing subject/title or submitter_name).
     *
     * @param array{size?:string,donor?:string,search?:string,listing_id?:int} $filters
     * @return array{items: object[], total: int, pages: int}
     */
    public function getPendingAllPaginated(int $perPage, int $page, array $filters = []): array
    {
        $conditions = ["status = 'pending'"];
        $params     = [];

        if (($filters['listing_id'] ?? 0) > 0) {
            $conditions[] = 'fanlisting_id = :fid';
            $params['fid'] = (int) $filters['listing_id'];
        }

        if (($filters['size'] ?? '') !== '') {
            $parts = explode('x', (string) $filters['size'], 2);
            if (count($parts) === 2 && ctype_digit($parts[0]) && ctype_digit($parts[1])) {
                $conditions[] = 'width = :sw AND height = :sh';
                $params['sw'] = (int) $parts[0];
                $params['sh'] = (int) $parts[1];
            }
        }

        if (($filters['donor'] ?? '') !== '') {
            $conditions[] = 'submitter_name = :donor';
            $params['donor'] = $filters['donor'];
        }

        if (($filters['search'] ?? '') !== '') {
            // Search matches submitter_name or is matched against listing names by the caller
            $conditions[] = '(submitter_name LIKE :search OR submitter_url LIKE :search2)';
            $params['search']  = '%' . $filters['search'] . '%';
            $params['search2'] = '%' . $filters['search'] . '%';
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM lstr_codes {$where}");
        $countStmt->execute($params);
        $total  = (int) $countStmt->fetchColumn();
        $pages  = max(1, (int) ceil($total / $perPage));
        $page   = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        $stmt = $this->db->prepare(
            "SELECT * FROM lstr_codes {$where}
             ORDER BY created_at DESC, id DESC
             LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $key => $val) {
            $stmt->bindValue(':' . $key, $val);
        }
        $stmt->bindValue(':limit',  $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  \PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => array_map(static fn(array $r) => (object) $r, $stmt->fetchAll()),
            'total' => $total,
            'pages' => $pages,
        ];
    }

    /**
     * Approves all pending codes — optionally scoped to one listing.
     * Returns the count of rows updated.
     */
    public function approveAllPending(?int $listingId = null): int
    {
        if ($listingId !== null) {
            $stmt = $this->db->prepare(
                "UPDATE lstr_codes SET status = 'approved'
                 WHERE status = 'pending' AND fanlisting_id = :fid"
            );
            $stmt->execute(['fid' => $listingId]);
        } else {
            $stmt = $this->db->prepare("UPDATE lstr_codes SET status = 'approved' WHERE status = 'pending'");
            $stmt->execute();
        }
        return (int) $stmt->rowCount();
    }

    /**
     * Sets status on multiple codes by ID array.
     *
     * @param int[]  $ids
     * @param string $status  'approved'|'pending'|'rejected'
     */
    public function bulkStatus(array $ids, string $status): int
    {
        if (empty($ids) || !in_array($status, self::STATUSES, true)) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params       = array_merge([$status], array_map('intval', $ids));
        $stmt = $this->db->prepare(
            "UPDATE lstr_codes SET status = ? WHERE id IN ({$placeholders})"
        );
        $stmt->execute($params);
        return (int) $stmt->rowCount();
    }

    /**
     * Returns the highest sort_order value for a listing (0 if none exist).
     */
    public function getMaxSortOrder(?int $listingId): int
    {
        $fidCond = $listingId === null ? 'fanlisting_id IS NULL' : 'fanlisting_id = :fid';
        $stmt = $this->db->prepare(
            "SELECT COALESCE(MAX(sort_order), 0) FROM lstr_codes WHERE {$fidCond}"
        );
        $stmt->execute($listingId === null ? [] : ['fid' => $listingId]);
        return (int) $stmt->fetchColumn();
    }

    // -----------------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------------

    /**
     * Inserts a new code record and returns its new ID.
     */
    public function insert(
        ?int    $listingId,
        string  $filename,
        int     $width,
        int     $height,
        int     $sortOrder,
        ?int    $sizeId         = null,
        ?int    $donorId        = null,
        string  $status         = 'approved',
        ?string $name           = null,
        ?string $submitterName  = null,
        ?string $submitterEmail = null,
        ?string $submitterUrl   = null,
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO lstr_codes (fanlisting_id, name, filename, width, height, size_id, donor_id, sort_order, status, submitter_name, submitter_email, submitter_url)
             VALUES (:fid, :name, :filename, :width, :height, :size_id, :donor_id, :sort, :status, :submitter_name, :submitter_email, :submitter_url)'
        );
        $stmt->execute([
            'fid'             => $listingId,
            'name'            => $name,
            'filename'        => $filename,
            'width'           => $width,
            'height'          => $height,
            'size_id'         => $sizeId,
            'donor_id'        => $donorId,
            'sort'            => $sortOrder,
            'status'          => $status,
            'submitter_name'  => $submitterName,
            'submitter_email' => $submitterEmail,
            'submitter_url'   => $submitterUrl,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Updates the approval status of a code.
     *
     * @param string $status One of CodesRepo::STATUSES ('approved', 'pending', 'rejected')
     */
    public function updateStatus(int $id, string $status): void
    {
        $stmt = $this->db->prepare(
            'UPDATE lstr_codes SET status = :status WHERE id = :id'
        );
        $stmt->execute(['status' => $status, 'id' => $id]);
    }

    /**
     * Sets or clears the human-readable label for a code.
     * Pass null (or an empty string normalised to null) to remove the name.
     */
    public function updateName(int $id, ?string $name): void
    {
        $stmt = $this->db->prepare(
            'UPDATE lstr_codes SET name = :name WHERE id = :id'
        );
        $stmt->execute(['name' => $name, 'id' => $id]);
    }

    /**
     * Reassigns a code to a different size (or clears it with null).
     */
    public function updateSize(int $id, ?int $sizeId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE lstr_codes SET size_id = :size_id WHERE id = :id'
        );
        $stmt->execute(['size_id' => $sizeId, 'id' => $id]);
    }

    /**
     * Sets or clears the submitter URL for a donated code.
     */
    public function updateSubmitterUrl(int $id, ?string $url): void
    {
        $stmt = $this->db->prepare(
            'UPDATE lstr_codes SET submitter_url = :url WHERE id = :id'
        );
        $stmt->execute(['url' => $url, 'id' => $id]);
    }

    public function updateSubmitterName(int $id, ?string $name): void
    {
        $stmt = $this->db->prepare(
            'UPDATE lstr_codes SET submitter_name = :name WHERE id = :id'
        );
        $stmt->execute(['name' => $name, 'id' => $id]);
    }

    public function updateSubmitterEmail(int $id, ?string $email): void
    {
        $stmt = $this->db->prepare(
            'UPDATE lstr_codes SET submitter_email = :email WHERE id = :id'
        );
        $stmt->execute(['email' => $email, 'id' => $id]);
    }

    /**
     * Reassigns a code to a different donor (or clears it with null).
     */
    public function updateDonor(int $id, ?int $donorId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE lstr_codes SET donor_id = :donor_id WHERE id = :id'
        );
        $stmt->execute(['donor_id' => $donorId, 'id' => $id]);
    }

    /**
     * Deletes a single code record by ID.
     */
    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM lstr_codes WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Bulk-updates sort_order for codes belonging to a listing.
     * Only codes that actually belong to $listingId are updated (ownership guard).
     *
     * @param int   $listingId   The fanlisting that owns these codes.
     * @param int[] $orderedIds  Code IDs in the desired display order (first = 0, etc.).
     */
    public function reorder(int $listingId, array $orderedIds): void
    {
        if (empty($orderedIds)) {
            return;
        }

        $stmt = $this->db->prepare(
            'UPDATE lstr_codes SET sort_order = :sort WHERE id = :id AND fanlisting_id = :fid'
        );

        foreach ($orderedIds as $index => $codeId) {
            $stmt->execute([
                'sort' => $index,
                'id'   => (int) $codeId,
                'fid'  => $listingId,
            ]);
        }
    }

    /**
     * Deletes all codes for a listing (used when deleting the listing itself).
     */
    public function deleteByListing(int $listingId): void
    {
        $stmt = $this->db->prepare('DELETE FROM lstr_codes WHERE fanlisting_id = :fid');
        $stmt->execute(['fid' => $listingId]);
    }

    /**
     * Bulk-updates sort_order for collective codes (fanlisting_id IS NULL).
     *
     * @param int[] $orderedIds  Code IDs in the desired display order (first = 0, etc.).
     */
    public function reorderCollective(array $orderedIds): void
    {
        if (empty($orderedIds)) {
            return;
        }

        $stmt = $this->db->prepare(
            'UPDATE lstr_codes SET sort_order = :sort WHERE id = :id AND fanlisting_id IS NULL'
        );

        foreach ($orderedIds as $index => $codeId) {
            $stmt->execute(['sort' => $index, 'id' => (int) $codeId]);
        }
    }
}
