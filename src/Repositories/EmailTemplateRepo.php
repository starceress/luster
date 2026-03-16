<?php

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Database;

/**
 * Data access for the lstr_email_templates table.
 *
 * Stores admin-configurable email subjects and bodies for transactional
 * emails. Each row covers one action type for one listing. Global templates
 * use fanlisting_id = 0 and apply to all listings unless overridden.
 *
 * Resolution order (first match wins):
 *   1. Per-listing template (fanlisting_id = N) — enabled, non-blank subject
 *   2. Global template      (fanlisting_id = 0) — enabled, non-blank subject
 *   3. Built-in hardcoded default
 *
 * Token syntax: $token_name$ (single dollar signs on both sides).
 *
 * Available tokens (all fan-facing types):
 *   $fan_name$          — member's name
 *   $fan_email$         — member's email address
 *   $fan_url$           — member's website URL
 *   $fan_country$       — member's country
 *   $listing_name$      — name / title of the listing
 *   $listing_subject$   — subject / topic of the listing
 *   $listing_url$       — URL of the listing
 *   $site_name$         — collective / site name from config
 *   $owner_name$        — site owner's display name (config: owner_name)
 *   $owner_email$       — site owner's contact email (config: owner_email)
 *
 * Join-only additional token:
 *   $fan_password$      — member's raw password (shown when set during join)
 *
 * Reset-only additional token:
 *   $reset_url$         — one-time password reset link
 *
 * Admin notification (notify_join) — sent to the listing's admin email:
 *   same tokens as join; $fan_password$ is intentionally left blank.
 *
 * Requires Migration 23.
 */
final class EmailTemplateRepo
{
    // -----------------------------------------------------------------------
    // Type constants
    // -----------------------------------------------------------------------

    public const TYPE_JOIN         = 'join';
    public const TYPE_APPROVE      = 'approve';
    public const TYPE_UPDATE       = 'update';
    public const TYPE_REMOVE       = 'remove';
    public const TYPE_RESET        = 'reset';
    public const TYPE_NOTIFY_JOIN  = 'notify_join';

    /** All valid type values in display order. */
    public const TYPES = [
        self::TYPE_JOIN,
        self::TYPE_APPROVE,
        self::TYPE_UPDATE,
        self::TYPE_REMOVE,
        self::TYPE_RESET,
        self::TYPE_NOTIFY_JOIN,
    ];

    // -----------------------------------------------------------------------
    // Default subjects
    // -----------------------------------------------------------------------

    private const DEFAULT_SUBJECTS = [
        self::TYPE_JOIN        => 'Welcome to the $listing_name$ listing!',
        self::TYPE_APPROVE     => 'Your $listing_name$ membership has been approved',
        self::TYPE_UPDATE      => 'Your $listing_name$ membership has been updated',
        self::TYPE_REMOVE      => 'You have been removed from the $listing_name$ listing',
        self::TYPE_RESET       => 'Reset your $listing_name$ password',
        self::TYPE_NOTIFY_JOIN => 'New member joined $listing_name$',
    ];

    // -----------------------------------------------------------------------
    // Default bodies
    // -----------------------------------------------------------------------

    private const DEFAULT_BODY_JOIN =
        'Hi $fan_name$,' . "\n\n"
        . 'Thank you for joining the $listing_name$ listing.' . "\n\n"
        . 'Here are your membership details:' . "\n"
        . '  Name    : $fan_name$' . "\n"
        . '  Email   : $fan_email$' . "\n"
        . '  Website : $fan_url$' . "\n"
        . '  Country : $fan_country$' . "\n"
        . '  Password: $fan_password$' . "\n\n"
        . 'Please keep this email for your records. You will need your password to update or remove your membership.' . "\n\n"
        . '-- $owner_name$' . "\n"
        . '$site_name$';

    private const DEFAULT_BODY_APPROVE =
        'Hi $fan_name$,' . "\n\n"
        . 'Great news — your membership in the $listing_name$ listing has been approved!' . "\n\n"
        . 'Visit the listing at: $listing_url$' . "\n\n"
        . '-- $owner_name$' . "\n"
        . '$site_name$';

    private const DEFAULT_BODY_UPDATE =
        'Hi $fan_name$,' . "\n\n"
        . 'Your membership in the $listing_name$ listing has been updated successfully.' . "\n\n"
        . '-- $owner_name$' . "\n"
        . '$site_name$';

    private const DEFAULT_BODY_REMOVE =
        'Hi $fan_name$,' . "\n\n"
        . 'Your membership in the $listing_name$ listing has been removed as requested.' . "\n\n"
        . '-- $owner_name$' . "\n"
        . '$site_name$';

