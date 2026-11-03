<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Import\FanaticallyImportSource;
use Luster\Import\ImportOptions;
use Luster\Import\ImportResult;
use Luster\Support\PreviousOwnersRenderer;
use Luster\Tests\Unit\Support\FanaticallyImportFixture;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Fanatically's previous owners (listings.previous_owners, a JSON list of
 * {name, url}) come over into lstr_listing_previous_owners, per Luster listing:
 * only real information changes Luster's (a missing column or no value leaves
 * them alone, and only an exact [] clears), Skip leaves an existing listing's
 * alone, Overwrite replaces them only when every Fanatically listing going into
 * it was saved and readable, and every write is checked against what's really
 * there afterwards. A save that can't be undone stops the import.
 */
final class FanaticallyPreviousOwnersImportTest extends TestCase
{
    use FanaticallyImportFixture;

    private const MOON = 'https://fans.test/moon';

    /** A Fanatically 1.1.10+ source: listings has the previous_owners column. */
    private function source(): PDO
    {
        $src = $this->makeSource();
        $src->exec('ALTER TABLE listings ADD COLUMN previous_owners TEXT NULL');
        return $src;
    }

    private function listing(PDO $src, int $id, string $title, string $url, ?string $owners, string $status = 'current'): void
    {
        $this->addSourceListing($src, $id, $title, $url, null, $status);
        $src->prepare('UPDATE listings SET previous_owners = :o WHERE id = :id')->execute(['o' => $owners, 'id' => $id]);
    }

    private function setOwners(PDO $src, int $id, ?string $owners): void
    {
        $src->prepare('UPDATE listings SET previous_owners = :o WHERE id = :id')->execute(['o' => $owners, 'id' => $id]);
    }

    /** @param list<array{0: string, 1: string}> $pairs */
    private function giveOwners(PDO $tgt, int $listingId, array $pairs): void
    {
        $tgt->prepare('DELETE FROM lstr_listing_previous_owners WHERE fanlisting_id = :id')->execute(['id' => $listingId]);
        $ins = $tgt->prepare('INSERT INTO lstr_listing_previous_owners (fanlisting_id, owner_name, owner_url, sort_order) VALUES (:id, :n, :u, :s)');
        foreach ($pairs as $i => [$name, $url]) {
            $ins->execute(['id' => $listingId, 'n' => $name, 'u' => $url, 's' => $i]);
        }
    }

    /** @return list<array{0: string, 1: string}> */
    private function ownersOf(PDO $tgt, int $listingId): array
    {
        $st = $tgt->prepare('SELECT owner_name, owner_url FROM lstr_listing_previous_owners WHERE fanlisting_id = :id ORDER BY sort_order, id');
        $st->execute(['id' => $listingId]);
        return array_map(static fn(array $r): array => [(string) $r['owner_name'], (string) $r['owner_url']], $st->fetchAll());
    }

    private function idOf(PDO $tgt, string $url): int
    {
        $st = $tgt->prepare('SELECT listingid FROM owned WHERE url = :u');
        $st->execute(['u' => $url]);
        return (int) $st->fetchColumn();
    }

    /**
     * Makes exactly the nth statement containing each needle fail, and no other.
     *
     * @param array<string,int> $nths needle → which one fails (1 = the first)
     */
    private function failThe(\Luster\Tests\Unit\Support\TracingPdo $pdo, array $nths): void
    {
        $seen = [];
        $pdo->failWhen = static function (string $sql) use ($nths, &$seen): bool {
            foreach ($nths as $needle => $nth) {
                if (str_contains($sql, $needle)) {
                    $seen[$needle] = ($seen[$needle] ?? 0) + 1;
                    if ($seen[$needle] === $nth) {
                        return true;
                    }
                }
            }
            return false;
        };
    }

    private function skip(): ImportOptions
    {
        return $this->options(['import_listings' => '1']);
    }

    private function overwrite(): ImportOptions
    {
        return $this->options(['import_listings' => '1', 'conflict_strategy' => 'overwrite']);
    }

    /** @return array<string,mixed>|null The Previous Owners result row, if there is one */
    private function ownersRow(ImportResult $result): ?array
    {
        foreach ($result->rows as $row) {
            if ($row['type'] === 'Previous Owners') {
                return $row;
            }
        }
        return null;
    }

    /** Moon, already in Luster (imported once) with two previous owners of its own. */
    private function moonWithOwners(PDO $src, PDO $tgt, ?string $sourceOwners): int
    {
        $this->listing($src, 1, 'Moon', self::MOON, null);
        $this->runImport($src, $tgt, $this->skip());
        $id = $this->idOf($tgt, self::MOON);
        $this->giveOwners($tgt, $id, [['Kai', 'https://kai.test/'], ['Mo', '']]);
        $this->setOwners($src, 1, $sourceOwners);
        return $id;
    }

    // -----------------------------------------------------------------------
    // What the source says
    // -----------------------------------------------------------------------

