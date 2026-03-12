<?php

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Database;

/**
 * Data access for the lstr_email_templates table.
 *
 * Stores admin-configurable email subjects and bodies for the four
 * member-facing transactional emails. Each row covers one action type
 * for one fanlisting. When no custom row exists, the DEFAULT_* constants
 * supply the fallback text.
 *
 * Token syntax: $token_name$ (dollar signs on both sides).
 *
 * Available tokens (all types):
 *   $fan_name$        — member's name
 *   $fan_email$       — member's email address
 *   $fan_url$         — member's website URL
 *   $fan_country$     — member's country
 *   $fanlisting_name$ — name of the fanlisting
 *   $fanlisting_url$  — URL of the fanlisting
 *   $app_name$        — collective / site name from config
 *
 * Join-only additional token:
 *   $fan_password$    — member's raw password (shown when set during join)
 *
 * Requires Migration 23.
 */
final class EmailTemplateRepo
{
    // -----------------------------------------------------------------------
    // Type constants
    // -----------------------------------------------------------------------

    public const TYPE_JOIN    = 'join';
    public const TYPE_APPROVE = 'approve';
    public const TYPE_UPDATE  = 'update';
    public const TYPE_REMOVE  = 'remove';

    /** All valid type values in display order. */
    public const TYPES = [self::TYPE_JOIN, self::TYPE_APPROVE, self::TYPE_UPDATE, self::TYPE_REMOVE];

    // -----------------------------------------------------------------------
    // Default subjects
    // -----------------------------------------------------------------------

    private const DEFAULT_SUBJECTS = [
        self::TYPE_JOIN    => 'Welcome to the $fanlisting_name$ fanlisting!',
        self::TYPE_APPROVE => 'Your $fanlisting_name$ membership has been approved',
        self::TYPE_UPDATE  => 'Your $fanlisting_name$ membership has been updated',
        self::TYPE_REMOVE  => 'You have been removed from the $fanlisting_name$ fanlisting',
    ];

    // -----------------------------------------------------------------------
    // Default bodies
    // -----------------------------------------------------------------------

    private const DEFAULT_BODY_JOIN =
        'Hi $fan_name$,' . "\n\n"
        . 'Thank you for joining the $fanlisting_name$ fanlisting.' . "\n\n"
        . 'Here are your membership details:' . "\n"
        . '  Name    : $fan_name$' . "\n"
        . '  Email   : $fan_email$' . "\n"
        . '  Website : $fan_url$' . "\n"
        . '  Country : $fan_country$' . "\n"
        . '  Password: $fan_password$' . "\n\n"
        . 'Please keep this email for your records. You will need your password to update or remove your membership.' . "\n\n"
        . '-- $app_name$';

    private const DEFAULT_BODY_APPROVE =
        'Hi $fan_name$,' . "\n\n"
        . 'Great news — your membership in the $fanlisting_name$ fanlisting has been approved!' . "\n\n"
        . 'Visit the fanlisting at: $fanlisting_url$' . "\n\n"
        . '-- $app_name$';

    private const DEFAULT_BODY_UPDATE =
        'Hi $fan_name$,' . "\n\n"
        . 'Your membership in the $fanlisting_name$ fanlisting has been updated successfully.' . "\n\n"
        . '-- $app_name$';

    private const DEFAULT_BODY_REMOVE =
        'Hi $fan_name$,' . "\n\n"
        . 'Your membership in the $fanlisting_name$ fanlisting has been removed as requested.' . "\n\n"
        . '-- $app_name$';

    // -----------------------------------------------------------------------

    public function __construct(private readonly Database $db) {}

    // -----------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------

