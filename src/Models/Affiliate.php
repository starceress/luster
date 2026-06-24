<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Value object for an affiliate from the lstr_affiliates table. Affiliates
 are partner sites linked from a specific listing or the collective as a
 whole (when listingId is null). Tracks approval status, optional image
 and email, sort order, and link-checker results including both URL
 reachability and reciprocal-link verification. Pending affiliates
 represent applications that haven't been approved yet.
*/

declare(strict_types=1);

namespace Luster\Models;

/**
 * Represents a single affiliate row from lstr_affiliates.
 *
 * Affiliates are partner sites that appear in the affiliates section of a
 * listing page or the collective as a whole. When listingId is null the
 * affiliate is collective-wide (not tied to any specific listing).
 *
 * status tracks approval state: 'approved' affiliates are shown publicly;
 * 'pending' affiliates are applications awaiting admin review. The pending
 * boolean flag mirrors status === 'pending' for convenience.
 *
 * linkStatus and reciprocalStatus are managed by the Link Checker feature.
 * linkRedirectUrl is set when LinkChecker follows a redirect chain so the
 * admin can accept the new URL with one click.
 *
 * email and image are optional — many affiliates are listed by name and URL only.
 */
final class Affiliate
{
    public function __construct(
        public readonly int     $id,
        /** ID of the associated listing; null means collective-wide. */
        public readonly ?int    $listingId,
        public readonly string  $name,
        public readonly string  $url,
        public readonly ?string $email,
        /** Filename (not a full URL) of the affiliate's button image, or null if none. */
        public readonly ?string $image,
        public readonly int     $sortOrder,
        public readonly bool    $active,
        /** Approval state: 'approved' or 'pending'. */
        public readonly string  $status,
        public readonly string  $createdAt,
        public readonly string  $updatedAt,
        /** Link checker result for the affiliate URL: 'ok', 'dead', 'moved', 'error', or 'unchecked'. */
        public readonly string  $linkStatus,
        /** Timestamp of the last link check, or null if never checked. */
        public readonly ?string $linkCheckedAt,
        /** Final redirect destination detected by the link checker; null if no redirect was followed. */
        public readonly ?string $linkRedirectUrl,
        /** Reciprocal link check result: 'ok', 'not_found', 'error', or 'unchecked'. */
        public readonly string  $reciprocalStatus,
        /** Timestamp of the last reciprocal check, or null if never checked. */
        public readonly ?string $reciprocalCheckedAt,
        /** True when this affiliate is an unapproved application (status === 'pending'). */
        public readonly bool    $pending,
        /** Message submitted by the applicant when applying for affiliation; null for manually added affiliates. */
        public readonly ?string $applicationMessage,
    ) {}

    /**
     * Hydrates an Affiliate from a PDO FETCH_ASSOC row.
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id:                   (int)    $row['id'],
            listingId:            $row['fanlisting_id'] !== null ? (int) $row['fanlisting_id'] : null,
            name:                 (string) $row['name'],
            url:                  (string) $row['url'],
            email:                isset($row['email'])  && $row['email']  !== null ? (string) $row['email']  : null,
            image:                isset($row['image'])  && $row['image']  !== null ? (string) $row['image']  : null,
            sortOrder:            (int)    ($row['sort_order'] ?? 0),
            active:               isset($row['active']) ? (bool) $row['active'] : true,
            status:               (string) ($row['status'] ?? 'approved'),
            createdAt:            (string) ($row['created_at'] ?? ''),
            updatedAt:            (string) ($row['updated_at'] ?? ''),
            linkStatus:           (string) ($row['link_status']           ?? 'unchecked'),
            linkCheckedAt:        isset($row['link_checked_at'])       && $row['link_checked_at']       !== null ? (string) $row['link_checked_at']       : null,
            linkRedirectUrl:      isset($row['link_redirect_url'])     && $row['link_redirect_url']     !== null ? (string) $row['link_redirect_url']     : null,
            reciprocalStatus:     (string) ($row['reciprocal_status']     ?? 'unchecked'),
            reciprocalCheckedAt:  isset($row['reciprocal_checked_at']) && $row['reciprocal_checked_at'] !== null ? (string) $row['reciprocal_checked_at'] : null,
            pending:              isset($row['pending']) ? (bool) $row['pending'] : false,
            applicationMessage:   isset($row['application_message']) && $row['application_message'] !== null ? (string) $row['application_message'] : null,
        );
    }
}
