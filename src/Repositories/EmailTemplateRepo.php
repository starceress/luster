<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Manages transactional email templates in lstr_email_templates. Each template
 has a type (join, approve, reset, affiliate_applied, etc.) and can exist at
 four levels: per-listing override (fanlisting_id = N), global listing default
 (fanlisting_id = 0), collective override (fanlisting_id = -1), or built-in
 hardcoded default. The resolve() method walks the appropriate inheritance
 chain to produce the final subject and body, applying {token} substitution
 along the way. Also provides static token-map builders for each email category.
*/

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Database;

/**
 * Data access for the lstr_email_templates table.
 *
 * Stores admin-configurable email subjects and bodies for transactional
 * emails. Each row covers one action type for one listing. Templates exist
 * at three levels:
 *   - Global (fanlisting_id = 0):  listing-level types, applies to all listings
 *   - Collective (fanlisting_id = -1): collective-level types (affiliates,
 *     adoptions, KIM)
 *   - Per-listing (fanlisting_id = N): per-listing overrides for listing types
 *
 * Resolution order for listing-level types (first match wins):
 *   1. Per-listing template (fanlisting_id = N) — enabled, non-blank subject
 *   2. Global template      (fanlisting_id = 0) — enabled, non-blank subject
 *   3. Built-in hardcoded default
 *
 * Resolution order for collective-level types:
 *   1. Collective template  (fanlisting_id = -1) — enabled, non-blank subject
 *   2. Collective built-in default
 *
 * Token syntax: {token_name} (curly braces on both sides).
 *
 * Available tokens (all fan-facing types):
 *   {fan_name}          — member's name
 *   {fan_email}         — member's email address
 *   {fan_url}           — member's website URL
 *   {fan_country}       — member's country
 *   {listing_title}      — name / title of the listing
 *   {listing_subject}   — subject / topic of the listing
 *   {listing_type}      — type of listing (listing, clique, etc.)
 *   {listing_url}       — URL of the listing
 *   {site_name}         — collective / site name from config
 *   {owner_name}        — site owner's display name (config: owner_name)
 *   {owner_email}       — site owner's contact email (config: owner_email)
 *
 * Reset-only additional token:
 *   {reset_url}         — one-time password reset link
 *
 * Admin notification (notify_join) — sent to the listing's admin email:
 *   same tokens as join.
 *
 * Affiliate application tokens (affiliate_applied / affiliate_approved / affiliate_rejected):
 *   {aff_name}          — applicant's site name
 *   {aff_url}           — applicant's site URL
 *   {aff_email}         — applicant's contact email
 *   {aff_message}       — optional message from the applicant (blank if not provided)
 *   plus {listing_title}, {listing_subject}, {listing_type}, {listing_url},
 *        {site_name}, {owner_name}, {owner_email}
 *
 * Adoption tokens (adoption_applied / adoption_approved / adoption_rejected):
 *   {applicant_name}    — adoption applicant's name
 *   {applicant_email}   — adoption applicant's email address
 *   {applicant_url}     — adoption applicant's website URL (blank if not provided)
 *   {applicant_message} — applicant's message, prefixed "Message: \n\n" when non-empty
 *   plus {listing_title}, {listing_subject}, {listing_type}, {listing_url},
 *        {site_name}, {owner_name}, {owner_email}
 *
 * KIM tokens (kim_applied / kim_approved):
 *   {kim_name}          — KIM member's name
 *   {kim_email}         — KIM member's email address
 *   {kim_url}           — KIM member's website URL
 *   {kim_password}      — KIM member's password (user-chosen or auto-generated)
 *   {kim_update_url}    — link to the KIM update form
 *   plus {listing_title}, {listing_subject}, {listing_type}, {listing_url},
 *        {site_name}, {owner_name}, {owner_email}
 *
 */
final class EmailTemplateRepo
{
    private static string $globalSiteUrl = '';

    public static function setGlobalSiteUrl(string $url): void
    {
        self::$globalSiteUrl = $url;
    }

    // -----------------------------------------------------------------------
    // Type constants
    // -----------------------------------------------------------------------

    public const TYPE_JOIN               = 'join';
    public const TYPE_APPROVE            = 'approve';
    public const TYPE_UPDATE             = 'update';
    public const TYPE_REMOVE             = 'remove';
    public const TYPE_RESET              = 'reset';
    public const TYPE_NOTIFY_JOIN        = 'notify_join';
    public const TYPE_AFFILIATE_APPLIED  = 'affiliate_applied';
    public const TYPE_AFFILIATE_APPROVED = 'affiliate_approved';
    public const TYPE_AFFILIATE_REJECTED = 'affiliate_rejected';
    public const TYPE_CODE_APPROVED      = 'code_approved';
    public const TYPE_ADOPTION_APPLIED   = 'adoption_applied';
    public const TYPE_ADOPTION_APPROVED  = 'adoption_approved';
    public const TYPE_ADOPTION_REJECTED  = 'adoption_rejected';
    public const TYPE_KIM_SIGNUP          = 'kim_signup';
    public const TYPE_KIM_APPLIED        = 'kim_applied';
    public const TYPE_KIM_APPROVED       = 'kim_approved';
    public const TYPE_KIM_UPDATE         = 'kim_update';
    public const TYPE_KIM_RESET          = 'kim_reset';

