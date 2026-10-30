<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Works out which Listing Admin install an import came from, so data from two
 different LA installs never gets mixed up. Shared by the LA importer (which
 saves listing links under this ID) and the LA compatibility bridge (which
 looks them up again).
*/

declare(strict_types=1);

namespace Luster\Import;

use PDO;
use RuntimeException;

/**
 * Source identity for Listing Admin imports.
 *
 * Every LA install gets a short "source key". It's either worked out from the
 * install's own web address (LA's adm_http option, falling back to my_website)
 * plus its table prefix, or typed in by the admin as a "Source ID" — useful
 * after a domain move, when the worked-out key would otherwise change.
 *
 * Listing links for the bridge are saved in lstr_listing_external_refs under
 * two source values: the shared 'listing-admin' one every bridge copy looks up
 * by default, and 'listing-admin:<key>' for this install only.
 */
final class ListingAdminSourceKey
{
    /** The shared source value existing bridge copies look up. */
    public const GENERIC_SOURCE = 'listing-admin';

    /**
     * Source IDs are lowercase letters, numbers, and hyphens. 36 characters
     * max so 'listing-admin:' (14) + the ID fits the 50-character
     * lstr_listing_external_refs.source column.
     */
    private const VALID_PATTERN = '/^[a-z0-9-]{1,36}$/';

    /** Whether $key is a usable source key (worked-out keys always are). */
    public static function isValid(string $key): bool
    {
        return preg_match(self::VALID_PATTERN, $key) === 1;
    }

    /** The lstr_listing_external_refs.source value for one install's own links. */
    public static function refSource(string $key): string
    {
        return self::GENERIC_SOURCE . ':' . $key;
    }

    /**
     * Picks the source key for an import.
     *
     * A typed-in Source ID wins. Otherwise the key comes from adm_http, then
     * my_website. Returns key null (origin 'none') when there's nothing to
     * work it out from — the caller decides what that means.
     *
     * @param  string $laTablePrefix The real LA prefix the admin typed (e.g. 'trex_'),
     *                               never the temporary prefix used for uploaded dumps,
     *                               so a live connection and a dump of the same install
     *                               get the same key.
     * @return array{key: ?string, origin: 'override'|'adm_http'|'my_website'|'none'}
     * @throws RuntimeException When a typed-in Source ID isn't valid.
     */
    public static function resolve(string $override, string $admHttp, string $myWebsite, string $laTablePrefix): array
    {
        if ($override !== '') {
            if (!self::isValid($override)) {
                throw new RuntimeException(
                    'The Source ID can only use lowercase letters, numbers, and hyphens, '
                    . 'up to 36 characters (for example: old-collective).'
                );
            }
            return ['key' => $override, 'origin' => 'override'];
        }

        foreach (['adm_http' => $admHttp, 'my_website' => $myWebsite] as $origin => $url) {
            $normalized = self::normalizeUrl($url);
            if ($normalized !== '') {
                return [
                    'key'    => substr(sha1($normalized . '|' . $laTablePrefix), 0, 12),
                    'origin' => $origin,
                ];
            }
        }

        return ['key' => null, 'origin' => 'none'];
    }

    /**
     * Normalizes an install URL so small differences don't change the key:
     * drops the scheme (http/https), lowercases the host only (folder names
     * keep their case, since /Admin/ and /admin/ can be different folders),
     * and trims trailing slashes.
     */
    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        $url = (string) preg_replace('~^[a-z][a-z0-9+.\-]*://~i', '', $url);

        $slash = strpos($url, '/');
        $host  = $slash === false ? $url : substr($url, 0, $slash);
        $path  = $slash === false ? ''   : substr($url, $slash);

        return rtrim(strtolower($host) . $path, '/');
    }

    /**
     * Source keys already used by earlier LA imports, with how many listing
     * links and codes each has — shown on the Options step so an admin can
     * reuse an old key after a domain move. Each table is read separately and
     * skipped if it isn't there (lstr_codes.import_ref only exists from
     * Migration 104 on).
     *
     * @return list<array{key: string, listings: int, codes: int}>
     */
    public static function knownSources(PDO $target): array
    {
        $prefix  = self::GENERIC_SOURCE . ':';
        $sources = [];

        try {
            $stmt = $target->prepare(
                'SELECT source, COUNT(*) AS listings
                   FROM lstr_listing_external_refs
                  WHERE source LIKE :pattern
                  GROUP BY source'
            );
            $stmt->execute(['pattern' => $prefix . '%']);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = substr((string) $row['source'], strlen($prefix));
                if (self::isValid($key)) {
                    $sources[$key] = ['key' => $key, 'listings' => (int) $row['listings'], 'codes' => 0];
                }
            }
        } catch (\Throwable) {
            // No listing links table yet.
        }

        try {
            // import_ref is "listing-admin:<key>:<cID>".
            $stmt = $target->prepare('SELECT import_ref FROM lstr_codes WHERE import_ref LIKE :pattern');
            $stmt->execute(['pattern' => $prefix . '%']);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $ref) {
                $key = explode(':', substr((string) $ref, strlen($prefix)), 2)[0];
                if (self::isValid($key)) {
                    $sources[$key] ??= ['key' => $key, 'listings' => 0, 'codes' => 0];
                    $sources[$key]['codes']++;
                }
            }
        } catch (\Throwable) {
            // lstr_codes has no import_ref column yet.
        }

        ksort($sources);
        return array_values($sources);
    }
}
