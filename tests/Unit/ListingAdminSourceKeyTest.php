<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Import\ListingAdminSourceKey;
use Luster\Tests\Unit\Support\TracingPdo;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * Source identity for Listing Admin imports: how an LA install's key is worked
 * out, what a typed-in Source ID may contain, and the list of earlier keys.
 */
final class ListingAdminSourceKeyTest extends TestCase
{
    // Keys 1.0.0-3 gave one install at example.com/fan/admin with table prefix 'trex_',
    // worked out with that release's code (git show e3da9ea:src/Import/ListingAdminSourceKey.php),
    // never with the code under test.
    /** https://, http:// or no scheme — also today's key for every form of the address. */
    private const C = 'abc3015cbf09';
    /** //Example.COM/fan/admin */
    private const L = '91083d439349';
    /** //example.com/fan/admin */
    private const D = 'ab4574387c15';

    public function testKeyIsWorkedOutFromAdminAddressFirst(): void
    {
        $result = ListingAdminSourceKey::resolve('', 'http://example.com/fan/admin/', 'http://example.com/fan', 'trex_');

        self::assertSame('adm_http', $result['origin']);
        self::assertSame(substr(sha1('example.com/fan/admin|trex_'), 0, 12), $result['key']);
        self::assertTrue(ListingAdminSourceKey::isValid((string) $result['key']));
    }

    public function testFallsBackToWebsiteAddress(): void
    {
        $result = ListingAdminSourceKey::resolve('', '  ', 'https://example.com/fan', '');

        self::assertSame('my_website', $result['origin']);
        self::assertSame(substr(sha1('example.com/fan|'), 0, 12), $result['key']);
    }

    public function testNoAddressMeansNoKey(): void
    {
        self::assertSame(['key' => null, 'origin' => 'none'], ListingAdminSourceKey::resolve('', '', '', 'trex_'));
    }

    public function testSchemeTrailingSlashesAndHostCaseDontChangeTheKey(): void
    {
        $a = ListingAdminSourceKey::resolve('', 'http://Example.COM/fan/admin', '', '');
        $b = ListingAdminSourceKey::resolve('', 'https://example.com/fan/admin///', '', '');

        self::assertSame($a['key'], $b['key']);
    }

    public function testFolderCaseDoesChangeTheKey(): void
    {
        $a = ListingAdminSourceKey::resolve('', 'http://example.com/Admin', '', '');
        $b = ListingAdminSourceKey::resolve('', 'http://example.com/admin', '', '');

        self::assertNotSame($a['key'], $b['key']);
    }

    public function testTablePrefixIsPartOfTheKey(): void
    {
        $a = ListingAdminSourceKey::resolve('', 'http://example.com/admin', '', 'one_');
        $b = ListingAdminSourceKey::resolve('', 'http://example.com/admin', '', 'two_');

        self::assertNotSame($a['key'], $b['key']);
    }

    public function testNormalizeUrl(): void
    {
        self::assertSame('example.com/Fan/Admin', ListingAdminSourceKey::normalizeUrl(' HTTP://EXAMPLE.com/Fan/Admin/ '));
        self::assertSame('example.com', ListingAdminSourceKey::normalizeUrl('https://Example.com/'));
        self::assertSame('example.com/a', ListingAdminSourceKey::normalizeUrl('example.com/a'));
        self::assertSame('', ListingAdminSourceKey::normalizeUrl('   '));
    }

    public function testTypedSourceIdWinsOverTheAddress(): void
    {
        $result = ListingAdminSourceKey::resolve('old-collective', 'http://example.com/admin', '', '');

        self::assertSame(['key' => 'old-collective', 'origin' => 'override'], $result);
    }

    public function testSourceIdOf36CharactersIsAccepted(): void
    {
        $id = str_repeat('a', 36);

        self::assertSame($id, ListingAdminSourceKey::resolve($id, '', '', '')['key']);
        // 'listing-admin:' + 36 must still fit lstr_listing_external_refs.source VARCHAR(50).
        self::assertSame(50, strlen(ListingAdminSourceKey::refSource($id)));
    }

    /** @return array<string, array{string}> */
    public static function invalidSourceIds(): array
    {
        return [
            '37 characters' => [str_repeat('a', 37)],
            'uppercase'     => ['Old-Collective'],
            'underscore'    => ['old_collective'],
            'colon'         => ['old:collective'],
            'space'         => ['old collective'],
        ];
    }

