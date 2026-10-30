<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Turns a Listing Admin listing ID into a Luster listing ID for the Listing
 Admin compatibility bridge (public/compat/listing-admin/luster-bridge.php),
 using the links the Listing Admin import saves.
*/

declare(strict_types=1);

namespace Luster\Support;

use Luster\Import\ListingAdminSourceKey;
use PDO;

/**
 * Lookup used by the Listing Admin bridge.
 *
 * With no source key (the default), it uses the shared 'listing-admin' links
 * every bridge copy has always looked up. With a source key (the bridge's
 * LUSTER_LA_SOURCE setting, for sites running more than one LA install), it
 * uses only that install's own links and never falls back to the shared ones —
 * the shared link may belong to the other install, and falling back would
 * show that install's listing (or its join form) instead.
 */
final class ListingAdminBridgeResolver
{
    /**
     * @param string $sourceKey '' for the shared links, or one install's source key.
     * @return int|null The Luster listing ID, or null when there's no link
     *                  (the bridge then falls back to a manually set ID).
     */
    public static function resolve(PDO $db, int $laListingId, string $sourceKey): ?int
    {
        if ($laListingId <= 0) {
            return null;
        }

        if ($sourceKey === '') {
            $source = ListingAdminSourceKey::GENERIC_SOURCE;
        } elseif (ListingAdminSourceKey::isValid($sourceKey)) {
            $source = ListingAdminSourceKey::refSource($sourceKey);
        } else {
            return null;
        }

        $stmt = $db->prepare(
            'SELECT listing_id FROM lstr_listing_external_refs
              WHERE source = :src AND external_id = :eid LIMIT 1'
        );
        $stmt->execute(['src' => $source, 'eid' => (string) $laListingId]);
        $listingId = $stmt->fetchColumn();

        return $listingId === false ? null : (int) $listingId;
    }
}
