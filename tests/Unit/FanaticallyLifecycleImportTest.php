<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\ListingLifecycleService;
use Luster\Repositories\ListingLifecycleRepo;
use Luster\Repositories\ListingRepo;
use Luster\Tests\Unit\Support\FanaticallyImportFixture;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Closed and Adopted Out Fanatically listings come over as real Closed / Adopted
 * Out listings: a lifecycle row with an unknown date (Fanatically keeps none),
 * a reopen state of what an active import would be, and the registry's inactive
 * state. Overwrite keeps what the owner set in Luster, switches the status when
 * Fanatically's changed, and reopens a listing that's active again; Skip leaves
 * it all alone. Without Migration 108 there's nowhere to keep an unknown date,
 * so the import waits before writing anything.
 */
final class FanaticallyLifecycleImportTest extends TestCase
{
    use FanaticallyImportFixture;

    private const MOON = 'https://fans.test/moon';

    /** @return array<string,mixed>|null */
    private function lifecycleRow(PDO $tgt, int $listingId): ?array
    {
        $st = $tgt->prepare('SELECT * FROM lstr_listing_lifecycle WHERE fanlisting_id = :id');
        $st->execute(['id' => $listingId]);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    private function registryStatus(PDO $tgt, int $listingId): int
    {
        $st = $tgt->prepare('SELECT status FROM owned WHERE listingid = :id');
        $st->execute(['id' => $listingId]);
        return (int) $st->fetchColumn();
    }

    /**
     * The import's notes about this listing ("Moon: ..."), without the join
     * approval note every Fanatically import carries.
     *
     * @param list<string> $warnings
     * @return list<string>
     */
    private function moonNotes(array $warnings): array
    {
        return array_values(array_filter($warnings, static fn(string $w): bool => str_starts_with($w, 'Moon:')));
    }

    private function idOf(PDO $tgt, string $title): int
    {
        $st = $tgt->prepare('SELECT listingid FROM owned WHERE title = :t');
        $st->execute(['t' => $title]);
        return (int) $st->fetchColumn();
    }

    private function setSourceStatus(PDO $src, int $id, string $status): void
    {
        $src->prepare('UPDATE listings SET status = :s WHERE id = :id')->execute(['s' => $status, 'id' => $id]);
    }

    /** @return array{0: ListingLifecycleService, 1: ListingLifecycleRepo} */
    private function lifecycle(PDO $tgt): array
    {
        $db   = $this->database($tgt);
        $repo = new ListingLifecycleRepo($db);
        return [new ListingLifecycleService(new ListingRepo($db, $this->config()), $repo), $repo];
    }

    /** Puts the lifecycle table back the way it was before Migration 108 (or 93). */
    private function rollBackLifecycleTable(PDO $tgt, bool $dropIt): void
    {
        $tgt->exec('DROP TABLE lstr_listing_lifecycle');
        if (!$dropIt) {
            $tgt->exec('CREATE TABLE lstr_listing_lifecycle (
                fanlisting_id INTEGER NOT NULL PRIMARY KEY, lifecycle_status TEXT NOT NULL, archived_at TEXT NOT NULL,
                legacy_upcoming INTEGER NOT NULL DEFAULT 0, legacy_trouble INTEGER NOT NULL DEFAULT 0,
                adopted_to_name TEXT NULL, adopted_to_url TEXT NULL, archive_note TEXT NULL)');
        }
    }

    private function overwrite(): \Luster\Import\ImportOptions
    {
        return $this->options(['import_listings' => '1', 'conflict_strategy' => 'overwrite']);
    }

    /**
     * Where each listing shows up, read the way Luster's pages read it
     * (ListingRepo::getFiltered()): title → the lists it's in.
     *
     * @return array<string, list<string>>
     */
    private function shownIn(PDO $tgt): array
    {
        $repo  = new ListingRepo($this->database($tgt), $this->config());
        $shown = [];
        foreach (['current', 'upcoming', 'pending', 'past'] as $list) {
            foreach ($repo->getFiltered($list) as $listing) {
                $shown[$listing->title][] = $list;
            }
        }
        ksort($shown);
        return $shown;
    }

    // -----------------------------------------------------------------------
    // A first import
    // -----------------------------------------------------------------------

    public function testAClosedListingComesOverAsClosedWithAnUnknownDate(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->addSourceListing($src, 1, 'Moon', self::MOON, null, 'closed');

        $row = $this->resultRow($this->runImport($src, $tgt, $this->options(['import_listings' => '1'])), 'Owned Listings');
        self::assertSame([], $row['errors']);
        self::assertSame(1, $row['imported']);

        self::assertSame(0, $this->registryStatus($tgt, 1), 'An archived listing is inactive in the registry');
        $life = $this->lifecycleRow($tgt, 1);
        self::assertNotNull($life);
        self::assertSame('closed', $life['lifecycle_status']);
        self::assertNull($life['archived_at'], 'Fanatically has no closing date, so it is unknown');
        self::assertSame([0, 0], [(int) $life['legacy_upcoming'], (int) $life['legacy_trouble']], 'Reopening makes it Current');
        self::assertNull($life['adopted_to_name']);
        self::assertNull($life['archive_note']);

        [$service, $repo] = $this->lifecycle($tgt);
        $stored = $repo->getByListingId(1);
        self::assertNotNull($stored);
        self::assertNull($stored['archived_at']);
        self::assertSame('', ListingLifecycleRepo::renderFields($stored)['archived_at'], '{archived_at} is blank for an unknown date');

        self::assertTrue($service->reopen(1));
        self::assertSame(2, $this->registryStatus($tgt, 1), 'Reopened, it is Current');
        self::assertNull($this->lifecycleRow($tgt, 1));
    }

    public function testAnAdoptedOutListingComesOverAsAdoptedOutWithNoNewOwner(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->addSourceListing($src, 1, 'Moon', self::MOON, null, 'adopted_out');

        $row = $this->resultRow($this->runImport($src, $tgt, $this->options(['import_listings' => '1'])), 'Owned Listings');
        self::assertSame([], $row['errors']);

        self::assertSame(0, $this->registryStatus($tgt, 1));
        $life = $this->lifecycleRow($tgt, 1);
        self::assertNotNull($life);
        self::assertSame('adopted_out', $life['lifecycle_status']);
        self::assertNull($life['archived_at']);
        self::assertNull($life['adopted_to_name'], 'Fanatically does not record who took it');
        self::assertNull($life['adopted_to_url']);
        self::assertSame([0, 0], [(int) $life['legacy_upcoming'], (int) $life['legacy_trouble']]);
    }

    public function testOnlyClosedAndAdoptedOutListingsAreArchived(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->addSourceListing($src, 1, 'Current', 'https://fans.test/one', null, 'current');
        $this->addSourceListing($src, 2, 'Upcoming', 'https://fans.test/two', null, 'upcoming');
        $this->addSourceListing($src, 3, 'Pending', 'https://fans.test/three', null, 'pending');
        $this->addSourceListing($src, 4, 'Unknown', 'https://fans.test/four', null, 'suspended');

        $row = $this->resultRow($this->runImport($src, $tgt, $this->options(['import_listings' => '1'])), 'Owned Listings');
        self::assertSame([], $row['errors']);
        self::assertSame(4, $row['imported']);
        self::assertSame(0, (int) $tgt->query('SELECT COUNT(*) FROM lstr_listing_lifecycle')->fetchColumn());
    }

    public function testAClosedListingWithNoAddressReopensInactive(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->addSourceListing($src, 1, 'Moon', '', null, 'closed');

        $this->runImport($src, $tgt, $this->options(['import_listings' => '1']));

        $life = $this->lifecycleRow($tgt, 1);
        self::assertNotNull($life);
        self::assertSame([0, 1], [(int) $life['legacy_upcoming'], (int) $life['legacy_trouble']],
            'A listing with no address is imported inactive, so that is what reopening gives');

        [$service] = $this->lifecycle($tgt);
        self::assertTrue($service->reopen(1));
        self::assertSame(0, $this->registryStatus($tgt, 1));
    }

    public function testThePreviewSaysClosedAndAdoptedOutListingsComeOverThatWay(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->addSourceListing($src, 1, 'Moon', self::MOON, null, 'closed');
        $this->addSourceListing($src, 2, 'Sun', 'https://fans.test/sun', null, 'adopted_out');

        $warnings = $this->previewRow($src, $tgt, $this->options(['import_listings' => '1']), 'Owned Listings')['warnings'];
        self::assertSomeWarningContains($warnings, '1 closed listing(s) will come over as Closed');
        self::assertSomeWarningContains($warnings, '1 adopted-out listing(s) will come over as Adopted Out');
        self::assertSomeWarningContains($warnings, 'the date is marked unknown');
        foreach ($warnings as $warning) {
            self::assertStringNotContainsString('imported as inactive; original status', $warning);
            self::assertStringNotContainsString('have no URL, so reopening', $warning, 'Both have an address');
        }

        $this->addSourceListing($src, 3, 'Star', '', null, 'closed');
        $this->addSourceListing($src, 4, 'Comet', '', null, 'current');
        $warnings = $this->previewRow($src, $tgt, $this->options(['import_listings' => '1']), 'Owned Listings')['warnings'];
        self::assertSomeWarningContains($warnings,
            '1 of the closed or adopted-out listing(s) have no URL, so reopening one in Luster makes it inactive instead of Current.');
    }

    // -----------------------------------------------------------------------
    // Before Migration 108 / 93
    // -----------------------------------------------------------------------

    /** @return array<string, array{0: bool}> */
    public static function oldLifecycleTableProvider(): array
    {
        return ['before Migration 108' => [false], 'before Migration 93' => [true]];
    }

    /** @dataProvider oldLifecycleTableProvider */
    public function testTheImportWaitsWhenLusterCantKeepAnUnknownDate(bool $dropIt): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->rollBackLifecycleTable($tgt, $dropIt);
        $this->addSourceListing($src, 1, 'Sun', 'https://fans.test/sun', null, 'current');
        $this->addSourceListing($src, 2, 'Moon', self::MOON, null, 'closed');
        $this->addSourceMember($src, 1, 'fan@example.com');

        $opts    = $this->options(['import_listings' => '1', 'import_members' => '1']);
        $preview = (new \Luster\Import\FanaticallyImportSource())->preview($src, $tgt, $opts);
        self::assertSame('Database update', $preview->rows[0]['type']);
        self::assertSomeWarningContains($preview->rows[0]['warnings'], "Can't import");
        self::assertSomeWarningContains($preview->rows[0]['warnings'], 'Migration 108');

        $result = $this->runImport($src, $tgt, $opts);
        self::assertSame('Database update', $result->rows[0]['type']);
        self::assertStringContainsString('Migration 108', $result->rows[0]['errors'][0]);
        self::assertStringContainsString('Nothing was imported', $result->rows[0]['errors'][0]);

        self::assertSame(0, (int) $tgt->query('SELECT COUNT(*) FROM owned')->fetchColumn());
        self::assertSame(0, (int) $tgt->query('SELECT COUNT(*) FROM lstr_listing_external_refs')->fetchColumn());
        self::assertSame(0, (int) $tgt->query('SELECT COUNT(*) FROM lstr_fanlisting_settings')->fetchColumn());
        self::assertSame(0, (int) $tgt->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'sun'")->fetchColumn(),
            'No member table is made either');
    }