    /** Sentinel fanlisting_id for collective-scope templates. */
    public const COLLECTIVE_ID = -1;

    /**
     * Types that are collective-level only (never appear in per-listing or
     * global-listing scopes). When resolveForListing() is called with a non-null
     * listing ID and one of these types, any stored per-listing row is ignored
     * and the collective template (fanlisting_id = -1) is used instead.
     */
    public const COLLECTIVE_ONLY_TYPES = [
        self::TYPE_ADOPTION_APPLIED,
        self::TYPE_ADOPTION_APPROVED,
        self::TYPE_ADOPTION_REJECTED,
        self::TYPE_KIM_SIGNUP,
        self::TYPE_KIM_APPLIED,
        self::TYPE_KIM_APPROVED,
        self::TYPE_KIM_UPDATE,
        self::TYPE_KIM_RESET,
    ];

    /** Types shown in the "Global Listings (Default)" and per-listing scopes. */
    public const LISTING_TYPES = [
        self::TYPE_JOIN,
        self::TYPE_APPROVE,
        self::TYPE_UPDATE,
        self::TYPE_REMOVE,
        self::TYPE_RESET,
        self::TYPE_NOTIFY_JOIN,
        self::TYPE_AFFILIATE_APPLIED,
        self::TYPE_AFFILIATE_APPROVED,
        self::TYPE_AFFILIATE_REJECTED,
        self::TYPE_CODE_APPROVED,
    ];

    /** Types shown in the "Collective" scope. */
    public const COLLECTIVE_TYPES = [
        self::TYPE_AFFILIATE_APPLIED,
        self::TYPE_AFFILIATE_APPROVED,
        self::TYPE_AFFILIATE_REJECTED,
        self::TYPE_CODE_APPROVED,
        self::TYPE_ADOPTION_APPLIED,
        self::TYPE_ADOPTION_APPROVED,
        self::TYPE_ADOPTION_REJECTED,
        self::TYPE_KIM_SIGNUP,
        self::TYPE_KIM_APPLIED,
        self::TYPE_KIM_APPROVED,
        self::TYPE_KIM_UPDATE,
        self::TYPE_KIM_RESET,
    ];

    public const DISABLED_BY_DEFAULT = [
        self::TYPE_AFFILIATE_REJECTED,
        self::TYPE_ADOPTION_REJECTED,
    ];

    /** All valid type values in display order. */
    public const TYPES = [
        self::TYPE_JOIN,
        self::TYPE_APPROVE,
        self::TYPE_UPDATE,
        self::TYPE_REMOVE,
        self::TYPE_RESET,
        self::TYPE_NOTIFY_JOIN,
        self::TYPE_AFFILIATE_APPLIED,
        self::TYPE_AFFILIATE_APPROVED,
        self::TYPE_AFFILIATE_REJECTED,
        self::TYPE_CODE_APPROVED,
        self::TYPE_ADOPTION_APPLIED,
        self::TYPE_ADOPTION_APPROVED,
        self::TYPE_ADOPTION_REJECTED,
        self::TYPE_KIM_SIGNUP,
        self::TYPE_KIM_APPLIED,
        self::TYPE_KIM_APPROVED,
        self::TYPE_KIM_UPDATE,
        self::TYPE_KIM_RESET,
    ];

    // -----------------------------------------------------------------------
    // Default subjects
    // -----------------------------------------------------------------------

    private const DEFAULT_SUBJECTS = [
        self::TYPE_JOIN               => 'Thank you for joining {listing_title}!',
        self::TYPE_APPROVE            => 'Your {listing_title} membership has been approved',
        self::TYPE_UPDATE             => 'Your {listing_title} membership has been updated',
        self::TYPE_REMOVE             => 'You have been removed from the {listing_title} listing',
        self::TYPE_RESET              => 'Reset your {listing_title} password',
        self::TYPE_NOTIFY_JOIN        => 'New member joined {listing_title}',
        self::TYPE_AFFILIATE_APPLIED  => 'New affiliate application for {listing_title}',
        self::TYPE_AFFILIATE_APPROVED => 'Your affiliate application for {listing_title} has been approved',
        self::TYPE_AFFILIATE_REJECTED => 'Your affiliate application for {listing_title}',
        self::TYPE_CODE_APPROVED      => 'Your code donation for {listing_title} has been approved!',
        self::TYPE_ADOPTION_APPLIED   => 'New adoption application for {listing_subject}',
        self::TYPE_ADOPTION_APPROVED  => 'Your adoption application for {listing_subject} has been approved',
        self::TYPE_ADOPTION_REJECTED  => 'Your adoption application for {listing_subject}',
        self::TYPE_KIM_SIGNUP         => 'Your KIM List sign-up for {listing_subject}',
        self::TYPE_KIM_APPLIED        => 'New KIM List sign-up for {listing_subject}',
        self::TYPE_KIM_APPROVED       => 'Your KIM List sign-up for {listing_subject} has been approved',
        self::TYPE_KIM_UPDATE         => 'Your KIM List entry has been updated',
        self::TYPE_KIM_RESET          => 'Reset your KIM List password',
    ];