    /**
     * Returns the custom template for one type, or null if none has been saved.
     */
    public function get(int $fanlistingId, string $type): ?object
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT id, fanlisting_id, type, enabled, subject, body
                 FROM lstr_email_templates
                 WHERE fanlisting_id = :fid AND type = :type'
            );
            $stmt->execute(['fid' => $fanlistingId, 'type' => $type]);
            $row = $stmt->fetch();
        } catch (\Exception) {
            return null;
        }

        if ($row === false) {
            return null;
        }

        return (object) [
            'id'           => (int)    $row['id'],
            'fanlistingId' => (int)    $row['fanlisting_id'],
            'type'         => (string) $row['type'],
            'enabled'      => (bool)   $row['enabled'],
            'subject'      => (string) $row['subject'],
            'body'         => (string) $row['body'],
        ];
    }

    /**
     * Returns custom templates for all four types, keyed by type.
     * Missing rows are represented as null.
     *
     * @return array<string, object|null>
     */
    public function getAll(int $fanlistingId): array
    {
        $result = array_fill_keys(self::TYPES, null);

        try {
            $stmt = $this->db->prepare(
                'SELECT id, fanlisting_id, type, enabled, subject, body
                 FROM lstr_email_templates
                 WHERE fanlisting_id = :fid'
            );
            $stmt->execute(['fid' => $fanlistingId]);
            $rows = $stmt->fetchAll();
        } catch (\Exception) {
            return $result;
        }

        foreach ($rows as $row) {
            $t = (string) $row['type'];
            if (array_key_exists($t, $result)) {
                $result[$t] = (object) [
                    'id'           => (int)    $row['id'],
                    'fanlistingId' => (int)    $row['fanlisting_id'],
                    'type'         => $t,
                    'enabled'      => (bool)   $row['enabled'],
                    'subject'      => (string) $row['subject'],
                    'body'         => (string) $row['body'],
                ];
            }
        }

        return $result;
    }

    // -----------------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------------

    /**
     * Upserts the template for one fanlisting+type combination.
     */
    public function save(
        int    $fanlistingId,
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
                'fid'     => $fanlistingId,
                'type'    => $type,
                'subject' => $subject,
                'body'    => $body,
                'enabled' => (int) $enabled,
            ]);
        } catch (\Exception) {
            // Migration not yet run — silently ignore
        }
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
            self::TYPE_JOIN    => self::DEFAULT_BODY_JOIN,
            self::TYPE_APPROVE => self::DEFAULT_BODY_APPROVE,
            self::TYPE_UPDATE  => self::DEFAULT_BODY_UPDATE,
            self::TYPE_REMOVE  => self::DEFAULT_BODY_REMOVE,
            default            => '',
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
     * Pass empty string for values that are not applicable for a given email type
     * (e.g. $fanPassword is only meaningful for join emails).
     *
     * @return array<string, string>
     */
    public static function buildTokens(
        string $fanlistingName,
        string $fanlistingUrl,
        string $appName,
        string $fanName     = '',
        string $fanEmail    = '',
        string $fanUrl      = '',
        string $fanCountry  = '',
        string $fanPassword = '',
    ): array {
        return [
            '$fan_name$'        => $fanName,
            '$fan_email$'       => $fanEmail,
            '$fan_url$'         => $fanUrl,
            '$fan_country$'     => $fanCountry,
            '$fan_password$'    => $fanPassword,
            '$fanlisting_name$' => $fanlistingName,
            '$fanlisting_url$'  => $fanlistingUrl,
            '$app_name$'        => $appName,
        ];
    }

    /**
     * Resolves the subject and body for a transactional email.
     *
     * Uses the custom template when it is saved and enabled; falls back to
     * the built-in default otherwise. Token replacement is applied to whichever
     * text is used.
     *
     * Returns [subject, body].
     *
     * @param  array<string, string>  $tokens  From buildTokens()
     * @return array{string, string}
     */
    public static function resolve(
        ?object $template,
        string  $type,
        array   $tokens,
    ): array {
        if ($template !== null && $template->enabled && trim($template->subject) !== '') {
            $subject = self::applyTokens($template->subject, $tokens);
            $body    = self::applyTokens($template->body,    $tokens);
        } else {
            $subject = self::applyTokens(self::defaultSubject($type), $tokens);
            $body    = self::applyTokens(self::defaultBody($type),    $tokens);
        }

        return [$subject, $body];
    }
}
