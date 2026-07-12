<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Import\EnthusiastImportSource;
use Luster\Import\ImportOptions;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests that importing a listing's additional fields from the enth_ schema
 * preserves field type (dropdown, checkbox, etc.), not just the bare field
 * name. Previously, EnthusiastImportSource::runOwnedListings() wrote only a
 * comma-separated name list to owned.additional, discarding label/type/
 * required entirely — every imported field silently became a plain text
 * field (FieldRepo::parseSimpleNames() defaults untyped bare names to
 * 'text'), even when the source Enthusiast install had it configured as a
 * dropdown/select.
 *
 * owned.additional supports the same PHP-serialized-array format
 * FieldRepo::saveFields() writes (FieldRepo::getFieldsForListing() detects
 * and unserializes it before falling back to bare-name parsing), so there
 * was no structural reason for the importer to lose this information.
 */
final class EnthusiastAdditionalFieldTypeImportTest extends TestCase
{
    private function makePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    }

    private function makeOptions(array $overrides = []): ImportOptions
    {
        return ImportOptions::fromPost(array_merge([
            'source'               => 'enthusiast',
            'conflict_strategy'    => 'skip',
            'import_owned_listings' => '1',
        ], $overrides));
    }

    private function adapter(): EnthusiastImportSource
    {
        return new EnthusiastImportSource();
    }

    /**
     * @return ReflectionMethod A callable handle to the private
     *   parseAdditionalFieldDefinitions() method for direct unit testing.
     */
    private function parseMethod(): ReflectionMethod
    {
        $ref = new ReflectionMethod(EnthusiastImportSource::class, 'parseAdditionalFieldDefinitions');
        $ref->setAccessible(true);
        return $ref;
    }

    // -----------------------------------------------------------------------
    // parseAdditionalFieldDefinitions() — pure parsing, no DB
    // -----------------------------------------------------------------------

    public function testParsesPipeDelimitedDropdownFieldWithType(): void
    {
        $result = $this->parseMethod()->invoke(
            $this->adapter(),
            'favorite,Favorite Character,select,0',
        );

        self::assertSame([
            ['name' => 'favorite', 'label' => 'Favorite Character', 'type' => 'select', 'required' => false],
        ], $result);
    }

    public function testParsesMultiplePipeDelimitedFieldsPreservingEachType(): void
    {
        $result = $this->parseMethod()->invoke(
            $this->adapter(),
            'favorite,Favorite Character,select,0|fave_song,Favourite Song,text,1',
        );

        self::assertCount(2, $result);
        self::assertSame('favorite', $result[0]['name']);
        self::assertSame('select', $result[0]['type']);
        self::assertFalse($result[0]['required']);
        self::assertSame('fave_song', $result[1]['name']);
        self::assertSame('text', $result[1]['type']);
        self::assertTrue($result[1]['required']);
    }

    public function testParsesSerializedArrayFormatPreservingType(): void
    {
        $raw = serialize([
            ['name' => 'favorite', 'label' => 'Favorite Character', 'type' => 'dropdown', 'required' => 0],
        ]);

        $result = $this->parseMethod()->invoke($this->adapter(), $raw);

        self::assertSame([
            ['name' => 'favorite', 'label' => 'Favorite Character', 'type' => 'dropdown', 'required' => false],
        ], $result);
    }

    public function testEmptyInputReturnsEmptyArray(): void
    {
        self::assertSame([], $this->parseMethod()->invoke($this->adapter(), ''));
    }

    public function testFiltersOutUnsafeFieldNames(): void
    {
        $result = $this->parseMethod()->invoke(
            $this->adapter(),
            'favorite,Favorite,select,0|bad name!,Bad,text,0',
        );

        self::assertCount(1, $result);
        self::assertSame('favorite', $result[0]['name']);
    }

    public function testMissingTypeDefaultsToText(): void
    {
        $result = $this->parseMethod()->invoke($this->adapter(), 'favorite,Favorite');

        self::assertSame('text', $result[0]['type']);
    }

    // -----------------------------------------------------------------------
    // End-to-end: runOwnedListings() writes a type-preserving serialized
    // value that FieldRepo reads back correctly.
    // -----------------------------------------------------------------------

    public function testImportedDropdownFieldSurvivesAsDropdownNotText(): void
    {
        $src = $this->makePdo();
        $src->exec(
            "CREATE TABLE `enth_fanlistings` (
                id              INTEGER PRIMARY KEY,
                dbprefix        TEXT NOT NULL,
                name            TEXT NOT NULL DEFAULT '',
                subject         TEXT NOT NULL DEFAULT '',
                email           TEXT NOT NULL DEFAULT '',
                url             TEXT NOT NULL DEFAULT '',
                catid           TEXT NOT NULL DEFAULT '',
                additional_info TEXT NOT NULL DEFAULT '',
                trouble         INTEGER NOT NULL DEFAULT 0,
                upcoming        INTEGER NOT NULL DEFAULT 0
            )"
        );
        $src->exec(
            "INSERT INTO `enth_fanlistings`
                (id, dbprefix, name, subject, email, url, catid, additional_info)
             VALUES
                (1, 'wmmp_', 'Jewel Eyes', 'Who Made Me a Princess', 'owner@test.com',
                 'http://test.com', '', 'favorite,Favorite Character,select,0')"
        );
        $src->exec(
            "CREATE TABLE `wmmp_members` (
                email TEXT NOT NULL PRIMARY KEY, name TEXT NOT NULL DEFAULT '',
                country TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '',
                pending INTEGER NOT NULL DEFAULT 0, password TEXT NOT NULL DEFAULT '',
                showemail INTEGER NOT NULL DEFAULT 1, showurl INTEGER NOT NULL DEFAULT 1,
                added TEXT NULL, additional_favorite TEXT NULL
            )"
        );

        $tgt = $this->makePdo();
        $tgt->exec(
            "CREATE TABLE `owned` (
                listingid INTEGER PRIMARY KEY, dbtable TEXT NOT NULL, title TEXT NOT NULL DEFAULT '',
                subject TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '',
                catid TEXT NOT NULL DEFAULT '', additional TEXT NOT NULL DEFAULT '', status INTEGER NOT NULL DEFAULT 2
            )"
        );

        $this->adapter()->run($src, $tgt, $this->makeOptions());

        $stmt = $tgt->prepare('SELECT additional FROM owned WHERE dbtable = :dbtable');
        $stmt->execute(['dbtable' => 'wmmp_members']);
        $stored = (string) $stmt->fetchColumn();

        self::assertNotSame('favorite', $stored, 'Must not collapse to a bare field name — that silently becomes a text field.');

        // Confirm it round-trips through the exact same unserialize() path
        // FieldRepo::getFieldsForListing() uses when the admin edit form and
        // public join form ask for this listing's additional fields.
        $decoded = @unserialize($stored, ['allowed_classes' => false]);
        self::assertIsArray($decoded, 'owned.additional should hold a serialized array, not a bare name string.');
        self::assertSame('favorite', $decoded[0]['name']);
        self::assertSame('select', $decoded[0]['type']);
        self::assertSame('Favorite Character', $decoded[0]['label']);
    }
}
