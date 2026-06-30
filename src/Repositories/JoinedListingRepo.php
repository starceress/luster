<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Manages the site owner's joined-listing tracker in lstr_joined. Each row
 represents a fanlisting the owner has joined as a member, with category
 metadata stored as both a legacy string and a structured JSON column.
 Supports paginated display, search, category/network filtering, status
 tabs, bulk operations, link checking with redirect-URL application, and
 the latest/random joined-listing embed queries.
*/

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Database;
use Luster\Models\JoinedListing;

/**
 * Data access for the lstr_joined table.
 *
 * Each row represents a fanlisting the site owner has joined as a member.
 * Category metadata is stored both as a legacy plain string (category) and
 * as a structured JSON column (categories_json) for multi-network support.
 * The active flag controls public visibility independently of the status
 * field ('approved' | 'pending' | 'hiatus' etc.).
 *
 * Includes link-checker integration: link_status, link_redirect_url, and
 * link_checked_at track whether the stored URL is alive, moved, or dead.
 */
final class JoinedListingRepo
{
    public function __construct(private readonly Database $db) {}

    // -----------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------

    /**
     * Returns a paginated page of joined listings, sorted by category then name.
     *
     * @return array{items: JoinedListing[], total: int, pages: int}
     */
    public function getAll(int $perPage = 10, int $page = 1, string $filterCat = '', string $statusTab = 'all'): array
    {
        $offset     = ($page - 1) * $perPage;
        $catPattern = '%"name":"' . $filterCat . '"%';

        $statusClause = match ($statusTab) {
            'approved'        => "status = 'approved'",
            'pending'         => "status = 'pending'",
            'hidden'          => 'active = 0',
            'visible'         => 'active = 1',
            'visible_pending' => "status = 'pending' AND active = 1",
            default           => '',
        };

        $whereParts = array_filter([
            $filterCat !== '' ? '(category = :cat OR categories_json LIKE :catj)' : '',
            $statusClause,
        ], static fn(string $s) => $s !== '');
        $whereClause = $whereParts !== [] ? 'WHERE ' . implode(' AND ', $whereParts) : '';

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM lstr_joined {$whereClause}");
        if ($filterCat !== '') {
            $countStmt->bindValue(':cat',  $filterCat,  \PDO::PARAM_STR);
            $countStmt->bindValue(':catj', $catPattern, \PDO::PARAM_STR);
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->prepare(
            "SELECT * FROM lstr_joined {$whereClause}
             ORDER BY category ASC, name ASC
             LIMIT :limit OFFSET :offset"
        );
        if ($filterCat !== '') {
            $stmt->bindValue(':cat',  $filterCat,  \PDO::PARAM_STR);
            $stmt->bindValue(':catj', $catPattern, \PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit',  $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  \PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => array_map(static fn(array $r) => JoinedListing::fromRow($r), $stmt->fetchAll()),
            'total' => $total,
            'pages' => $total > 0 ? (int) ceil($total / $perPage) : 1,
        ];
    }

    /**
     * Returns row counts for each status tab in one query, optionally scoped
     * to the same search/network/category filters the listing view uses.
     *
     * @return array{all: int, approved: int, pending: int, hidden: int, visible_pending: int}
     */
    public function countAllByStatusTab(string $search = '', string $filterNetwork = '', string $filterCat = ''): array
    {
        $conditions = [];
        $params     = [];

        if ($search !== '') {
            $pattern       = '%' . $search . '%';
            $conditions[]  = '(name LIKE :q1 OR url LIKE :q2 OR category LIKE :q3 OR categories_json LIKE :q4)';
            $params[':q1'] = $pattern;
            $params[':q2'] = $pattern;
            $params[':q3'] = $pattern;
            $params[':q4'] = $pattern;
        }

        if ($filterNetwork !== '') {
            $conditions[]   = 'categories_json LIKE :net';
            $params[':net'] = '%' . $filterNetwork . '%';
        }

        if ($filterCat !== '') {
            $conditions[]    = '(category = :cat OR categories_json LIKE :catj)';
            $params[':cat']  = $filterCat;
            $params[':catj'] = '%"name":"' . $filterCat . '"%';
        }

        $whereClause = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $stmt = $this->db->prepare(
            "SELECT
                 COUNT(*) AS all_count,
                 SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
                 SUM(CASE WHEN status = 'pending'  THEN 1 ELSE 0 END) AS pending_count,
                 SUM(CASE WHEN active = 0          THEN 1 ELSE 0 END) AS hidden_count,
                 SUM(CASE WHEN status = 'pending' AND active = 1 THEN 1 ELSE 0 END) AS visible_pending_count
             FROM lstr_joined {$whereClause}"
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, \PDO::PARAM_STR);
        }
        $stmt->execute();
        $row = $stmt->fetch();

        return [
            'all'             => (int) ($row['all_count']             ?? 0),
            'approved'        => (int) ($row['approved_count']        ?? 0),
            'pending'         => (int) ($row['pending_count']         ?? 0),
            'hidden'          => (int) ($row['hidden_count']          ?? 0),
            'visible_pending' => (int) ($row['visible_pending_count'] ?? 0),
        ];
    }

    /**
     * Full-text search across name, url, category, and categories_json,
     * optionally narrowed to a specific network name.
     *
     * @return array{items: JoinedListing[], total: int, pages: int}
     */
    public function search(string $q, string $network, int $perPage = 10, int $page = 1, string $filterCat = '', string $statusTab = 'all'): array
    {
        $offset     = ($page - 1) * $perPage;
        $pattern    = '%' . $q . '%';
        $catClause  = $filterCat !== '' ? ' AND (category = :cat OR categories_json LIKE :catj)' : '';
        $catPattern = '%"name":"' . $filterCat . '"%';

        $tabClause = match ($statusTab) {
            'approved' => " AND status = 'approved'",
            'pending'  => " AND status = 'pending'",
            'hidden'   => ' AND active = 0',
            default    => '',
        };

        if ($network !== '') {
            $netPattern = '%' . $network . '%';

            $countStmt = $this->db->prepare(
                "SELECT COUNT(*) FROM lstr_joined
                 WHERE (name LIKE :q1 OR url LIKE :q2 OR category LIKE :q3 OR categories_json LIKE :q4)
                   AND categories_json LIKE :net{$catClause}{$tabClause}"
            );
            $countStmt->bindValue(':q1',  $pattern,    \PDO::PARAM_STR);
            $countStmt->bindValue(':q2',  $pattern,    \PDO::PARAM_STR);
            $countStmt->bindValue(':q3',  $pattern,    \PDO::PARAM_STR);
            $countStmt->bindValue(':q4',  $pattern,    \PDO::PARAM_STR);
            $countStmt->bindValue(':net', $netPattern, \PDO::PARAM_STR);
            if ($filterCat !== '') {
                $countStmt->bindValue(':cat',  $filterCat,  \PDO::PARAM_STR);
                $countStmt->bindValue(':catj', $catPattern, \PDO::PARAM_STR);
            }
            $countStmt->execute();
            $total = (int) $countStmt->fetchColumn();

            $stmt = $this->db->prepare(
                "SELECT * FROM lstr_joined
                 WHERE (name LIKE :q1 OR url LIKE :q2 OR category LIKE :q3 OR categories_json LIKE :q4)
                   AND categories_json LIKE :net{$catClause}{$tabClause}
                 ORDER BY name ASC
                 LIMIT :limit OFFSET :offset"
            );
            $stmt->bindValue(':q1',     $pattern,    \PDO::PARAM_STR);
            $stmt->bindValue(':q2',     $pattern,    \PDO::PARAM_STR);
            $stmt->bindValue(':q3',     $pattern,    \PDO::PARAM_STR);
            $stmt->bindValue(':q4',     $pattern,    \PDO::PARAM_STR);
            $stmt->bindValue(':net',    $netPattern, \PDO::PARAM_STR);
            if ($filterCat !== '') {
                $stmt->bindValue(':cat',  $filterCat,  \PDO::PARAM_STR);
                $stmt->bindValue(':catj', $catPattern, \PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit',  $perPage, \PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset,  \PDO::PARAM_INT);
        } else {
            $countStmt = $this->db->prepare(
                "SELECT COUNT(*) FROM lstr_joined
                 WHERE (name LIKE :q1 OR url LIKE :q2 OR category LIKE :q3 OR categories_json LIKE :q4){$catClause}{$tabClause}"
            );
            $countStmt->bindValue(':q1', $pattern, \PDO::PARAM_STR);
            $countStmt->bindValue(':q2', $pattern, \PDO::PARAM_STR);
            $countStmt->bindValue(':q3', $pattern, \PDO::PARAM_STR);
            $countStmt->bindValue(':q4', $pattern, \PDO::PARAM_STR);
            if ($filterCat !== '') {
                $countStmt->bindValue(':cat',  $filterCat,  \PDO::PARAM_STR);
                $countStmt->bindValue(':catj', $catPattern, \PDO::PARAM_STR);
            }
            $countStmt->execute();
            $total = (int) $countStmt->fetchColumn();

            $stmt = $this->db->prepare(
                "SELECT * FROM lstr_joined
                 WHERE (name LIKE :q1 OR url LIKE :q2 OR category LIKE :q3 OR categories_json LIKE :q4){$catClause}{$tabClause}
                 ORDER BY name ASC
                 LIMIT :limit OFFSET :offset"
            );
            $stmt->bindValue(':q1',     $pattern, \PDO::PARAM_STR);
            $stmt->bindValue(':q2',     $pattern, \PDO::PARAM_STR);
            $stmt->bindValue(':q3',     $pattern, \PDO::PARAM_STR);
            $stmt->bindValue(':q4',     $pattern, \PDO::PARAM_STR);
            if ($filterCat !== '') {
                $stmt->bindValue(':cat',  $filterCat,  \PDO::PARAM_STR);
                $stmt->bindValue(':catj', $catPattern, \PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit',  $perPage, \PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset,  \PDO::PARAM_INT);
        }

        $stmt->execute();

        return [
            'items' => array_map(static fn(array $r) => JoinedListing::fromRow($r), $stmt->fetchAll()),
            'total' => $total,
            'pages' => $total > 0 ? (int) ceil($total / $perPage) : 1,
        ];
    }

    /**
     * Returns all joined listing IDs matching the given search/network/cat filters.
     * Used by the bulk handler when "select all across pages" is triggered.
     *
     * @return int[]
     */
    public function getAllIdsByFilter(string $search, string $network, string $cat, string $statusTab = 'all'): array
    {
        if ($search !== '' || $network !== '') {
            $result = $this->search($search, $network, PHP_INT_MAX, 1, $cat, $statusTab);
        } else {
            $result = $this->getAll(PHP_INT_MAX, 1, $cat, $statusTab);
        }

        return array_map(static fn(JoinedListing $j): int => $j->id, $result['items']);
    }

    /**
     * Returns joined listings filtered by category.
     *
     * @return JoinedListing[]
     */
    public function getByCategory(string $category): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_joined
             WHERE category = :category
             ORDER BY name ASC'
        );
        $stmt->execute(['category' => $category]);

        return array_map(static fn(array $r) => JoinedListing::fromRow($r), $stmt->fetchAll());
    }

    /**
     * Returns all approved, visible (non-hidden) joined listings — the pool
     * eligible for the Random Joined Listing embed.
     *
     * @return JoinedListing[]
     */
    public function getActiveApproved(): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM lstr_joined WHERE status = 'approved' AND active = 1 ORDER BY name ASC"
        );
        $stmt->execute();

        return array_map(static fn(array $r) => JoinedListing::fromRow($r), $stmt->fetchAll());
    }

    /**
     * Returns the $limit most-recently-joined approved, visible joined listings,
     * ordered by date_joined descending (NULLs last, falling back to id descending).
     *
     * @return JoinedListing[]
     */
    public function getLatestJoined(int $limit = 1): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM lstr_joined
             WHERE status = 'approved' AND active = 1
             ORDER BY date_joined IS NULL, date_joined DESC, id DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn(array $r) => JoinedListing::fromRow($r), $stmt->fetchAll());
    }

    /**
     * Returns the total number of joined listings (active + pending).
     */
    public function countAll(): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM lstr_joined'
        );
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Returns all distinct, non-empty category names sorted alphabetically.
     *
     * For rows with a non-empty categories_json, each entry's 'name' value is
     * collected. For rows without categories_json, the legacy category string
     * is used as the fallback.
     *
     * @param bool $activeOnly When true, only considers non-hidden (active=1) rows,
     *                         so categories that only contain hidden listings are excluded.
     *                         Defaults to false for admin use (shows all categories).
     * @return string[]
     */
    public function getCategories(bool $activeOnly = false): array
    {
        $sql  = 'SELECT id, categories_json, category FROM lstr_joined';
        $sql .= $activeOnly ? ' WHERE active = 1' : '';
        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        $names = [];

        foreach ($stmt->fetchAll() as $row) {
            $json = $row['categories_json'] ?? null;

            if ($json !== null && $json !== '') {
                $decoded = json_decode($json, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $entry) {
                        if (isset($entry['name']) && $entry['name'] !== '') {
                            $names[] = (string) $entry['name'];
                        }
                    }
                }
            } elseif (isset($row['category']) && $row['category'] !== '') {
                $names[] = (string) $row['category'];
            }
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * Returns a map of category name → count of active listings in that category.
     * Only active listings are counted. Mirrors the logic in getCategories().
     *
     * @return array<string, int>
     */
    public function getCategoryCountMap(bool $activeOnly = true): array
    {
        $stmt = $this->db->prepare(
            'SELECT categories_json, category, active FROM lstr_joined'
        );
        $stmt->execute();

        $counts = [];

        foreach ($stmt->fetchAll() as $row) {
            if ($activeOnly && !(bool) $row['active']) {
                continue;
            }

            $json = $row['categories_json'] ?? null;

            if ($json !== null && $json !== '') {
                $decoded = json_decode($json, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $entry) {
                        if (isset($entry['name']) && $entry['name'] !== '') {
                            $name          = (string) $entry['name'];
                            $counts[$name] = ($counts[$name] ?? 0) + 1;
                        }
                    }
                }
            } elseif (isset($row['category']) && $row['category'] !== '') {
                $name          = (string) $row['category'];
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Returns all category names grouped by network, derived from categories_json.
     *
     * Only rows with a non-null, non-empty categories_json are considered.
     * Each network key maps to a sorted unique list of category names.
     *
     * @return array<string, string[]>  e.g. ['TFL'=>['Actors','Animals'],'TAFL'=>['Characters 0-M']]
     */
    public function getNetworkCategories(): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, categories_json FROM lstr_joined
             WHERE categories_json IS NOT NULL AND categories_json != ''"
        );
        $stmt->execute();

        $networks = [];

        foreach ($stmt->fetchAll() as $row) {
            $decoded = json_decode((string) $row['categories_json'], true);
            if (!is_array($decoded)) {
                continue;
            }
            foreach ($decoded as $entry) {
                $network = isset($entry['network']) ? trim((string) $entry['network']) : '';
                $name    = isset($entry['name'])    ? trim((string) $entry['name'])    : '';
                if ($network === '' || $name === '') {
                    continue;
                }
                $networks[$network][] = $name;
            }
        }

        foreach ($networks as $net => $names) {
            $networks[$net] = array_values(array_unique($names));
            sort($networks[$net]);
        }

        ksort($networks);

        return $networks;
    }

    /**
     * Returns a map of network name → count of joined listings in that network.
     * A listing is counted once per network even if it has multiple categories there.
     *
     * @return array<string, int>  e.g. ['TFL' => 5, 'TAFL' => 3]
     */
    public function getNetworkCounts(): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, categories_json FROM lstr_joined
             WHERE categories_json IS NOT NULL AND categories_json != ''"
        );
        $stmt->execute();

        $counts = [];

        foreach ($stmt->fetchAll() as $row) {
            $decoded = json_decode((string) $row['categories_json'], true);
            if (!is_array($decoded)) {
                continue;
            }
            $netsForRow = [];
            foreach ($decoded as $entry) {
                $network = isset($entry['network']) ? trim((string) $entry['network']) : '';
                if ($network !== '') {
                    $netsForRow[$network] = true;
                }
            }
            foreach (array_keys($netsForRow) as $net) {
                $counts[$net] = ($counts[$net] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Returns the total count of ALL joined listings (not paginated) per
     * display-mode group key. Mirrors the PHP grouping logic in the index template
     * so the group header can show accurate totals regardless of the current page.
     *
     * @param  string $displayMode 'network'|'category'|'status'|'all'
     * @return array<string, int>
     */
    public function countByGroup(string $displayMode): array
    {
        $stmt = $this->db->prepare(
            'SELECT categories_json, category, status FROM lstr_joined'
        );
        $stmt->execute();

        $counts = [];

        foreach ($stmt->fetchAll() as $row) {
            if ($displayMode === 'network') {
                $key = $this->resolveGroupKey($row, 'network');
            } elseif ($displayMode === 'category') {
                $key = $this->resolveGroupKey($row, 'name');
            } elseif ($displayMode === 'status') {
                $key = ucfirst($row['status'] ?? 'approved');
            } else {
                $key = 'All Joined';
            }
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Resolves the group key for a raw DB row using the same logic as the index
     * template: checks categories_json entries for $field, falls back to the
     * legacy category string, then to 'Uncategorised'.
     */
    private function resolveGroupKey(array $row, string $field): string
    {
        $json = $row['categories_json'] ?? null;
        if ($json !== null && $json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                foreach ($decoded as $entry) {
                    if (!empty($entry[$field])) {
                        return (string) $entry[$field];
                    }
                }
            }
        }
        if (($row['category'] ?? '') !== '') {
            return (string) $row['category'];
        }
        return 'Uncategorised';
    }

    /**
     * Returns a single joined listing by primary key, or null if not found.
     */
    public function getById(int $id): ?JoinedListing
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_joined WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row !== false ? JoinedListing::fromRow($row) : null;
    }

    // -----------------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------------

    /**
     * Inserts a new joined listing and returns the new primary key.
     *
     * @param array{name: string, url: string, image?: ?string, category?: string, categories_json?: ?string, date_joined?: ?string, active?: bool, status?: string, description?: ?string, comments?: ?string} $data
     */
    public function insert(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO lstr_joined
                (name, url, image, category, categories_json, date_joined, active, status, description, comments)
             VALUES
                (:name, :url, :image, :category, :categories_json, :date_joined, :active, :status, :description, :comments)'
        );

        $stmt->execute([
            'name'            => (string) $data['name'],
            'url'             => (string) $data['url'],
            'image'           => isset($data['image'])           && $data['image']           !== null ? (string) $data['image']           : null,
            'category'        => (string) ($data['category']        ?? ''),
            'categories_json' => isset($data['categories_json']) && $data['categories_json'] !== null ? (string) $data['categories_json'] : null,
            'date_joined'     => isset($data['date_joined'])     && $data['date_joined']     !== null ? (string) $data['date_joined']     : null,
            'active'          => isset($data['active'])          ? (int) (bool) $data['active'] : 1,
            'status'          => (string) ($data['status']          ?? 'approved'),
            'description'     => isset($data['description']) && $data['description'] !== null && $data['description'] !== '' ? (string) $data['description'] : null,
            'comments'        => isset($data['comments'])    && $data['comments']    !== null && $data['comments']    !== '' ? (string) $data['comments']    : null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Replaces the core editable fields on an existing row.
     *
     * description and comments are write-if-present: omitting either key
     * entirely leaves the stored value untouched (so older/other callers
     * that don't know about these fields can't silently erase imported
     * notes). Passing the key with a null or empty-string value clears it.
     *
     * @param array{name: string, url: string, image: ?string, category: string, categories_json: ?string, date_joined: ?string, active: bool, status: string, description?: ?string, comments?: ?string} $data
     */
    public function update(int $id, array $data): bool
    {
        $setParts = [
            'name = :name',
            'url = :url',
            'image = :image',
            'category = :category',
            'categories_json = :categories_json',
            'date_joined = :date_joined',
            'active = :active',
            'status = :status',
        ];
        $params = [
            'name'            => (string) $data['name'],
            'url'             => (string) $data['url'],
            'image'           => isset($data['image'])           && $data['image']           !== null ? (string) $data['image']           : null,
            'category'        => (string) ($data['category']        ?? ''),
            'categories_json' => isset($data['categories_json']) && $data['categories_json'] !== null ? (string) $data['categories_json'] : null,
            'date_joined'     => isset($data['date_joined'])     && $data['date_joined']     !== null ? (string) $data['date_joined']     : null,
            'active'          => (int) (bool) ($data['active']   ?? true),
            'status'          => (string) ($data['status']          ?? 'approved'),
            'id'              => $id,
        ];

        if (array_key_exists('description', $data)) {
            $setParts[] = 'description = :description';
            $params['description'] = $data['description'] !== null && $data['description'] !== '' ? (string) $data['description'] : null;
        }
        if (array_key_exists('comments', $data)) {
            $setParts[] = 'comments = :comments';
            $params['comments'] = $data['comments'] !== null && $data['comments'] !== '' ? (string) $data['comments'] : null;
        }

        $stmt = $this->db->prepare('UPDATE lstr_joined SET ' . implode(', ', $setParts) . ' WHERE id = :id');

        return $stmt->execute($params);
    }

    /**
     * Deletes a joined listing by primary key.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM lstr_joined WHERE id = :id'
        );

        return $stmt->execute(['id' => $id]);
    }

    /**
     * Flips the active flag between 1 and 0 — hides a visible listing or reveals a hidden one.
     */
    public function toggleActive(int $id): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE lstr_joined SET active = 1 - active WHERE id = :id'
        );

        return $stmt->execute(['id' => $id]);
    }

    // -----------------------------------------------------------------------
    // Link checking
    // -----------------------------------------------------------------------

    /**
     * Returns every joined listing row, ordered by name.
     * Used by the link-checker page which never paginates.
     *
     * @return JoinedListing[]
     */
    public function getAllForLinkCheck(): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_joined ORDER BY name ASC'
        );
        $stmt->execute();

        return array_map(static fn(array $r) => JoinedListing::fromRow($r), $stmt->fetchAll());
    }

    /**
     * @param string  $orderBy      Pre-validated "col DIR[, col DIR]" fragment.
     * @param ?string $statusFilter 'ok'|'redirect'|'dead'|'unchecked'|null (null = all)
     * @param ?string $search       Name substring filter (null = all)
     * @return array{items: JoinedListing[], total: int}
     */
    public function paginateForLinkCheck(int $perPage, int $page, string $orderBy = 'name ASC', ?string $statusFilter = null, ?string $search = null): array
    {
        $where  = '1=1';
        $params = [];

        if ($statusFilter === 'unchecked') {
            $where = "link_status = 'unchecked'";
        } elseif (in_array($statusFilter, ['ok', 'redirect', 'dead'], true)) {
            $where = 'link_status = :ls_filter';
            $params[':ls_filter'] = $statusFilter;
        }

        if ($search !== null && $search !== '') {
            $where .= ' AND name LIKE :lc_search';
            $params[':lc_search'] = '%' . $search . '%';
        }

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM lstr_joined WHERE {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->prepare("SELECT * FROM lstr_joined WHERE {$where} ORDER BY {$orderBy} LIMIT :lim OFFSET :off");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':off', ($page - 1) * $perPage, \PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => array_map(static fn(array $r) => JoinedListing::fromRow($r), $stmt->fetchAll()),
            'total' => $total,
        ];
    }

    /**
     * @return array{ok:int,dead:int,redirect:int,unchecked:int}
     */
    public function getLinkStats(): array
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(link_status, 'unchecked') AS s, COUNT(*) AS cnt FROM lstr_joined GROUP BY s"
        );
        $stmt->execute();
        $out = ['ok' => 0, 'dead' => 0, 'redirect' => 0, 'unchecked' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $s = $row['s'];
            if (isset($out[$s])) { $out[$s] = (int) $row['cnt']; }
            else { $out['unchecked'] += (int) $row['cnt']; }
        }
        return $out;
    }

    /**
     * Returns all joined listings with a given link_status, ordered by name.
     *
     * @return JoinedListing[]
     */
    public function getByLinkStatus(string $status): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_joined WHERE link_status = :status ORDER BY name ASC'
        );
        $stmt->execute(['status' => $status]);

        return array_map(static fn(array $r) => JoinedListing::fromRow($r), $stmt->fetchAll());
    }

    /**
     * Applies the stored redirect URL to the joined listing's url field and resets
     * link-check state so the new URL is re-validated on the next check run.
     *
     * Only acts on records with link_status = 'redirect' and a non-empty
     * link_redirect_url — the new URL comes entirely from the DB, never from
     * a browser-supplied value.
     *
     * @return bool  true if a row was updated, false if nothing matched.
     */
    public function applyRedirectUrl(int $id): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE lstr_joined
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
     * Records the outcome of a URL check for a joined listing.
     *
     * Status values: 'ok', 'redirect', 'dead', 'unchecked'.
     * When status is 'redirect', $redirectUrl holds the final destination URL
     * so the admin can apply it later via applyRedirectUrl().
     */
    public function updateLinkStatus(int $id, string $status, ?string $redirectUrl, ?string $now = null): void
    {
        $stmt = $this->db->prepare(
            'UPDATE lstr_joined
             SET link_status       = :status,
                 link_redirect_url = :redir,
                 link_checked_at   = :now
             WHERE id = :id'
        );
        $stmt->execute(['status' => $status, 'redir' => $redirectUrl, 'now' => $now ?? date('Y-m-d H:i:s'), 'id' => $id]);
    }

    /**
     * Resets link-check state for all joined listings back to 'unchecked'.
     *
     * Call this before starting a fresh check run so stale results from the
     * previous run do not persist alongside newly checked rows.
     */
    public function resetLinkStatus(): void
    {
        $this->db->prepare(
            "UPDATE lstr_joined
             SET link_status       = 'unchecked',
                 link_checked_at   = NULL,
                 link_redirect_url = NULL"
        )->execute();
    }
}
