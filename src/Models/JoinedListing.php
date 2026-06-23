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
 * Represents a single row from lstr_joined.
 *
 * Tracks listings the site owner has joined (as opposed to running).
 */
final class JoinedListing
{
    public function __construct(
        public readonly int     $id,
        public readonly string  $name,
        public readonly string  $url,
        public readonly ?string $image,
        public readonly string  $category,
        public readonly ?string $categoriesJson,
        public readonly ?string $dateJoined,
        public readonly bool    $active,
        public readonly string  $status,
        public readonly string  $createdAt,
        public readonly string  $linkStatus,
        public readonly ?string $linkCheckedAt,
        public readonly ?string $linkRedirectUrl,
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
        );
    }
}
