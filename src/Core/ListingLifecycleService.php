<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Archives and reopens listings into the Closed / Adopted Out lifecycle
 states. Never widens the meaning of the legacy upcoming/trouble columns:
 archiving snapshots the listing's current upcoming/trouble into
 lstr_listing_lifecycle, then sets the legacy columns to their existing
 inactive representation (the same one "Pending" already uses) so older
 Enthusiast/Listing Admin compat includes that read those columns directly
 keep working correctly. Reopening restores the exact snapshot and removes
 the lifecycle row.
*/

declare(strict_types=1);

namespace Luster\Core;

use Luster\Repositories\ListingLifecycleRepo;
use Luster\Repositories\ListingRepo;

/**
 * Archives/reopens listings between the normal current/upcoming/pending
 * states and the Closed/Adopted Out lifecycle states.
 */
final class ListingLifecycleService
{
    public const STATUS_CLOSED       = 'closed';
    public const STATUS_ADOPTED_OUT  = 'adopted_out';

    private const ALLOWED_STATUSES = [self::STATUS_CLOSED, self::STATUS_ADOPTED_OUT];

    public function __construct(
        private readonly ListingRepo          $listingRepo,
        private readonly ListingLifecycleRepo $lifecycleRepo,
    ) {}

    /** True for any status string this service recognizes as an archived state. */
    public static function isLifecycleStatus(string $status): bool
    {
        return in_array($status, self::ALLOWED_STATUSES, true);
    }

    /**
     * Archives $listingId as Closed or Adopted Out. Snapshots the listing's
     * current upcoming/trouble booleans into the lifecycle row, then writes
     * the legacy inactive representation (upcoming=false, trouble=true) back
     * to the registry. Returns false when the listing doesn't exist or
     * $status isn't a recognized lifecycle status.
     */
    public function archive(
        int     $listingId,
        string  $status,
        ?string $adoptedToName = null,
        ?string $adoptedToUrl = null,
        ?string $archiveNote = null,
    ): bool {
        if (!self::isLifecycleStatus($status)) {
            return false;
        }

        $listing = $this->listingRepo->getById($listingId);
        if ($listing === null) {
            return false;
        }

        $this->lifecycleRepo->upsert(
            $listingId,
            $status,
            $listing->upcoming,
            $listing->trouble,
            $status === self::STATUS_ADOPTED_OUT ? $adoptedToName : null,
            $status === self::STATUS_ADOPTED_OUT ? $adoptedToUrl  : null,
            $archiveNote,
        );

        $this->listingRepo->updateMeta(
            $listingId,
            $listing->title,
            $listing->url,
            $listing->email,
            upcoming: false,
            trouble: true,
        );

        return true;
    }

    /**
     * Reopens $listingId: restores its pre-archive upcoming/trouble snapshot
     * to the registry and removes the lifecycle row. Returns false when the
     * listing isn't currently archived or doesn't exist.
     */
    public function reopen(int $listingId): bool
    {
        $snapshot = $this->lifecycleRepo->getByListingId($listingId);
        if ($snapshot === null) {
            return false;
        }

        $listing = $this->listingRepo->getById($listingId);
        if ($listing === null) {
            return false;
        }

        $this->listingRepo->updateMeta(
            $listingId,
            $listing->title,
            $listing->url,
            $listing->email,
            $snapshot['legacy_upcoming'],
            $snapshot['legacy_trouble'],
        );

        $this->lifecycleRepo->delete($listingId);

        return true;
    }
}
