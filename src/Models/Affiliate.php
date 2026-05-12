<?php

declare(strict_types=1);

namespace Luster\Models;

/**
 * Represents a single affiliate row from lstr_affiliates.
 *
 * email and image are nullable — they may not be provided for every affiliate.
 */
final class Affiliate
{
    public function __construct(
        public readonly int     $id,
        public readonly ?int    $listingId,
        public readonly string  $name,
        public readonly string  $url,
        public readonly ?string $email,
        public readonly ?string $image,
        public readonly int     $sortOrder,
        public readonly bool    $active,
        public readonly string  $createdAt,
        public readonly string  $updatedAt,
        public readonly string  $linkStatus,
        public readonly ?string $linkCheckedAt,
        public readonly ?string $linkRedirectUrl,
        public readonly string  $reciprocalStatus,
        public readonly ?string $reciprocalCheckedAt,
        public readonly bool    $pending,
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
