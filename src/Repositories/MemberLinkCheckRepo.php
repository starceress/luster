<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Tracks URL link-check state for fan members in lstr_member_link_checks.
 Because member URLs live in per-listing dynamic tables whose names are
 only known at runtime, this repo maintains a snapshot table: one row per
 (listing_id, member_email) with the member's URL and link-check state.
 The sync step must run before any checking starts for a given listing.
 Supports per-listing and cross-listing paginated views, redirect-URL
 application back to the source member table, and per-listing summaries.
*/

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

    /** Column sizes in lstr_member_link_checks, in characters. */
    private const MAX_EMAIL = 255;
    private const MAX_NAME  = 255;
    private const MAX_URL   = 500;

    /**
     * Rebuilds a listing's snapshot in lstr_member_link_checks from its member table:
     * every approved member, including ones with no URL set, which get
     * link_status='no_url' up front so they never get sent through a live check (an
     * empty URL would otherwise misreport as a dead link) and read distinctly from a
     * link that's actually broken.
     *
     * A successful sync replaces the listing's earlier rows, so its earlier check
     * results are reset. It's all or nothing: the delete and the inserts run in one
     * transaction, and if any of them fails the listing's rows are left exactly as they
     * were and the error is rethrown. Because the sync owns that transaction, it refuses
     * to run inside one the caller already opened.
     *
     * Members that can't be stored are skipped and reported rather than failing the
     * whole scan: a blank email, an email over 255 characters, a URL over 500, and all
     * but one of several members whose emails differ only in letter case or surrounding
     * spaces (the check table's key treats those as the same email). Among those, one
     * with a usable URL is kept; any remaining tie is broken byte by byte on name, email
     * and URL, so the choice never depends on the order the database returns rows in.
     * The kept member's email is stored exactly as the member table has it. A name over
     * 255 characters is cut to fit (it's only displayed).
     *
     * @return array{synced: int, skipped: list<array{email: string, reason: string}>}
     * @throws \LogicException when called inside an open transaction.
     */
    public function syncFromListing(int $listingId, string $dbprefix): array
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException('syncFromListing() runs its own transaction; call it outside one.');
        }

        $table = $this->resolveTable($dbprefix);

        $stmt = $pdo->prepare(
            "SELECT email, name, url
               FROM `{$table}`
              WHERE {$this->approvedWhere}
              ORDER BY name"
        );
        $stmt->execute();
        ['rows' => $rows, 'skipped' => $skipped] = self::chooseMembers($stmt->fetchAll(PDO::FETCH_ASSOC));

        // Begun outside the try: if this throws, there's nothing of ours to roll back.
        $pdo->beginTransaction();
        try {
            $this->clearListing($listingId);

            if ($rows !== []) {
                $insert = $pdo->prepare(
                    "INSERT INTO `lstr_member_link_checks`
                         (listing_id, member_email, member_name, member_url, link_status)
                     VALUES
                         (:listing_id, :email, :name, :url, :link_status)"
                );

                foreach ($rows as $m) {
                    $insert->execute([
                        'listing_id'  => $listingId,
                        'email'       => $m['email'],
                        'name'        => $m['name'],
                        'url'         => $m['url'],
                        'link_status' => $m['url'] === '' ? 'no_url' : 'unchecked',
                    ]);
                }
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['synced' => count($rows), 'skipped' => $skipped];
    }

    /**
     * Which members to store and which to skip (with why), both in the order they
     * were read. See syncFromListing() for the rules.
     *
     * @param list<array<string, mixed>> $members Rows with email, name and url.
     * @return array{rows: list<array{email: string, name: string, url: string}>, skipped: list<array{email: string, reason: string}>}
     */
    private static function chooseMembers(array $members): array
    {
        $candidates = [];
        $reasons    = []; // index => why it's skipped
        $groups     = []; // email, lowercased and trimmed => indexes

        foreach (array_values($members) as $i => $member) {
            $m = [
                'email' => (string) ($member['email'] ?? ''),
                'name'  => (string) ($member['name'] ?? ''),
                'url'   => (string) ($member['url'] ?? ''),
            ];
            $candidates[$i] = $m;

            if (trim($m['email']) === '') {
                $reasons[$i] = 'No email address';
            } elseif (mb_strlen($m['email'], 'UTF-8') > self::MAX_EMAIL) {
                $reasons[$i] = 'Email address too long to check';
            } elseif (mb_strlen($m['url'], 'UTF-8') > self::MAX_URL) {
                $reasons[$i] = 'Website address too long to check';
            } else {
                $groups[mb_strtolower(trim($m['email']), 'UTF-8')][] = $i;
            }
        }

        $kept = [];
        foreach ($groups as $indexes) {
            usort($indexes, static fn(int $a, int $b): int => self::compareDuplicates($candidates[$a], $candidates[$b]));
            $kept[array_shift($indexes)] = true;
            foreach ($indexes as $i) {
                $reasons[$i] = 'Same email as another member';
            }
        }

        $rows    = [];
        $skipped = [];
        foreach ($candidates as $i => $m) {
            if (isset($kept[$i])) {
                $m['name'] = mb_substr($m['name'], 0, self::MAX_NAME, 'UTF-8');
                $rows[]    = $m;
            } else {
                $skipped[] = ['email' => $m['email'], 'reason' => $reasons[$i]];
            }
        }

        return ['rows' => $rows, 'skipped' => $skipped];
    }

    /**
     * Orders members that share an email: one with a usable (non-blank) URL first, then
     * name, email and URL compared byte by byte.
     *
     * @param array{email: string, name: string, url: string} $a
     * @param array{email: string, name: string, url: string} $b
     */
    private static function compareDuplicates(array $a, array $b): int
    {
        $aHasUrl = trim($a['url']) !== '';
        $bHasUrl = trim($b['url']) !== '';
        if ($aHasUrl !== $bHasUrl) {
            return $aHasUrl ? -1 : 1;
        }

        return strcmp($a['name'], $b['name'])
            ?: strcmp($a['email'], $b['email'])
            ?: strcmp($a['url'], $b['url']);
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
            'no_url'    => (int) ($rows['no_url']     ?? 0),
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
                SET link_status        = 'unchecked',
                    link_redirect_url  = NULL,
                    link_checked_at    = NULL,
                    backlink_status    = 'unchecked',
                    backlink_checked_at = NULL
              WHERE listing_id = :listing_id"
        );
        $stmt->execute(['listing_id' => $listingId]);
    }

    /** Resets link-check statuses across every listing back to 'unchecked'. */
    public function resetLinkStatusAll(): void
    {
        $this->db->pdo()->exec(
            "UPDATE `lstr_member_link_checks`
                SET link_status        = 'unchecked',
                    link_redirect_url  = NULL,
                    link_checked_at    = NULL,
                    backlink_status    = 'unchecked',
                    backlink_checked_at = NULL"
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
     * @return array{ok:int, dead:int, redirect:int, unchecked:int, no_url:int}
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
            'no_url'    => (int) ($rows['no_url']     ?? 0),
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
     * @return array<int, array{total:int, ok:int, dead:int, redirect:int, unchecked:int, no_url:int, last_checked:?string}>
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
                 SUM(link_status = 'no_url')      AS cnt_no_url,
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
                'no_url'       => (int)   ($row['cnt_no_url']     ?? 0),
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

    /**
     * Re-keys a member's link-check row to their new email address. Called
     * when an admin changes a member's email from the Edit Member page, so
     * the cached check state (and history) stays with the row instead of
     * being orphaned under the old address while upsertMember() creates a
     * fresh, un-checked row under the new one.
     */
    public function renameMemberEmail(int $listingId, string $oldEmail, string $newEmail): void
    {
        $this->db->pdo()->prepare(
            'UPDATE `lstr_member_link_checks` SET member_email = :new
              WHERE listing_id = :lid AND member_email = :old'
        )->execute(['new' => $newEmail, 'lid' => $listingId, 'old' => $oldEmail]);
    }

    // -----------------------------------------------------------------------
    // Backlink (Step 4.1) — does the member's site link back to the fanlisting?
    // -----------------------------------------------------------------------

    /**
     * Persists the result of a backlink check for one member record.
     *
     * @param string $status  'found' | 'missing' | 'unchecked'
     */
    public function updateBacklinkStatus(int $id, string $status): void
    {
        $this->db->pdo()->prepare(
            "UPDATE `lstr_member_link_checks`
                SET backlink_status     = :status,
                    backlink_checked_at = NOW()
              WHERE id = :id"
        )->execute(['status' => $status, 'id' => $id]);
    }

    /** Resets the backlink state for a single listing back to 'unchecked'. */
    public function resetBacklinkStatus(int $listingId): void
    {
        $this->db->pdo()->prepare(
            "UPDATE `lstr_member_link_checks`
                SET backlink_status     = 'unchecked',
                    backlink_checked_at = NULL
              WHERE listing_id = :lid"
        )->execute(['lid' => $listingId]);
    }

    /** Resets the backlink state across all listings back to 'unchecked'. */
    public function resetBacklinkStatusAll(): void
    {
        $this->db->pdo()->exec(
            "UPDATE `lstr_member_link_checks`
                SET backlink_status     = 'unchecked',
                    backlink_checked_at = NULL"
        );
    }

    /**
     * Returns backlink status counts for a single listing.
     *
     * @return array{found:int, missing:int, unchecked:int}
     */
    public function getBacklinkStats(int $listingId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT backlink_status, COUNT(*) AS n
               FROM `lstr_member_link_checks`
              WHERE listing_id = :lid
              GROUP BY backlink_status"
        );
        $stmt->execute(['lid' => $listingId]);
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        return [
            'found'     => (int) ($rows['found']     ?? 0),
            'missing'   => (int) ($rows['missing']   ?? 0),
            'unchecked' => (int) ($rows['unchecked'] ?? 0),
        ];
    }

    /**
     * Returns backlink status counts across all listings.
     *
     * @return array{found:int, missing:int, unchecked:int}
     */
    public function getBacklinkStatsAll(): array
    {
        $stmt = $this->db->pdo()->query(
            "SELECT backlink_status, COUNT(*) AS n
               FROM `lstr_member_link_checks`
              GROUP BY backlink_status"
        );
        $rows = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_KEY_PAIR) : [];

        return [
            'found'     => (int) ($rows['found']     ?? 0),
            'missing'   => (int) ($rows['missing']   ?? 0),
            'unchecked' => (int) ($rows['unchecked'] ?? 0),
        ];
    }

    /** Returns all IDs for a single listing (backlink batch). */
    public function getAllBacklinkIds(int $listingId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM `lstr_member_link_checks`
              WHERE listing_id = :lid AND member_url != \'\'
              ORDER BY id'
        );
        $stmt->execute(['lid' => $listingId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Returns all IDs across every listing (backlink batch for "all listings"). */
    public function getAllBacklinkIdsAll(): array
    {
        $stmt = $this->db->pdo()->query(
            "SELECT id FROM `lstr_member_link_checks`
              WHERE member_url != ''
              ORDER BY id"
        );
        return $stmt !== false ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    }
}
