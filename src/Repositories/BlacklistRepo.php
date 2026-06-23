<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Manages the email/domain blacklist stored in lstr_blacklist. Entries are
 either exact email addresses or domain blocks (prefixed with "@"). The
 isBlocked() method checks incoming emails against both types using fast
 indexed lookups rather than loading the entire table into memory.
*/

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Database;

/**
 * Data access for the lstr_blacklist table.
 *
 * Patterns are stored as entered:
 *  - Starting with '@'  → domain block  (e.g. "@spam.com")  — matched via str_ends_with()
 *  - Otherwise          → exact email   (e.g. "foo@bar.com") — matched via strcasecmp()
 */
final class BlacklistRepo
{
    public function __construct(private readonly Database $db) {}

    // -----------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------

    /**
     * Returns all blacklist entries, newest first.
     *
     * @return array<int, array{id: int, pattern: string, note: string, created_at: string}>
     */
    public function getAll(): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, pattern, note, created_at
             FROM lstr_blacklist
             ORDER BY created_at DESC'
        );
        $stmt->execute();

        return array_map(static function (array $row): array {
            return [
                'id'         => (int)    $row['id'],
                'pattern'    => (string) $row['pattern'],
                'note'       => (string) $row['note'],
                'created_at' => (string) $row['created_at'],
            ];
        }, $stmt->fetchAll());
    }

    /**
     * Returns the total number of blacklist entries.
     */
    public function count(): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM lstr_blacklist');
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * Returns true if the given email address matches any blacklist entry.
     *
     * Uses two targeted LIMIT 1 queries instead of loading the full table:
     *  1. Exact email match  — WHERE pattern = :email
     *  2. Domain block match — WHERE pattern = :domain  (e.g. '@spam.com')
     */
    public function isBlocked(string $email): bool
    {
        $email = strtolower(trim($email));

        if ($email === '') {
            return false;
        }

        // 1. Exact email match.
        $stmt = $this->db->prepare(
            'SELECT 1 FROM lstr_blacklist WHERE pattern = :email LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        if ($stmt->fetchColumn() !== false) {
            return true;
        }

        // 2. Domain block — extract '@domain.tld' from the submitted address.
        $atPos = strpos($email, '@');
        if ($atPos === false) {
            return false;
        }
        $domain = substr($email, $atPos); // e.g. '@spam.com'

        $stmt = $this->db->prepare(
            'SELECT 1 FROM lstr_blacklist WHERE pattern = :domain LIMIT 1'
        );
        $stmt->execute(['domain' => $domain]);
        return $stmt->fetchColumn() !== false;
    }

    // -----------------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------------

    /**
     * Inserts a new blacklist entry and returns its new primary key.
     *
     * @throws \PDOException on duplicate pattern
     */
    public function insert(string $pattern, string $note): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO lstr_blacklist (pattern, note)
             VALUES (:pattern, :note)'
        );
        $stmt->execute([
            'pattern' => strtolower(trim($pattern)),
            'note'    => trim($note),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Deletes a blacklist entry by primary key.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM lstr_blacklist WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }
}