    #[DataProvider('invalidSourceIds')]
    public function testInvalidSourceIdIsRejected(string $id): void
    {
        self::assertFalse(ListingAdminSourceKey::isValid($id));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Source ID');
        ListingAdminSourceKey::resolve($id, 'http://example.com/admin', '', '');
    }

    public function testKnownSourcesComeFromListingLinksOnly(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE lstr_listing_external_refs (
                id INTEGER PRIMARY KEY AUTOINCREMENT, listing_id INTEGER NOT NULL,
                source TEXT NOT NULL, external_id TEXT NOT NULL,
                external_slug_or_url TEXT NOT NULL DEFAULT \'\', UNIQUE (source, external_id)
            )'
        );
        // lstr_codes as it is before the codes-import columns exist.
        $pdo->exec('CREATE TABLE lstr_codes (id INTEGER PRIMARY KEY, fanlisting_id INTEGER NULL, filename TEXT NOT NULL)');

        $insert = $pdo->prepare('INSERT INTO lstr_listing_external_refs (listing_id, source, external_id) VALUES (?, ?, ?)');
        $insert->execute([1, 'listing-admin', '1']);
        $insert->execute([1, 'listing-admin:old-collective', '1']);
        $insert->execute([2, 'listing-admin:old-collective', '2']);
        $insert->execute([3, 'listing-admin:3f9a1c0b7d2e', '9']);
        $insert->execute([4, 'codesort', '4']);
        $insert->execute([5, 'listing-admin:BAD_KEY', '5']);