    public function testAListingsPreviousOwnersComeOverInOrder(): void
    {
        $src = $this->source();
        $tgt = $this->makeOwnedTarget();
        $this->listing($src, 1, 'Moon', self::MOON,
            '[{"name":"Kai","url":"https://kai.test/"},{"name":"Mo","url":""},{"name":"Kai","url":"https://kai.test/"},{"url":"https://only-a-link.test/"}]');

        $result = $this->runImport($src, $tgt, $this->skip());
        $row    = $this->ownersRow($result);
        self::assertNotNull($row);
        self::assertSame([], $row['errors']);
        self::assertSame(4, $row['imported']);
        self::assertSame([['Kai', 'https://kai.test/'], ['Mo', ''], ['Kai', 'https://kai.test/'], ['', 'https://only-a-link.test/']],
            $this->ownersOf($tgt, $this->idOf($tgt, self::MOON)), 'Order and a repeat within one list are kept');
    }

    public function testAFanaticallyFromBeforePreviousOwnersLeavesLustersAlone(): void
    {
        $src = $this->makeSource(); // no previous_owners column
        $tgt = $this->makeOwnedTarget();
        $this->addSourceListing($src, 1, 'Moon', self::MOON);
        $this->runImport($src, $tgt, $this->skip());
        $id = $this->idOf($tgt, self::MOON);
        $this->giveOwners($tgt, $id, [['Kai', '']]);

        $result = $this->runImport($src, $tgt, $this->overwrite());
        self::assertNull($this->ownersRow($result), 'Nothing to say');
        self::assertSame([['Kai', '']], $this->ownersOf($tgt, $id));

        $tgt->exec('DROP TABLE lstr_listing_previous_owners');
        self::assertSame([], $this->resultRow($this->runImport($src, $tgt, $this->overwrite()), 'Owned Listings')['errors'],
            'And it never waits for Migration 102');
    }

    /** @return array<string, array{0: ?string}> */
    public static function noValueProvider(): array
    {
        return ['NULL' => [null], 'empty' => [''], 'spaces' => ['   ']];
    }

    /** @dataProvider noValueProvider */
    public function testNoValueLeavesLustersAlone(?string $value): void
    {
        $src = $this->source();
        $tgt = $this->makeOwnedTarget();
        $id  = $this->moonWithOwners($src, $tgt, $value);

        foreach ([$this->skip(), $this->overwrite()] as $opts) {
            $this->runImport($src, $tgt, $opts);
            self::assertSame([['Kai', 'https://kai.test/'], ['Mo', '']], $this->ownersOf($tgt, $id));
        }
    }

    /** @return array<string, array{0: string}> */
    public static function malformedProvider(): array
    {
        return [
            'broken JSON' => ['[{"name":'],
            'an object'   => ['{}'],
            'an object with a name' => ['{"name":"Kai"}'],
            'a string'    => ['"Kai"'],
            'a number'    => ['5'],
        ];
    }

    /** @dataProvider malformedProvider */
    public function testPreviousOwnersThatArentAListAreLeftAloneWithANote(string $value): void
    {
        $src = $this->source();
        $tgt = $this->makeOwnedTarget();
        $id  = $this->moonWithOwners($src, $tgt, $value);
        $note = "Moon: its previous owners couldn't be read, so Luster left them alone.";

        self::assertContains($note, $this->previewRow($src, $tgt, $this->overwrite(), 'Previous Owners')['warnings']);
        $row = $this->ownersRow($this->runImport($src, $tgt, $this->overwrite()));
        self::assertContains($note, $row['warnings']);
        self::assertSame([], $row['errors']);
        self::assertSame([['Kai', 'https://kai.test/'], ['Mo', '']], $this->ownersOf($tgt, $id), 'Never taken for an empty list');
    }

    /** @return array<string, array{0: string}> */
    public static function unusableProvider(): array
    {
        return [
            'a string entry'          => ['["Kai"]'],
            'an empty entry'          => ['[{}]'],
            'a blank entry'           => ['[{"name":"","url":""}]'],
            'only a bad link'         => ['[{"url":"javascript:alert(1)"}]'],
            'a name that is a list'   => ['[{"name":["Kai"]}]'],
            'a name that is an object' => ['[{"name":{"first":"Kai"}}]'],
            'a name that is a number' => ['[{"name":5}]'],
            'a link that is true'     => ['[{"name":"","url":true}]'],
        ];
    }

    /** @dataProvider unusableProvider */
    public function testAListWithNothingUsableIsNeverAClear(string $value): void
    {
        $src = $this->source();
        $tgt = $this->makeOwnedTarget();
        $id  = $this->moonWithOwners($src, $tgt, $value);

        $row = $this->ownersRow($this->runImport($src, $tgt, $this->overwrite()));
        self::assertContains('Moon: none of its previous owners could be used, so Luster left them alone.', $row['warnings']);
        self::assertSame([['Kai', 'https://kai.test/'], ['Mo', '']], $this->ownersOf($tgt, $id));
    }