    // -----------------------------------------------------------------------
    // Default bodies
    // -----------------------------------------------------------------------

    private const DEFAULT_BODY_JOIN =
        'Hi {fan_name},' . "\n\n"
        . 'Thank you for joining "{listing_title}", the {listing_type} for {listing_subject}.' . "\n\n"
        . 'Here are your membership details:' . "\n"
        . '  Name: {fan_name}' . "\n"
        . '  Email: {fan_email}' . "\n"
        . '  Website: {fan_url}' . "\n"
        . '  Country: {fan_country}' . "\n"
        . '  Password: {fan_password}' . "\n\n"
        . 'Your membership is currently in queue for approval. You\'ll receive another email once you\'ve been approved.' . "\n\n"
        . 'Keep your password somewhere safe! If you ever need to reset your password, you can do so at: {reset_url}. If you need to update your information, you can do so at: {update_url}.' . "\n\n"
        . '-- {owner_name}' . "\n"
        . '{listing_title} ({listing_url})';

    private const DEFAULT_BODY_APPROVE =
        'Hi {fan_name},' . "\n\n"
        . 'Your membership in "{listing_title}", the {listing_type} for {listing_subject}, has been approved! You should now be able to see your information at the site: {listing_url}.' . "\n\n"
        . 'Thanks for joining!' . "\n\n"
        . '-- {owner_name}' . "\n"
        . '{listing_title} ({listing_url})';

    private const DEFAULT_BODY_UPDATE =
        'Hi {fan_name},' . "\n\n"
        . 'Your membership in "{listing_title}", the {listing_type} for {listing_subject}, has been updated successfully.' . "\n\n"
        . '-- {owner_name}' . "\n"
        . '{listing_title} ({listing_url})';

    private const DEFAULT_BODY_REMOVE =
        'Hi {fan_name},' . "\n\n"
        . 'Your membership in "{listing_title}", the {listing_type} for {listing_subject}, has been removed as requested. If you change your mind and would like to join again in the future, you can do so at {join_url}.' . "\n\n"
        . '-- {owner_name}' . "\n"
        . '{listing_title} ({listing_url})';

    private const DEFAULT_BODY_RESET =
        'Hi {fan_name},' . "\n\n"
        . 'We received a request to reset the password for your membership in "{listing_title}", the {listing_type} for {listing_subject}.' . "\n\n"
        . 'Click the link below to set a new password. For your security, the link expires in 1 hour.' . "\n\n"
        . '{reset_url}' . "\n\n"
        . 'If you did not request this, you can safely ignore this email. Your password will not change.' . "\n\n"
        . '-- {owner_name}' . "\n"
        . '{listing_title} ({listing_url})';

    private const DEFAULT_BODY_NOTIFY_JOIN =
        'A new member has joined "{listing_title}" - {listing_subject} {listing_type}!' . "\n\n"
        . '  Name: {fan_name}' . "\n"
        . '  Email: {fan_email}' . "\n"
        . '  Website: {fan_url}' . "\n"
        . '  Country: {fan_country}' . "\n\n"
        . '-- {owner_name}' . "\n"
        . '{listing_title} ({listing_url})';

    private const DEFAULT_BODY_AFFILIATE_APPLIED =
        'A new affiliate application has been received for "{listing_title}" - {listing_subject} {listing_type}!' . "\n\n"
        . '  Name: {aff_name}' . "\n"
        . '  Email: {aff_email}' . "\n"
        . '  Website: {aff_url}' . "\n"
        . '  Message: {aff_message}' . "\n\n"
        . 'Review and approve or reject this application from your admin panel.' . "\n\n"
        . '-- {site_name} ({site_url})';

    private const DEFAULT_BODY_AFFILIATE_APPROVED =
        'Hi {aff_name},' . "\n\n"
        . 'Your affiliate application for "{listing_title}", the {listing_type} for {listing_subject}, has been approved!' . "\n\n"
        . 'Your site is now listed as an affiliate at: {listing_url}' . "\n\n"
        . 'Thank you!' . "\n\n"
        . '-- {owner_name}' . "\n"
        . '{site_name} ({site_url})';

    private const DEFAULT_BODY_AFFILIATE_REJECTED =
        'Hello {aff_name},' . "\n\n"
        . 'Thank you for applying to affiliate with "{listing_title}", the {listing_type} for {listing_subject}.' . "\n\n"
        . 'Unfortunately, I am not able to accept you as an affiliate at this time. Nevertheless, thank you for reaching out.' . "\n\n"
        . '-- {owner_name}' . "\n"
        . '{site_name} ({site_url})';