    private const DEFAULT_BODY_RESET =
        'Hi $fan_name$,' . "\n\n"
        . 'We received a request to reset the password for your membership in the $listing_name$ listing.' . "\n\n"
        . 'Click the link below to set a new password. This link expires in 1 hour.' . "\n\n"
        . '$reset_url$' . "\n\n"
        . 'If you did not request this, you can safely ignore this email. Your password will not change.' . "\n\n"
        . '-- $owner_name$' . "\n"
        . '$site_name$';

    private const DEFAULT_BODY_NOTIFY_JOIN =
        'A new member has joined the $listing_name$ listing.' . "\n\n"
        . '  Name   : $fan_name$' . "\n"
        . '  Email  : $fan_email$' . "\n"
        . '  Website: $fan_url$' . "\n"
        . '  Country: $fan_country$' . "\n\n"
        . '-- $owner_name$' . "\n"
        . '$site_name$';

    // -----------------------------------------------------------------------

    public function __construct(private readonly Database $db) {}

    // -----------------------------------------------------------------------
    // Reads — per-listing
    // -----------------------------------------------------------------------

    /**
     * Returns the custom template for one listing + type, or null if none saved.
     */
    public function get(int $listingId, string $type): ?object
    {
        return $this->fetchOne($listingId, $type);
    }

    /**
     * Returns custom templates for all types for one listing, keyed by type.
     * Missing rows are represented as null.
     *
     * @return array<string, object|null>
     */
    public function getAll(int $listingId): array
    {
        return $this->fetchAll($listingId);
    }

    // -----------------------------------------------------------------------
    // Reads — global (fanlisting_id = 0)
    // -----------------------------------------------------------------------

    /**
     * Returns the global template for one type, or null if none saved.
     */
    public function getGlobal(string $type): ?object
    {
        return $this->fetchOne(0, $type);
    }

    /**
     * Returns global templates for all types, keyed by type.
     * Missing rows are represented as null.
     *
     * @return array<string, object|null>
     */
    public function getAllGlobal(): array
    {
        return $this->fetchAll(0);
    }

    // -----------------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------------