    public function testALifecycleTableThatCantBeCheckedStopsTheImport(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget($this->makeTracingPdo());
        $this->addSourceListing($src, 1, 'Moon', self::MOON, null, 'closed');
        $opts = $this->options(['import_listings' => '1']);

        $tgt->failOn    = '/PRAGMA table_info\(`lstr_listing_lifecycle`\)/';
        $tgt->failTimes = 1;
        self::assertSomeWarningContains($this->previewRow($src, $tgt, $opts, 'Database')['warnings'], "couldn't read part of its own database");
        $tgt->failTimes = 1;
        $errors = $this->resultRow($this->runImport($src, $tgt, $opts), 'Database')['errors'];
        $tgt->failOn = null;

        self::assertSomeWarningContains($errors, 'Nothing was imported.');
        self::assertSame(0, (int) $tgt->query('SELECT COUNT(*) FROM owned')->fetchColumn());

        $row = $this->resultRow($this->runImport($src, $tgt, $opts), 'Owned Listings');
        self::assertSame([], $row['errors'], 'Trying again works');
        self::assertSame('closed', $this->lifecycleRow($tgt, 1)['lifecycle_status']);
    }

    /** @dataProvider oldLifecycleTableProvider */
    public function testWithNothingArchivedTheImportDoesNotWait(bool $dropIt): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->rollBackLifecycleTable($tgt, $dropIt);
        $this->addSourceListing($src, 1, 'Sun', 'https://fans.test/sun', null, 'current');
        $this->addSourceListing($src, 2, 'Moon', self::MOON, null, 'upcoming');

