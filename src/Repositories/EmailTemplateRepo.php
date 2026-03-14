<?php

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Database;

/**
 * Data access for the lstr_email_templates table.
 *
 * Stores admin-configurable email subjects and bodies for transactional
 * emails. Each row covers one action type for one listing. When no custom
 * row exists, the DEFAULT_* constants supply the fallback text.
 *
 * Token syntax: $token_name$ (dollar signs on both sides).
 *
 * Available tokens (all fan-facing types):
 *   $fan_name$        — member's name
 *   $fan_email$       — member's email address
 *   $fan_url$         — member's website URL
 *   $fan_country$     — member's country
 *   $listing_name$    — name of the listing
 *   $listing_url$     — URL of the listing
 *   $app_name$        — collective / site name from config
 *
 * Join-only additional token:
 *   $fan_password$    — member's raw password (shown when set during join)
 *
 * Reset-only additional token:
 *   $reset_url$       — one-time password reset link
 *
 * Admin notification (notify_join) — sent to the listing's admin email:
 *   same tokens as join (fan_name, fan_email, fan_url, fan_country, listing_name,
 *   listing_url, app_name); $fan_password$ is intentionally left blank.
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
        . '-- $app_name$';

    private const DEFAULT_BODY_APPROVE =
        'Hi $fan_name$,' . "\n\n"
        . 'Great news — your membership in the $listing_name$ listing has been approved!' . "\n\n"
        . 'Visit the listing at: $listing_url$' . "\n\n"
        . '-- $app_name$';

    private const DEFAULT_BODY_UPDATE =
        'Hi $fan_name$,' . "\n\n"
        . 'Your membership in the $listing_name$ listing has been updated successfully.' . "\n\n"
        . '-- $app_name$';

    private const DEFAULT_BODY_REMOVE =
        'Hi $fan_name$,' . "\n\n"
        . 'Your membership in the $listing_name$ listing has been removed as requested.' . "\n\n"
        . '-- $app_name$';

    private const DEFAULT_BODY_RESET =
        'Hi $fan_name$,' . "\n\n"
        . 'We received a request to reset the password for your membership in the $listing_name$ listing.' . "\n\n"
        . 'Click the link below to set a new password. This link expires in 1 hour.' . "\n\n"
        . '$reset_url$' . "\n\n"
        . 'If you did not request this, you can safely ignore this email. Your password will not change.' . "\n\n"
        . '-- $app_name$';

    private const DEFAULT_BODY_NOTIFY_JOIN =
        'A new member has joined the $listing_name$ listing.' . "\n\n"
        . '  Name   : $fan_name$' . "\n"
        . '  Email  : $fan_email$' . "\n"
        . '  Website: $fan_url$' . "\n"
        . '  Country: $fan_country$' . "\n\n"
        . '-- $app_name$';

    // -----------------------------------------------------------------------

    public function __construct(private readonly Database $db) {}

    // -----------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------

    /**
     * Returns the custom template for one type, or null if none has been saved.
     */
    public function get(int $listingId, string $type): ?object
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

        return (object) [
            'id'           => (int)    $row['id'],
            'listingId' => (int)    $row['fanlisting_id'],
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
    public function getAll(int $listingId): array
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
                $result[$t] = (object) [
                    'id'           => (int)    $row['id'],
                    'listingId' => (int)    $row['fanlisting_id'],
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
     * Pass empty string for values that are not applicable for a given email type
     * (e.g. $fanPassword is only meaningful for join emails).
     *
     * @return array<string, string>
     */
    public static function buildTokens(
        string $listingName,
        string $listingUrl,
        string $appName,
        string $fanName     = '',
        string $fanEmail    = '',
        string $fanUrl      = '',
        string $fanCountry  = '',
        string $fanPassword = '',
        string $resetUrl    = '',
    ): array {
        return [
            '$fan_name$'        => $fanName,
            '$fan_email$'       => $fanEmail,
            '$fan_url$'         => $fanUrl,
            '$fan_country$'     => $fanCountry,
            '$fan_password$'    => $fanPassword,
            '$reset_url$'       => $resetUrl,
            '$listing_name$'    => $listingName,
            '$listing_url$'     => $listingUrl,
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
