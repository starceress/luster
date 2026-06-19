<?php

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Config;
use Luster\Core\Database;
use PDO;

/**
 * Tracks URL link-check state for fan members.
 *
 * Member URLs live in per-listing dynamic tables whose names are only known at
 * runtime. This repository maintains lstr_member_link_checks as a snapshot:
 * one row per (listing_id, member_email) with the member's name, URL, and
 * link-check state. The sync step (syncFromListing) must be run before any
 * checking can start for a given listing.
 *
 * Supports both Enthusiast schema variants, driven by 'enth_table_prefix':
 *   ''      (unprefixed): table = $dbprefix;       approved = pending=0
 *   'enth_' (standard):  table = ${dbprefix}members; approved = approved=1
 */
final class MemberLinkCheckRepo
{
    private bool   $appendMembers;
    private string $approvedWhere;

    public function __construct(
        private readonly Database $db,
        private readonly Config   $config,
    ) {
        $prefix = (string) $this->config->get('enth_table_prefix', '');

        if ($prefix === '') {
            $this->approvedWhere = 'pending = 0';
            $this->appendMembers = false;
        } else {
            $this->approvedWhere = 'approved = 1';
            $this->appendMembers = true;
        }
    }

    /** Resolves the actual member table name from the dbprefix/dbtable value. */
    private function resolveTable(string $dbprefix): string
    {
        $raw = $this->appendMembers ? $dbprefix . 'members' : $dbprefix;
        return $this->db->validatePrefix($raw);
    }

    /**
     * Copies all approved members with URLs from the per-listing member table
     * into lstr_member_link_checks. Clears any previous check records for the
     * listing first so the snapshot is always fresh.
     *
     * Returns the number of rows inserted.
     */
    public function syncFromListing(int $listingId, string $dbprefix): int
    {
        $table = $this->resolveTable($dbprefix);

        $stmt = $this->db->pdo()->prepare(
            "SELECT email, name, url
               FROM `{$table}`
              WHERE url <> '' AND ({$this->approvedWhere})
              ORDER BY name"
        );
        $stmt->execute();
        $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->clearListing($listingId);

        if (empty($members)) {
            return 0;
        }

        $insert = $this->db->pdo()->prepare(
            "INSERT INTO `lstr_member_link_checks`
                 (listing_id, member_email, member_name, member_url)
             VALUES
                 (:listing_id, :email, :name, :url)"
        );

        foreach ($members as $m) {
            $insert->execute([
                'listing_id' => $listingId,
                'email'      => (string) ($m['email'] ?? ''),
                'name'       => (string) ($m['name']  ?? ''),
                'url'        => (string) ($m['url']   ?? ''),
            ]);
        }

        return count($members);
    }

