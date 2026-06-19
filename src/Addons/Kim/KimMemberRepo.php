<?php

declare(strict_types=1);

namespace Luster\Addons\Kim;

use Luster\Core\Database;

/**
 * Data access for the lstr_kim_members table.
 *
 * All queries use prepared statements. The table stores one row per
 * (email, fanlisting_id) pair — the unique key prevents duplicates.
 */
final class KimMemberRepo
{
    public function __construct(private readonly Database $db) {}

    // -----------------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------------

    /**
     * Inserts a new KIM member (always pending).
     *
     * @return int  The new row's auto-increment ID.
     */
    public function create(
        int    $fanlistingId,
        string $name,
        string $email,
        string $url,
        string $passwordHash,
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO lstr_kim_members
                (fanlisting_id, name, email, url, password, pending)
             VALUES
                (:fanlisting_id, :name, :email, :url, :password, 1)'
        );

        $stmt->execute([
            'fanlisting_id' => $fanlistingId,
            'name'          => $name,
            'email'         => $email,
            'url'           => $url,
            'password'      => $passwordHash,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Approves a pending KIM member.
     */
    public function approve(int $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE lstr_kim_members SET pending = 0 WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * Updates allowed columns on a KIM member row.
     *
     * @param array<string,mixed> $fields  Allowed keys: name, email, url, password, fanlisting_id
     */
    public function update(int $id, array $fields): void
    {
        $allowed = ['name', 'email', 'url', 'password', 'fanlisting_id', 'granted'];
        $setClauses = [];
        $params = ['id' => $id];

        foreach ($fields as $col => $value) {
            if (!in_array($col, $allowed, true)) {
                continue;
            }
            $setClauses[] = "`{$col}` = :{$col}";
            $params[$col] = $value;
        }

        if (empty($setClauses)) {
            return;
        }

        $sql = 'UPDATE lstr_kim_members SET ' . implode(', ', $setClauses) . ' WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Deletes a KIM member by ID. Cascade deletes reset tokens via FK.
     */
    public function delete(int $id): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM lstr_kim_members WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * Approves multiple KIM members by ID.
     *
     * @param int[] $ids
     */
    public function approveByIds(array $ids): void
    {
        if (empty($ids)) {
            return;
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $this->db->prepare(
            "UPDATE lstr_kim_members SET pending = 0 WHERE id IN ({$placeholders})"
        )->execute(array_values($ids));
    }

    /**
     * Deletes multiple KIM members by ID. Cascade deletes reset tokens via FK.
     *
     * @param int[] $ids
     */
    public function deleteByIds(array $ids): void
    {
        if (empty($ids)) {
            return;
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $this->db->prepare(
            "DELETE FROM lstr_kim_members WHERE id IN ({$placeholders})"
        )->execute(array_values($ids));
    }

    // -----------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------

    /**
     * Finds a KIM member by email + fanlisting combination.
     */
    public function findByEmailAndFanlisting(string $email, int $fanlistingId): ?KimMember
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_kim_members
             WHERE email = :email AND fanlisting_id = :fanlisting_id
             LIMIT 1'
        );
        $stmt->execute(['email' => $email, 'fanlisting_id' => $fanlistingId]);
        $row = $stmt->fetch();

        return $row !== false ? KimMember::fromRow($row) : null;
    }

    /**
     * Finds a KIM member by primary key.
     */
    public function findById(int $id): ?KimMember
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_kim_members WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row !== false ? KimMember::fromRow($row) : null;
    }

    /**
     * Returns all approved (non-pending) KIM members for a fanlisting.
     *
     * @return KimMember[]
     */
    public function getApprovedByFanlisting(int $fanlistingId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_kim_members
             WHERE fanlisting_id = :fanlisting_id AND pending = 0
             ORDER BY name ASC'
        );
        $stmt->execute(['fanlisting_id' => $fanlistingId]);

        return array_map(
            static fn(array $row) => KimMember::fromRow($row),
            $stmt->fetchAll()
        );
    }

    /**
     * Returns all pending members for a fanlisting (unpaginated — pending lists are small).
     *
     * @return KimMember[]
     */
    public function getPendingByFanlisting(int $fanlistingId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_kim_members
             WHERE fanlisting_id = :fanlisting_id AND pending = 1
             ORDER BY added_at ASC'
        );
        $stmt->execute(['fanlisting_id' => $fanlistingId]);

        return array_map(
            static fn(array $row) => KimMember::fromRow($row),
            $stmt->fetchAll()
        );
    }

    /**
     * Returns a page of approved members for a fanlisting.
     *
     * @return KimMember[]
     */
    public function getApprovedByFanlistingPaged(int $fanlistingId, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;
        $stmt   = $this->db->prepare(
            'SELECT * FROM lstr_kim_members
             WHERE fanlisting_id = :fanlisting_id AND pending = 0
             ORDER BY name ASC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('fanlisting_id', $fanlistingId, \PDO::PARAM_INT);
        $stmt->bindValue('limit',         $perPage,      \PDO::PARAM_INT);
        $stmt->bindValue('offset',        $offset,       \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn(array $row) => KimMember::fromRow($row),
            $stmt->fetchAll()
        );
    }

    /**
     * Returns all KIM members for a fanlisting — pending first, then approved.
     *
     * @return KimMember[]
     */
    public function getAllByFanlisting(int $fanlistingId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_kim_members
             WHERE fanlisting_id = :fanlisting_id
             ORDER BY pending DESC, name ASC'
        );
        $stmt->execute(['fanlisting_id' => $fanlistingId]);

        return array_map(
            static fn(array $row) => KimMember::fromRow($row),
            $stmt->fetchAll()
        );
    }

    /**
     * Returns pending counts grouped by fanlisting_id.
     *
     * @return array<int, int>  Map of fanlisting_id => pending count
     */
    public function getPendingCounts(): array
    {
        $stmt = $this->db->prepare(
            'SELECT fanlisting_id, COUNT(*) AS cnt
             FROM lstr_kim_members
             WHERE pending = 1
             GROUP BY fanlisting_id'
        );
        $stmt->execute();

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['fanlisting_id']] = (int) $row['cnt'];
        }

