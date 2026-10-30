<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Import\ListingAdminSourceKey;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Source identity for Listing Admin imports: how an LA install's key is worked
 * out, what a typed-in Source ID may contain, and the list of earlier keys.
 */
final class ListingAdminSourceKeyTest extends TestCase
{
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
}
