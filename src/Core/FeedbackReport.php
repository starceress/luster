<?php

declare(strict_types=1);

namespace Luster\Core;

/**
 * Pure formatter for admin feedback reports.
 *
 * No DB, config, session, or app dependencies — accepts pre-assembled data
 * and returns plain-text strings suitable for email delivery or preview.
 *
 * Interface:
 *   formatSysInfo(array $data): string  — format the system info block
 *   formatErrorLog(array $rows): string — format the error log block
 *   compose(array $form, string $sysInfo, string $errorLog): string — assemble full report
 *
 * Expected formatSysInfo() keys:
 *   luster_version (string), base_url (string — path only, e.g. "luster/public"; displayed as install type, not raw path),
 *   report_timestamp (string), php_version (string), db_version (string), debug_enabled (bool),
 *   mail_configured (bool), admin_username (string),
 *   addons (string[]), migration_level (?int)
 *
 * Expected compose() form keys:
 *   type (string), subject (string), message (string),
 *   contact_email (string), include_sysinfo (bool), include_errorlog (bool)
 */
final class FeedbackReport
{
    private const SEP = "========================================\n";

    public const TYPE_LABELS = [
        'bug'      => 'Bug Report',
        'question' => 'Question / Concern',
        'feature'  => 'Feature Request',
        'other'    => 'Other',
    ];

    public static function typeLabel(string $type): string
    {
        return self::TYPE_LABELS[$type] ?? 'Other';
    }

    public static function formatSysInfo(array $data): string
    {
        $addons    = !empty($data['addons']) ? implode(', ', $data['addons']) : 'None';
        $migration = $data['migration_level'] !== null ? (string) $data['migration_level'] : 'unknown';

        $rawPath  = (string) ($data['base_url'] ?? '');
        $segments = array_values(array_filter(explode('/', $rawPath), fn($s) => $s !== ''));
        $n = count($segments);
        $installType = $n === 0
            ? 'Root install'
            : 'Subdirectory (' . $n . ' ' . ($n === 1 ? 'segment' : 'segments') . ')';

        return self::SEP
            . "SYSTEM INFO\n"
            . self::SEP
            . self::row('Luster version', $data['luster_version'] ?? 'unknown')
            . self::row('PHP',            $data['php_version']    ?? 'unknown')
            . self::row('Database',       $data['db_version']     ?? 'unknown')
            . self::row('Debug mode',     ($data['debug_enabled']  ?? false) ? 'Yes' : 'No')
            . self::row('Mail',           ($data['mail_configured'] ?? false) ? 'Owner email set' : 'Owner email not set')
            . self::row('Migration',      $migration)
            . self::row('Install type',   $installType)
            . self::row('Reported by',    $data['admin_username'] ?? 'unknown')
            . self::row('Report time',    $data['report_timestamp'] ?? 'unknown')
            . self::row('Add-ons',        $addons);
    }

    public static function formatErrorLog(array $rows): string
    {
        $header = self::SEP . "RECENT ERRORS (LAST 10)\n" . self::SEP;

        if ($rows === []) {
            return $header . "No recent errors.\n";
        }

        $lines = $header;
        foreach ($rows as $row) {
            $dt      = $row['created_at'] ?? '';
            $source  = $row['source']     ?? '';
            $message = $row['message']    ?? '';
            $lines .= "[{$dt}]  {$source}\n  {$message}\n\n";
        }

        return rtrim($lines) . "\n";
    }

    public static function compose(array $form, string $sysInfo, string $errorLog): string
    {
        $type    = $form['type']          ?? 'other';
        $subject = $form['subject']       ?? '';
        $message = $form['message']       ?? '';
        $name    = $form['contact_name']  ?? '';
        $contact = $form['contact_email'] ?? '';
        $includeSysInfo  = (bool) ($form['include_sysinfo']  ?? false);
        $includeErrorLog = (bool) ($form['include_errorlog'] ?? false);

        $typeLabel      = self::TYPE_LABELS[$type] ?? 'Other';
        $nameDisplay    = $name    !== '' ? $name    : 'Not provided';
        $contactDisplay = $contact !== '' ? $contact : 'Not provided';

        $body  = "LUSTER FEEDBACK REPORT\n";
        $body .= self::SEP;
        $body .= self::row('Type',    $typeLabel);
        $body .= self::row('Name',    $nameDisplay);
        $body .= self::row('Contact', $contactDisplay);
        $body .= "\n";
        $body .= "Subject: {$subject}\n\n";
        $body .= $message;
        $body .= "\n\n";

        if ($includeSysInfo) {
            $body .= $sysInfo . "\n";
        }

        if ($includeErrorLog) {
            $body .= $errorLog . "\n";
        }

        return $body;
    }

    private static function row(string $label, string $value): string
    {
        return sprintf("%-18s %s\n", $label . ':', $value);
    }
}