        return $result;
    }

    /**
     * Counts approved members for a given fanlisting.
     */
    public function countApprovedByFanlisting(int $fanlistingId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM lstr_kim_members
             WHERE fanlisting_id = :fanlisting_id AND pending = 0'
        );
        $stmt->execute(['fanlisting_id' => $fanlistingId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Returns approved member counts for every fanlisting in a single query.
     *
     * @return array<int, int>  Map of fanlisting_id => approved count (only IDs with at least one approved member included)
     */
    public function getApprovedCountsAll(): array
    {
        $stmt = $this->db->prepare(
            'SELECT fanlisting_id, COUNT(*) AS cnt
             FROM lstr_kim_members
             WHERE pending = 0
             GROUP BY fanlisting_id'
        );
        $stmt->execute();

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['fanlisting_id']] = (int) $row['cnt'];
        }

        return $result;
    }

    /**
     * Returns id + name + email + url + added_at for a specific set of member IDs,
     * keyed by member ID. Used for bulk email-selected.
     *
     * @param  int[]    $ids
     * @param  int|null $fanlistingId  When set, restricts to that fanlisting (prevents cross-listing ID spoofing)
     * @return array<int, array{id: int, name: string, email: string, url: string, added_at: string}>
     */
    public function getEmailsByIds(array $ids, ?int $fanlistingId = null): array
    {
        if (empty($ids)) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $params       = array_values($ids);
        $flClause     = '';
        if ($fanlistingId !== null) {
            $flClause = ' AND fanlisting_id = ?';
            $params[] = $fanlistingId;
        }
        $stmt = $this->db->prepare(
            "SELECT id, name, email, url, added_at FROM lstr_kim_members
              WHERE id IN ({$placeholders}){$flClause}
              ORDER BY name ASC"
        );
        $stmt->execute($params);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $id = (int) $row['id'];
            $result[$id] = [
                'id'       => $id,
                'name'     => (string) $row['name'],
                'email'    => (string) $row['email'],
                'url'      => (string) ($row['url'] ?? ''),
                'added_at' => (string) ($row['added_at'] ?? ''),
            ];
        }
        return $result;
    }

    /**
     * Returns name + email + url + added_at for approved members of a fanlisting.
     * Used for bulk email.
     *
     * @return array<int, array{name: string, email: string, url: string, added_at: string}>
     */
    public function getEmailsByFanlisting(int $fanlistingId): array
    {
        $stmt = $this->db->prepare(
            'SELECT name, email, url, added_at FROM lstr_kim_members
             WHERE fanlisting_id = :fanlisting_id AND pending = 0
             ORDER BY name ASC'
        );
        $stmt->execute(['fanlisting_id' => $fanlistingId]);

        return array_map(
            static fn(array $row) => [
                'name'     => (string) $row['name'],
                'email'    => (string) $row['email'],
                'url'      => (string) ($row['url'] ?? ''),
                'added_at' => (string) ($row['added_at'] ?? ''),
            ],
            $stmt->fetchAll()
        );
    }

    // -----------------------------------------------------------------------
    // Filtered queries (unified tabbed index)
    // -----------------------------------------------------------------------

    /**
     * Returns a filtered, paginated list of KIM members.
     *
     * @param  int    $listingId  0 = all fanlistings
     * @param  string $tab        'all' | 'approved' | 'pending' | 'granted'
     * @param  string $search     Partial name match
     * @param  int    $perPage
     * @param  int    $offset
     * @return KimMember[]
     */
    public function getFiltered(
        int $listingId,
        string $tab,
        string $search,
        int $perPage,
        int $offset,
        string $sort = 'added_at',
        string $dir  = 'desc'
    ): array {
        [$where, $params] = $this->buildFilterConditions($listingId, $tab, $search);
        $orderBy = $this->resolveOrderBy($sort, $dir);

        $stmt = $this->db->prepare(
            "SELECT * FROM lstr_kim_members
             WHERE {$where}
             ORDER BY {$orderBy}
             LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $key => $value) {
            $type = is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR;
            $stmt->bindValue($key, $value, $type);
        }
        $stmt->bindValue('limit',  $perPage, \PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset,  \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn(array $row) => KimMember::fromRow($row),
            $stmt->fetchAll()
        );
    }

    /**
     * Resolves the ORDER BY clause for getFiltered().
     * All column names are hardcoded — no user input is interpolated directly.
     */
    private function resolveOrderBy(string $sort, string $dir): string
    {
        $d = $dir === 'asc' ? 'ASC' : 'DESC';
        return match ($sort) {
            'name'    => "name {$d}, added_at DESC",
            'email'   => "email {$d}, name ASC",
            'status'  => $d === 'ASC'
                             ? 'pending DESC, granted DESC, name ASC'
                             : 'pending ASC, granted ASC, name ASC',
            'listing' => "fanlisting_id {$d}, name ASC",
            default   => "added_at {$d}, name ASC",
        };
    }

    /**
     * Counts KIM members matching the given filters.
     */
    public function countFiltered(int $listingId, string $tab, string $search): int
    {
        [$where, $params] = $this->buildFilterConditions($listingId, $tab, $search);

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM lstr_kim_members WHERE {$where}"
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Returns a map of fanlisting_id → total KIM member count (all statuses).
     * Used to show member counts next to listing filter options.
     *
     * @return array<int, int>
     */
    public function getCountsByListing(): array
    {
        $stmt = $this->db->prepare(
            'SELECT fanlisting_id, COUNT(*) AS cnt
             FROM lstr_kim_members
             GROUP BY fanlisting_id'
        );
        $stmt->execute();

        $counts = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $counts[(int) $row['fanlisting_id']] = (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * Returns tab counts (all / approved / pending / granted) in one query.
     * Applies listing and search filters but ignores tab.
     *
     * @return array{all: int, approved: int, pending: int, granted: int}
     */
    public function getTabCounts(int $listingId, string $search): array
    {
        [$where, $params] = $this->buildFilterConditions($listingId, 'all', $search);

        $stmt = $this->db->prepare(
            "SELECT
               COUNT(*)                                                      AS cnt_all,
               SUM(CASE WHEN pending = 1                THEN 1 ELSE 0 END)  AS cnt_pending,
               SUM(CASE WHEN pending = 0 AND granted = 0 THEN 1 ELSE 0 END) AS cnt_approved,
               SUM(CASE WHEN pending = 0 AND granted = 1 THEN 1 ELSE 0 END) AS cnt_granted
             FROM lstr_kim_members
             WHERE {$where}"
        );
        $stmt->execute($params);
        $row = $stmt->fetch() ?: [];

        return [
            'all'      => (int) ($row['cnt_all']      ?? 0),
            'approved' => (int) ($row['cnt_approved']  ?? 0),
            'pending'  => (int) ($row['cnt_pending']   ?? 0),
            'granted'  => (int) ($row['cnt_granted']   ?? 0),
        ];
    }

    /**
     * Returns pending/approved/granted counts grouped by fanlisting in one query.
     * Used by the Listings overview page to avoid N+1 queries.
     *
     * @return array<int, array{pending: int, approved: int, granted: int}>
     */
    public function getAllCountsByFanlisting(): array
    {
        $stmt = $this->db->prepare(
            "SELECT
               fanlisting_id,
               SUM(CASE WHEN pending = 1                THEN 1 ELSE 0 END)  AS pending_cnt,
               SUM(CASE WHEN pending = 0 AND granted = 0 THEN 1 ELSE 0 END) AS approved_cnt,
               SUM(CASE WHEN pending = 0 AND granted = 1 THEN 1 ELSE 0 END) AS granted_cnt
             FROM lstr_kim_members
             GROUP BY fanlisting_id"
        );
        $stmt->execute();

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['fanlisting_id']] = [
                'pending'  => (int) $row['pending_cnt'],
                'approved' => (int) $row['approved_cnt'],
                'granted'  => (int) $row['granted_cnt'],
            ];
        }

        return $result;
    }

    /**
     * Builds the WHERE clause and params for filtered KIM queries.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildFilterConditions(
        int $listingId,
        string $tab,
        string $search
    ): array {
        $conditions = [];
        $params     = [];

        if ($listingId > 0) {
            $conditions[]            = 'fanlisting_id = :fanlisting_id';
            $params['fanlisting_id'] = $listingId;
        }

        if ($tab === 'pending') {
            $conditions[] = 'pending = 1';
        } elseif ($tab === 'approved') {
            $conditions[] = '(pending = 0 AND granted = 0)';
        } elseif ($tab === 'granted') {
            $conditions[] = '(pending = 0 AND granted = 1)';
        }

        if ($search !== '') {
            $conditions[]       = '(name LIKE :search_n OR email LIKE :search_e)';
            $params['search_n'] = '%' . $search . '%';
            $params['search_e'] = '%' . $search . '%';
        }

        $where = $conditions !== [] ? implode(' AND ', $conditions) : '1=1';

        return [$where, $params];
    }
}
