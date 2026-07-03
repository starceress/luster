<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Import\FanUpdateImportSource;
use Luster\Import\ImportOptions;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FanUpdateImportSource::preview() — specifically the
 * "Newsfeed add-on installed?" gate, which is checked against $target
 * (Luster's own database), and the source `{prefix}` blog table checks.
 *
 * Uses two in-memory SQLite PDO connections (source and target).
 * schemaVariant is pre-set in options so detectSchema() — which uses
 * MySQL-only SHOW TABLES syntax against $source — is never called.
 */
final class FanUpdateImportSourceTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    }

    /** @param array<string,mixed> $overrides */
    private function makeOptions(array $overrides = []): ImportOptions
    {
        return ImportOptions::fromPost(array_merge([
            'source'              => 'fanupdate',
            'conflict_strategy'   => 'skip',
            'import_updates'      => '1',
            '_dump_schema_variant' => 'fanupdate_blog',
        ], $overrides));
    }

    private function adapter(): FanUpdateImportSource
    {
        return new FanUpdateImportSource();
    }

    /** Creates the `lstr_newsfeed` table in $pdo, marking the Newsfeed add-on as installed. */
    private function installNewsfeedAddon(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE lstr_newsfeed (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                fanlisting_id INTEGER,
                title         TEXT    NOT NULL,
                body          TEXT    NOT NULL,
                category      TEXT    NOT NULL DEFAULT \'\',
                author        TEXT    NOT NULL DEFAULT \'\',
                posted_at     TEXT    NOT NULL,
                created_at    TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
    }

    /**
     * Creates a `blog` table with $total entries, $public of which are
     * is_public = 1.
     */
    private function seedBlog(PDO $pdo, int $total, int $public): void
    {
        $pdo->exec(
            'CREATE TABLE blog (
                entry_id  INTEGER PRIMARY KEY,
                added     TEXT NOT NULL,
                title     TEXT NOT NULL,
                body      TEXT NOT NULL,
                is_public INTEGER NOT NULL
            )'
        );

        $stmt = $pdo->prepare(
            'INSERT INTO blog (added, title, body, is_public) VALUES (:added, :title, :body, :is_public)'
        );

        for ($i = 1; $i <= $total; $i++) {
            $stmt->execute([
                'added'     => '2026-01-0' . $i . ' 12:00:00',
                'title'     => "Entry {$i}",
                'body'      => "Body {$i}",
                'is_public' => $i <= $public ? 1 : 0,
            ]);
        }
    }

    // -----------------------------------------------------------------------
    // Add-on NOT installed (lstr_newsfeed absent from $target)
    // -----------------------------------------------------------------------

    public function testPreviewWarnsWhenAddonNotInstalledAndSourceTableMissing(): void
    {
        $source = $this->makePdo();
        $target = $this->makePdo();

        $preview = $this->adapter()->preview($source, $target, $this->makeOptions());

        $row = $preview->rows[0];
        $this->assertSame('Updates (blog entries)', $row['type']);
        $this->assertSame(0, $row['found']);
        $this->assertSame(0, $row['import']);
        $this->assertSame(0, $row['skip']);
        $this->assertSame([
            'News Feed add-on is not installed yet — running the import will install it automatically so this data is not lost.',
            'Table `blog` not found — check the Blog Table Name setting.',
        ], $row['warnings']);
    }

    public function testPreviewWarnsWhenAddonNotInstalledAndAllEntriesPublic(): void
    {
        $source = $this->makePdo();
        $target = $this->makePdo();
        $this->seedBlog($source, total: 3, public: 3);

        $preview = $this->adapter()->preview($source, $target, $this->makeOptions());

        $row = $preview->rows[0];
        $this->assertSame(3, $row['found']);
        $this->assertSame(3, $row['import']);
        $this->assertSame(0, $row['skip']);
        $this->assertSame([
            'News Feed add-on is not installed yet — running the import will install it automatically so this data is not lost.',
        ], $row['warnings']);
    }

    public function testPreviewWarnsWhenAddonNotInstalledAndSomeEntriesNonPublic(): void
    {
        $source = $this->makePdo();
        $target = $this->makePdo();
        $this->seedBlog($source, total: 5, public: 3);

        $preview = $this->adapter()->preview($source, $target, $this->makeOptions());

        $row = $preview->rows[0];
        $this->assertSame(5, $row['found']);
        $this->assertSame(3, $row['import']);
        $this->assertSame(2, $row['skip']);
        $this->assertSame([
            'News Feed add-on is not installed yet — running the import will install it automatically so this data is not lost.',
            '2 non-public entries will be skipped.',
        ], $row['warnings']);
    }

    // -----------------------------------------------------------------------
    // Add-on installed (lstr_newsfeed present in $target)
    // -----------------------------------------------------------------------

    public function testPreviewDoesNotWarnAboutAddonWhenInstalledAndSourceTableMissing(): void
    {
        $source = $this->makePdo();
        $target = $this->makePdo();
        $this->installNewsfeedAddon($target);

        $preview = $this->adapter()->preview($source, $target, $this->makeOptions());

        $row = $preview->rows[0];
        $this->assertSame(0, $row['found']);
        $this->assertSame(['Table `blog` not found — check the Blog Table Name setting.'], $row['warnings']);
    }

    public function testPreviewHasNoWarningsWhenAddonInstalledAndAllEntriesPublic(): void
    {
        $source = $this->makePdo();
        $target = $this->makePdo();
        $this->installNewsfeedAddon($target);
        $this->seedBlog($source, total: 4, public: 4);

        $preview = $this->adapter()->preview($source, $target, $this->makeOptions());

        $row = $preview->rows[0];
        $this->assertSame(4, $row['found']);
        $this->assertSame(4, $row['import']);
        $this->assertSame(0, $row['skip']);
        $this->assertSame([], $row['warnings']);
    }

    public function testPreviewWarnsAboutSingleNonPublicEntryWhenAddonInstalled(): void
    {
        $source = $this->makePdo();
        $target = $this->makePdo();
        $this->installNewsfeedAddon($target);
        $this->seedBlog($source, total: 4, public: 3);

        $preview = $this->adapter()->preview($source, $target, $this->makeOptions());

        $row = $preview->rows[0];
        $this->assertSame(4, $row['found']);
        $this->assertSame(3, $row['import']);
        $this->assertSame(1, $row['skip']);
        $this->assertSame(['1 non-public entry will be skipped.'], $row['warnings']);
    }

    // -----------------------------------------------------------------------
    // import_updates = false
    // -----------------------------------------------------------------------

    public function testPreviewReturnsNoRowsWhenUpdatesNotSelected(): void
    {
        $source = $this->makePdo();
        $target = $this->makePdo();

        $preview = $this->adapter()->preview($source, $target, $this->makeOptions(['import_updates' => '0']));

        $this->assertSame([], $preview->rows);
    }
}