    public function testAMixedListBringsTheUsableOnesAndSaysWhatWasLeftOut(): void
    {
        $src = $this->source();
        $tgt = $this->makeOwnedTarget();
        $this->listing($src, 1, 'Moon', self::MOON, json_encode([
            ['name' => 'A', 'url' => 'https://a.test/'],
            'not an entry',
            ['name' => 'B', 'url' => 'ftp://b.test/'],
            ['url' => 'nope'],
            ['name' => ['nested']],
            ['name' => 'C'],
            ['name' => '', 'url' => ''],
        ], JSON_THROW_ON_ERROR));

        $preview = $this->previewRow($src, $tgt, $this->skip(), 'Previous Owners');
        $row     = $this->ownersRow($this->runImport($src, $tgt, $this->skip()));
        self::assertSame([['A', 'https://a.test/'], ['B', ''], ['C', '']], $this->ownersOf($tgt, $this->idOf($tgt, self::MOON)));
        self::assertSame(3, $row['imported']);
        foreach ([$preview['warnings'], $row['warnings']] as $warnings) {
            self::assertContains("Moon: 3 of its previous owners couldn't be read and were left out.", $warnings);
            self::assertContains("Moon: 1 previous owner link(s) aren't web addresses Luster can use (or are over 500 characters), so those previous owners are saved without a link.", $warnings);
        }
        self::assertSame(3, $preview['found']);
    }

    public function testLinksWithACapitalisedSchemeAreKeptAsWritten(): void
    {
        $src = $this->source();
        $tgt = $this->makeOwnedTarget();
        $this->listing($src, 1, 'Moon', self::MOON, '[{"name":"Kai","url":"HTTPS://kai.test/"},{"name":"Mo","url":"Http://mo.test/"}]');

        $row = $this->ownersRow($this->runImport($src, $tgt, $this->skip()));
        self::assertSame([], $row['warnings'], 'No link dropped');
        self::assertSame([['Kai', 'HTTPS://kai.test/'], ['Mo', 'Http://mo.test/']], $this->ownersOf($tgt, $this->idOf($tgt, self::MOON)));
    }

    public function testNamesAreKeptAsStoredAndLongOnesFitLustersColumns(): void
    {
        $src  = $this->source();
        $tgt  = $this->makeOwnedTarget(); // owner_name <= 255, owner_url <= 500, like the installer
        $long = str_repeat('é', 300);
        $this->listing($src, 1, 'Moon', self::MOON, json_encode([
            ['name' => 'A &amp; B <b>', 'url' => ''],
            ['name' => '  {fan_email}  ', 'url' => 'https://ok.test/'],
            ['name' => $long, 'url' => 'https://x.test/' . str_repeat('a', 500)],
        ], JSON_THROW_ON_ERROR));

        $row = $this->ownersRow($this->runImport($src, $tgt, $this->skip()));
        self::assertSame([], $row['errors']);
        $owners = $this->ownersOf($tgt, $this->idOf($tgt, self::MOON));
        self::assertSame(['A &amp; B <b>', ''], $owners[0], 'Entity text kept as Fanatically stores it');
        self::assertSame(['{fan_email}', 'https://ok.test/'], $owners[1]);
        self::assertSame([str_repeat('é', 255), ''], $owners[2], 'Cut at a character; the too-long link dropped');
        self::assertContains('Moon: 1 previous owner name(s) were over 255 characters and were shortened.', $row['warnings']);

        // Shown as text: escaped, and never run as a token.
        $html = PreviousOwnersRenderer::resolve(PreviousOwnersRenderer::shield('{previous_owners}'),
            array_map(static fn(array $o): array => ['owner_name' => $o[0], 'owner_url' => $o[1]], $owners));
        self::assertStringContainsString('A &amp;amp; B &lt;b&gt;', $html);
        self::assertStringContainsString('{fan_email}', $html);
    }

    // -----------------------------------------------------------------------
    // Skip, Overwrite and clearing
    // -----------------------------------------------------------------------

    public function testAnEmptyListClearsThemOnOverwrite(): void
    {
        $src = $this->source();
        $tgt = $this->makeOwnedTarget();
        $id  = $this->moonWithOwners($src, $tgt, '[]');

        $clears = 'Moon: Fanatically has no previous owners for it, so Overwrite clears the ones it has in Luster.';
        self::assertContains($clears, $this->previewRow($src, $tgt, $this->overwrite(), 'Previous Owners')['warnings'], 'The preview says so first');
        self::assertNotContains($clears, $this->previewRow($src, $tgt, $this->skip(), 'Previous Owners')['warnings'], 'Skip leaves them');

        $row = $this->ownersRow($this->runImport($src, $tgt, $this->overwrite()));
        self::assertSame([], $this->ownersOf($tgt, $id));
        self::assertContains('Moon: previous owners cleared, to match Fanatically.', $row['warnings']);

        $row = $this->ownersRow($this->runImport($src, $tgt, $this->overwrite()));
        self::assertNull($row, 'Already clear: nothing to do or say');
    }

