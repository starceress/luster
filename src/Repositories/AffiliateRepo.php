<?php

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Database;
use Luster\Models\Affiliate;

/**
 * Data access for the lstr_affiliates table.
 *
 * Affiliates are linked to a specific fanlisting via fanlisting_id, which
 * corresponds to enth_fanlistings.id. All queries use prepared statements.
 */
final class AffiliateRepo
{
    public function __construct(private readonly Database $db) {}

    // -----------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------

    /**
     * Returns every affiliate, sorted by sort_order then name.
     *
     * @return Affiliate[]
     */
    public function getAll(): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_affiliates ORDER BY sort_order ASC, name ASC'
        );
        $stmt->execute();

        return array_map(static fn(array $r) => Affiliate::fromRow($r), $stmt->fetchAll());
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
     * Returns collective affiliates (fanlisting_id IS NULL), sorted by sort_order then name.
     *
     * @return Affiliate[]
     */
    public function getCollective(): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_affiliates
             WHERE fanlisting_id IS NULL
             ORDER BY sort_order ASC, name ASC'
        );
        $stmt->execute();

        return array_map(static fn(array $r) => Affiliate::fromRow($r), $stmt->fetchAll());
    }

    /**
     * Returns affiliates for a specific fanlisting, sorted by sort_order then name.
     *
     * @return Affiliate[]
     */
    public function getByFanlisting(int $fanlistingId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_affiliates
             WHERE fanlisting_id = :fid
             ORDER BY sort_order ASC, name ASC'
        );
        $stmt->execute(['fid' => $fanlistingId]);

        return array_map(static fn(array $r) => Affiliate::fromRow($r), $stmt->fetchAll());
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
            'sort_order'    => isset($data['sort_order']) ? (int)    $data['sort_order'] : 0,
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
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'id'         => $id,
        ]);
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
}
