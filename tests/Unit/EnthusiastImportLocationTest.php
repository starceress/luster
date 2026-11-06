<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Import\EnthusiastListingPlan;
use Luster\Import\MemberConfirmationChanged;
use Luster\Tests\Unit\Support\EnthusiastImportFixture;
use Luster\Tests\Unit\Support\TracingPdo;
use PHPUnit\Framework\TestCase;

/**
 * Where a listing's members really are, the one-run confirmation for moved copies,
 * no promises about deleting the old database, and a kept listing always having a
 * member table.
 */
final class EnthusiastImportLocationTest extends TestCase
{
    use EnthusiastImportFixture;

    private const SCOPE = ['import_owned_listings' => '1', 'import_members' => '1'];
    private const LIVE  = ['_source_host' => 'localhost'];

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: bool, 5: bool}> */
    public static function locations(): array
    {
        // Saved server, saved database, typed host, connected database, from a file, is it here.
        return [
            'nothing saved'                    => ['', '', 'localhost', 'enth', false, true],
            'nothing saved, remote'            => ['', '', 'db.far.test', 'enth', false, true],
            'nothing saved, file'              => ['', '', '', '', true, true],
            'exact match'                      => ['localhost', 'enth', 'localhost', 'enth', false, true],
            'capitals in the server'           => ['LOCALHOST', 'enth', 'localhost', 'enth', false, true],
            'spaces around it'                 => [' localhost ', ' enth ', 'localhost', 'enth', false, true],
            'another name for this machine'    => ['127.0.0.1', 'enth', 'localhost', 'enth', false, true],
            'and another'                      => ['::1', 'enth', '127.0.0.1', 'enth', false, true],
            'blank server means this machine'  => ['', 'enth', 'localhost', 'enth', false, true],
            'a hosted name that matches'       => ['mysql.host.test', 'enth', 'MySQL.Host.test', 'enth', false, true],
            'a hosted name that does not'      => ['mysql.host.test', 'enth', 'localhost', 'enth', false, false],
            'localhost from a remote import'   => ['localhost', 'enth', 'db.far.test', 'enth', false, false],
            '127.0.0.1 from a remote import'   => ['127.0.0.1', 'enth', 'db.far.test', 'enth', false, false],
            'blank server from a remote import' => ['', 'enth', 'db.far.test', 'enth', false, false],
            'host not known'                   => ['localhost', 'enth', '', 'enth', false, false],
            'another database'                 => ['localhost', 'other', 'localhost', 'enth', false, false],
            'database capitals differ'         => ['localhost', 'ENTH', 'localhost', 'enth', false, false],
            'only a server saved'              => ['localhost', '', 'localhost', 'enth', false, false],
            'connected database not known'     => ['localhost', 'enth', 'localhost', '', false, false],
            'a file names anything'            => ['localhost', 'enth', '', 'enth', true, false],
            'a file, even with a host and a match' => ['localhost', 'enth', 'localhost', 'enth', true, false],
        ];
    }

    /** @dataProvider locations */
    public function testWhereMembersAreCountsAsHere(string $server, string $database, string $host, string $connected, bool $file, bool $here): void
    {
        self::assertSame($here, EnthusiastListingPlan::locationIsHere($server, $database, $host, $connected, $file));
    }

    /** A source whose only listing names another server, with a same-named table where it's imported from. */
    private function elsewhere(string $server = 'db.far.test', string $database = 'far'): array
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->connectedAs($src, 'enth');
        $this->addSourceListing($src, 1, 'moon', 'http://a.test/moon', [
            'dbserver' => $server, 'dbdatabase' => $database, 'dbuser' => 'storeduser', 'dbpassword' => 'storedsecret',
        ]);
        $this->addFan($src, 'moon', ['email' => 'stale@example.test', 'added' => '2020-01-01']);
        return [$src, $tgt];
    }

    public function testAnUnconfirmedListingsMembersAreNeverReadOrCopied(): void
    {
        [$src, $tgt] = $this->elsewhere();

        $preview = $this->previewImport($src, $tgt, self::SCOPE + self::LIVE + ['import_settings' => '1']);
        self::assertSame(0, $this->row($preview, 'Members')['found']);

        $src->statements = [];
        $result = $this->runImport($src, $tgt, self::SCOPE + self::LIVE + ['import_settings' => '1']);

        foreach ($src->statements as $sql) {
            self::assertDoesNotMatchRegularExpression('/SELECT\s+(\*|COUNT|MAX)\b.*\bmoon\b/is', $sql);
        }
        self::assertSame(0, $this->row($result, 'Members')['imported']);
        self::assertSame(0, $this->rowCount($tgt, 'moon'));
        self::assertNotFalse($this->ownedRow($tgt, 1), 'the listing itself still comes across');
        self::assertSame(1, $this->rowCount($tgt, 'lstr_fanlisting_settings'));
        self::assertStringContainsString('db.far.test', $this->allText($result));
        self::assertStringContainsString('far', $this->allText($result));
    }

    public function testCodexsCaseARemoteRegistryAndAListingSayingLocalhostIsNotConfirmed(): void
    {
        [$src, $tgt] = $this->elsewhere('localhost', 'enth');

        $result = $this->runImport($src, $tgt, self::SCOPE + ['_source_host' => 'db.registry.test']);

        self::assertSame(0, $this->row($result, 'Members')['imported']);
        self::assertSame(0, $this->rowCount($tgt, 'moon'));
    }

    public function testAMatchingLocationCopiesWithNoConfirmation(): void
    {
        [$src, $tgt] = $this->elsewhere('localhost', 'enth');

        self::assertNull($this->previewImport($src, $tgt, self::SCOPE + self::LIVE)->memberConfirmation);
        $result = $this->runImport($src, $tgt, self::SCOPE + self::LIVE);

        self::assertSame(1, $this->row($result, 'Members')['imported']);
    }

    public function testASavedPasswordOrUserNeverAppearsInANoteOrTheFingerprint(): void
    {
        [$src, $tgt] = $this->elsewhere();

        $preview = $this->previewImport($src, $tgt, self::SCOPE + self::LIVE);
        $result  = $this->runImport($src, $tgt, self::SCOPE + self::LIVE);

        foreach ([$this->allText($preview), $this->allText($result), serialize($preview->memberConfirmation)] as $text) {
            self::assertStringNotContainsString('storedsecret', $text);
            self::assertStringNotContainsString('storeduser', $text);
        }
    }

    public function testConfirmingWhatThePreviewShowedCopiesThem(): void
    {
        [$src, $tgt] = $this->elsewhere();

        $confirmation = $this->previewImport($src, $tgt, self::SCOPE + self::LIVE)->memberConfirmation;
        self::assertNotNull($confirmation);
        self::assertCount(1, $confirmation['listings']);
        self::assertSame(1, $confirmation['listings'][0]['id']);
        self::assertSame('moon', $confirmation['listings'][0]['table']);
        self::assertSame('db.far.test', $confirmation['listings'][0]['server']);
        self::assertSame('far', $confirmation['listings'][0]['database']);

        $result = $this->runImport($src, $tgt, self::SCOPE + self::LIVE + ['_member_consent' => $confirmation['fingerprint']]);

        self::assertSame(1, $this->row($result, 'Members')['imported']);
        self::assertSame(1, $this->rowCount($tgt, 'moon'));
    }

    /** @return array<string, array{0: callable(TracingPdo, TracingPdo): void}> */
    public static function changesAfterThePreview(): array
    {
        return [
            'a new listing appears' => [static function (TracingPdo $src): void {
                $src->exec("INSERT INTO owned (listingid, dbtable, url, title, dbserver, dbdatabase) VALUES (2, 'star', 'http://a.test/star', 'Star', 'db.far.test', 'far')");
                $src->exec("CREATE TABLE star (email TEXT PRIMARY KEY, name TEXT NOT NULL DEFAULT '', pending INTEGER NOT NULL DEFAULT 0)");
            }],
            'the table changes' => [static function (TracingPdo $src): void {
                $src->exec("UPDATE owned SET dbtable = 'moon2' WHERE listingid = 1");
                $src->exec("CREATE TABLE moon2 (email TEXT PRIMARY KEY, name TEXT NOT NULL DEFAULT '', pending INTEGER NOT NULL DEFAULT 0)");
            }],
            'the server changes' => [static function (TracingPdo $src): void {
                $src->exec("UPDATE owned SET dbserver = 'db.elsewhere.test' WHERE listingid = 1");
            }],
            'the database changes' => [static function (TracingPdo $src): void {
                $src->exec("UPDATE owned SET dbdatabase = 'further' WHERE listingid = 1");
            }],
            'the listing changes' => [static function (TracingPdo $src): void {
                $src->exec('UPDATE owned SET listingid = 5 WHERE listingid = 1');
            }],
            'the destination changes' => [static function (TracingPdo $src, TracingPdo $tgt): void {
                $tgt->exec("INSERT INTO owned (listingid, dbtable, url) VALUES (9, 'nine', 'http://a.test/moon')");
            }],
        ];
    }

    /**
     * @dataProvider changesAfterThePreview
     * @param callable(TracingPdo, TracingPdo): void $change
     */
    public function testConsentOnlyCoversWhatThePreviewShowed(callable $change): void
    {
        [$src, $tgt] = $this->elsewhere();
        $fingerprint = $this->previewImport($src, $tgt, self::SCOPE + self::LIVE)->memberConfirmation['fingerprint'];
        $change($src, $tgt);
        $before = $this->rowCount($tgt, 'owned');

        try {
            $this->runImport($src, $tgt, self::SCOPE + self::LIVE + ['_member_consent' => $fingerprint]);
            self::fail('The run should stop.');
        } catch (MemberConfirmationChanged) {
        }

        self::assertSame($before, $this->rowCount($tgt, 'owned'));
        self::assertFalse($this->tableExists($tgt, 'moon'));
    }

    public function testConsentAlsoCoversTheSourceTableOfAListingAlreadyHere(): void
    {
        [$src, $tgt] = $this->elsewhere();
        $this->addTargetListing($tgt, 9, 'nine', 'http://a.test/moon');
        $fingerprint = $this->previewImport($src, $tgt, self::SCOPE + self::LIVE)->memberConfirmation['fingerprint'];
        $src->exec("UPDATE owned SET dbtable = 'moon2' WHERE listingid = 1");
        $this->createSourceMemberTable($src, 'moon2');

        $this->expectException(MemberConfirmationChanged::class);
        $this->runImport($src, $tgt, self::SCOPE + self::LIVE + ['_member_consent' => $fingerprint]);
    }

    public function testConsentOnARunWithNothingToPlanStops(): void
    {
        [$src, $tgt] = $this->elsewhere();

        $this->expectException(MemberConfirmationChanged::class);
        $this->runImport($src, $tgt, ['import_categories' => '1', '_member_consent' => 'abc123']);
    }

    public function testConsentForADifferentSourceStops(): void
    {
        [$src, $tgt] = $this->elsewhere();
        $fingerprint = $this->previewImport($src, $tgt, self::SCOPE + self::LIVE)->memberConfirmation['fingerprint'];

        $this->expectException(MemberConfirmationChanged::class);
        $this->runImport($src, $tgt, self::SCOPE + ['_source_host' => 'db.other.test', '_member_consent' => $fingerprint]);
    }

    /** @return array<string, array{0: string}> */
    public static function badConsent(): array
    {
        return ['a tick with no fingerprint' => ['missing'], 'made up' => ['abc123']];
    }

    /** @dataProvider badConsent */
    public function testATickWithoutTheRightFingerprintStops(string $consent): void
    {
        [$src, $tgt] = $this->elsewhere();

        $this->expectException(MemberConfirmationChanged::class);
        $this->runImport($src, $tgt, self::SCOPE + self::LIVE + ['_member_consent' => $consent]);
    }

    public function testATickWithNothingToConfirmStops(): void
    {
        [$src, $tgt] = $this->elsewhere('localhost', 'enth');

        $this->expectException(MemberConfirmationChanged::class);
        $this->runImport($src, $tgt, self::SCOPE + self::LIVE + ['_member_consent' => 'abc123']);
    }

    public function testNoTickSimplySkipsThoseMembersAndCarriesOn(): void
    {
        [$src, $tgt] = $this->elsewhere();
        $this->addSourceListing($src, 2, 'star', 'http://a.test/star', ['dbserver' => 'localhost', 'dbdatabase' => 'enth']);
        $this->addFan($src, 'star', ['email' => 'here@example.test']);

        $result = $this->runImport($src, $tgt, self::SCOPE + self::LIVE);

        self::assertSame(0, $this->rowCount($tgt, 'moon'));
        self::assertSame(1, $this->rowCount($tgt, 'star'));
        self::assertSame(1, $this->row($result, 'Members')['imported']);
    }

    public function testNothingIsOfferedWhereATickCouldNotHelp(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->connectedAs($src, 'enth');
        $this->addSourceListing($src, 1, 'moon', 'http://a.test/moon', ['dbserver' => 'db.far.test', 'dbdatabase' => 'far'], null);

        $preview = $this->previewImport($src, $tgt, self::SCOPE + self::LIVE);

        self::assertNull($preview->memberConfirmation);
        self::assertStringContainsString('Import Members', $this->allText($preview));
        $result = $this->runImport($src, $tgt, self::SCOPE + self::LIVE);
        self::assertSame([], $this->row($result, 'Members')['errors'], 'no read of a table that is not there');
        self::assertTrue($this->tableExists($tgt, 'moon'), 'the kept listing still has a table');
    }

    public function testNothingIsOfferedWhenMembersIsNotTicked(): void
    {
        [$src, $tgt] = $this->elsewhere();

        self::assertNull($this->previewImport($src, $tgt, ['import_owned_listings' => '1'] + self::LIVE)->memberConfirmation);
    }

    public function testNothingIsOfferedForAListingThatIsNotImported(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->connectedAs($src, 'enth');
        $this->addSourceListing($src, 1, 'moon', '', ['dbserver' => 'db.far.test', 'dbdatabase' => 'far']);

        self::assertNull($this->previewImport($src, $tgt, self::SCOPE + self::LIVE)->memberConfirmation);
    }

    public function testAnUploadedFileAsksEvenWhenTheNameLooksRight(): void
    {
        $db = $this->targetDb();
        $this->connectedAs($db, 'luster');
        $db->exec("CREATE TABLE tmp_category (catid INTEGER PRIMARY KEY, catname TEXT, parent INTEGER DEFAULT 0)");
        $db->exec(str_replace('CREATE TABLE owned', 'CREATE TABLE tmp_owned', self::ownedSourceDdl()));
        $db->prepare("INSERT INTO tmp_owned (listingid, dbserver, dbdatabase, dbtable, url, title) VALUES (1, 'localhost', 'luster', 'moon', 'http://a.test/moon', 'Moon')")->execute();
        $this->createSourceMemberTable($db, 'tmp_moon');
        $this->addFan($db, 'tmp_moon', ['email' => 'one@example.test']);
        $file = ['_dump_table_prefix' => 'tmp_', '_dump_schema_variant' => 'enthusiast_unprefixed'];

        self::assertNotNull($this->previewImport($db, $db, self::SCOPE + $file)->memberConfirmation);

        $without = $this->runImport($db, $db, self::SCOPE + $file);
        self::assertSame(0, $this->row($without, 'Members')['imported']);

        // The listing is here now, so what's being confirmed has changed, and the
        // run needs a fresh look at it.
        $confirmation = $this->previewImport($db, $db, self::SCOPE + $file)->memberConfirmation;
        self::assertNotNull($confirmation);
        $with = $this->runImport($db, $db, self::SCOPE + $file + ['_member_consent' => $confirmation['fingerprint']]);
        self::assertSame(1, $this->row($with, 'Members')['imported']);
        self::assertSame(1, $this->rowCount($db, 'moon'));
    }

    /** A backup restored straight into Luster's database, whose saved name is stale. */
    private function restoredInPlace(bool $withTable = true): TracingPdo
    {
        $db = $this->sharedDb();
        $this->connectedAs($db, 'new_name');
        $this->addSourceListing($db, 4, 'moon', 'http://a.test/moon', ['dbserver' => 'localhost', 'dbdatabase' => 'old_name'], $withTable ? [] : null);
        if ($withTable) {
            $this->addFan($db, 'moon', ['email' => 'one@example.test']);
        }
        return $db;
    }

    public function testARestoredBackupUsedInPlaceIsOfferedTheConfirmation(): void
    {
        $db = $this->restoredInPlace();

        $confirmation = $this->previewImport($db, $db, ['import_members' => '1'] + self::LIVE)->memberConfirmation;

        self::assertNotNull($confirmation);
        self::assertSame(4, $confirmation['listings'][0]['id']);
    }

    public function testWithoutConfirmingAnInPlaceListingDoesNotClaimLusterIsReadingIt(): void
    {
        $db = $this->restoredInPlace();

        $result = $this->runImport($db, $db, ['import_members' => '1'] + self::LIVE);

        self::assertStringNotContainsString('already reading', $this->allText($result));
        self::assertStringContainsString('old_name', $this->allText($result));
    }

    public function testConfirmingAnInPlaceListingOnlyChangesTheWordingAndWritesNothing(): void
    {
        $db = $this->restoredInPlace();
        $fingerprint = $this->previewImport($db, $db, ['import_members' => '1'] + self::LIVE)->memberConfirmation['fingerprint'];
        $db->statements = [];

        $result = $this->runImport($db, $db, ['import_members' => '1'] + self::LIVE + ['_member_consent' => $fingerprint]);

        foreach ($db->statements as $sql) {
            self::assertDoesNotMatchRegularExpression('/^\s*(INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|REPLACE|TRUNCATE)\b/i', $sql);
        }
        self::assertSame(0, $this->row($result, 'Members')['imported']);
        self::assertStringContainsString('already reading', $this->allText($result));
        self::assertStringContainsString('keep this database', $this->allText($result));
    }

    public function testAnInPlaceListingWithNoTableIsNotOfferedAndGetsNoTable(): void
    {
        $db = $this->restoredInPlace(false);

        self::assertNull($this->previewImport($db, $db, ['import_members' => '1'] + self::LIVE)->memberConfirmation);
        $result = $this->runImport($db, $db, self::SCOPE + self::LIVE);

        self::assertFalse($this->tableExists($db, 'moon'), 'nothing is created in the owner\'s own database');
        self::assertStringNotContainsString('already reading', $this->allText($result));
    }

    /** @return array<string, array{0: array<string, string>}> */
    public static function scopes(): array
    {
        return [
            'everything'   => [['import_owned_listings' => '1', 'import_members' => '1', 'import_settings' => '1', 'import_templates' => '1']],
            'members only' => [['import_members' => '1']],
            'members, owned' => [['import_members' => '1', 'import_owned_listings' => '1']],
        ];
    }

    /**
     * @dataProvider scopes
     * @param array<string, string> $scope
     */
    public function testNoPathPromisesTheOldDatabaseCanGo(array $scope): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'moon', 'http://a.test/moon');
        $this->addFan($src, 'moon', ['email' => 'one@example.test']);
        $this->addTargetListing($tgt, 9, 'nine', 'http://a.test/moon');

        $result  = $this->runImport($src, $tgt, $scope);
        $preview = $this->previewImport($src, $tgt, $scope);

        self::assertSame(1, $this->row($result, 'Members')['imported']);
        foreach ([$this->allText($result), $this->allText($preview)] as $text) {
            self::assertStringNotContainsStringIgnoringCase('delete the old', $text);
            self::assertStringNotContainsStringIgnoringCase('safely delete', $text);
        }
        self::assertStringContainsString('Review this report and check your listings before you retire Enthusiast', $this->allText($result));
    }

    public function testReadingInPlaceSaysToKeepTheDatabase(): void
    {
        $db = $this->sharedDb();
        $this->addSourceListing($db, 4, 'moon', 'http://a.test/moon');
        $this->addFan($db, 'moon', ['email' => 'one@example.test']);

        $result = $this->runImport($db, $db, ['import_members' => '1']);

        self::assertStringContainsString('keep this database', $this->allText($result));
        self::assertStringNotContainsString('Review this report', $this->allText($result));
    }

    public function testTheScopePageMakesNoPromiseAboutDeleting(): void
    {
        $page = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/import/import-wizard/options.php');

        self::assertStringNotContainsString('you can delete the old Enthusiast database', $page);
        self::assertStringContainsString('check your listings before you retire Enthusiast', $page);
        self::assertStringContainsString('that database has to stay', $page);
    }

    public function testTheOldExternalDatabaseWarningIsGone(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->connectedAs($src, 'enth');
        $this->addSourceListing($src, 1, 'moon', 'http://a.test/moon', ['dbserver' => 'mysql.host.test', 'dbdatabase' => 'enth']);

        $preview = $this->previewImport($src, $tgt, ['import_settings' => '1', 'import_owned_listings' => '1', '_source_host' => 'mysql.host.test']);

        self::assertStringNotContainsString('external databases', $this->allText($preview));
    }

    public function testOwnedListingsOnlyGivesEachListingAnEmptyTableAndSaysSo(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'moon', 'http://a.test/moon', ['additional' => 'fave'], ['fave']);
        $this->addFan($src, 'moon', ['email' => 'one@example.test']);

        $result = $this->runImport($src, $tgt, ['import_owned_listings' => '1']);

        self::assertTrue($this->tableExists($tgt, 'moon'));
        self::assertSame(0, $this->rowCount($tgt, 'moon'));
        self::assertContains('fave', array_column($tgt->query('PRAGMA table_info(moon)')->fetchAll(), 'name'));
        $text = implode(' ', $this->row($result, 'Owned listings')['warnings']);
        self::assertStringContainsString('without copying', $text);
        self::assertStringContainsString('DROP TABLE IF EXISTS', $text);
    }

    public function testAnExistingListingWithNoTableGetsAnEmptyOne(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'src_moon', 'http://a.test/moon', [], null);
        $this->addTargetListing($tgt, 9, 'nine', 'http://a.test/moon', [], null);

        $this->runImport($src, $tgt, ['import_members' => '1']);

        self::assertTrue($this->tableExists($tgt, 'nine'));
    }

    public function testAnExistingTableIsNeverReplacedOrEmptied(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'src_moon', 'http://a.test/moon', [], null);
        $this->addTargetListing($tgt, 9, 'nine', 'http://a.test/moon');
        $this->addFan($tgt, 'nine', ['email' => 'kept@example.test']);

        $this->runImport($src, $tgt, ['conflict_strategy' => 'overwrite', 'import_owned_listings' => '1']);

        self::assertSame(1, $this->rowCount($tgt, 'nine'));
    }

    public function testAListingWithAnIncompatibleTableIsNotImportedAndTheTableIsLeftAlone(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'src_moon', 'http://a.test/moon', ['title' => 'From source']);
        $this->addFan($src, 'src_moon', ['email' => 'one@example.test']);
        $this->addTargetListing($tgt, 9, 'odd', 'http://a.test/moon', [], null);
        $tgt->exec("CREATE TABLE odd (email TEXT PRIMARY KEY, approved INTEGER NOT NULL DEFAULT 0)");
        $tgt->exec("INSERT INTO odd (email) VALUES ('kept@example.test')");

        $result = $this->runImport($src, $tgt, ['conflict_strategy' => 'overwrite'] + self::SCOPE);

        self::assertSame('Target 9', $this->ownedRow($tgt, 9)['title']);
        self::assertSame(1, $this->rowCount($tgt, 'odd'));
        self::assertNotNull($this->row($result, 'Listings not imported'));
    }

    public function testAMembersOnlyTableOnlyGetsColumnsForTheListingsOwnFields(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'src_moon', 'http://a.test/moon', ['additional' => 'fave,era'], ['fave', 'era']);
        $this->addTargetListing($tgt, 9, 'nine', 'http://a.test/moon', ['additional' => 'fave'], null);

        $this->runImport($src, $tgt, ['import_members' => '1']);

        $columns = array_column($tgt->query('PRAGMA table_info(nine)')->fetchAll(), 'name');
        self::assertContains('fave', $columns);
        self::assertNotContains('era', $columns);
    }

    public function testAConfirmedListingWhoseTableIsNotThereIsReported(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'moon', 'http://a.test/moon', [], null);

        $result = $this->runImport($src, $tgt, self::SCOPE);

        self::assertStringContainsString('moon', implode(' ', $this->row($result, 'Members')['errors']));
        self::assertTrue($this->tableExists($tgt, 'moon'), 'the listing still gets its table');
    }

    public function testAFailedTableCreateIsReportedWithWhatItMeans(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'moon', 'http://a.test/moon');
        $tgt->failOn = '/CREATE TABLE IF NOT EXISTS `moon`/';

        $result = $this->runImport($src, $tgt, ['import_owned_listings' => '1']);

        self::assertStringContainsString("public page won't work", implode(' ', $this->row($result, 'Owned listings')['errors']));
    }
}