    public function testSkipLeavesAListingAlreadyInLusterAlone(): void
    {
        $src = $this->source();
        $tgt = $this->makeOwnedTarget();
        $id  = $this->moonWithOwners($src, $tgt, '[{"name":"New","url":""}]');
        $this->giveOwners($tgt, $id, []); // even with none of its own

        $note = '1 listing(s) already in Luster keep their own previous owners, because you chose Skip.';
        $preview = $this->previewRow($src, $tgt, $this->skip(), 'Previous Owners');
        self::assertSame([1, 0, 1], [$preview['found'], $preview['import'], $preview['skip']]);
        self::assertContains($note, $preview['warnings']);

        $row = $this->ownersRow($this->runImport($src, $tgt, $this->skip()));
        self::assertSame([0, 1], [$row['imported'], $row['skipped']]);
        self::assertContains($note, $row['warnings']);
        self::assertSame([], $this->ownersOf($tgt, $id));

        $this->runImport($src, $tgt, $this->overwrite());
        self::assertSame([['New', '']], $this->ownersOf($tgt, $id), 'Overwrite replaces them');
    }

    public function testListingsSharingAnAddressCombineTheirPreviousOwners(): void
    {
        $src = $this->source();
        $tgt = $this->makeOwnedTarget();
        $this->listing($src, 1, 'Moon', self::MOON, '[{"name":"X","url":""},{"name":"Y","url":""}]');
        $this->listing($src, 2, 'Moon Too', self::MOON, '[{"name":"Y","url":""},{"name":"Z","url":""},{"name":"Z","url":""}]');

        // Skip: the second one is "kept" (the first created the listing) and still counts.
        $preview = $this->previewRow($src, $tgt, $this->skip(), 'Previous Owners');
        self::assertSame([5, 4, 1], [$preview['found'], $preview['import'], $preview['skip']], 'Counted the way the import counts');
        $row = $this->ownersRow($this->runImport($src, $tgt, $this->skip()));
        self::assertSame(1, (int) $tgt->query('SELECT COUNT(*) FROM owned')->fetchColumn());
        self::assertSame([['X', ''], ['Y', ''], ['Z', ''], ['Z', '']], $this->ownersOf($tgt, $this->idOf($tgt, self::MOON)),
            "The second's Y repeats the first's and is dropped; its own repeat of Z is kept");
        self::assertSame([4, 1], [$row['imported'], $row['skipped']]);

        $this->setOwners($src, 2, '[{"name":"W","url":""}]');
        $this->runImport($src, $tgt, $this->overwrite());
        self::assertSame([['X', ''], ['Y', ''], ['W', '']], $this->ownersOf($tgt, $this->idOf($tgt, self::MOON)));
    }

    public function testThePreviewDropsARepeatFromASharedListingToo(): void
    {
        $src = $this->source();
        $tgt = $this->makeOwnedTarget();
        $this->listing($src, 1, 'Moon', self::MOON, '[{"name":"Kai","url":""}]');
        $this->listing($src, 2, 'Moon Too', self::MOON, '[{"name":"Kai","url":""}]');

        $preview = $this->previewRow($src, $tgt, $this->skip(), 'Previous Owners');
        $row     = $this->ownersRow($this->runImport($src, $tgt, $this->skip()));
        self::assertSame([2, 1, 1], [$preview['found'], $preview['import'], $preview['skip']]);
        self::assertSame([1, 1], [$row['imported'], $row['skipped']]);
        self::assertSame([['Kai', '']], $this->ownersOf($tgt, $this->idOf($tgt, self::MOON)));
    }

    public function testRepeatsAreComparedBeforeLongNamesAreCut(): void
    {
        $src = $this->source();
        $tgt = $this->makeOwnedTarget();
        $one = str_repeat('a', 260) . 'one';
        $two = str_repeat('a', 260) . 'two';
        $this->listing($src, 1, 'Moon', self::MOON, json_encode([['name' => $one]], JSON_THROW_ON_ERROR));
        $this->listing($src, 2, 'Moon Too', self::MOON, json_encode([['name' => $two]], JSON_THROW_ON_ERROR));

        $this->runImport($src, $tgt, $this->skip());
        self::assertCount(2, $this->ownersOf($tgt, $this->idOf($tgt, self::MOON)), 'Two different people, even once cut to 255');
    }

    public function testASiblingWithBrokenPreviousOwnersHoldsUpTheReplacement(): void
    {
        $src = $this->source();
        $tgt = $this->makeOwnedTarget();
        $id  = $this->moonWithOwners($src, $tgt, '[{"name":"New","url":""}]');
        $this->listing($src, 2, 'Moon Too', self::MOON, '{oops');
        $held = "Moon / Moon Too: previous owners weren't changed, because the previous owners of \"Moon Too\", which has the same address in Fanatically, couldn't be used. Fix them in Fanatically, then import again with Overwrite to bring them over.";

        $preview = $this->previewRow($src, $tgt, $this->overwrite(), 'Previous Owners');
        self::assertSame([1, 0, 1], [$preview['found'], $preview['import'], $preview['skip']], 'The preview holds them back too');
        self::assertContains($held, $preview['warnings']);

        $row = $this->ownersRow($this->runImport($src, $tgt, $this->overwrite()));
        self::assertSame([['Kai', 'https://kai.test/'], ['Mo', '']], $this->ownersOf($tgt, $id));
        self::assertSame([0, 1], [$row['imported'], $row['skipped']]);
        self::assertContains($held, $row['warnings']);
    }

