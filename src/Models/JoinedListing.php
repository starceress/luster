<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Value object for a joined listing — a fanlisting the site owner has joined
 as a member (as opposed to one they run). Each row comes from the
 lstr_joined table and tracks the listing name, URL, category, join date,
 active/inactive status, and link-checker results. Used by the Joined
 Listings admin page, the public joined-listings embed, and the link
 checker feature.
*/

declare(strict_types=1);

namespace Luster\Models;

/**
 * Represents a single row from lstr_joined — a fanlisting the site owner has
 * joined as a member (as opposed to one they run).
 *
 * The distinction between active/status flags: active is the primary display
 * gate (false = listing is inactive/closed and should not appear publicly);
 * status is a string label ('approved', 'pending', etc.) used by imports to
 * carry over upstream state.
 *
 * category holds a single plain-text category string for display; categoriesJson
 * is a JSON-encoded array of category names stored by import sources that
 * support multi-category joined listings (may be null for manually added entries).
 *
 * linkStatus and linkRedirectUrl are set by the Link Checker feature.
 *
 * description and comments are private admin-only notes (never shown on the
 * public joined-listings embed), preserved from Enthusiast's joined-listing
 * `desc` and `comments` fields on import.
 */
final class JoinedListing
{
    public function __construct(
        public readonly int     $id,
        /** Subject or name of the fanlisting (e.g. "Archaeology", "Marilyn Monroe"). */
        public readonly string  $name,
        public readonly string  $url,
        /** Filename of the listing's button/image, or null if none was saved. */
        public readonly ?string $image,
        /** Primary category label for display purposes. */
        public readonly string  $category,
        /** JSON-encoded array of category names from import sources; null for manually added entries. */
        public readonly ?string $categoriesJson,
        /** Date the site owner joined this listing, or null if not recorded. */
        public readonly ?string $dateJoined,
        /** False when the listing is closed/inactive and should be hidden from the public page. */
        public readonly bool    $active,
        /** Upstream status string carried over from imports (e.g. 'approved', 'pending'). */
        public readonly string  $status,
        public readonly string  $createdAt,
        /** Link checker result for the listing URL: 'ok', 'dead', 'moved', 'error', or 'unchecked'. */
        public readonly string  $linkStatus,
        /** Timestamp of the last link check, or null if never checked. */
        public readonly ?string $linkCheckedAt,
        /** Final redirect destination detected by the link checker; null if no redirect was followed. */
        public readonly ?string $linkRedirectUrl,
        /** Free-text "about this listing" note, preserved from Enthusiast's joined-listing desc field. Admin-only, not shown publicly. */
        public readonly ?string $description,
        /** Free-text admin note, preserved from Enthusiast's joined-listing comments field. Admin-only, not shown publicly. */
        public readonly ?string $comments,
    ) {}

    /**
     * Hydrates a JoinedListing from a PDO FETCH_ASSOC row.
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id:              (int)    $row['id'],
            name:            (string) $row['name'],
            url:             (string) $row['url'],
            image:           isset($row['image'])           && $row['image']           !== null ? (string) $row['image']           : null,
            category:        (string) ($row['category']     ?? ''),
            categoriesJson:  isset($row['categories_json']) && $row['categories_json'] !== null ? (string) $row['categories_json'] : null,
            dateJoined:      isset($row['date_joined'])     && $row['date_joined']     !== null ? (string) $row['date_joined']     : null,
            active:          (bool)   ($row['active']       ?? true),
            status:          (string) ($row['status']       ?? 'approved'),
            createdAt:       (string) ($row['created_at']   ?? ''),
            linkStatus:      (string) ($row['link_status']       ?? 'unchecked'),
            linkCheckedAt:   isset($row['link_checked_at'])   && $row['link_checked_at']   !== null ? (string) $row['link_checked_at']   : null,
            linkRedirectUrl: isset($row['link_redirect_url']) && $row['link_redirect_url'] !== null ? (string) $row['link_redirect_url'] : null,
            description:     isset($row['description']) && $row['description'] !== null ? (string) $row['description'] : null,
            comments:        isset($row['comments'])    && $row['comments']    !== null ? (string) $row['comments']    : null,
        );
    }
}
