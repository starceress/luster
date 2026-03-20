<?php

declare(strict_types=1);

namespace Luster\Addons\Updates;

use Luster\Core\Database;

/**
 * Data-access layer for lstr_updates.
 */
final class UpdateRepo
{
    public function __construct(private readonly Database $db) {}

    // -----------------------------------------------------------------------
    // Reads — per-fanlisting
    // -----------------------------------------------------------------------

    /**
     * Returns a paginated list of updates for one fanlisting, newest first by posted_at.
     *
     * @return Update[]
     */
    public function getForFanlisting(int $fanlistingId, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;
        $stmt   = $this->db->prepare(
            'SELECT * FROM `lstr_updates`
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

    public function countForFanlisting(int $fanlistingId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM `lstr_updates` WHERE `fanlisting_id` = :fid',
        );
        $stmt->execute([':fid' => $fanlistingId]);
        return (int) $stmt->fetchColumn();
    }

    // -----------------------------------------------------------------------
    // Reads — collective (fanlisting_id IS NULL)
    // -----------------------------------------------------------------------

    /**
     * Returns a paginated list of collective updates (fanlisting_id IS NULL), newest first.
     *
     * @return Update[]
     */
    public function getCollective(int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;
        $stmt   = $this->db->prepare(
            'SELECT * FROM `lstr_updates`
              WHERE `fanlisting_id` IS NULL
              ORDER BY `posted_at` DESC
              LIMIT :limit OFFSET :offset',
        );
        $stmt->bindValue(':limit',  $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  \PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function countCollective(): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM `lstr_updates` WHERE `fanlisting_id` IS NULL',
        );
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    // -----------------------------------------------------------------------
    // Reads — overview helpers
    // -----------------------------------------------------------------------

    /**
     * Returns one row per fanlisting_id that has at least one update.
     * Each row includes the total count and the latest update object.
     *
     * @return array<int, array{count: int, latest: Update}>  keyed by fanlisting_id
     */
    public function getLatestPerFanlisting(): array
    {
        // Get counts
        $stmt = $this->db->prepare(
            'SELECT `fanlisting_id`, COUNT(*) AS cnt
               FROM `lstr_updates`
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

        // Get latest update per fanlisting
        $placeholders = implode(',', array_fill(0, count($counts), '?'));
        $stmt = $this->db->prepare(
            'SELECT u.*
               FROM `lstr_updates` u
               INNER JOIN (
                   SELECT `fanlisting_id`, MAX(`posted_at`) AS max_posted
                     FROM `lstr_updates`
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

    public function getLatestCollective(): ?Update
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM `lstr_updates`
              WHERE `fanlisting_id` IS NULL
              ORDER BY `posted_at` DESC
              LIMIT 1',
        );
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function countCollectiveTotal(): int
    {
        return $this->countCollective();
    }

    // -----------------------------------------------------------------------
    // Single-row fetch
    // -----------------------------------------------------------------------

    public function getById(int $id): ?Update
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM `lstr_updates` WHERE `id` = :id',
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
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
            'SELECT DISTINCT `category` FROM `lstr_updates` WHERE `category` != \'\'',
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
    // Writes
    // -----------------------------------------------------------------------

    public function insert(?int $fanlistingId, array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO `lstr_updates`
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

    public function update(int $id, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE `lstr_updates`
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

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM `lstr_updates` WHERE `id` = :id');
        $stmt->execute([':id' => $id]);
    }

    // -----------------------------------------------------------------------
    // Hydration
    // -----------------------------------------------------------------------

    private function hydrate(array $row): Update
    {
        return new Update(
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