        $warnings = $this->previewWarnings($src, $tgt, $this->options(['import_listings' => '1']));
        foreach ($warnings as $warning) {
            self::assertStringNotContainsString('Migration 108', $warning);
        }
        $row = $this->resultRow($this->runImport($src, $tgt, $this->options(['import_listings' => '1'])), 'Owned Listings');
        self::assertSame([], $row['errors']);
        self::assertSame(2, $row['imported']);
    }

    // -----------------------------------------------------------------------
    // Importing again
    // -----------------------------------------------------------------------

    public function testOverwriteKeepsTheOwnersDateNoteAndReopenState(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->addSourceListing($src, 1, 'Moon', self::MOON, null, 'closed');
        $this->runImport($src, $tgt, $this->options(['import_listings' => '1']));

        $tgt->exec("UPDATE lstr_listing_lifecycle SET archived_at = '2021-03-04 00:00:00', archive_note = 'Bye!', legacy_upcoming = 1");
        $before = $this->lifecycleRow($tgt, 1);

        $row = $this->resultRow($this->runImport($src, $tgt, $this->overwrite()), 'Owned Listings');
        self::assertSame([], $row['errors']);
        self::assertSame([], $this->moonNotes($row['warnings']), 'Nothing changed, so there is nothing to say');
        self::assertSame($before, $this->lifecycleRow($tgt, 1));
        self::assertSame(0, $this->registryStatus($tgt, 1));
    }

    public function testOverwriteSwitchesClosedToAdoptedOutKeepingTheDateAndNote(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->addSourceListing($src, 1, 'Moon', self::MOON, null, 'closed');
        $this->runImport($src, $tgt, $this->options(['import_listings' => '1']));
        $tgt->exec("UPDATE lstr_listing_lifecycle SET archived_at = '2021-03-04 00:00:00', archive_note = 'Bye!'");

        $this->setSourceStatus($src, 1, 'adopted_out');
        $row = $this->resultRow($this->runImport($src, $tgt, $this->overwrite()), 'Owned Listings');
        self::assertSame([], $row['errors']);
        self::assertSame(["Moon: now Adopted Out instead of Closed, to match Fanatically. Its Closed On date (2021-03-04) is kept as its Adopted On date; you can change it on the listing's edit page."],
            $this->moonNotes($row['warnings']));

        $life = $this->lifecycleRow($tgt, 1);
        self::assertSame('adopted_out', $life['lifecycle_status']);
        self::assertSame('2021-03-04 00:00:00', $life['archived_at']);
        self::assertSame('Bye!', $life['archive_note']);
    }

    public function testBecomingClosedDropsTheNewOwner(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->addSourceListing($src, 1, 'Moon', self::MOON, null, 'adopted_out');
        $this->runImport($src, $tgt, $this->options(['import_listings' => '1']));
        $tgt->exec("UPDATE lstr_listing_lifecycle SET adopted_to_name = 'Kai', adopted_to_url = 'https://kai.test/', archive_note = 'Bye!'");

        $this->setSourceStatus($src, 1, 'closed');
        $row = $this->resultRow($this->runImport($src, $tgt, $this->overwrite()), 'Owned Listings');
        self::assertSame([], $row['errors']);
        self::assertSame(['Moon: now Closed instead of Adopted Out, to match Fanatically. Its date is still unknown.'],
            $this->moonNotes($row['warnings']));

        $life = $this->lifecycleRow($tgt, 1);
        self::assertSame('closed', $life['lifecycle_status']);
        self::assertNull($life['adopted_to_name']);
        self::assertNull($life['adopted_to_url']);
        self::assertSame('Bye!', $life['archive_note']);
        self::assertNull($life['archived_at']);
    }

    public function testOverwriteReopensAListingThatIsActiveInFanatically(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->addSourceListing($src, 1, 'Moon', self::MOON, null, 'closed');
        $this->runImport($src, $tgt, $this->options(['import_listings' => '1']));

        $this->setSourceStatus($src, 1, 'upcoming');
        $row = $this->resultRow($this->runImport($src, $tgt, $this->overwrite()), 'Owned Listings');
        self::assertSame([], $row['errors']);
        self::assertSomeWarningContains($row['warnings'], "Moon: reopened, because it isn't closed or adopted out in Fanatically.");
        self::assertNull($this->lifecycleRow($tgt, 1));
        self::assertSame(1, $this->registryStatus($tgt, 1), 'Upcoming, as in Fanatically');
        self::assertSame(['Moon' => ['upcoming']], $this->shownIn($tgt));
    }

    public function testPendingInFanaticallyReopensAsPending(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->addSourceListing($src, 1, 'Moon', self::MOON, null, 'closed');
        $this->runImport($src, $tgt, $this->options(['import_listings' => '1']));

        $this->setSourceStatus($src, 1, 'pending');
        $row = $this->resultRow($this->runImport($src, $tgt, $this->overwrite()), 'Owned Listings');
        self::assertSame([], $row['errors']);
        self::assertSomeWarningContains($row['warnings'], 'Moon: reopened');
        self::assertNull($this->lifecycleRow($tgt, 1));
        self::assertSame(['Moon' => ['pending']], $this->shownIn($tgt));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function unknownStatusProvider(): array
    {
        return [
            'a status Luster does not know' => ['suspended', 'Its status in Fanatically is "suspended", which Luster doesn\'t know.'],
            'no status at all'              => ['', "Its status in Fanatically is blank, which Luster doesn't know."],
        ];
    }

    /** @dataProvider unknownStatusProvider */
    public function testAStatusLusterDoesNotKnowKeepsTheListingArchivedWithEverythingOnIt(string $status, string $why): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->addSourceListing($src, 1, 'Moon', self::MOON, null, 'adopted_out');
        $this->runImport($src, $tgt, $this->options(['import_listings' => '1']));
        $tgt->exec("UPDATE lstr_listing_lifecycle SET archived_at = '2021-03-04 00:00:00', archive_note = 'Bye!',
                    adopted_to_name = 'Kai', adopted_to_url = 'https://kai.test/', legacy_upcoming = 1");
        $before = $this->lifecycleRow($tgt, 1);

        $this->setSourceStatus($src, 1, $status);
        $row = $this->resultRow($this->runImport($src, $tgt, $this->overwrite()), 'Owned Listings');
        self::assertSame([], $row['errors']);
        self::assertSame(["Moon: stays Adopted Out, with its date and note as they were. {$why}"], $this->moonNotes($row['warnings']));

        self::assertSame($before, $this->lifecycleRow($tgt, 1), 'Date, note, new owner and reopen state all kept');
        self::assertSame(0, $this->registryStatus($tgt, 1), 'Still inactive in the registry');
        self::assertSame(['Moon' => ['past']], $this->shownIn($tgt));
    }

    public function testSkipLeavesAnArchivedListingArchived(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->addSourceListing($src, 1, 'Moon', self::MOON, null, 'closed');
        $this->runImport($src, $tgt, $this->options(['import_listings' => '1']));
        $before = $this->lifecycleRow($tgt, 1);

        $this->setSourceStatus($src, 1, 'current');
        $row = $this->resultRow($this->runImport($src, $tgt, $this->options(['import_listings' => '1'])), 'Owned Listings');
        self::assertSame([], $row['errors']);
        self::assertSame(1, $row['skipped']);
        self::assertSame($before, $this->lifecycleRow($tgt, 1));
        self::assertSame(0, $this->registryStatus($tgt, 1));
    }

    public function testSkipDoesNotArchiveAListingThatIsActiveInLuster(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $tgt->exec("INSERT INTO owned (dbtable, title, subject, url, status) VALUES ('moon', 'Moon', 'Moon', '" . self::MOON . "', 2)");
        $this->addSourceListing($src, 1, 'Moon', self::MOON, null, 'closed');

        $row = $this->resultRow($this->runImport($src, $tgt, $this->options(['import_listings' => '1'])), 'Owned Listings');
        self::assertSame([], $row['errors']);
        self::assertSame(1, $row['skipped']);
        self::assertNull($this->lifecycleRow($tgt, 1));
        self::assertSame(2, $this->registryStatus($tgt, 1));
    }

    public function testOverwriteArchivesAListingAlreadyInLusterMatchedByItsAddress(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $tgt->exec("INSERT INTO owned (dbtable, title, subject, url, status) VALUES ('moon', 'Moon', 'Moon', '" . self::MOON . "', 1)");
        $this->addSourceListing($src, 1, 'Moon', self::MOON, null, 'adopted_out');

        $row = $this->resultRow($this->runImport($src, $tgt, $this->overwrite()), 'Owned Listings');
        self::assertSame([], $row['errors']);
        self::assertSame(1, (int) $tgt->query('SELECT COUNT(*) FROM owned')->fetchColumn());

        $life = $this->lifecycleRow($tgt, 1);
        self::assertNotNull($life);
        self::assertSame('adopted_out', $life['lifecycle_status']);
        self::assertNull($life['archived_at']);
        self::assertSame([0, 0], [(int) $life['legacy_upcoming'], (int) $life['legacy_trouble']]);
        self::assertSame(0, $this->registryStatus($tgt, 1));
    }

    // -----------------------------------------------------------------------
    // When the lifecycle write fails
    // -----------------------------------------------------------------------

    /** @return array<string, array{0: bool}> */
    public static function rollbackProvider(): array
    {
        return ['a table that rolls back (InnoDB)' => [true], 'a table that cannot (MyISAM)' => [false]];
    }

    /** @dataProvider rollbackProvider */
    public function testAFailedArchiveOfANewListingSavesNothingAndStopsTheListings(bool $rollsBack): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget($this->makeTracingPdo());
        $tgt->noTransactions = !$rollsBack;
        $this->addSourceListing($src, 1, 'Moon', self::MOON, null, 'closed');
        $this->addSourceListing($src, 2, 'Sun', 'https://fans.test/sun', null, 'current');

        $tgt->failOn    = '/INSERT INTO `lstr_listing_lifecycle`/';
        $tgt->failTimes = 1;
        $row = $this->resultRow($this->runImport($src, $tgt, $this->options(['import_listings' => '1'])), 'Owned Listings');
        $tgt->failOn = null;

        self::assertCount(2, $row['errors']);
        self::assertStringStartsWith("Listing 'Moon': Luster couldn't mark it Closed (", $row['errors'][0]);
        self::assertStringEndsWith('), so nothing about this listing was saved.', $row['errors'][0]);
        self::assertStringStartsWith("The 1 listing(s) after it weren't imported", $row['errors'][1]);
        self::assertSame(0, $row['imported']);

        // Not a plain inactive listing without its archive record (open to joins):
        // nothing at all, and nothing recorded as mapped.
        self::assertSame(0, (int) $tgt->query('SELECT COUNT(*) FROM owned')->fetchColumn());
        self::assertSame(0, (int) $tgt->query('SELECT COUNT(*) FROM lstr_listing_lifecycle')->fetchColumn());
        self::assertSame(0, (int) $tgt->query('SELECT COUNT(*) FROM lstr_listing_external_refs')->fetchColumn());
        self::assertSame(0, (int) $tgt->query('SELECT COUNT(*) FROM lstr_fanlisting_settings')->fetchColumn());
        self::assertSame([], $this->shownIn($tgt));

        $row = $this->resultRow($this->runImport($src, $tgt, $this->options(['import_listings' => '1'])), 'Owned Listings');
        self::assertSame([], $row['errors'], 'Trying again works');
        self::assertSame(2, $row['imported']);
        self::assertSame(['Moon' => ['past'], 'Sun' => ['current']], $this->shownIn($tgt));
        self::assertNull($this->lifecycleRow($tgt, $this->idOf($tgt, 'Moon'))['archived_at']);
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: bool}> */
    public static function failedChangeProvider(): array
    {
        $read  = '/SELECT lifecycle_status, archived_at FROM `lstr_listing_lifecycle`/';
        $cases = [
            'switch'         => ['adopted_out', '/UPDATE `lstr_listing_lifecycle`/', "Luster couldn't change it to Adopted Out ("],
            'reopen'         => ['current', '/DELETE FROM `lstr_listing_lifecycle`/', "Luster couldn't reopen it ("],
            'read, archived' => ['adopted_out', $read, "Luster couldn't check whether it's closed or adopted out ("],
            'read, active'   => ['current', $read, "Luster couldn't check whether it's closed or adopted out ("],
        ];
        $out = [];
        foreach ($cases as $label => $case) {
            $out["{$label}, InnoDB"] = [...$case, true];
            $out["{$label}, MyISAM"] = [...$case, false];
        }
        return $out;
    }

    /** @dataProvider failedChangeProvider */
    public function testAFailedChangeLeavesTheListingExactlyAsItWas(string $newStatus, string $failOn, string $error, bool $rollsBack): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget($this->makeTracingPdo());
        $tgt->noTransactions = !$rollsBack;
        $this->addSourceListing($src, 1, 'Moon', self::MOON, null, 'closed');
        $this->addSourceListing($src, 2, 'Sun', 'https://fans.test/sun', null, 'current');
        $this->runImport($src, $tgt, $this->options(['import_listings' => '1']));
        $tgt->exec("UPDATE lstr_listing_lifecycle SET archived_at = '2021-03-04 00:00:00', archive_note = 'Bye!'");
        $before = $this->lifecycleRow($tgt, 1);

        // Fanatically now has a new title for both, so a half-saved row would show.
        $src->exec("UPDATE listings SET title = title || ' II'");
        $this->setSourceStatus($src, 1, $newStatus);
        $tgt->failOn = $failOn;
        $row = $this->resultRow($this->runImport($src, $tgt, $this->overwrite()), 'Owned Listings');
        $tgt->failOn = null;

        self::assertCount(2, $row['errors']);
        self::assertStringStartsWith("Listing 'Moon II': {$error}", $row['errors'][0]);
        self::assertStringEndsWith('), so nothing about this listing was saved.', $row['errors'][0]);
        self::assertStringStartsWith("The 1 listing(s) after it weren't imported", $row['errors'][1]);
        self::assertSame([], $this->moonNotes($row['warnings']), 'No "now ..." or "reopened" note for a change that did not happen');

        self::assertSame($before, $this->lifecycleRow($tgt, 1));
        self::assertSame(['Moon', 0], [(string) $tgt->query('SELECT title FROM owned WHERE listingid = 1')->fetchColumn(), $this->registryStatus($tgt, 1)],
            'The registry row was rolled back with it');
        self::assertSame(['Moon' => ['past'], 'Sun' => ['current']], $this->shownIn($tgt), 'In Past only, never Current and Past at once');

        $row = $this->resultRow($this->runImport($src, $tgt, $this->overwrite()), 'Owned Listings');
        self::assertSame([], $row['errors'], 'The next Overwrite finishes it');
        if ($newStatus === 'current') {
            self::assertNull($this->lifecycleRow($tgt, 1));
            self::assertSame(['Moon II' => ['current'], 'Sun II' => ['current']], $this->shownIn($tgt));
        } else {
            self::assertSame($newStatus, $this->lifecycleRow($tgt, 1)['lifecycle_status']);
            self::assertSame('Bye!', $this->lifecycleRow($tgt, 1)['archive_note']);
            self::assertSame(['Moon II' => ['past'], 'Sun II' => ['current']], $this->shownIn($tgt));
        }
    }

    public function testOnATableThatRollsBackTheRollbackIsAllItTakes(): void
    {
        // The writes that would put the row back by hand fail too, but with a real
        // rollback there's nothing for them to do, so the listing is untouched.
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget($this->makeTracingPdo());
        $this->addSourceListing($src, 1, 'Moon', self::MOON, null, 'closed');
        $byHand = '|DELETE FROM `owned` WHERE `listingid` = :id|UPDATE `owned` SET `title` = :c0';

        $tgt->failOn = '/INSERT INTO `lstr_listing_lifecycle`' . $byHand . '/';
        $row = $this->resultRow($this->runImport($src, $tgt, $this->options(['import_listings' => '1'])), 'Owned Listings');
        $tgt->failOn = null;
        self::assertStringEndsWith('), so nothing about this listing was saved.', $row['errors'][0]);
        self::assertSame([], $this->shownIn($tgt));

        $this->runImport($src, $tgt, $this->options(['import_listings' => '1']));
        $src->exec("UPDATE listings SET title = 'Moon II', status = 'current'");
        $tgt->failOn = '/DELETE FROM `lstr_listing_lifecycle`' . $byHand . '/';
        $row = $this->resultRow($this->runImport($src, $tgt, $this->overwrite()), 'Owned Listings');
        $tgt->failOn = null;
        self::assertStringEndsWith('), so nothing about this listing was saved.', $row['errors'][0]);
        self::assertSame(['Moon' => ['past']], $this->shownIn($tgt));
    }

    // On a MyISAM `owned`, when putting the row back fails as well, the error says
    // exactly how the listing was left, and the next Overwrite finishes it.

    public function testANewListingThatCantBeRemovedAgainIsReportedAsItReallyIs(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget($this->makeTracingPdo());
        $tgt->noTransactions = true;
        $this->addSourceListing($src, 1, 'Moon', self::MOON, null, 'closed');

        $tgt->failOn = '/INSERT INTO `lstr_listing_lifecycle`|DELETE FROM `owned` WHERE `listingid` = :id/';
        $row = $this->resultRow($this->runImport($src, $tgt, $this->options(['import_listings' => '1'])), 'Owned Listings');
        $tgt->failOn = null;

        self::assertCount(1, $row['errors']);
        self::assertStringStartsWith("Listing 'Moon': Luster couldn't mark it Closed (", $row['errors'][0]);
        self::assertStringContainsString("), and Luster couldn't remove the new listing again (", $row['errors'][0]);
        self::assertStringEndsWith("). It's saved as listing #1, which shows as Pending. Import again with Overwrite to finish it.", $row['errors'][0]);
        self::assertSame(['Moon' => ['pending']], $this->shownIn($tgt), 'What the error says');

        $row = $this->resultRow($this->runImport($src, $tgt, $this->overwrite()), 'Owned Listings');
        self::assertSame([], $row['errors']);
        self::assertSame(['Moon' => ['past']], $this->shownIn($tgt));
    }

    public function testANewCurrentListingThatCantBeRemovedAgainIsReportedAsCurrent(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget($this->makeTracingPdo());
        $tgt->noTransactions = true;
        $this->addSourceListing($src, 1, 'Moon', self::MOON, null, 'current');

        $tgt->failOn = '/SELECT lifecycle_status, archived_at FROM `lstr_listing_lifecycle`|DELETE FROM `owned` WHERE `listingid` = :id/';
        $row = $this->resultRow($this->runImport($src, $tgt, $this->options(['import_listings' => '1'])), 'Owned Listings');
        $tgt->failOn = null;

        self::assertStringStartsWith("Listing 'Moon': Luster couldn't check whether it's closed or adopted out (", $row['errors'][0]);
        self::assertStringEndsWith("). It's saved as listing #1, which shows as Current. Import again with Overwrite to finish it.", $row['errors'][0]);
        self::assertSame(['Moon' => ['current']], $this->shownIn($tgt));
    }

    public function testAnOverwriteThatCantBePutBackIsReportedAsItReallyIs(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget($this->makeTracingPdo());
        $tgt->noTransactions = true;
        $this->addSourceListing($src, 1, 'Moon', self::MOON, null, 'closed');
        $this->runImport($src, $tgt, $this->options(['import_listings' => '1']));

        $src->exec("UPDATE listings SET title = 'Moon II', status = 'current'");
        $tgt->failOn = '/DELETE FROM `lstr_listing_lifecycle`|UPDATE `owned` SET `title` = :c0/';
        $row = $this->resultRow($this->runImport($src, $tgt, $this->overwrite()), 'Owned Listings');
        $tgt->failOn = null;

        self::assertCount(1, $row['errors']);
        self::assertStringStartsWith("Listing 'Moon II': Luster couldn't reopen it (", $row['errors'][0]);
        self::assertStringContainsString("), and Luster couldn't put listing #1 back the way it was (", $row['errors'][0]);
        self::assertStringEndsWith('). It has its new details from Fanatically and shows as both Current and Closed. Import again with Overwrite to finish it.', $row['errors'][0]);
        self::assertSame(['Moon II' => ['current', 'past']], $this->shownIn($tgt), 'What the error says');

        $row = $this->resultRow($this->runImport($src, $tgt, $this->overwrite()), 'Owned Listings');
        self::assertSame([], $row['errors']);
        self::assertSame(['Moon II' => ['current']], $this->shownIn($tgt));
    }

    public function testANewListingWithNoAddressThatStaysBehindIsFoundAgainNotCopied(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget($this->makeTracingPdo());
        $tgt->noTransactions = true;
        $this->addSourceListing($src, 1, 'Moon', '', null, 'closed');

        $tgt->failOn = '/INSERT INTO `lstr_listing_lifecycle`|DELETE FROM `owned` WHERE `listingid` = :id/';
        $row = $this->resultRow($this->runImport($src, $tgt, $this->options(['import_listings' => '1'])), 'Owned Listings');
        $tgt->failOn = null;
        self::assertStringEndsWith("It's saved as listing #1, which shows as Pending. Import again with Overwrite to finish it.", $row['errors'][0]);
        self::assertSame('1', (string) $tgt->query("SELECT listing_id FROM lstr_listing_external_refs WHERE source = 'fanatically' AND external_id = '1'")->fetchColumn(),
            'The record that lets the next import find it');

        $row = $this->resultRow($this->runImport($src, $tgt, $this->overwrite()), 'Owned Listings');
        self::assertSame([], $row['errors']);
        self::assertSame(1, (int) $tgt->query('SELECT COUNT(*) FROM owned')->fetchColumn(), 'Found again, not added a second time');
        self::assertSame(['Moon' => ['past']], $this->shownIn($tgt));
    }

    /** @return array<string, array{0: string}> */
    public static function unrecordedProvider(): array
    {
        return [
            'the record fails'              => ['REPLACE INTO lstr_listing_external_refs'],
            'the record can\'t be read back' => ["SELECT listing_id FROM lstr_listing_external_refs WHERE source = 'fanatically'"],
        ];
    }

    /** @dataProvider unrecordedProvider */
    public function testANewListingWithNoAddressThatCantBeFoundAgainIsNamed(string $recordFails): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget($this->makeTracingPdo());
        $tgt->noTransactions = true;
        $this->addSourceListing($src, 1, 'Moon', '', null, 'closed');

        $tgt->failOn = '/INSERT INTO `lstr_listing_lifecycle`|DELETE FROM `owned` WHERE `listingid` = :id|' . preg_quote($recordFails, '/') . '/';
        $row = $this->resultRow($this->runImport($src, $tgt, $this->options(['import_listings' => '1'])), 'Owned Listings');
        $tgt->failOn = null;

        self::assertStringEndsWith("It's saved as listing #1, which shows as Pending. Luster couldn't record which Fanatically listing it came from either, so importing again would add it a second time: delete listing #1 first, then import again.", $row['errors'][0]);
        self::assertStringNotContainsString('Import again with Overwrite to finish it', $row['errors'][0]);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function uncheckedNewListingProvider(): array
    {
        return [
            'with an address' => [self::MOON, ' Import again with Overwrite to finish it.'],
            'with no address' => ['', ' If listing #1 is on your Listings page, delete it before importing again, or the import will add it a second time.'],
        ];
    }

    /** @dataProvider uncheckedNewListingProvider */
    public function testANewListingThatCantBeCheckedAfterwardsSaysSo(string $url, string $advice): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget($this->makeTracingPdo()); // rolls back
        $this->addSourceListing($src, 1, 'Moon', $url, null, 'closed');

        $tgt->failOn = '/INSERT INTO `lstr_listing_lifecycle`|SELECT `title`, `subject`/';
        $row = $this->resultRow($this->runImport($src, $tgt, $this->options(['import_listings' => '1'])), 'Owned Listings');
        $tgt->failOn = null;

        self::assertStringContainsString("), and Luster couldn't check whether the new listing (#1) was removed again (", $row['errors'][0]);
        self::assertStringEndsWith(").{$advice}", $row['errors'][0]);
        self::assertStringNotContainsString('saved as', $row['errors'][0], 'It never claims a state it did not see');
        self::assertSame([], $this->shownIn($tgt), 'The rollback did remove it');
    }

    /** @return array<string, array{0: bool, 1: int}> */
    public static function uncheckedOverwriteProvider(): array
    {
        // Reads of the listing's row: the 1st is before writing, then the checks.
        return [
            // InnoDB: the check after the rollback fails.
            'rolled back, then not checked'      => [true, 2],
            // MyISAM: the first check works, the undo by hand works, and the
            // check after it fails.
            'put back by hand, then not checked' => [false, 3],
        ];
    }

    /** @dataProvider uncheckedOverwriteProvider */
    public function testAnOverwriteThatCantBeCheckedAfterwardsSaysSo(bool $rollsBack, int $failingRead): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget($this->makeTracingPdo());
        $tgt->noTransactions = !$rollsBack;
        $this->addSourceListing($src, 1, 'Moon', self::MOON, null, 'closed');
        $this->runImport($src, $tgt, $this->options(['import_listings' => '1']));

        $this->setSourceStatus($src, 1, 'current');
        $reads = 0;
        $tgt->failWhen = static function (string $sql) use (&$reads, $failingRead): bool {
            if (str_contains($sql, 'DELETE FROM `lstr_listing_lifecycle`')) {
                return true;
            }
            return str_contains($sql, 'SELECT `title`, `subject`') && ++$reads === $failingRead;
        };
        $row = $this->resultRow($this->runImport($src, $tgt, $this->overwrite()), 'Owned Listings');
        $tgt->failWhen = null;
        self::assertSame($failingRead, $reads, 'Got as far as the read meant to fail');

        self::assertStringStartsWith("Listing 'Moon': Luster couldn't reopen it (", $row['errors'][0]);
        self::assertStringEndsWith("Check it on your Listings page, then import again with Overwrite to finish it.", $row['errors'][0]);
        self::assertStringContainsString("), and Luster couldn't check whether listing #1 was put back the way it was (", $row['errors'][0]);
        self::assertSame(['Moon' => ['past']], $this->shownIn($tgt), 'It was put back, though Luster could not see it');
    }
}
