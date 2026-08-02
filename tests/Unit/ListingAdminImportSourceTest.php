<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Import\ImportOptions;
use Luster\Import\ListingAdminImportSource;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Covers the la_table_prefix validation guard added to
 * ListingAdminImportSource::preview()/run() (SQL injection fix — the field
 * was previously interpolated into raw SQL across dozens of call sites with
 * no character-class validation). Not a full behavioral suite for the
 * adapter itself.
 */
final class ListingAdminImportSourceTest extends TestCase
{
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
            'source'            => 'listing-admin',
            'conflict_strategy' => 'skip',
        ], $overrides));
    }

    private function adapter(): ListingAdminImportSource
    {
        return new ListingAdminImportSource();
    }

    public function testPreviewRejectsLaTablePrefixWithIllegalCharacters(): void
    {
        $source = $this->makePdo();
        $target = $this->makePdo();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid table prefix');

        $this->adapter()->preview($source, $target, $this->makeOptions([
            'la_table_prefix' => "trex` UNION SELECT password_hash FROM lstr_admin_users-- ",
        ]));
    }

    public function testRunRejectsLaTablePrefixWithIllegalCharacters(): void
    {
        $source = $this->makePdo();
        $target = $this->makePdo();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid table prefix');

        $this->adapter()->run($source, $target, $this->makeOptions([
            'la_table_prefix' => "trex`; DROP TABLE lstr_admin_users;--",
        ]));
    }

    public function testPreviewAcceptsBlankLaTablePrefix(): void
    {
        // Blank is the documented, valid "normal Listing Admin install" case.
        $source = $this->makePdo();
        $target = $this->makePdo();

        $preview = $this->adapter()->preview($source, $target, $this->makeOptions([
            'la_table_prefix' => '',
        ]));

        $this->assertSame([], $preview->rows);
    }

    public function testPreviewAcceptsValidLaTablePrefix(): void
    {
        $source = $this->makePdo();
        $target = $this->makePdo();

        $preview = $this->adapter()->preview($source, $target, $this->makeOptions([
            'la_table_prefix' => 'trex_',
        ]));

        $this->assertSame([], $preview->rows);
    }

    /**
     * NewsfeedBodySanitizer (src/Support/NewsfeedBodySanitizer.php) runs at
     * read time only - this importer's Updates insert path must keep storing
     * the raw source text exactly as before, tag-shaped or not.
     */
    public function testImportedUpdateBodyIsStoredRawUntouchedByHtmlSanitizer(): void
    {
        $source = $this->makePdo();
        $target = $this->makePdo();

        $target->exec(
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

        $source->exec(
            'CREATE TABLE updates (
                uID     INTEGER PRIMARY KEY,
                fNiq    INTEGER,
                uTitle  TEXT,
                uEntry  TEXT,
                uAdded  TEXT
            )'
        );

        $rawBody = 'Update: <b>thrilled</b> to announce <script>alert(1)</script> a relaunch!';

        $source->prepare(
            'INSERT INTO updates (fNiq, uTitle, uEntry, uAdded) VALUES (:fniq, :title, :body, :added)'
        )->execute([
            'fniq'  => 0,
            'title' => 'Entry 1',
            'body'  => $rawBody,
            'added' => '2026-01-01 12:00:00',
        ]);

        $result = $this->adapter()->run($source, $target, $this->makeOptions([
            'import_updates' => '1',
        ]));

        $updatesRow = null;
        foreach ($result->rows as $row) {
            if ($row['type'] === 'Updates') {
                $updatesRow = $row;
            }
        }
        $this->assertNotNull($updatesRow, 'Updates step should have run');
        $this->assertSame(1, $updatesRow['imported']);

        $stored = $target->query('SELECT body FROM lstr_newsfeed')->fetchColumn();
        $this->assertSame($rawBody, $stored);
    }
}