    /**
     * Upserts the template for one fanlisting_id + type combination.
     * Pass fanlisting_id = 0 for global templates.
     */
    public function save(
        int    $listingId,
        string $type,
        string $subject,
        string $body,
        bool   $enabled,
    ): void {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO lstr_email_templates (fanlisting_id, type, subject, body, enabled)
                 VALUES (:fid, :type, :subject, :body, :enabled)
                 ON DUPLICATE KEY UPDATE
                     subject = VALUES(subject),
                     body    = VALUES(body),
                     enabled = VALUES(enabled)'
            );
            $stmt->execute([
                'fid'     => $listingId,
                'type'    => $type,
                'subject' => $subject,
                'body'    => $body,
                'enabled' => (int) $enabled,
            ]);
        } catch (\Exception) {
            // Migration not yet run — silently ignore
        }
    }

    /**
     * Deletes the saved template for one fanlisting_id + type, reverting to
     * the next level in the inheritance chain.
     * - fanlisting_id = 0 → deletes global, reverts to built-in default
     * - fanlisting_id = N → deletes per-listing, reverts to global (or built-in)
     */
    public function delete(int $listingId, string $type): void
    {
        try {
            $stmt = $this->db->prepare(
                'DELETE FROM lstr_email_templates WHERE fanlisting_id = :fid AND type = :type'
            );
            $stmt->execute(['fid' => $listingId, 'type' => $type]);
        } catch (\Exception) {
            // Migration not yet run — silently ignore
        }
    }

    // -----------------------------------------------------------------------
    // Convenience — resolve for a listing (handles full inheritance chain)
    // -----------------------------------------------------------------------

    /**
     * Full inheritance-chain resolution for sending an email.
     *
     * Loads the per-listing template and global template, then applies
     * token replacement on whichever level wins:
     *   per-listing (enabled) → global (enabled) → built-in default
     *
     * @param  array<string, string>  $tokens  From buildTokens()
     * @return array{string, string}  [subject, body]
     */
    public function resolveForListing(int $listingId, string $type, array $tokens): array
    {
        $perListing = $this->get($listingId, $type);
        $global     = $this->getGlobal($type);
        return self::resolve($perListing, $type, $tokens, $global);
    }

    // -----------------------------------------------------------------------
    // Static helpers
    // -----------------------------------------------------------------------

    /** Returns the default subject line for the given email type. */
    public static function defaultSubject(string $type): string
    {
        return self::DEFAULT_SUBJECTS[$type] ?? '';
    }

    /** Returns the default body text for the given email type. */
    public static function defaultBody(string $type): string
    {
        return match ($type) {
            self::TYPE_JOIN        => self::DEFAULT_BODY_JOIN,
            self::TYPE_APPROVE     => self::DEFAULT_BODY_APPROVE,
            self::TYPE_UPDATE      => self::DEFAULT_BODY_UPDATE,
            self::TYPE_REMOVE      => self::DEFAULT_BODY_REMOVE,
            self::TYPE_RESET       => self::DEFAULT_BODY_RESET,
            self::TYPE_NOTIFY_JOIN => self::DEFAULT_BODY_NOTIFY_JOIN,
            default                => '',
        };
    }

    /**
     * Replaces all $token$ placeholders in $text with the corresponding values.
     *
     * @param array<string, string> $tokens  Keys are '$token_name$' style strings
     */
    public static function applyTokens(string $text, array $tokens): string
    {
        return str_replace(array_keys($tokens), array_values($tokens), $text);
    }

    /**
     * Builds the standard token map for use with applyTokens().
     *
     * @return array<string, string>
     */
    public static function buildTokens(
        string $listingName,
        string $listingUrl,
        string $siteName,
        string $listingSubject = '',
        string $ownerName      = '',
        string $ownerEmail     = '',
        string $fanName        = '',
        string $fanEmail       = '',
        string $fanUrl         = '',
        string $fanCountry     = '',
        string $fanPassword    = '',
        string $resetUrl       = '',
    ): array {
        return [
            '$fan_name$'         => $fanName,
            '$fan_email$'        => $fanEmail,
            '$fan_url$'          => $fanUrl,
            '$fan_country$'      => $fanCountry,
            '$fan_password$'     => $fanPassword,
            '$reset_url$'        => $resetUrl,
            '$listing_name$'     => $listingName,
            '$listing_subject$'  => $listingSubject,
            '$listing_url$'      => $listingUrl,
            '$site_name$'        => $siteName,
            '$owner_name$'       => $ownerName,
            '$owner_email$'      => $ownerEmail,
        ];
    }

    /**
     * Resolves the subject and body for a transactional email.
     *
     * Resolution order:
     *   1. $perListing — if non-null, enabled, and non-blank subject
     *   2. $global     — if non-null, enabled, and non-blank subject
     *   3. Built-in hardcoded default for $type
     *
     * Token replacement is applied to whichever text is used.
     *
     * @param  array<string, string>  $tokens  From buildTokens()
     * @return array{string, string}  [subject, body]
     */
    public static function resolve(
        ?object $perListing,
        string  $type,
        array   $tokens,
        ?object $global = null,
    ): array {
        if ($perListing !== null && $perListing->enabled && trim($perListing->subject) !== '') {
            return [
                self::applyTokens($perListing->subject, $tokens),
                self::applyTokens($perListing->body,    $tokens),
            ];
        }

        if ($global !== null && $global->enabled && trim($global->subject) !== '') {
            return [
                self::applyTokens($global->subject, $tokens),
                self::applyTokens($global->body,    $tokens),
            ];
        }

        return [
            self::applyTokens(self::defaultSubject($type), $tokens),
            self::applyTokens(self::defaultBody($type),    $tokens),
        ];
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    private function fetchOne(int $listingId, string $type): ?object
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT id, fanlisting_id, type, enabled, subject, body
                 FROM lstr_email_templates
                 WHERE fanlisting_id = :fid AND type = :type'
            );
            $stmt->execute(['fid' => $listingId, 'type' => $type]);
            $row = $stmt->fetch();
        } catch (\Exception) {
            return null;
        }

        if ($row === false) {
            return null;
        }

        return $this->rowToObject($row);
    }

    /**
     * @return array<string, object|null>
     */
    private function fetchAll(int $listingId): array
    {
        $result = array_fill_keys(self::TYPES, null);

        try {
            $stmt = $this->db->prepare(
                'SELECT id, fanlisting_id, type, enabled, subject, body
                 FROM lstr_email_templates
                 WHERE fanlisting_id = :fid'
            );
            $stmt->execute(['fid' => $listingId]);
            $rows = $stmt->fetchAll();
        } catch (\Exception) {
            return $result;
        }

        foreach ($rows as $row) {
            $t = (string) $row['type'];
            if (array_key_exists($t, $result)) {
                $result[$t] = $this->rowToObject($row);
            }
        }

        return $result;
    }

    private function rowToObject(array $row): object
    {
        return (object) [
            'id'         => (int)    $row['id'],
            'listingId'  => (int)    $row['fanlisting_id'],
            'type'       => (string) $row['type'],
            'enabled'    => (bool)   $row['enabled'],
            'subject'    => (string) $row['subject'],
            'body'       => (string) $row['body'],
        ];
    }
}
