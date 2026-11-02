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

    /** Shown when a typed Source ID breaks the rule above (CodeSort's Source ID uses it too). */
    public const INVALID_ID_MESSAGE = 'The Source ID can only use lowercase letters, numbers, and hyphens, '
        . 'up to 36 characters (for example: old-collective).';

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
     * Picks the source key for a brand-new install, without looking at what
     * earlier imports saved. The importer uses resolveFor() instead whenever
     * the key is actually used (listings and codes).
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
            return self::typed($override);
        }

        foreach (['adm_http' => $admHttp, 'my_website' => $myWebsite] as $origin => $url) {
            $normalized = self::normalizeUrl($url);
            if ($normalized !== '') {
                return ['key' => self::keyFor($normalized, $laTablePrefix), 'origin' => $origin];
            }
        }

        return ['key' => null, 'origin' => 'none'];
    }

    /**
     * The source key an import should use, given what earlier imports of this
     * install saved in Luster's database.
     *
     * Up to 1.0.0-3, a protocol-relative address ('//Example.COM/admin') got a
     * different key from its http(s):// form. So an install imported back then
     * may have its links and codes under one of up to three keys: today's
     * key, the key its current address got before, or the key the lowercase
     * '//' form of its address got before. Whichever of those earlier imports
     * used is kept, so bridge pages with LUSTER_LA_SOURCE set keep working and
     * codes aren't added again. Stored rows are never re-keyed.
     *
     * No database read for a typed Source ID, no address, or an address with
     * only one possible key.
     *
     * @param  PDO    $target        Luster's database.
     * @param  string $laTablePrefix See resolve().
     * @return array{key: ?string, origin: 'override'|'adm_http'|'my_website'|'none'}
     * @throws RuntimeException When a typed-in Source ID isn't valid; when more than one
     *                          of this install's keys is already in use (only the admin
     *                          can say which one their bridge pages use); or when the
     *                          database couldn't be read to find out.
     */
    public static function resolveFor(PDO $target, string $override, string $admHttp, string $myWebsite, string $laTablePrefix): array
    {
        if ($override !== '') {
            return self::typed($override);
        }

        foreach (['adm_http' => $admHttp, 'my_website' => $myWebsite] as $origin => $url) {
            if (self::normalizeUrl($url) === '') {
                continue;
            }

            $candidates = self::candidates($url, $laTablePrefix);
            if (count($candidates) === 1) {
                return ['key' => $candidates[0], 'origin' => $origin];
            }

            try {
                $inUse = self::usage($target, $candidates);
            } catch (\Throwable $e) {
                throw new RuntimeException(
                    "Luster couldn't check which Source ID earlier imports of this install used ("
                    . $e->getMessage() . '). Try again, or enter the Source ID on the Options step.',
                    0,
                    $e
                );
            }

            if (count($inUse) > 1) {
                throw new RuntimeException(self::severalKeysMessage($inUse));
            }
            // (string): PHP turns an all-digit array key like '123456789012' into an int.
            return ['key' => $inUse === [] ? $candidates[0] : (string) array_key_first($inUse), 'origin' => $origin];
        }

        return ['key' => null, 'origin' => 'none'];
    }

    /**
     * Normalizes an install URL so small differences don't change the key:
     * drops the scheme (http/https) or a leading '//' (protocol-relative),
     * lowercases the host only (folder names keep their case, since /Admin/
     * and /admin/ can be different folders), and trims trailing slashes.
     */
    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        // '///x' has no host, so it keeps all its slashes, as it always has.
        $url = (string) preg_replace('~^//(?=[^/])~', '', $url);
        $url = (string) preg_replace('~^[a-z][a-z0-9+.\-]*://~i', '', $url);

        $slash = strpos($url, '/');
        $host  = $slash === false ? $url : substr($url, 0, $slash);
        $path  = $slash === false ? ''   : substr($url, $slash);

        return rtrim(strtolower($host) . $path, '/');
    }

    /**
     * normalizeUrl() as it was before it understood '//' addresses (1.0.0-3),
     * kept unchanged so resolveFor() can recompute the keys earlier imports
     * saved. Never use it for a new key.
     */
    private static function legacyNormalizeUrl(string $url): string
    {
        $url = trim($url);
        $url = (string) preg_replace('~^[a-z][a-z0-9+.\-]*://~i', '', $url);

        $slash = strpos($url, '/');
        $host  = $slash === false ? $url : substr($url, 0, $slash);
        $path  = $slash === false ? ''   : substr($url, $slash);

        return rtrim(strtolower($host) . $path, '/');
    }

    /** @return array{key: string, origin: 'override'} */
    private static function typed(string $override): array
    {
        if (!self::isValid($override)) {
            throw new RuntimeException(self::INVALID_ID_MESSAGE);
        }
        return ['key' => $override, 'origin' => 'override'];
    }

    private static function keyFor(string $normalized, string $laTablePrefix): string
    {
        return substr(sha1($normalized . '|' . $laTablePrefix), 0, 12);
    }

    /**
     * This install's possible keys, today's first, without repeats: today's key;
     * the key the same address got before; and, only when the address has a real
     * host, the key its lowercase '//' form got before. Without a host, '//' plus
     * the address would be a different install's address ('fan/admin' would
     * become host 'fan').
     *
     * @return list<string>
     */
    private static function candidates(string $url, string $laTablePrefix): array
    {
        $forms = [self::normalizeUrl($url), self::legacyNormalizeUrl($url)];

        $host = parse_url(trim($url), PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            $forms[] = '//' . self::normalizeUrl($url);
        }

        $keys = array_map(static fn(string $form): string => self::keyFor($form, $laTablePrefix), $forms);
        return array_values(array_unique($keys));
    }

    /**
     * How many listing links and codes each key already has. A table or column
     * that isn't there counts as none; any other failure throws, so an identity
     * is never picked from a partial read. (knownSources() is lenient on purpose,
     * which is why it isn't used here.)
     *
     * @param  list<string> $keys Worked-out keys only: hex, so no LIKE wildcards.
     * @return array<string, array{listings: int, codes: int}> keys with at least one
     *                                                          of either, in $keys order.
     */
    private static function usage(PDO $target, array $keys): array
    {
        $counts = array_fill_keys($keys, ['listings' => 0, 'codes' => 0]);

        if (self::columnsOf($target, 'lstr_listing_external_refs') !== []) {
            $marks = implode(', ', array_fill(0, count($keys), '?'));
            $rows  = self::query(
                $target,
                "SELECT source, COUNT(*) FROM lstr_listing_external_refs WHERE source IN ({$marks}) GROUP BY source",
                array_map(self::refSource(...), $keys)
            )->fetchAll(PDO::FETCH_NUM);
            foreach ($rows as [$source, $count]) {
                $key = substr((string) $source, strlen(self::GENERIC_SOURCE) + 1);
                if (isset($counts[$key])) {
                    $counts[$key]['listings'] = (int) $count;
                }
            }
        }

        // import_ref arrived with Migration 104.
        if (in_array('import_ref', self::columnsOf($target, 'lstr_codes'), true)) {
            foreach ($keys as $key) {
                $counts[$key]['codes'] = (int) self::query(
                    $target,
                    'SELECT COUNT(*) FROM lstr_codes WHERE import_ref LIKE ?',
                    [self::refSource($key) . ':%']
                )->fetchColumn();
            }
        }

        return array_filter($counts, static fn(array $c): bool => $c['listings'] > 0 || $c['codes'] > 0);
    }

    /**
     * A table's column names, lowercased; [] when the table isn't there (every
     * table has at least one column). Errors are not caught.
     *
     * @return list<string>
     */
    private static function columnsOf(PDO $target, string $table): array
    {
        $columns = $target->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? array_column(self::query($target, "PRAGMA table_info(`{$table}`)")->fetchAll(PDO::FETCH_ASSOC), 'name')
            : self::query(
                $target,
                'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$table]
            )->fetchAll(PDO::FETCH_COLUMN);

        return array_map(static fn($name): string => strtolower((string) $name), $columns);
    }

    /**
     * Runs a read and fails loudly, whatever the connection's error mode.
     *
     * @param list<string> $params
     */
    private static function query(PDO $target, string $sql, array $params = []): \PDOStatement
    {
        $stmt = $target->prepare($sql);
        if ($stmt === false || !$stmt->execute($params)) {
            throw new RuntimeException('The database read failed.');
        }
        return $stmt;
    }

    /** @param array<string, array{listings: int, codes: int}> $inUse */
    private static function severalKeysMessage(array $inUse): string
    {
        $count = static fn(int $n, string $one, string $many): string
            => $n === 0 ? "no {$many}" : ($n === 1 ? "1 {$one}" : "{$n} {$many}");

        $named = [];
        foreach ($inUse as $key => $c) {
            $named[] = "{$key} (" . $count($c['listings'], 'listing link', 'listing links')
                . ', ' . $count($c['codes'], 'code', 'codes') . ')';
        }
        $last = array_pop($named);

        return 'Earlier imports saved this Listing Admin install under more than one Source ID: '
            . implode(', ', $named) . " and {$last}. Luster can't tell which one your bridge pages and "
            . 'codes should use, so the import stops here. Enter one of them as the Source ID on the '
            . "Options step and run the import again: the one in this install's luster-bridge.php "
            . '(LUSTER_LA_SOURCE), if you set it there, otherwise the one with the codes you want to keep.';
    }

    /**
     * Source keys already used by earlier LA imports, with how many listing
     * links and codes each has — shown on the Options step so an admin can
     * reuse an old key after a domain move. Each table is read separately and
     * skipped if it isn't there (lstr_codes.import_ref only exists from
     * Migration 104 on).
     *
     * Only for showing the list: a failed read is skipped, so this never
     * decides which key an import uses (resolveFor() reads strictly).
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