        self::assertSame(
            [
                ['key' => '3f9a1c0b7d2e', 'listings' => 1, 'codes' => 0],
                ['key' => 'old-collective', 'listings' => 2, 'codes' => 0],
            ],
            ListingAdminSourceKey::knownSources($pdo),
        );
    }

    public function testKnownSourcesIsEmptyWhenTheTableIsMissing(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        self::assertSame([], ListingAdminSourceKey::knownSources($pdo));
    }

    // -----------------------------------------------------------------------
    // Protocol-relative ('//host/path') addresses, without moving shipped keys
    // -----------------------------------------------------------------------

    /**
     * Luster's tables as resolveFor() reads them. $used maps a key to how many
     * listing links and codes it has. A shared link, a manual code and a CodeSort
     * code with a lookalike key are always there too, and must never count.
     *
     * @param array<string, array{int, int}> $used
     * @param 'import_ref'|'old'|'none' $codes lstr_codes with import_ref, without it
     *                                         (before Migration 104), or no table.
     */
    private static function target(array $used = [], bool $refs = true, string $codes = 'import_ref'): TracingPdo
    {
        $pdo = TracingPdo::memory();
        if ($refs) {
            $pdo->exec(
                "CREATE TABLE lstr_listing_external_refs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT, listing_id INTEGER NOT NULL,
                    source TEXT NOT NULL, external_id TEXT NOT NULL,
                    external_slug_or_url TEXT NOT NULL DEFAULT '', UNIQUE (source, external_id)
                )"
            );
            $pdo->exec("INSERT INTO lstr_listing_external_refs (listing_id, source, external_id) VALUES (1, 'listing-admin', '1')");
        }
        if ($codes !== 'none') {
            $pdo->exec('CREATE TABLE lstr_codes (id INTEGER PRIMARY KEY AUTOINCREMENT, filename TEXT NOT NULL'
                . ($codes === 'import_ref' ? ', import_ref TEXT NULL UNIQUE' : '') . ')');
        }
        if ($codes === 'import_ref') {
            $pdo->exec("INSERT INTO lstr_codes (filename, import_ref) VALUES ('manual.png', NULL), ('cs.png', 'codesort:" . self::C . ":1')");
        }

        foreach ($used as $key => [$links, $codeCount]) {
            for ($i = 1; $i <= $links; $i++) {
                $pdo->prepare('INSERT INTO lstr_listing_external_refs (listing_id, source, external_id) VALUES (?, ?, ?)')
                    ->execute([$i, ListingAdminSourceKey::refSource((string) $key), (string) $i]);
            }
            for ($i = 1; $i <= $codeCount; $i++) {
                $pdo->prepare("INSERT INTO lstr_codes (filename, import_ref) VALUES ('c.png', ?)")
                    ->execute([ListingAdminSourceKey::refSource((string) $key) . ':' . $i]);
            }
        }

        $pdo->statements = [];
        return $pdo;
    }

    private static function keyFor(PDO $target, string $admHttp, string $prefix = 'trex_', string $typed = ''): ?string
    {
        return ListingAdminSourceKey::resolveFor($target, $typed, $admHttp, '', $prefix)['key'];
    }

    /** @return array<string, array{string, string, string, string}> */
    public static function keysFromBeforeThisRelease(): array
    {
        return [
            'http, trailing slash'        => ['http://example.com/fan/admin/', '', 'trex_', 'abc3015cbf09'],
            'HTTPS, capitals, slashes'    => ['HTTPS://Example.COM/Fan/Admin//', '', 'trex_', 'f59a1424ac81'],
            'no scheme'                   => ['example.com/fan/admin', '', 'trex_', 'abc3015cbf09'],
            'relative'                    => ['/fan/admin', '', 'trex_', 'e75224daf049'],
            'folder only'                 => ['fan/admin', '', 'trex_', 'e99b492a35cb'],
            'three slashes'               => ['///example.com/fan/admin', '', 'trex_', 'c8cd7ba8d192'],
            'website address fallback'    => ['', 'https://example.com/fan', 'la_', 'd10f1472ece6'],
            'prefix one_'                 => ['http://example.com/fan/admin', '', 'one_', '078b70cb0a1a'],
            'prefix two_'                 => ['http://example.com/fan/admin', '', 'two_', '1b088c9e10f1'],
        ];
    }

    #[DataProvider('keysFromBeforeThisRelease')]
    public function testKeysFromBeforeThisReleaseAreUnchanged(string $admHttp, string $myWebsite, string $prefix, string $key): void
    {
        self::assertSame($key, ListingAdminSourceKey::resolve('', $admHttp, $myWebsite, $prefix)['key']);
        self::assertSame($key, ListingAdminSourceKey::resolveFor(self::target(), '', $admHttp, $myWebsite, $prefix)['key']);
    }

    /** @return array<string, array{string}> */
    public static function addressesNotStartingWithDoubleSlash(): array
    {
        return array_map(static fn(string $url): array => [$url], [
            'scheme, capitals'  => ' HTTP://EXAMPLE.com/Fan/Admin/ ',
            'scheme, root'      => 'https://Example.com/',
            'other scheme'      => 'ftp://Host.example/Path',
            'no scheme'         => 'Example.com/a',
            'host only'         => 'Example.COM',
            'relative'          => '/fan/admin',
            'folder only'       => 'fan/admin',
            'three slashes'     => '///Example.com/x',
            'two slashes only'  => '//',
            'one slash'         => '/',
            'blank'             => '   ',
            'empty'             => '',
        ]);
    }

    #[DataProvider('addressesNotStartingWithDoubleSlash')]
    public function testNormalizeUrlOnlyChangesProtocolRelativeAddresses(string $url): void
    {
        $legacy = new ReflectionMethod(ListingAdminSourceKey::class, 'legacyNormalizeUrl');

        self::assertSame($legacy->invoke(null, $url), ListingAdminSourceKey::normalizeUrl($url));
    }

    public function testProtocolRelativeAddressesNormalizeLikeTheirFullForm(): void
    {
        self::assertSame('example.com/fan/admin', ListingAdminSourceKey::normalizeUrl('//Example.COM/fan/admin/'));
        self::assertSame('example.com/Fan/Admin', ListingAdminSourceKey::normalizeUrl('  //example.com/Fan/Admin//  '));
        self::assertSame('example.com', ListingAdminSourceKey::normalizeUrl('//EXAMPLE.com'));
        self::assertSame('///example.com/x', ListingAdminSourceKey::normalizeUrl('///example.com/x'), 'No host, so the slashes stay');
    }

    public function testProtocolRelativeAndFullAddressGiveOneNewKey(): void
    {
        foreach (['//Example.COM/fan/admin', '//example.com/fan/admin/', 'https://example.com/fan/admin'] as $url) {
            self::assertSame(self::C, ListingAdminSourceKey::resolve('', $url, '', 'trex_')['key'], $url);
        }
        self::assertSame(
            ListingAdminSourceKey::resolve('', 'https://example.com/Fan/admin', '', 'trex_')['key'],
            ListingAdminSourceKey::resolve('', '//example.com/Fan/admin', '', 'trex_')['key'],
        );
        self::assertNotSame(self::C, ListingAdminSourceKey::resolve('', '//example.com/Fan/admin', '', 'trex_')['key'], 'Folder case is kept');
        self::assertNotSame(self::C, ListingAdminSourceKey::resolve('', '//example.com/fan/admin', '', 'other_')['key'], 'Prefix is still part of the key');
    }

    public function testNothingInUseGivesTodaysKey(): void
    {
        foreach (['//Example.COM/fan/admin', '//example.com/fan/admin', 'https://example.com/fan/admin'] as $url) {
            self::assertSame(self::C, self::keyFor(self::target(), $url), $url);
        }
    }

    public function testTheKeyEarlierImportsUsedIsKept(): void
    {
        // Same address as before.
        self::assertSame(self::L, self::keyFor(self::target([self::L => [2, 0]]), '//Example.COM/fan/admin'));
        self::assertSame(self::D, self::keyFor(self::target([self::D => [0, 3]]), '//example.com/fan/admin/'));
        // Imported as //example.com/..., written another way now.
        self::assertSame(self::D, self::keyFor(self::target([self::D => [1, 1]]), 'https://example.com/fan/admin'));
        self::assertSame(self::D, self::keyFor(self::target([self::D => [1, 1]]), 'HTTP://EXAMPLE.com/fan/admin/'));
        self::assertSame(self::D, self::keyFor(self::target([self::D => [1, 1]]), '//Example.COM/fan/admin'));
        // Imported as https://..., written as // now.
        self::assertSame(self::C, self::keyFor(self::target([self::C => [1, 1]]), '//Example.COM/fan/admin'));
    }

    public function testAKeyThatIsAllDigitsComesBackAsAString(): void
    {
        // 1.0.0-3's key for //Example.COM/fan/admin with prefix p236_: PHP would make it an int array key.
        $key = ListingAdminSourceKey::resolveFor(self::target(['914487175492' => [1, 0]]), '', '//Example.COM/fan/admin', '', 'p236_')['key'];

        self::assertSame('914487175492', $key);
    }

    /** @return array<string, array{array<string, array{int, int}>, string}> */
    public static function severalKeysInUse(): array
    {
        return [
            'C and L'     => [[self::C => [3, 12], self::L => [3, 0]],
                'abc3015cbf09 (3 listing links, 12 codes) and 91083d439349 (3 listing links, no codes).'],
            'C and D'     => [[self::D => [0, 1], self::C => [1, 0]],
                'abc3015cbf09 (1 listing link, no codes) and ab4574387c15 (no listing links, 1 code).'],
            'L and D'     => [[self::L => [2, 2], self::D => [1, 5]],
                '91083d439349 (2 listing links, 2 codes) and ab4574387c15 (1 listing link, 5 codes).'],
            'all three'   => [[self::C => [1, 1], self::L => [1, 1], self::D => [1, 1]],
                'abc3015cbf09 (1 listing link, 1 code), 91083d439349 (1 listing link, 1 code) and ab4574387c15 (1 listing link, 1 code).'],
        ];
    }

    /** @param array<string, array{int, int}> $used */
    #[DataProvider('severalKeysInUse')]
    public function testSeveralKeysInUseStopsAndNamesThem(array $used, string $named): void
    {
        try {
            self::keyFor(self::target($used), '//Example.COM/fan/admin');
            self::fail('Several keys in use must stop the import');
        } catch (RuntimeException $e) {
            self::assertStringStartsWith(
                'Earlier imports saved this Listing Admin install under more than one Source ID: ' . $named,
                $e->getMessage()
            );
            self::assertStringContainsString('Enter one of them as the Source ID on the Options step', $e->getMessage());
            foreach ([self::C, self::L, self::D] as $key) {
                self::assertSame(isset($used[$key]), str_contains($e->getMessage(), $key), $key);
            }
        }
    }

    public function testATypedSourceIdNeverReadsTheDatabase(): void
    {
        $target = self::target([self::C => [1, 1], self::L => [1, 1]]);
        $target->failOn = '/^/';

        self::assertSame(
            ['key' => 'old-collective', 'origin' => 'override'],
            ListingAdminSourceKey::resolveFor($target, 'old-collective', '//Example.COM/fan/admin', '', 'trex_'),
        );
        self::assertSame(self::L, self::keyFor($target, '//Example.COM/fan/admin', typed: self::L));
        self::assertSame(['key' => null, 'origin' => 'none'], ListingAdminSourceKey::resolveFor($target, '', '', '', 'trex_'));

        try {
            self::keyFor($target, '//Example.COM/fan/admin', typed: 'Old_Collective');
            self::fail('An invalid Source ID must be rejected');
        } catch (RuntimeException $e) {
            self::assertSame(ListingAdminSourceKey::INVALID_ID_MESSAGE, $e->getMessage());
        }
        self::assertSame([], $target->statements);
    }

    public function testMixedCaseProtocolRelativeAddressWrittenAnotherWayGetsTodaysKey(): void
    {
        // The known limit: the old key can't be recomputed without the old capitals.
        self::assertSame(self::C, self::keyFor(self::target([self::L => [2, 2]]), 'https://example.com/fan/admin'));
        // Typing it keeps it.
        self::assertSame(self::L, self::keyFor(self::target([self::L => [2, 2]]), 'https://example.com/fan/admin', typed: self::L));
    }

    public function testAnotherInstallsKeyIsNeverPicked(): void
    {
        $others = [
            '46b913def7f1' => [4, 4], // //Example.COM/fan/admin with prefix other_
            '16bb9d2e2aae' => [4, 4], // https://other.example.com/fan/admin with prefix trex_
        ];

        self::assertSame(self::C, self::keyFor(self::target($others), '//Example.COM/fan/admin'));
    }

    public function testAddressesWithoutAHostNeverRecoverAProtocolRelativeKey(): void
    {
        // 1.0.0-3's key for //fan/admin: an install whose host is "fan".
        $hostFan = self::target(['8f9d89a801e0' => [3, 3]]);
        self::assertSame('e99b492a35cb', self::keyFor($hostFan, 'fan/admin'));
        self::assertSame('e75224daf049', self::keyFor($hostFan, '/fan/admin'));

        // The second known limit: //example.com/... now written with no scheme at all.
        self::assertSame(self::C, self::keyFor(self::target([self::D => [3, 3]]), 'example.com/fan/admin'));
    }

    public function testAnAddressWithOnlyOneKeyNeverReadsTheDatabase(): void
    {
        $target = self::target();
        $target->failOn = '/^/';

        self::assertSame('e99b492a35cb', self::keyFor($target, 'fan/admin'));
        self::assertSame(self::C, self::keyFor($target, 'example.com/fan/admin'));
        self::assertSame([], $target->statements);
    }

    /** @return array<string, array{string}> */
    public static function failedReads(): array
    {
        return [
            'listing links read'         => ['/source IN/'],
            'codes read (links worked)'  => ['/import_ref LIKE/'],
            'listing links table check'  => ['/table_info\(`lstr_listing_external_refs`\)/'],
            'codes column check'         => ['/table_info\(`lstr_codes`\)/'],
        ];
    }

    #[DataProvider('failedReads')]
    public function testAFailedReadStopsInsteadOfGuessing(string $failOn): void
    {
        // The install's old bridge links really are there: a failed read must not look like "never imported".
        $target = self::target([self::L => [2, 0]]);
        $target->failOn = $failOn;

        try {
            self::keyFor($target, '//Example.COM/fan/admin');
            self::fail('A failed read must stop the import');
        } catch (RuntimeException $e) {
            self::assertStringStartsWith(
                "Luster couldn't check which Source ID earlier imports of this install used (Simulated failure for: ",
                $e->getMessage()
            );
            self::assertStringEndsWith('Try again, or enter the Source ID on the Options step.', $e->getMessage());
            self::assertInstanceOf(PDOException::class, $e->getPrevious());
        }
        self::assertNotSame([], $target->statementsMatching($failOn), 'The failure came from the read being tested');
    }

    public function testAMissingTableOrColumnCountsAsNothingThere(): void
    {
        // No listing links table: decided by codes alone.
        self::assertSame(self::L, self::keyFor(self::target([self::L => [0, 2]], refs: false), '//Example.COM/fan/admin'));
        // lstr_codes from before Migration 104: decided by listing links alone.
        self::assertSame(self::L, self::keyFor(self::target([self::L => [2, 0]], codes: 'old'), '//Example.COM/fan/admin'));
        self::assertSame(self::L, self::keyFor(self::target([self::L => [2, 0]], codes: 'none'), '//Example.COM/fan/admin'));
        // Neither.
        self::assertSame(self::C, self::keyFor(self::target(refs: false, codes: 'old'), '//Example.COM/fan/admin'));
        self::assertSame(self::C, self::keyFor(self::target(refs: false, codes: 'none'), '//Example.COM/fan/admin'));
    }
}
