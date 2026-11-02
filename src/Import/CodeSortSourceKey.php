<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Works out which CodeSort install an import came from, so codes from two
 different CodeSort installs never get mixed up, and a re-run finds the codes
 it imported before.
*/

declare(strict_types=1);

namespace Luster\Import;

use PDO;
use RuntimeException;

/**
 * Source identity for CodeSort imports.
 *
 * Every imported code is saved with import_ref = 'codesort:<key>:<code_id>'. The
 * key is a Source ID the admin typed, or worked out from the install's own
 * images_url (its codes_options table) when that's a full web address. A folder
 * (images_folder, or a relative images_url) never makes a key: the same folder
 * name turns up on unrelated installs. The table prefix isn't part of the key
 * either — CodeSort has no admin-set prefix, and an uploaded dump's temporary
 * prefix changes on every upload.
 *
 * Source IDs follow the Listing Admin rule (ListingAdminSourceKey::isValid()).
 */
final class CodeSortSourceKey
{
    /** Start of every CodeSort code's import_ref. */
    public const REF_SOURCE = 'codesort';

    /**
     * Picks the source key for an import.
     *
     * @param  string $imagesUrl images_url from the install's own codes_options, never
     *                           the address typed into the wizard (that can change from
     *                           run to run).
     * @return array{key: ?string, origin: 'override'|'images_url'|'none'}
     * @throws RuntimeException When a typed-in Source ID isn't valid.
     */
    public static function resolve(string $override, string $imagesUrl): array
    {
        if ($override !== '') {
            if (!ListingAdminSourceKey::isValid($override)) {
                throw new RuntimeException(ListingAdminSourceKey::INVALID_ID_MESSAGE);
            }
            return ['key' => $override, 'origin' => 'override'];
        }

        $imagesUrl = trim($imagesUrl);
        $host      = parse_url($imagesUrl, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            // normalizeUrl() reads '//host/path' the same as http(s)://host/path.
            return [
                'key'    => substr(sha1(ListingAdminSourceKey::normalizeUrl($imagesUrl)), 0, 12),
                'origin' => 'images_url',
            ];
        }

        return ['key' => null, 'origin' => 'none'];
    }

    /** The import_ref prefix every code from this install shares ('codesort:<key>:'). */
    public static function refPrefix(string $key): string
    {
        return self::REF_SOURCE . ':' . $key . ':';
    }

    /** One code's import_ref — at most 9 + 36 + 1 + 10 = 56 characters (column: 100). */
    public static function importRef(string $key, int $codeId): string
    {
        return self::refPrefix($key) . $codeId;
    }

    /**
     * Source keys already used by earlier CodeSort imports, with how many codes each
     * has — shown on the Options step so an admin can reuse an old key. Empty when
     * lstr_codes has no import_ref column yet (before Migration 104).
     *
     * @return list<array{key: string, codes: int}>
     */
    public static function knownSources(PDO $target): array
    {
        $prefix  = self::REF_SOURCE . ':';
        $sources = [];

        try {
            $stmt = $target->prepare('SELECT import_ref FROM lstr_codes WHERE import_ref LIKE :pattern');
            $stmt->execute(['pattern' => $prefix . '%']);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $ref) {
                $key = explode(':', substr((string) $ref, strlen($prefix)), 2)[0];
                if (ListingAdminSourceKey::isValid($key)) {
                    $sources[$key] ??= ['key' => $key, 'codes' => 0];
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
