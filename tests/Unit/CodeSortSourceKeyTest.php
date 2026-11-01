<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Import\CodeSortSourceKey;
use Luster\Import\ListingAdminSourceKey;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Source identity for CodeSort imports: which settings make a key, what a
 * typed-in Source ID may contain, and the list of earlier keys.
 */
final class CodeSortSourceKeyTest extends TestCase
{
    public function testKeyComesFromAFullImageAddress(): void
    {
        $result = CodeSortSourceKey::resolve('', 'http://example.com/fan/codes/');

        self::assertSame('images_url', $result['origin']);
        self::assertSame(substr(sha1('example.com/fan/codes'), 0, 12), $result['key']);
        self::assertTrue(ListingAdminSourceKey::isValid((string) $result['key']));
    }

    public function testSchemeAndHostCaseDontChangeTheKeyButPathCaseDoes(): void
    {
        $a = CodeSortSourceKey::resolve('', 'http://Example.COM/Codes');
        $b = CodeSortSourceKey::resolve('', 'https://example.com/Codes/');
        $c = CodeSortSourceKey::resolve('', 'https://example.com/codes/');

        self::assertSame($a['key'], $b['key']);
        self::assertNotSame($a['key'], $c['key']);
    }

    /** '//host/path' (no http: or https:) is the same address. */
    public function testAnAddressWithoutTheSchemeGivesTheSameKey(): void
    {
        $full = CodeSortSourceKey::resolve('', 'https://example.com/Codes')['key'];

        foreach (['//Example.COM/Codes', '//example.com/Codes/', '  //EXAMPLE.com/Codes  '] as $url) {
            self::assertSame(['key' => $full, 'origin' => 'images_url'], CodeSortSourceKey::resolve('', $url), $url);
        }
        self::assertNotSame($full, CodeSortSourceKey::resolve('', '//example.com/codes')['key']);
        // Three slashes is a path on this server, not an address.
        self::assertSame(['key' => null, 'origin' => 'none'], CodeSortSourceKey::resolve('', '///example.com/Codes'));
    }

    /** A folder or a relative address turns up on unrelated installs, so it never makes a key. */
    public function testAFolderOrRelativeAddressGivesNoKey(): void
    {
        foreach (['images/', '/home/user/codes', 'codes', '', '   '] as $url) {
            self::assertSame(['key' => null, 'origin' => 'none'], CodeSortSourceKey::resolve('', $url), $url);
        }
    }

    public function testTypedSourceIdWinsOverTheAddress(): void
    {
        self::assertSame(
            ['key' => 'old-codes', 'origin' => 'override'],
            CodeSortSourceKey::resolve('old-codes', 'http://example.com/codes')
        );
    }

    public function testInvalidSourceIdIsRejectedWithTheListingAdminMessage(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(ListingAdminSourceKey::INVALID_ID_MESSAGE);
        CodeSortSourceKey::resolve('Old_Codes', 'http://example.com/codes');
    }

    public function testImportRefFitsTheColumn(): void
    {
        $ref = CodeSortSourceKey::importRef(str_repeat('a', 36), 4294967295);

        self::assertSame('codesort:' . str_repeat('a', 36) . ':4294967295', $ref);
        self::assertLessThanOrEqual(100, strlen($ref));
    }

    public function testKnownSourcesCountsCodesPerKey(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE lstr_codes (id INTEGER PRIMARY KEY, import_ref TEXT NULL UNIQUE)');
        $insert = $pdo->prepare('INSERT INTO lstr_codes (import_ref) VALUES (?)');
        foreach (['codesort:old-codes:1', 'codesort:old-codes:2', 'codesort:3f9a1c0b7d2e:1', 'listing-admin:old-codes:1', 'codesort:BAD:1', null] as $ref) {
            $insert->execute([$ref]);
        }

        self::assertSame(
            [['key' => '3f9a1c0b7d2e', 'codes' => 1], ['key' => 'old-codes', 'codes' => 2]],
            CodeSortSourceKey::knownSources($pdo)
        );
    }

    public function testKnownSourcesIsEmptyBeforeMigration104(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE lstr_codes (id INTEGER PRIMARY KEY, filename TEXT NOT NULL)');

        self::assertSame([], CodeSortSourceKey::knownSources($pdo));
    }
}
