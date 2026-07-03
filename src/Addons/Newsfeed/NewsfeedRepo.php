<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Repository for the lstr_newsfeed table. Provides paginated reads for both
 per-fanlisting and collective (fanlisting_id IS NULL) feeds, category
 filtering, CRUD operations for individual entries, the latest-per-fanlisting
 aggregation used by the admin index, and category tag autocomplete data.
*/

declare(strict_types=1);

namespace Luster\Addons\Newsfeed;

use Luster\Core\Database;

/**
 * Data-access layer for lstr_newsfeed.
 */
final class NewsfeedRepo
{
    public function __construct(private readonly Database $db) {}

    // -----------------------------------------------------------------------
    // Reads — per-fanlisting
    // -----------------------------------------------------------------------

    /**
     * Returns a paginated list of entries for one fanlisting, newest first by posted_at.
     *
     * @return NewsfeedEntry[]
     */
    public function getForFanlisting(int $fanlistingId, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;
        $stmt   = $this->db->prepare(
            'SELECT * FROM `lstr_newsfeed`
              WHERE `fanlisting_id` = :fid
              ORDER BY `posted_at` DESC
              LIMIT :limit OFFSET :offset',
        );
        $stmt->bindValue(':fid',    $fanlistingId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit',  $perPage,      \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,       \PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** Counts all entries for a fanlisting. */
    public function countForFanlisting(int $fanlistingId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM `lstr_newsfeed` WHERE `fanlisting_id` = :fid',
        );
        $stmt->execute([':fid' => $fanlistingId]);
        return (int) $stmt->fetchColumn();
    }

    // -----------------------------------------------------------------------
    // Reads — collective (fanlisting_id IS NULL)
    // -----------------------------------------------------------------------

    /**
     * Returns a paginated list of collective entries (fanlisting_id IS NULL), newest first.
     *
     * @return NewsfeedEntry[]
     */
    public function getCollective(int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;
        $stmt   = $this->db->prepare(
            'SELECT * FROM `lstr_newsfeed`
              WHERE `fanlisting_id` IS NULL
              ORDER BY `posted_at` DESC
              LIMIT :limit OFFSET :offset',
        );
        $stmt->bindValue(':limit',  $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  \PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** Counts all collective (fanlisting_id IS NULL) entries. */
    public function countCollective(): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM `lstr_newsfeed` WHERE `fanlisting_id` IS NULL',
        );
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    // -----------------------------------------------------------------------
    // Reads — overview helpers
    // -----------------------------------------------------------------------

    /**
     * Returns one row per fanlisting_id that has at least one entry.
     * Each row includes the total count and the latest entry object.
     *
     * @return array<int, array{count: int, latest: NewsfeedEntry}>  keyed by fanlisting_id
     */
    public function getLatestPerFanlisting(): array
    {
        // Get counts
        $stmt = $this->db->prepare(
            'SELECT `fanlisting_id`, COUNT(*) AS cnt
               FROM `lstr_newsfeed`
              WHERE `fanlisting_id` IS NOT NULL
              GROUP BY `fanlisting_id`',
        );
        $stmt->execute();
        $counts = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $counts[(int) $row['fanlisting_id']] = (int) $row['cnt'];
        }

        if (empty($counts)) {
            return [];
        }

        // Get latest entry per fanlisting
        $stmt = $this->db->prepare(
            'SELECT u.*
               FROM `lstr_newsfeed` u
               INNER JOIN (
                   SELECT `fanlisting_id`, MAX(`posted_at`) AS max_posted
                     FROM `lstr_newsfeed`
                    WHERE `fanlisting_id` IS NOT NULL
                    GROUP BY `fanlisting_id`
               ) m ON u.`fanlisting_id` = m.`fanlisting_id`
                   AND u.`posted_at` = m.`max_posted`',
        );
        $stmt->execute();

        $result = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $fid = (int) $row['fanlisting_id'];
            $result[$fid] = [
                'count'  => $counts[$fid] ?? 0,
                'latest' => $this->hydrate($row),
            ];
        }

        return $result;
    }