    private const DEFAULT_BODY_CODE_APPROVED =
        'Hi {fan_name},' . "\n\n"
        . 'Thank you for donating a code to "{listing_title}", the {listing_type} for {listing_subject}. Your code has been approved and is now live!' . "\n\n"
        . 'View the codes at: {listing_url}' . "\n\n"
        . '-- {owner_name}' . "\n"
        . '{site_name} ({site_url})';

    private const DEFAULT_BODY_ADOPTION_APPLIED =
        'A new adoption application has been received for "{listing_title}" - {listing_subject} {listing_type}.' . "\n\n"
        . '  Name: {applicant_name}' . "\n"
        . '  Email: {applicant_email}' . "\n"
        . '  Website: {applicant_url}' . "\n"
        . '  Message: {applicant_message}' . "\n\n"
        . 'Review this application from your admin panel.' . "\n\n"
        . '-- {site_name} ({site_url})';

    private const DEFAULT_BODY_ADOPTION_APPROVED =
        'Hi {applicant_name},' . "\n\n"
        . 'Your adoption application for "{listing_title}", the {listing_type} for {listing_subject}, has been approved!' . "\n\n"
        . 'We will be in touch soon with the next steps. Thank you!' . "\n\n"
        . '-- {owner_name}' . "\n"
        . '{site_name} ({site_url})';

    private const DEFAULT_BODY_ADOPTION_REJECTED =
        'Hi {applicant_name},' . "\n\n"
        . 'Thank you for your interest in adopting "{listing_title}", the {listing_type} for {listing_subject}.' . "\n\n"
        . 'Unfortunately, I am not able to approve your application at this time. Nevertheless, thank you for reaching out and showing your interest.' . "\n\n"
        . '-- {owner_name}' . "\n"
        . '{site_name} ({site_url})';

    private const DEFAULT_BODY_KIM_APPLIED =
        'A new Keep In Mind sign-up has been received for {listing_subject}.' . "\n\n"
        . '  Name: {kim_name}' . "\n"
        . '  Email: {kim_email}' . "\n"
        . '  Website: {kim_url}' . "\n\n"
        . 'Review and approve or reject from your admin panel.' . "\n\n"
        . '-- {site_name} ({site_url})';

    private const DEFAULT_BODY_KIM_APPROVED =
        'Hi {kim_name},' . "\n\n"
        . 'Your Keep In Mind sign-up for {listing_subject} has been approved.' . "\n\n"
        . 'We will let you know when the listing is open for members.' . "\n\n"
        . '-- {owner_name}' . "\n"
        . '{site_name} ({site_url})';

    private const DEFAULT_BODY_KIM_UPDATE =
        'Hi {kim_name},' . "\n\n"
        . 'Your Keep In Mind entry has been updated successfully.' . "\n\n"
        . '-- {owner_name}' . "\n"
        . '{site_name} ({site_url})';

    private const DEFAULT_BODY_KIM_SIGNUP =
        'Hi {kim_name},' . "\n\n"
        . 'You have signed up for the KIM List for {listing_subject}.' . "\n\n"
        . '  Name: {kim_name}' . "\n"
        . '  Email: {kim_email}' . "\n"
        . '  Website: {kim_url}' . "\n"
        . '  Password: {kim_password}' . "\n\n"
        . 'Your entry is currently in queue for approval. You\'ll receive another email once you\'ve been approved.' . "\n\n"
        . 'Keep your password somewhere safe! If you ever need to reset it, you can do so at: {reset_url}. If you need to update your information, you can do so at: {kim_update_url}.' . "\n\n"
        . '-- {owner_name}' . "\n"
        . '{site_name} ({site_url})';

    private const DEFAULT_BODY_KIM_RESET =
        'Hi {kim_name},' . "\n\n"
        . 'We received a request to reset the password for your Keep In Mind sign-up(s).' . "\n\n"
        . 'Use the link below to set a new password. For your security, the link expires in 1 hour.' . "\n\n"
        . '  {reset_url}' . "\n\n"
        . 'If you did not request this, you can safely ignore this email. Your password will not change.' . "\n\n"
        . '-- {owner_name}' . "\n"
        . '{site_name} ({site_url})';

    // -----------------------------------------------------------------------
    // Collective default subjects (for collective-scope templates)
    // -----------------------------------------------------------------------

