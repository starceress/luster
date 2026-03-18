<?php

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Database;

/**
 * Data access for the lstr_code_donors table.
 *
 * Donors are per-listing people who contributed fan codes.
 * Codes reference donors via lstr_codes.donor_id (nullable FK).
 * Requires Migration 31 to run before use.
 */
final class CodeDonorsRepo
{
    public function __construct(private readonly Database $db) {}

    // -----------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------

    /**
     * Returns all donors for a listing, ordered by name.
     *
     * @return object[]
     */
    public function getByListing(int $listingId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, fanlisting_id, name, email, url
             FROM lstr_code_donors
             WHERE fanlisting_id = :fid
             ORDER BY name ASC'
        );
        $stmt->execute(['fid' => $listingId]);

        return array_map(static fn(array $r) => (object) [
            'id'        => (int)    $r['id'],
            'listingId' => (int)    $r['fanlisting_id'],
            'name'      => (string) $r['name'],
            'email'     => ($r['email'] !== null && $r['email'] !== '') ? (string) $r['email'] : null,
            'url'       => ($r['url']   !== null && $r['url']   !== '') ? (string) $r['url']   : null,
        ], $stmt->fetchAll());
    }

    /**
     * Returns a single donor by ID, or null if not found.
     */
    public function getById(int $id): ?object
    {
        $stmt = $this->db->prepare(
            'SELECT id, fanlisting_id, name, email, url
             FROM lstr_code_donors WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return (object) [
            'id'        => (int)    $row['id'],
            'listingId' => (int)    $row['fanlisting_id'],
            'name'      => (string) $row['name'],
            'email'     => ($row['email'] !== null && $row['email'] !== '') ? (string) $row['email'] : null,
            'url'       => ($row['url']   !== null && $row['url']   !== '') ? (string) $row['url']   : null,
        ];
    }

    /**
     * Returns how many codes are attributed to a donor.
     */
    public function countCodesForDonor(int $donorId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM lstr_codes WHERE donor_id = :did'
        );
        $stmt->execute(['did' => $donorId]);
        return (int) $stmt->fetchColumn();
    }

    // -----------------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------------

    public function insert(int $listingId, string $name, ?string $email, ?string $url): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO lstr_code_donors (fanlisting_id, name, email, url)
             VALUES (:fid, :name, :email, :url)'
        );
        $stmt->execute([
            'fid'   => $listingId,
            'name'  => $name,
            'email' => $email,
            'url'   => $url,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, string $name, ?string $email, ?string $url): void
    {
        $stmt = $this->db->prepare(
            'UPDATE lstr_code_donors SET name = :name, email = :email, url = :url WHERE id = :id'
        );
        $stmt->execute(['name' => $name, 'email' => $email, 'url' => $url, 'id' => $id]);
    }

    /**
     * Deletes a donor. Codes referencing this donor will have donor_id set to NULL
     * via the ON DELETE SET NULL foreign key.
     */
    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM lstr_code_donors WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