    public function testASiblingThatWasntImportedHoldsUpTheReplacement(): void
    {
        $src = $this->source();
        $tgt = $this->makeOwnedTarget($this->makeTracingPdo());
        $id  = $this->moonWithOwners($src, $tgt, '[{"name":"New","url":""}]');
        $this->listing($src, 2, 'Moon Too', self::MOON, '[{"name":"Other","url":""}]');
        $this->runImport($src, $tgt, $this->skip()); // both on record

        $this->failThe($tgt, ['UPDATE `owned` SET title=:title' => 2]);
        $result = $this->runImport($src, $tgt, $this->overwrite());
        $tgt->failWhen = null;

        self::assertStringStartsWith("Listing 'Moon Too': ", $this->resultRow($result, 'Owned Listings')['errors'][0]);
        self::assertSame([['Kai', 'https://kai.test/'], ['Mo', '']], $this->ownersOf($tgt, $id), "Moon Too's Other must not be lost");
        self::assertContains("Moon / Moon Too: previous owners weren't changed, because \"Moon Too\", which has the same address in Fanatically, wasn't imported. Import again with Overwrite to bring them over.",
            $this->ownersRow($result)['warnings']);

        $this->runImport($src, $tgt, $this->overwrite());
        self::assertSame([['New', ''], ['Other', '']], $this->ownersOf($tgt, $id));
    }

    // -----------------------------------------------------------------------
    // Before Migration 102
    // -----------------------------------------------------------------------

    public function testPreviousOwnersWaitForMigration102(): void
    {
        $src = $this->source();
        $tgt = $this->makeOwnedTarget();
        $tgt->exec('DROP TABLE lstr_listing_previous_owners');
        $this->listing($src, 1, 'Sun', 'https://fans.test/sun', '[]');
        $this->listing($src, 2, 'Moon', self::MOON, '[{"name":"Kai","url":""}]');

        $preview = (new FanaticallyImportSource())->preview($src, $tgt, $this->skip());
        self::assertSame('Database update', $preview->rows[0]['type']);
        self::assertSomeWarningContains($preview->rows[0]['warnings'], 'Migration 102');

        $result = $this->runImport($src, $tgt, $this->skip());
        self::assertStringContainsString('Migration 102', $result->rows[0]['errors'][0]);
        self::assertSame(0, (int) $tgt->query('SELECT COUNT(*) FROM owned')->fetchColumn(), 'Nothing written');
    }

    /** @return array<string, array{0: string}> */
    public static function nothingToKeepProvider(): array
    {
        return ['an empty list' => ['[]'], 'nothing usable' => ['[{}]'], 'broken' => ['{']];
    }

    /** @dataProvider nothingToKeepProvider */
    public function testWithNothingToKeepTheImportDoesNotWaitForMigration102(string $value): void
    {
        $src = $this->source();
        $tgt = $this->makeOwnedTarget($this->makeTracingPdo());
        $tgt->exec('DROP TABLE lstr_listing_previous_owners');
        $this->listing($src, 1, 'Moon', self::MOON, null);
        $this->runImport($src, $tgt, $this->skip()); // already in Luster, so Overwrite reaches it
        $this->setOwners($src, 1, $value);

        $tgt->statements = [];
        $result = $this->runImport($src, $tgt, $this->overwrite());
        self::assertSame([], $this->resultRow($result, 'Owned Listings')['errors']);
        $touched = array_filter($tgt->statementsMatching('/lstr_listing_previous_owners/i'), static fn(string $s): bool => !str_starts_with($s, 'PRAGMA'));
        self::assertSame([], array_values($touched), 'Only the check that the table is missing: no DELETE, nothing else');
    }

    public function testAPreviousOwnersTableThatCantBeCheckedStopsTheImport(): void
    {
        $src = $this->source();
        $tgt = $this->makeOwnedTarget($this->makeTracingPdo());
        $this->listing($src, 1, 'Moon', self::MOON, '[{"name":"Kai","url":""}]');

        $tgt->failOn    = '/PRAGMA table_info\(`lstr_listing_previous_owners`\)/';
        $tgt->failTimes = 1;
        self::assertSomeWarningContains($this->previewRow($src, $tgt, $this->skip(), 'Database')['warnings'], "couldn't read part of its own database");
        $tgt->failTimes = 1;
        $errors = $this->resultRow($this->runImport($src, $tgt, $this->skip()), 'Database')['errors'];
        $tgt->failOn = null;
        self::assertSomeWarningContains($errors, 'Nothing was imported.');
        self::assertSame(0, (int) $tgt->query('SELECT COUNT(*) FROM owned')->fetchColumn());
    }

    // -----------------------------------------------------------------------
    // When writing them fails
    // -----------------------------------------------------------------------