    private const COLLECTIVE_DEFAULT_SUBJECTS = [
        self::TYPE_AFFILIATE_APPLIED  => 'New affiliate application for {site_name}',
        self::TYPE_AFFILIATE_APPROVED => 'Your affiliate application for {site_name} has been approved',
        self::TYPE_AFFILIATE_REJECTED => 'Your affiliate application for {site_name}',
        self::TYPE_ADOPTION_APPLIED   => 'New adoption application for {listing_subject}',
        self::TYPE_ADOPTION_APPROVED  => 'Your adoption application for {listing_subject} has been approved',
        self::TYPE_ADOPTION_REJECTED  => 'Your adoption application for {listing_subject}',
        self::TYPE_KIM_SIGNUP         => 'Your KIM List sign-up for {listing_subject}',
        self::TYPE_KIM_APPLIED        => 'New KIM List sign-up for {listing_subject}',
        self::TYPE_KIM_APPROVED       => 'Your KIM List sign-up for {listing_subject} has been approved',
        self::TYPE_CODE_APPROVED      => 'Your code donation for {site_name} has been approved!',
        self::TYPE_KIM_UPDATE         => 'Your KIM List entry has been updated',
        self::TYPE_KIM_RESET          => 'Reset your KIM List password',
    ];

    // -----------------------------------------------------------------------
    // Collective default bodies
    // -----------------------------------------------------------------------

    private const COLLECTIVE_DEFAULT_BODY_CODE_APPROVED =
        'Hi {fan_name},' . "\n\n"
        . 'Thank you for donating a code to {site_name}. Your code has been approved and is now live!' . "\n\n"
        . '-- {owner_name}' . "\n"
        . '{site_name} ({site_url})';

    private const COLLECTIVE_DEFAULT_BODY_AFFILIATE_APPLIED =
        'A new affiliate application has been received for {site_name}.' . "\n\n"
        . '  Name: {aff_name}' . "\n"
        . '  Email: {aff_email}' . "\n"
        . '  Website: {aff_url}' . "\n"
        . '  Message: {aff_message}' . "\n\n"
        . 'Review and approve or reject this application from your admin panel.' . "\n\n"
        . '-- {site_name} ({site_url})';

    private const COLLECTIVE_DEFAULT_BODY_AFFILIATE_APPROVED =
        'Hi {aff_name},' . "\n\n"
        . 'Your affiliate application for {site_name} has been approved!' . "\n\n"
        . 'Your site is now listed as an affiliate. Thank you!' . "\n\n"
        . '-- {owner_name}' . "\n"
        . '{site_name} ({site_url})';

    private const COLLECTIVE_DEFAULT_BODY_AFFILIATE_REJECTED =
        'Hello {aff_name},' . "\n\n"
        . 'Thank you for applying to affiliate with {site_name}.' . "\n\n"
        . 'Unfortunately, I am not able to accept you as an affiliate at this time. Nevertheless, thank you for reaching out.' . "\n\n"
        . '-- {owner_name}' . "\n"
        . '{site_name} ({site_url})';

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