    /** Returns the most recent collective entry, or null if none exist. */
    public function getLatestCollective(): ?NewsfeedEntry
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM `lstr_newsfeed`
              WHERE `fanlisting_id` IS NULL
              ORDER BY `posted_at` DESC
              LIMIT 1',
        );
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    /** Alias for countCollective() — kept for symmetry with the admin overview. */
    public function countCollectiveTotal(): int
    {
        return $this->countCollective();
    }

    // -----------------------------------------------------------------------
    // Single-row fetch
    // -----------------------------------------------------------------------

    /** Returns a single entry by primary key, or null if not found. */
    public function getById(int $id): ?NewsfeedEntry
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM `lstr_newsfeed` WHERE `id` = :id',
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    // -----------------------------------------------------------------------
    // Reads — by category (PHP-side filter; correct for typical feed sizes)
    // -----------------------------------------------------------------------

    /** @return NewsfeedEntry[] */
    public function getForFanlistingByCategory(int $fanlistingId, string $category, int $page, int $perPage): array
    {
        $filtered = $this->filterByCategory($this->fetchAllByFanlisting($fanlistingId), $category);
        return array_slice($filtered, ($page - 1) * $perPage, $perPage);
    }

    public function countForFanlistingByCategory(int $fanlistingId, string $category): int
    {
        return count($this->filterByCategory($this->fetchAllByFanlisting($fanlistingId), $category));
    }

    /** @return NewsfeedEntry[] */
    public function getCollectiveByCategory(string $category, int $page, int $perPage): array
    {
        $filtered = $this->filterByCategory($this->fetchAllCollective(), $category);
        return array_slice($filtered, ($page - 1) * $perPage, $perPage);
    }

    public function countCollectiveByCategory(string $category): int
    {
        return count($this->filterByCategory($this->fetchAllCollective(), $category));
    }

    // -----------------------------------------------------------------------
    // Category datalist
    // -----------------------------------------------------------------------

    /**
     * Returns all distinct individual category values across every row,
     * split from the comma-separated `category` column, sorted alphabetically.
     *
     * @return string[]
     */
    public function getKnownCategories(): array
    {
        $stmt = $this->db->prepare(
            'SELECT DISTINCT `category` FROM `lstr_newsfeed` WHERE `category` != \'\'',
        );
        $stmt->execute();

        $all = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $raw) {
            foreach (explode(',', (string) $raw) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $all[$part] = true;
                }
            }
        }

        $keys = array_keys($all);
        sort($keys);
        return $keys;
    }

    // -----------------------------------------------------------------------
    // Filtered reads (search + category)
    // -----------------------------------------------------------------------

    /** @return NewsfeedEntry[] */
    public function getFiltered(?int $fanlistingId, string $search, string $category, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;
        [$where, $params] = $this->filterWhere($fanlistingId, $search, $category);
        $stmt = $this->db->prepare(
            "SELECT * FROM `lstr_newsfeed` WHERE {$where} ORDER BY `posted_at` DESC LIMIT :limit OFFSET :offset",
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit',  $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  \PDO::PARAM_INT);
        $stmt->execute();
        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function countFiltered(?int $fanlistingId, string $search, string $category): int
    {
        [$where, $params] = $this->filterWhere($fanlistingId, $search, $category);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM `lstr_newsfeed` WHERE {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    // -----------------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------------

    /**
     * Inserts a new entry. Pass null for $fanlistingId to create a collective entry.
     * $data keys: title, body, category, author, posted_at.
     * Returns the new row's auto-increment ID.
     */
    public function insert(?int $fanlistingId, array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO `lstr_newsfeed`
                (`fanlisting_id`, `title`, `body`, `category`, `author`, `posted_at`)
             VALUES
                (:fid, :title, :body, :category, :author, :posted_at)',
        );
        $stmt->execute([
            ':fid'       => $fanlistingId,
            ':title'     => $data['title'],
            ':body'      => $data['body'],
            ':category'  => $data['category'] ?? '',
            ':author'    => $data['author']   ?? '',
            ':posted_at' => $data['posted_at'],
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Updates editable fields on an existing entry.
     * $data keys: title, body, category, posted_at.
     */
    public function update(int $id, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE `lstr_newsfeed`
                SET `title`     = :title,
                    `body`      = :body,
                    `category`  = :category,
                    `posted_at` = :posted_at
              WHERE `id` = :id',
        );
        $stmt->execute([
            ':title'     => $data['title'],
            ':body'      => $data['body'],
            ':category'  => $data['category'] ?? '',
            ':posted_at' => $data['posted_at'],
            ':id'        => $id,
        ]);
    }

    /** Deletes an entry by primary key. */
    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM `lstr_newsfeed` WHERE `id` = :id');
        $stmt->execute([':id' => $id]);
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Builds the WHERE clause and parameter map for filtered queries.
     *
     * @return array{0: string, 1: array<string,mixed>}
     */
    private function filterWhere(?int $fanlistingId, string $search, string $category): array
    {
        $conds  = [];
        $params = [];

        if ($fanlistingId !== null) {
            $conds[]          = '`fanlisting_id` = :fid';
            $params[':fid']   = $fanlistingId;
        } else {
            $conds[] = '`fanlisting_id` IS NULL';
        }

        if ($search !== '') {
            $conds[]            = '(`title` LIKE :search OR `body` LIKE :search)';
            $params[':search']  = '%' . $search . '%';
        }

        if ($category !== '') {
            // FIND_IN_SET after normalising ", " → "," handles all spacing variants
            $conds[]          = 'FIND_IN_SET(:cat, REPLACE(`category`, \', \', \',\')) > 0';
            $params[':cat']   = $category;
        }

        return [implode(' AND ', $conds), $params];
    }

    /**
     * Fetches all entries for a fanlisting ordered by posted_at DESC.
     *
     * @return NewsfeedEntry[]
     */
    private function fetchAllByFanlisting(int $fanlistingId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM `lstr_newsfeed` WHERE `fanlisting_id` = :fid ORDER BY `posted_at` DESC',
        );
        $stmt->execute([':fid' => $fanlistingId]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Fetches all collective entries ordered by posted_at DESC.
     *
     * @return NewsfeedEntry[]
     */
    private function fetchAllCollective(): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM `lstr_newsfeed` WHERE `fanlisting_id` IS NULL ORDER BY `posted_at` DESC',
        );
        $stmt->execute();
        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * @param  NewsfeedEntry[] $entries
     * @return NewsfeedEntry[]
     */
    private function filterByCategory(array $entries, string $category): array
    {
        return array_values(array_filter(
            $entries,
            static fn(NewsfeedEntry $e): bool => in_array($category, $e->categories(), true),
        ));
    }

    // -----------------------------------------------------------------------
    // Hydration
    // -----------------------------------------------------------------------

    /** Hydrates a NewsfeedEntry instance from an associative database row. */
    private function hydrate(array $row): NewsfeedEntry
    {
        return new NewsfeedEntry(
            id:           (int)    $row['id'],
            fanlistingId: isset($row['fanlisting_id']) ? (int) $row['fanlisting_id'] : null,
            title:        (string) $row['title'],
            body:         (string) $row['body'],
            category:     (string) $row['category'],
            author:       (string) $row['author'],
            postedAt:     (string) $row['posted_at'],
            createdAt:    (string) $row['created_at'],
        );
    }
}