    /** Fetches a single check record by its ID; returns null when not found. */
    public function getById(int $id): ?object
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM `lstr_member_link_checks` WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_OBJ);
        return $row !== false ? $row : null;
    }

    /**
     * Returns a paginated result set plus total count for the Members tab.
     *
     * @return array{items: object[], total: int}
     */
    public function paginate(
        int    $listingId,
        string $statusFilter,
        string $search,
        string $sortCol,
        string $sortDir,
        int    $perPage,
        int    $page,
    ): array {
        $allowed = [
            'member_name'    => 'member_name',
            'member_url'     => 'member_url',
            'link_status'    => 'link_status',
            'link_checked_at'=> 'link_checked_at',
        ];
        $col     = $allowed[$sortCol] ?? 'member_name';
        $dir     = $sortDir === 'desc' ? 'DESC' : 'ASC';
        $orderBy = "`{$col}` {$dir}" . ($col !== 'member_name' ? ', member_name ASC' : '');

        $where  = ['listing_id = :listing_id'];
        $params = ['listing_id' => $listingId];

        if ($statusFilter !== '') {
            $where[]          = 'link_status = :status';
            $params['status'] = $statusFilter;
        }
        if ($search !== '') {
            $where[]          = '(member_name LIKE :search OR member_url LIKE :search OR member_email LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $whereClause = implode(' AND ', $where);
        $offset      = ($page - 1) * $perPage;

        $totalStmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM `lstr_member_link_checks` WHERE {$whereClause}"
        );
        $totalStmt->execute($params);
        $total = (int) $totalStmt->fetchColumn();

        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM `lstr_member_link_checks`
              WHERE {$whereClause}
              ORDER BY {$orderBy}
              LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => $stmt->fetchAll(PDO::FETCH_OBJ),
            'total' => $total,
        ];
    }

    /**
     * Returns link-status counts for all rows in the given listing.
     *
     * @return array{ok:int, dead:int, redirect:int, unchecked:int}
     */
    public function getLinkStats(int $listingId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT link_status, COUNT(*) AS n
               FROM `lstr_member_link_checks`
              WHERE listing_id = :listing_id
              GROUP BY link_status"
        );
        $stmt->execute(['listing_id' => $listingId]);
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        return [
            'ok'        => (int) ($rows['ok']        ?? 0),
            'dead'      => (int) ($rows['dead']       ?? 0),
            'redirect'  => (int) ($rows['redirect']   ?? 0),
            'unchecked' => (int) ($rows['unchecked']  ?? 0),
        ];
    }

    /** Returns all IDs for the listing (for the batch-check "all IDs" endpoint). */
    public function getAllIds(int $listingId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM `lstr_member_link_checks` WHERE listing_id = :listing_id ORDER BY id'
        );
        $stmt->execute(['listing_id' => $listingId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Returns all records for a listing with the given link status (Moved/Dead tabs). */
    public function getByLinkStatus(string $status, int $listingId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM `lstr_member_link_checks`
              WHERE listing_id = :listing_id AND link_status = :status
              ORDER BY member_name"
        );
        $stmt->execute(['listing_id' => $listingId, 'status' => $status]);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /** Records the result of a single URL check for the given row. */
    public function updateLinkStatus(int $id, string $status, ?string $redirectUrl): void
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE `lstr_member_link_checks`
                SET link_status        = :status,
                    link_redirect_url  = :redirect_url,
                    link_checked_at    = CURRENT_TIMESTAMP
              WHERE id = :id"
        );
        $stmt->execute([
            'id'           => $id,
            'status'       => $status,
            'redirect_url' => $redirectUrl,
        ]);
    }

    /**
     * Applies the stored redirect URL as the new canonical URL for the member.
     * Updates lstr_member_link_checks and the per-listing member table.
     *
     * Returns true on success, false when the row is not in 'redirect' state
     * or has no redirect URL stored.
     */
    public function applyRedirectUrl(int $id, string $dbprefix): bool
    {
        $row = $this->getById($id);
        if ($row === null || $row->link_status !== 'redirect' || $row->link_redirect_url === null) {
            return false;
        }

        $table = $this->resolveTable($dbprefix);

        $stmt = $this->db->pdo()->prepare(
            "UPDATE `{$table}` SET url = :url WHERE email = :email"
        );
        $stmt->execute([
            'url'   => $row->link_redirect_url,
            'email' => $row->member_email,
        ]);

        $stmt2 = $this->db->pdo()->prepare(
            "UPDATE `lstr_member_link_checks`
                SET member_url        = :url,
                    link_status       = 'unchecked',
                    link_redirect_url = NULL,
                    link_checked_at   = NULL
              WHERE id = :id"
        );
        $stmt2->execute(['url' => $row->link_redirect_url, 'id' => $id]);

        return true;
    }

    /** Resets all link-check statuses for a listing back to 'unchecked'. */
    public function resetLinkStatus(int $listingId): void
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE `lstr_member_link_checks`
                SET link_status       = 'unchecked',
                    link_redirect_url = NULL,
                    link_checked_at   = NULL
              WHERE listing_id = :listing_id"
        );
        $stmt->execute(['listing_id' => $listingId]);
    }

    /** Resets link-check statuses across every listing back to 'unchecked'. */
    public function resetLinkStatusAll(): void
    {
        $this->db->pdo()->exec(
            "UPDATE `lstr_member_link_checks`
                SET link_status       = 'unchecked',
                    link_redirect_url = NULL,
                    link_checked_at   = NULL"
        );
    }

    /** Removes all check records for a listing (called during re-sync). */
    public function clearListing(int $listingId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM `lstr_member_link_checks` WHERE listing_id = :listing_id'
        );
        $stmt->execute(['listing_id' => $listingId]);
    }

    /**
     * Inserts or updates a single member in the check table.
     * When the URL changes, the link status is reset to 'unchecked'.
     * No-op when a row already exists with the identical URL.
     */
    public function upsertMember(int $listingId, string $email, string $name, string $url): void
    {
        $pdo = $this->db->pdo();

        $sel = $pdo->prepare(
            'SELECT id, member_url FROM `lstr_member_link_checks`
              WHERE listing_id = :lid AND member_email = :email LIMIT 1'
        );
        $sel->execute(['lid' => $listingId, 'email' => $email]);
        $existing = $sel->fetch(PDO::FETCH_OBJ);

        if ($existing === false) {
            $pdo->prepare(
                'INSERT INTO `lstr_member_link_checks`
                     (listing_id, member_email, member_name, member_url)
                 VALUES (:lid, :email, :name, :url)'
            )->execute(['lid' => $listingId, 'email' => $email, 'name' => $name, 'url' => $url]);
        } else {
            $urlChanged = $existing->member_url !== $url;
            $reset = $urlChanged
                ? ", link_status = 'unchecked', link_redirect_url = NULL, link_checked_at = NULL"
                : '';
            $pdo->prepare(
                "UPDATE `lstr_member_link_checks`
                    SET member_name = :name, member_url = :url{$reset}
                  WHERE listing_id = :lid AND member_email = :email"
            )->execute(['name' => $name, 'url' => $url, 'lid' => $listingId, 'email' => $email]);
        }
    }

    // -----------------------------------------------------------------------
    // All-listings variants (no listing_id filter)
    // -----------------------------------------------------------------------

    /**
     * Paginate across every listing simultaneously.
     *
     * @return array{items: object[], total: int}
     */
    public function paginateAll(
        string $statusFilter,
        string $search,
        string $sortCol,
        string $sortDir,
        int    $perPage,
        int    $page,
    ): array {
        $allowed = [
            'member_name'     => 'member_name',
            'member_url'      => 'member_url',
            'link_status'     => 'link_status',
            'link_checked_at' => 'link_checked_at',
        ];
        $col     = $allowed[$sortCol] ?? 'member_name';
        $dir     = $sortDir === 'desc' ? 'DESC' : 'ASC';
        $orderBy = "`{$col}` {$dir}" . ($col !== 'member_name' ? ', member_name ASC' : '');

        $where  = [];
        $params = [];

        if ($statusFilter !== '') {
            $where[]          = 'link_status = :status';
            $params['status'] = $statusFilter;
        }
        if ($search !== '') {
            $where[]          = '(member_name LIKE :search OR member_url LIKE :search OR member_email LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $whereClause = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';
        $offset      = ($page - 1) * $perPage;

        $totalStmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM `lstr_member_link_checks` {$whereClause}"
        );
        $totalStmt->execute($params);
        $total = (int) $totalStmt->fetchColumn();

        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM `lstr_member_link_checks`
              {$whereClause}
              ORDER BY {$orderBy}
              LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => $stmt->fetchAll(PDO::FETCH_OBJ),
            'total' => $total,
        ];
    }

    /** Returns all IDs across every listing (for the batch-check all-IDs endpoint). */
    public function getAllIdsAll(): array
    {
        $stmt = $this->db->pdo()->query(
            'SELECT id FROM `lstr_member_link_checks` ORDER BY id'
        );
        return $stmt !== false ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    }

    /**
     * Returns link-status counts across every listing.
     *
     * @return array{ok:int, dead:int, redirect:int, unchecked:int}
     */
    public function getLinkStatsAll(): array
    {
        $stmt = $this->db->pdo()->query(
            "SELECT link_status, COUNT(*) AS n
               FROM `lstr_member_link_checks`
              GROUP BY link_status"
        );
        $rows = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_KEY_PAIR) : [];

        return [
            'ok'        => (int) ($rows['ok']        ?? 0),
            'dead'      => (int) ($rows['dead']       ?? 0),
            'redirect'  => (int) ($rows['redirect']   ?? 0),
            'unchecked' => (int) ($rows['unchecked']  ?? 0),
        ];
    }

    /** Returns all records with the given link status across every listing. */
    public function getByLinkStatusAll(string $status): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM `lstr_member_link_checks`
              WHERE link_status = :status
              ORDER BY member_name"
        );
        $stmt->execute(['status' => $status]);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /**
     * Returns one summary row per listing that has been synced into the check
     * table — used by the All Listings view to render the per-listing overview.
     *
     * @return array<int, array{total:int, ok:int, dead:int, redirect:int, unchecked:int, last_checked:?string}>
     */
    public function getPerListingSummary(): array
    {
        $stmt = $this->db->pdo()->query(
            "SELECT
                 listing_id,
                 COUNT(*)                         AS total,
                 SUM(link_status = 'ok')          AS cnt_ok,
                 SUM(link_status = 'dead')        AS cnt_dead,
                 SUM(link_status = 'redirect')    AS cnt_redirect,
                 SUM(link_status = 'unchecked')   AS cnt_unchecked,
                 MAX(link_checked_at)             AS last_checked
               FROM `lstr_member_link_checks`
              GROUP BY listing_id
              ORDER BY listing_id"
        );

        $result = [];
        foreach ($stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
            $result[(int) $row['listing_id']] = [
                'total'        => (int)    $row['total'],
                'ok'           => (int)   ($row['cnt_ok']        ?? 0),
                'dead'         => (int)   ($row['cnt_dead']       ?? 0),
                'redirect'     => (int)   ($row['cnt_redirect']   ?? 0),
                'unchecked'    => (int)   ($row['cnt_unchecked']  ?? 0),
                'last_checked' => $row['last_checked'],
            ];
        }
        return $result;
    }

    // -----------------------------------------------------------------------

    /** Removes a single member from the check table (on delete or move to pending). */
    public function removeMember(int $listingId, string $email): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM `lstr_member_link_checks`
              WHERE listing_id = :lid AND member_email = :email'
        )->execute(['lid' => $listingId, 'email' => $email]);
    }
}
