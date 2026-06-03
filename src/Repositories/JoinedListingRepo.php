<?php

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Database;
use Luster\Models\JoinedListing;

/**
 * Data access for the lstr_joined table.
 *
 * Tracks listings that the site owner has joined (as a member), grouped by
 * category. All queries use prepared statements.
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
    public function getAll(int $perPage = 10, int $page = 1, string $filterCat = ''): array
    {
        $offset     = ($page - 1) * $perPage;
        // $catClause is a hardcoded SQL fragment — $filterCat itself is always bound, never interpolated
        $catClause  = $filterCat !== '' ? 'WHERE (category = :cat OR categories_json LIKE :catj)' : '';
        $catPattern = '%"name":"' . $filterCat . '"%';

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM lstr_joined {$catClause}");
        if ($filterCat !== '') {
            $countStmt->bindValue(':cat',  $filterCat,  \PDO::PARAM_STR);
            $countStmt->bindValue(':catj', $catPattern, \PDO::PARAM_STR);
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->prepare(
            "SELECT * FROM lstr_joined {$catClause}
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
     * Full-text search across name, url, category, and categories_json,
     * optionally narrowed to a specific network name.
     *
     * @return array{items: JoinedListing[], total: int, pages: int}
     */
    public function search(string $q, string $network, int $perPage = 10, int $page = 1, string $filterCat = ''): array
    {
        $offset     = ($page - 1) * $perPage;
        $pattern    = '%' . $q . '%';
        // $catClause is a hardcoded SQL fragment — $filterCat itself is always bound, never interpolated
        $catClause  = $filterCat !== '' ? ' AND (category = :cat OR categories_json LIKE :catj)' : '';
        $catPattern = '%"name":"' . $filterCat . '"%';

        if ($network !== '') {
            $netPattern = '%' . $network . '%';

            $countStmt = $this->db->prepare(
                "SELECT COUNT(*) FROM lstr_joined
                 WHERE (name LIKE :q1 OR url LIKE :q2 OR category LIKE :q3 OR categories_json LIKE :q4)
                   AND categories_json LIKE :net{$catClause}"
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
                   AND categories_json LIKE :net{$catClause}
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
                 WHERE (name LIKE :q1 OR url LIKE :q2 OR category LIKE :q3 OR categories_json LIKE :q4){$catClause}"
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
                 WHERE (name LIKE :q1 OR url LIKE :q2 OR category LIKE :q3 OR categories_json LIKE :q4){$catClause}
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
    public function getAllIdsByFilter(string $search, string $network, string $cat): array
    {
        if ($search !== '' || $network !== '') {
            $result = $this->search($search, $network, PHP_INT_MAX, 1, $cat);
        } else {
            $result = $this->getAll(PHP_INT_MAX, 1, $cat);
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
     * Returns all distinct, non-empty category names sorted alphabetically.
     *
     * For rows with a non-empty categories_json, each entry's 'name' value is
     * collected. For rows without categories_json, the legacy category string
     * is used as the fallback.
     *
     * @return string[]
     */
    /**
     * Returns the total number of active joined listings.
     */
    public function countActive(): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM lstr_joined WHERE active = 1'
        );
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public function getCategories(): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, categories_json, category FROM lstr_joined'
        );
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
    public function getCategoryCountMap(): array
    {
        $stmt = $this->db->prepare(
            'SELECT categories_json, category, active FROM lstr_joined'
        );
        $stmt->execute();

        $counts = [];

        foreach ($stmt->fetchAll() as $row) {
            if (!(bool) $row['active']) {
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
     * @param array{name: string, url: string, image?: ?string, category?: string, categories_json?: ?string, date_joined?: ?string, active?: bool, status?: string} $data
     */
    public function insert(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO lstr_joined
                (name, url, image, category, categories_json, date_joined, active, status)
             VALUES
                (:name, :url, :image, :category, :categories_json, :date_joined, :active, :status)'
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
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Replaces all editable fields on an existing row.
     *
     * @param array{name: string, url: string, image: ?string, category: string, categories_json: ?string, date_joined: ?string, active: bool, status: string} $data
     */
    public function update(int $id, array $data): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE lstr_joined
             SET name            = :name,
                 url             = :url,
                 image           = :image,
                 category        = :category,
                 categories_json = :categories_json,
                 date_joined     = :date_joined,
                 active          = :active,
                 status          = :status
             WHERE id = :id'
        );

        return $stmt->execute([
            'name'            => (string) $data['name'],
            'url'             => (string) $data['url'],
            'image'           => isset($data['image'])           && $data['image']           !== null ? (string) $data['image']           : null,
            'category'        => (string) ($data['category']        ?? ''),
            'categories_json' => isset($data['categories_json']) && $data['categories_json'] !== null ? (string) $data['categories_json'] : null,
            'date_joined'     => isset($data['date_joined'])     && $data['date_joined']     !== null ? (string) $data['date_joined']     : null,
            'active'          => (int) (bool) ($data['active']   ?? true),
            'status'          => (string) ($data['status']          ?? 'approved'),
            'id'              => $id,
        ]);
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
     * Toggles the active flag.
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