    /** Moon (Kai, Mo in Luster; three new ones in Fanatically) and Sun after it (one new one). */
    private function twoListings(PDO $src, PDO $tgt): int
    {
        $id = $this->moonWithOwners($src, $tgt, '[{"name":"A","url":""},{"name":"B","url":""},{"name":"C","url":""}]');
        $this->listing($src, 2, 'Sun', 'https://fans.test/sun', '[{"name":"S","url":""}]');
        return $id;
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function failedWriteProvider(): array
    {
        return [
            'the DELETE'         => ['DELETE FROM lstr_listing_previous_owners', 1],
            'the second INSERT'  => ['INSERT INTO lstr_listing_previous_owners', 2],
        ];
    }

    /** @dataProvider failedWriteProvider */
    public function testAFailedWriteLeavesThemAsTheyWereAndTheImportGoesOn(string $failing, int $nth): void
    {
        $src = $this->source();
        $tgt = $this->makeOwnedTarget($this->makeTracingPdo());
        $id  = $this->twoListings($src, $tgt);

        $this->failThe($tgt, [$failing => $nth]); // Moon's, not Sun's
        $row = $this->ownersRow($this->runImport($src, $tgt, $this->overwrite()));
        $tgt->failWhen = null;

        self::assertCount(1, $row['errors']);
        self::assertStringStartsWith("Listing 'Moon': Luster couldn't save its previous owners (", $row['errors'][0]);
        self::assertStringEndsWith("), so they're as they were. Import again with Overwrite to try once more.", $row['errors'][0]);
        self::assertSame([['Kai', 'https://kai.test/'], ['Mo', '']], $this->ownersOf($tgt, $id), 'Exactly as they were, in order');
        self::assertSame([['S', '']], $this->ownersOf($tgt, $this->idOf($tgt, 'https://fans.test/sun')), 'The next listing still gets its own');
        self::assertSame(1, $row['imported']);

        $this->runImport($src, $tgt, $this->overwrite());
        self::assertSame([['A', ''], ['B', ''], ['C', '']], $this->ownersOf($tgt, $id), 'The retry finishes it');
    }

    /** @return array<string, array{0: int, 1: list<array{0: string, 1: string}>}> */
    public static function partialWriteProvider(): array
    {
        return [
            'one of three'  => [2, [['A', '']]],
            // Two rows, as many as before (Kai, Mo): the count alone would pass for "as they were".
            'two of three' => [3, [['A', ''], ['B', '']]],
        ];
    }

    /**
     * @dataProvider partialWriteProvider
     * @param list<array{0: string, 1: string}> $left
     */
    public function testOnATableThatCantRollBackAPartialWriteIsDescribedAsItIs(int $failingInsert, array $left): void
    {
        $src = $this->source();
        $tgt = $this->makeOwnedTarget($this->makeTracingPdo());
        $id  = $this->twoListings($src, $tgt);
        $tgt->noTransactions = true;

        $this->failThe($tgt, ['INSERT INTO lstr_listing_previous_owners' => $failingInsert]);
        $row = $this->ownersRow($this->runImport($src, $tgt, $this->overwrite()));
        $tgt->failWhen = null;

        $saved = count($left);
        self::assertStringEndsWith("), and they're not as they were either: only the first {$saved} of its 3 previous owners were saved. Import again with Overwrite to finish it.", $row['errors'][0]);
        self::assertSame($left, $this->ownersOf($tgt, $id), 'What the error says');

        $this->runImport($src, $tgt, $this->overwrite());
        self::assertSame([['A', ''], ['B', ''], ['C', '']], $this->ownersOf($tgt, $id));
    }

    public function testOnATableThatCantRollBackAFailedDeleteIsAsTheyWere(): void
    {
        $src = $this->source();
        $tgt = $this->makeOwnedTarget($this->makeTracingPdo());
        $id  = $this->twoListings($src, $tgt);
        $tgt->noTransactions = true;

        $this->failThe($tgt, ['DELETE FROM lstr_listing_previous_owners' => 1]);
        $row = $this->ownersRow($this->runImport($src, $tgt, $this->overwrite()));
        $tgt->failWhen = null;
        self::assertStringEndsWith("), so they're as they were. Import again with Overwrite to try once more.", $row['errors'][0]);
        self::assertSame([['Kai', 'https://kai.test/'], ['Mo', '']], $this->ownersOf($tgt, $id));
    }

    public function testAFailedCommitIsRolledBackAndChecked(): void
    {
        $src = $this->source();
        $tgt = $this->makeOwnedTarget($this->makeTracingPdo());
        $id  = $this->twoListings($src, $tgt);

        // The listings' own saves commit first; fail Moon's previous-owners commit only.
        $armed = false;
        $tgt->failWhen = static function (string $sql) use ($tgt, &$armed): bool {
            if (!$armed && str_contains($sql, 'DELETE FROM lstr_listing_previous_owners')) {
                $armed           = true;
                $tgt->failCommit = true;
            }
            return false;
        };
        $row = $this->ownersRow($this->runImport($src, $tgt, $this->overwrite()));
        $tgt->failWhen   = null;
        $tgt->failCommit = false;

        self::assertStringStartsWith("Listing 'Moon': Luster couldn't save its previous owners (Simulated failure for: COMMIT), so they're as they were.", $row['errors'][0]);
        self::assertSame([['Kai', 'https://kai.test/'], ['Mo', '']], $this->ownersOf($tgt, $id));
    }

    public function testWhenTheOnesThereCantBeReadNothingIsWritten(): void
    {
        $src = $this->source();
        $tgt = $this->makeOwnedTarget($this->makeTracingPdo());
        $id  = $this->twoListings($src, $tgt);

        $tgt->failOn    = '/SELECT owner_name, owner_url FROM lstr_listing_previous_owners/';
        $tgt->failTimes = 1;
        $row = $this->ownersRow($this->runImport($src, $tgt, $this->overwrite()));
        $tgt->failOn = null;

        self::assertStringStartsWith("Listing 'Moon': Luster couldn't read the previous owners it already has (", $row['errors'][0]);
        self::assertStringEndsWith('), so it left them alone. Import again with Overwrite to try once more.', $row['errors'][0]);
        self::assertSame([['Kai', 'https://kai.test/'], ['Mo', '']], $this->ownersOf($tgt, $id));
        self::assertSame([['S', '']], $this->ownersOf($tgt, $this->idOf($tgt, 'https://fans.test/sun')));
    }

    /** @return array<string, array{0: ?string, 1: string}> */
    public static function uncheckedProvider(): array
    {
        return [
            'after a save'   => [null, "Listing 'Moon': Luster saved its previous owners but couldn't check them afterwards ("],
            'after a failure' => ['INSERT INTO lstr_listing_previous_owners', "Listing 'Moon': Luster couldn't save its previous owners ("],
        ];
    }

    /** @dataProvider uncheckedProvider */
    public function testWhenTheyCantBeCheckedAfterwardsItSaysSo(?string $failing, string $start): void
    {
        $src = $this->source();
        $tgt = $this->makeOwnedTarget($this->makeTracingPdo());
        $this->twoListings($src, $tgt);

        // Moon's read before writing works, its check afterwards doesn't; Sun is fine.
        $this->failThe($tgt, ['SELECT owner_name, owner_url FROM lstr_listing_previous_owners' => 2] + ($failing !== null ? [$failing => 1] : []));
        $row = $this->ownersRow($this->runImport($src, $tgt, $this->overwrite()));
        $tgt->failWhen = null;

        self::assertStringStartsWith($start, $row['errors'][0]);
        self::assertStringContainsString('Check them on the listing', $row['errors'][0]);
        self::assertSame(1, $row['imported'], 'Only the checked one counts');
    }

    public function testASaveThatCantBeUndoneStopsTheImportBeforeAnythingElse(): void
    {
        $src = $this->source();
        $tgt = $this->makeOwnedTarget($this->makeTracingPdo());
        $this->twoListings($src, $tgt);
        $this->addSourceMember($src, 2, 'fan@example.com');

        $tgt->failOn       = '/INSERT INTO lstr_listing_previous_owners/';
        $tgt->failRollBack = true;
        $result = $this->runImport($src, $tgt, $this->options(['import_listings' => '1', 'import_members' => '1', 'conflict_strategy' => 'overwrite']));
        $tgt->failOn       = null;
        $tgt->failRollBack = false;

        $row = $this->ownersRow($result);
        self::assertStringEndsWith(') or finish undoing that.', $row['errors'][0]);
        self::assertSame("The previous owners of the 1 listing(s) after it weren't imported.", $row['errors'][1]);
        $stopped = $this->resultRow($result, 'Import stopped');
        self::assertStringStartsWith("Luster couldn't finish undoing a save that failed, so the import stopped", $stopped['errors'][0]);
        self::assertSame([], array_values(array_filter($result->rows, static fn(array $r): bool => $r['type'] === 'Members')),
            'Nothing after it ran: no members, no member tables');
        self::assertTrue($tgt->inTransaction(), 'Left for the caller to deal with, not committed by later DDL');
        $tgt->rollBack();
    }

    public function testAListingSaveThatCantBeUndoneStopsTheImportToo(): void
    {
        // 7b's per-listing save has the same rule.
        $src = $this->source();
        $tgt = $this->makeOwnedTarget($this->makeTracingPdo());
        $this->listing($src, 1, 'Moon', self::MOON, null, 'closed');
        $this->addSourceMember($src, 1, 'fan@example.com');

        $tgt->failOn       = '/INSERT INTO `lstr_listing_lifecycle`/';
        $tgt->failRollBack = true;
        $result = $this->runImport($src, $tgt, $this->options(['import_listings' => '1', 'import_members' => '1']));
        $tgt->failOn       = null;
        $tgt->failRollBack = false;

        self::assertStringEndsWith("), and Luster couldn't finish undoing it, so it couldn't check whether the new listing (#1) was removed again. Import again with Overwrite to finish it.",
            $this->resultRow($result, 'Owned Listings')['errors'][0]);
        self::assertStringStartsWith("Luster couldn't finish undoing a save that failed", $this->resultRow($result, 'Import stopped')['errors'][0]);
        self::assertSame(0, (int) $tgt->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'moon'")->fetchColumn(),
            'No member table made');
        $tgt->rollBack();
    }

    /**
     * Ends a save the import left open the way the database does once the page
     * is done (it's rolled back), with `owned` on MyISAM as on installs that
     * began on Enthusiast: what was written to `owned` stays, the rest is undone.
     */
    private function rollBackKeepingOwned(\Luster\Tests\Unit\Support\TracingPdo $tgt): void
    {
        $owned = $tgt->query('SELECT * FROM owned')->fetchAll();
        $tgt->failRollBack = false;
        $tgt->rollBack();
        $tgt->exec('DELETE FROM owned');
        foreach ($owned as $row) {
            $columns = array_keys($row);
            $tgt->prepare('INSERT INTO owned (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')')->execute($row);
        }
    }

    /** @return array<string, array{0: bool, 1: string, 2: string}> */
    public static function leftOpenListingProvider(): array
    {
        return [
            'a new listing with an address' => [false, self::MOON,
                'the new listing (#1) was removed again. Import again with Overwrite to finish it.'],
            'a new listing with no address' => [false, '',
                'the new listing (#1) was removed again. If listing #1 is on your Listings page, delete it before importing again, or the import will add it a second time.'],
            'a listing already in Luster'   => [true, self::MOON,
                'listing #1 was put back the way it was. Check it on your Listings page, then import again with Overwrite to finish it.'],
        ];
    }

    /**
     * `owned` on MyISAM, the rest on InnoDB: the listing's row saves at once, its
     * Closed record fails, and the rollback doesn't finish. Nothing more may run,
     * so Luster can't check or undo the row; the error names the listing and what
     * to do first, and doing that never ends with a second copy of it.
     *
     * @dataProvider leftOpenListingProvider
     */
    public function testAListingASaveThatCantBeUndoneMayHaveLeftIsNamed(bool $inLuster, string $url, string $advice): void
    {
        $src = $this->source();
        $tgt = $this->makeOwnedTarget($this->makeTracingPdo());
        $this->listing($src, 1, 'Moon', $url, null, $inLuster ? 'current' : 'closed');
        if ($inLuster) {
            $this->runImport($src, $tgt, $this->overwrite());
            $src->exec("UPDATE listings SET status = 'closed'");
        }

        $tgt->failOn       = '/INSERT INTO `lstr_listing_lifecycle`/';
        $tgt->failRollBack = true;
        $result = $this->runImport($src, $tgt, $this->overwrite());
        $tgt->failOn = null;

        self::assertStringEndsWith("), and Luster couldn't finish undoing it, so it couldn't check whether {$advice}",
            $this->resultRow($result, 'Owned Listings')['errors'][0]);
        self::assertStringEndsWith(' Reload the page and do what the errors above say, then import again with Overwrite.',
            $this->resultRow($result, 'Import stopped')['errors'][0]);
        $failed = max(array_keys(array_filter($tgt->statements, static fn(string $sql): bool => str_contains($sql, 'INSERT INTO `lstr_listing_lifecycle`'))));
        self::assertSame([], array_slice($tgt->statements, $failed + 1), 'Nothing ran after it: no check, no undo, no import record');

        $this->rollBackKeepingOwned($tgt);
        self::assertSame(1, (int) $tgt->query('SELECT COUNT(*) FROM owned')->fetchColumn(), 'The listing stayed behind');
        if ($url === '') {
            self::assertSame(0, (int) $tgt->query('SELECT COUNT(*) FROM lstr_listing_external_refs')->fetchColumn(),
                'Nothing would let the next import find it');
            $tgt->exec('DELETE FROM owned WHERE listingid = 1'); // what the error says to do
        }

        $row = $this->resultRow($this->runImport($src, $tgt, $this->overwrite()), 'Owned Listings');
        self::assertSame([], $row['errors']);
        self::assertSame(1, (int) $tgt->query('SELECT COUNT(*) FROM owned')->fetchColumn(), 'One Moon, never two');
        self::assertSame('closed', (string) $tgt->query('SELECT lifecycle_status FROM lstr_listing_lifecycle')->fetchColumn());
    }

    public function testTheDumpCleanUpWaitsWhileASaveIsStillOpen(): void
    {
        $method = new \ReflectionMethod(\Luster\Controllers\Admin\ImportController::class, 'noSaveLeftOpen');
        $method->setAccessible(true);
        $pdo = $this->makeTracingPdo();

        self::assertTrue($method->invoke(null, $pdo), 'Nothing open');
        $pdo->beginTransaction();
        self::assertTrue($method->invoke(null, $pdo), 'Rolled back first');
        self::assertFalse($pdo->inTransaction());

        $pdo->beginTransaction();
        $pdo->failRollBack = true;
        self::assertFalse($method->invoke(null, $pdo), 'Still open: the DROPs would commit it');
        $pdo->failRollBack = false;
        $pdo->rollBack();
    }
}