    /** Returns the body string of an existing template, or null when no row exists. */
    public function getBody(int $listingId, string $type): ?string
    {
        return $this->fetchOne($listingId, $type)?->body;
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
    // Reads — collective (fanlisting_id = -1)
    // -----------------------------------------------------------------------

    /**
     * Returns the collective template for one type, or null if none saved.
     */
    public function getCollective(string $type): ?object
    {
        return $this->fetchOne(self::COLLECTIVE_ID, $type);
    }

    /**
     * Returns collective templates for all types, keyed by type.
     * Missing rows are represented as null.
     *
     * @return array<string, object|null>
     */
    public function getAllCollective(): array
    {
        return $this->fetchAll(self::COLLECTIVE_ID);
    }

    // -----------------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------------

    /**
     * Upserts the template for one fanlisting_id + type combination.
     * Pass fanlisting_id = 0 for global, -1 for collective templates.
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
     * Strict variant of save() that writes only the body, preserving the existing
     * subject and enabled state. For use inside transactions; propagates \PDOException.
     *
     * When no row exists yet, inserts with the default subject for the given type
     * so resolve() can use the row immediately; the admin can customise further.
     */
    public function saveBodyStrict(int $listingId, string $type, string $body): void
    {
        // Seed the default subject on insert so resolve() can use this row.
        // ON DUPLICATE KEY only updates the body, preserving any existing subject.
        $stmt = $this->db->prepare(
            'INSERT INTO lstr_email_templates (fanlisting_id, type, subject, body, enabled)
             VALUES (:fid, :type, :subject, :body, 1)
             ON DUPLICATE KEY UPDATE body = VALUES(body)'
        );
        $stmt->execute([
            'fid'     => $listingId,
            'type'    => $type,
            'subject' => self::defaultSubject($type),
            'body'    => $body,
        ]);
    }

    /**
     * Upserts only the enabled flag for one fanlisting_id + type, leaving
     * subject and body untouched if a row already exists.
     */
    public function setEnabled(int $listingId, string $type, bool $enabled): void
    {
        try {
            $seedDefaults = $enabled && in_array($type, self::DISABLED_BY_DEFAULT, true);
            $isCollective = $listingId === self::COLLECTIVE_ID;
            $insertSubject = $seedDefaults
                ? ($isCollective ? self::collectiveDefaultSubject($type) : self::defaultSubject($type))
                : '';
            $insertBody = $seedDefaults
                ? ($isCollective ? self::collectiveDefaultBody($type) : self::defaultBody($type))
                : '';

            if ($seedDefaults) {
                $stmt = $this->db->prepare(
                    'INSERT INTO lstr_email_templates (fanlisting_id, type, subject, body, enabled)
                     VALUES (:fid, :type, :subject, :body, :enabled)
                     ON DUPLICATE KEY UPDATE
                         enabled = VALUES(enabled),
                         subject = IF(TRIM(subject) = \'\', VALUES(subject), subject),
                         body    = IF(TRIM(body)    = \'\', VALUES(body),    body)'
                );
            } else {
                $stmt = $this->db->prepare(
                    'INSERT INTO lstr_email_templates (fanlisting_id, type, subject, body, enabled)
                     VALUES (:fid, :type, :subject, :body, :enabled)
                     ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)'
                );
            }

            $stmt->execute([
                'fid'     => $listingId,
                'type'    => $type,
                'subject' => $insertSubject,
                'body'    => $insertBody,
                'enabled' => (int) $enabled,
            ]);
        } catch (\Exception) {
            // Migration not yet run — silently ignore
        }
    }

    /**
     * Deletes the saved template for one fanlisting_id + type, reverting to
     * the next level in the inheritance chain.
     * - fanlisting_id = -1 → deletes collective, reverts to collective built-in default
     * - fanlisting_id = 0  → deletes global, reverts to built-in default
     * - fanlisting_id = N  → deletes per-listing, reverts to global (or built-in)
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
     * Resolves subject and body for the given listing (or collective when $listingId is null).
     *
     * For listing-level types: per-listing → global (fanlisting_id=0) → built-in default.
     * For collective-level types (COLLECTIVE_ONLY_TYPES + affiliate_* when $listingId is
     * null): collective (fanlisting_id=-1) → collective built-in default.
     *
     * @return array{string, string}  [subject, body]
     */
    public function resolveForListing(?int $listingId, string $type, array $tokens): array
    {
        // Collective-only types must not pick up stale per-listing rows: treat as collective
        $effectiveId = ($listingId !== null && in_array($type, self::COLLECTIVE_ONLY_TYPES, true))
            ? null
            : $listingId;

        // Determine whether this resolution should use collective scope
        $useCollective = $effectiveId === null
            && in_array($type, self::COLLECTIVE_TYPES, true);

        if ($useCollective) {
            // Collective resolution: collective template → collective built-in default
            $collective = $this->getCollective($type);

            if ($collective !== null) {
                if (!$collective->enabled) {
                    return ['', ''];
                }
                if (trim($collective->subject) !== '') {
                    return [
                        self::applyTokens($collective->subject, $tokens),
                        self::applyTokens($collective->body,    $tokens),
                    ];
                }
            }

            if (in_array($type, self::DISABLED_BY_DEFAULT, true)) {
                return ['', ''];
            }

            return [
                self::applyTokens(self::collectiveDefaultSubject($type), $tokens),
                self::applyTokens(self::collectiveDefaultBody($type),    $tokens),
            ];
        }

        // Listing-level resolution: per-listing → global → built-in default
        $perListing = $effectiveId !== null ? $this->get($effectiveId, $type) : null;
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
            self::TYPE_JOIN               => self::DEFAULT_BODY_JOIN,
            self::TYPE_APPROVE            => self::DEFAULT_BODY_APPROVE,
            self::TYPE_UPDATE             => self::DEFAULT_BODY_UPDATE,
            self::TYPE_REMOVE             => self::DEFAULT_BODY_REMOVE,
            self::TYPE_RESET              => self::DEFAULT_BODY_RESET,
            self::TYPE_NOTIFY_JOIN        => self::DEFAULT_BODY_NOTIFY_JOIN,
            self::TYPE_AFFILIATE_APPLIED  => self::DEFAULT_BODY_AFFILIATE_APPLIED,
            self::TYPE_AFFILIATE_APPROVED => self::DEFAULT_BODY_AFFILIATE_APPROVED,
            self::TYPE_AFFILIATE_REJECTED => self::DEFAULT_BODY_AFFILIATE_REJECTED,
            self::TYPE_CODE_APPROVED      => self::DEFAULT_BODY_CODE_APPROVED,
            self::TYPE_ADOPTION_APPLIED   => self::DEFAULT_BODY_ADOPTION_APPLIED,
            self::TYPE_ADOPTION_APPROVED  => self::DEFAULT_BODY_ADOPTION_APPROVED,
            self::TYPE_ADOPTION_REJECTED  => self::DEFAULT_BODY_ADOPTION_REJECTED,
            self::TYPE_KIM_SIGNUP         => self::DEFAULT_BODY_KIM_SIGNUP,
            self::TYPE_KIM_APPLIED        => self::DEFAULT_BODY_KIM_APPLIED,
            self::TYPE_KIM_APPROVED       => self::DEFAULT_BODY_KIM_APPROVED,
            self::TYPE_KIM_UPDATE         => self::DEFAULT_BODY_KIM_UPDATE,
            self::TYPE_KIM_RESET          => self::DEFAULT_BODY_KIM_RESET,
            default                       => '',
        };
    }

    /** Returns the collective default subject line for the given email type. */
    public static function collectiveDefaultSubject(string $type): string
    {
        return self::COLLECTIVE_DEFAULT_SUBJECTS[$type] ?? self::DEFAULT_SUBJECTS[$type] ?? '';
    }

    /** Returns the collective default body text for the given email type. */
    public static function collectiveDefaultBody(string $type): string
    {
        return match ($type) {
            self::TYPE_AFFILIATE_APPLIED  => self::COLLECTIVE_DEFAULT_BODY_AFFILIATE_APPLIED,
            self::TYPE_AFFILIATE_APPROVED => self::COLLECTIVE_DEFAULT_BODY_AFFILIATE_APPROVED,
            self::TYPE_AFFILIATE_REJECTED => self::COLLECTIVE_DEFAULT_BODY_AFFILIATE_REJECTED,
            self::TYPE_CODE_APPROVED      => self::COLLECTIVE_DEFAULT_BODY_CODE_APPROVED,
            // Adoption and KIM types share the listing-level defaults (they already
            // reference {listing_title} for the specific listing being adopted/watched)
            default                       => self::defaultBody($type),
        };
    }

    /**
     * Replaces all {token} placeholders in $text with the corresponding values.
     *
     * @param array<string, string> $tokens  Keys are '{token_name}' style strings
     */
    public static function applyTokens(string $text, array $tokens): string
    {
        return str_replace(array_keys($tokens), array_values($tokens), $text);
    }

    /**
     * Builds the standard token map for use with applyTokens().
     *
     * Populates all standard fan-email tokens. Form-URL params fall back to
     * $listingUrl when left empty. Legacy aliases ({form_url_join}, {form_url_update},
     * {form_url_reset}) are emitted alongside the short names for saved template compat.
     *
     * @param string $listingEmail  The listing's own contact email ({listing_email}).
     * @param string $formUrlJoin   Direct join-form URL ({join_url}); falls back to $listingUrl.
     * @param string $formUrlUpdate Direct update-form URL ({update_url}); falls back to $listingUrl.
     * @param string $formUrlReset  Direct reset-form URL ({form_url_reset}); falls back to $listingUrl.
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
        string $resetUrl       = '',
        string $fanPassword    = '',
        string $listingEmail   = '',
        string $formUrlJoin    = '',
        string $formUrlUpdate  = '',
        string $formUrlReset   = '',
        string $listingType    = '',
        string $siteUrl        = '',
    ): array {
        return [
            '{fan_name}'         => $fanName,
            '{fan_email}'        => $fanEmail,
            '{fan_url}'          => $fanUrl,
            '{fan_country}'      => $fanCountry,
            '{fan_password}'     => $fanPassword,
            '{reset_url}'        => $resetUrl,
            '{listing_title}'    => $listingName,
            '{listing_subject}'  => $listingSubject,
            '{listing_type}'     => $listingType,
            '{listing_url}'      => $listingUrl,
            '{listing_email}'    => $listingEmail,
            '{site_name}'        => $siteName,
            '{site_url}'         => $siteUrl !== '' ? $siteUrl : self::$globalSiteUrl,
            '{owner_name}'       => $ownerName,
            '{owner_email}'      => $ownerEmail,
            '{join_url}'         => self::resolveFormUrl($formUrlJoin, $listingUrl),
            '{update_url}'       => self::resolveFormUrl($formUrlUpdate, $listingUrl),
            '{form_url_join}'    => self::resolveFormUrl($formUrlJoin, $listingUrl),
            '{form_url_update}'  => self::resolveFormUrl($formUrlUpdate, $listingUrl),
            '{form_url_reset}'   => self::resolveFormUrl($formUrlReset, $listingUrl),
        ];
    }

    /**
     * Resolves a form page URL to an absolute URL. If the per-listing URL is
     * empty, falls back to the global default from lstr_site_settings (when
     * available), then to the listing URL.
     */
    private static function resolveFormUrl(string $url, string $listingUrl, string $globalDefault = ''): string
    {
        return \Luster\Support\ListingFormUrlResolver::resolve($url, $listingUrl, $globalDefault);
    }

    /**
     * Builds the affiliate-application token map for use with applyTokens().
     * Used for affiliate_applied, affiliate_approved, and affiliate_rejected emails.
     *
     * @return array<string, string>
     */
    public static function buildAffiliateTokens(
        string $listingName,
        string $listingUrl,
        string $siteName,
        string $ownerName       = '',
        string $ownerEmail      = '',
        string $affName         = '',
        string $affUrl          = '',
        string $affEmail        = '',
        string $affMessage      = '',
        string $listingSubject  = '',
        string $listingType     = '',
        string $siteUrl         = '',
    ): array {
        $messageBlock = $affMessage !== ''
            ? 'Message: ' . $affMessage . "\n\n"
            : '';

        return [
            '{listing_title}'    => $listingName,
            '{listing_subject}'  => $listingSubject,
            '{listing_type}'     => $listingType,
            '{listing_url}'      => $listingUrl,
            '{site_name}'        => $siteName,
            '{site_url}'         => $siteUrl !== '' ? $siteUrl : self::$globalSiteUrl,
            '{owner_name}'       => $ownerName,
            '{owner_email}'      => $ownerEmail,
            '{aff_name}'         => $affName,
            '{aff_url}'          => $affUrl,
            '{aff_email}'        => $affEmail,
            '{aff_message}'      => $messageBlock,
        ];
    }

    /**
     * Builds the adoption-application token map for use with applyTokens().
     * Used for adoption_applied, adoption_approved, and adoption_rejected emails.
     *
     * @return array<string, string>
     */
    public static function buildAdoptionTokens(
        string $listingName      = '',
        string $listingUrl       = '',
        string $siteName         = '',
        string $ownerName        = '',
        string $ownerEmail       = '',
        string $applicantName    = '',
        string $applicantEmail   = '',
        string $applicantUrl     = '',
        string $applicantMessage = '',
        string $listingSubject   = '',
        string $listingType      = '',
        string $siteUrl          = '',
    ): array {
        $messageBlock = $applicantMessage !== ''
            ? 'Message: ' . $applicantMessage . "\n\n"
            : '';

        return [
            '{listing_title}'      => $listingName,
            '{listing_subject}'    => $listingSubject,
            '{listing_type}'       => $listingType,
            '{listing_url}'        => $listingUrl,
            '{site_name}'          => $siteName,
            '{site_url}'           => $siteUrl !== '' ? $siteUrl : self::$globalSiteUrl,
            '{owner_name}'         => $ownerName,
            '{owner_email}'        => $ownerEmail,
            '{applicant_name}'     => $applicantName,
            '{applicant_email}'    => $applicantEmail,
            '{applicant_url}'      => $applicantUrl,
            '{applicant_message}'  => $messageBlock,
        ];
    }

    /**
     * Builds the KIM-member token map for use with applyTokens().
     * Used for kim_applied and kim_approved emails.
     *
     * @return array<string, string>
     */
    public static function buildKimTokens(
        string $listingName    = '',
        string $listingUrl     = '',
        string $siteName       = '',
        string $ownerName      = '',
        string $ownerEmail     = '',
        string $kimName        = '',
        string $kimEmail       = '',
        string $kimUrl         = '',
        string $resetUrl       = '',
        string $listingSubject = '',
        string $listingType    = '',
        string $kimPassword    = '',
        string $kimUpdateUrl   = '',
        string $siteUrl        = '',
    ): array {
        return [
            '{listing_title}'    => $listingName,
            '{listing_subject}'  => $listingSubject,
            '{listing_type}'     => $listingType,
            '{listing_url}'      => $listingUrl,
            '{site_name}'        => $siteName,
            '{site_url}'         => $siteUrl !== '' ? $siteUrl : self::$globalSiteUrl,
            '{owner_name}'       => $ownerName,
            '{owner_email}'      => $ownerEmail,
            '{kim_name}'         => $kimName,
            '{kim_email}'        => $kimEmail,
            '{kim_url}'          => $kimUrl,
            '{kim_password}'     => $kimPassword,
            '{kim_update_url}'   => $kimUpdateUrl,
            '{reset_url}'        => $resetUrl,
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
        if ($perListing !== null) {
            if (!$perListing->enabled) {
                return ['', ''];
            }
            if (trim($perListing->subject) !== '') {
                return [
                    self::applyTokens($perListing->subject, $tokens),
                    self::applyTokens($perListing->body,    $tokens),
                ];
            }
        }

        if ($global !== null) {
            if (!$global->enabled) {
                return ['', ''];
            }
            if (trim($global->subject) !== '') {
                return [
                    self::applyTokens($global->subject, $tokens),
                    self::applyTokens($global->body,    $tokens),
                ];
            }
        }

        if (in_array($type, self::DISABLED_BY_DEFAULT, true)) {
            return ['', ''];
        }

        return [
            self::applyTokens(self::defaultSubject($type), $tokens),
            self::applyTokens(self::defaultBody($type),    $tokens),
        ];
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Fetches a single template row for a fanlisting_id + type pair, or returns
     * null when no row exists or when the table does not yet exist.
     */
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
     * Fetches all template rows for a fanlisting_id, returning a map of
     * type → object|null (null for any type with no saved row).
     *
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

    /**
     * Converts a raw DB row into a typed object with consistent property names.
     *
     * The returned object has properties: id, listingId, type, enabled, subject, body.
     */
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
