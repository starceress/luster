<?php

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Database;

/**
 * Data access for the lstr_saved_emails table.
 *
 * Stores admin-saved reusable email templates for composing single-member
 * and bulk emails. These are distinct from the transactional templates in
 * lstr_email_templates.
 *
 * Requires Migration 29.
 */
final class SavedEmailRepo
{
    public function __construct(private readonly Database $db) {}

    /**
     * Returns all saved templates, newest first.
     *
     * @return object[]
     */
    public function getAll(): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT id, name, subject, body, created_at
                 FROM lstr_saved_emails
                 ORDER BY created_at DESC'
            );
            $stmt->execute();

            return $stmt->fetchAll(\PDO::FETCH_OBJ);
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * Returns one saved template by ID, or null if not found.
     */
    public function getById(int $id): ?object
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT id, name, subject, body, created_at
                 FROM lstr_saved_emails WHERE id = :id'
            );
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(\PDO::FETCH_OBJ);

            return $row !== false ? $row : null;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Inserts a new saved template and returns its ID.
     */
    public function create(string $name, string $subject, string $body): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO lstr_saved_emails (name, subject, body)
             VALUES (:name, :subject, :body)'
        );
        $stmt->execute([
            'name'    => $name,
            'subject' => $subject,
            'body'    => $body,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Updates an existing saved template.
     */
    public function update(int $id, string $name, string $subject, string $body): void
    {
        $stmt = $this->db->prepare(
            'UPDATE lstr_saved_emails SET name = :name, subject = :subject, body = :body
             WHERE id = :id'
        );
        $stmt->execute([
            'id'      => $id,
            'name'    => $name,
            'subject' => $subject,
            'body'    => $body,
        ]);
    }

    /**
     * Deletes a saved template by ID.
     */
    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM lstr_saved_emails WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
