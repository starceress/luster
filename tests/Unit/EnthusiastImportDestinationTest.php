<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Tests\Unit\Support\EnthusiastImportFixture;
use PHPUnit\Framework\TestCase;

/**
 * Every Enthusiast import phase obeys one answer for where each source listing goes,
 * worked out before anything is written: the same row in a proven shared registry, the
 * one listing with that address, a new listing, or not imported at all.
 */
final class EnthusiastImportDestinationTest extends TestCase
{
    use EnthusiastImportFixture;

    private const ALL = ['import_owned_listings' => '1', 'import_members' => '1', 'import_settings' => '1', 'import_templates' => '1'];

    public function testANewListingGetsItsRowItsTableItsMembersAndItsSettings(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'moon', 'http://a.test/moon');
        $this->addFan($src, 'moon', ['email' => 'one@example.test', 'name' => 'One']);

        $result = $this->runImport($src, $tgt, self::ALL);

        self::assertSame('moon', $this->ownedRow($tgt, 1)['dbtable']);
        self::assertSame(1, $this->rowCount($tgt, 'moon'));
        self::assertSame(77, (int) $tgt->query('SELECT members_per_page FROM lstr_fanlisting_settings WHERE fanlisting_id = 1')->fetchColumn());
        self::assertNull($this->row($result, 'Listings not imported'));
    }

    public function testAChangedAddressDoesNotMakeASecondListingOnTheSameTable(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'moon', 'http://a.test/new');
        $this->addFan($src, 'moon', ['email' => 'one@example.test']);
        $this->addTargetListing($tgt, 5, 'moon', 'http://a.test/old');
        $this->addFan($tgt, 'moon', ['email' => 'kept@example.test']);

        $result = $this->runImport($src, $tgt, self::ALL);

        self::assertSame(1, $this->rowCount($tgt, 'owned'), 'no second listing');
        self::assertSame(1, $this->rowCount($tgt, 'moon'), 'nothing merged in');
        self::assertStringContainsString('moon', implode(' ', $this->row($result, 'Listings not imported')['errors']));
    }

    public function testATableThatBelongsToAnotherListingIsRefusedWhateverItsCapitals(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'moon', 'http://a.test/new');
        $this->addFan($src, 'moon', ['email' => 'one@example.test']);
        $this->addTargetListing($tgt, 5, 'MOON', 'http://a.test/other');
        $this->addFan($tgt, 'MOON', ['email' => 'kept@example.test']);

        $this->runImport($src, $tgt, self::ALL);

        self::assertSame(1, $this->rowCount($tgt, 'owned'));
        self::assertSame(1, $this->rowCount($tgt, 'MOON'));
    }

    public function testAnAddressMatchUsesThatListingsOwnTable(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'src_moon', 'http://a.test/moon');
        $this->addFan($src, 'src_moon', ['email' => 'one@example.test']);
        $this->addTargetListing($tgt, 9, 'nine', 'http://a.test/moon');

        $this->runImport($src, $tgt, self::ALL);

        self::assertSame(1, $this->rowCount($tgt, 'nine'));
        self::assertFalse($this->tableExists($tgt, 'src_moon'), 'no unused table');
        self::assertSame('nine', $this->ownedRow($tgt, 9)['dbtable']);
        self::assertFalse($this->ownedRow($tgt, 1));
    }

    public function testSettingsAndTemplatesNeverLandOnAnUnrelatedListingWithTheSameId(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'moon', 'http://a.test/moon', ['emailsignup' => 'Hello']);
        $this->addTargetListing($tgt, 1, 'stranger', 'http://a.test/stranger');
        $tgt->exec('INSERT INTO lstr_fanlisting_settings (fanlisting_id, members_per_page) VALUES (1, 10)');

        $result = $this->runImport($src, $tgt, ['conflict_strategy' => 'overwrite', 'import_settings' => '1', 'import_templates' => '1']);

        self::assertSame(10, (int) $tgt->query('SELECT members_per_page FROM lstr_fanlisting_settings WHERE fanlisting_id = 1')->fetchColumn());
        self::assertSame(0, $this->rowCount($tgt, 'lstr_email_templates'));
        self::assertNotNull($this->row($result, 'Listings not imported'));
    }

    public function testASourceIdEqualToAnUnrelatedTargetIdGetsItsOwnNewListing(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'moon', 'http://a.test/moon');
        $this->addTargetListing($tgt, 1, 'stranger', 'http://a.test/stranger');
        $tgt->exec('INSERT INTO lstr_fanlisting_settings (fanlisting_id, members_per_page) VALUES (1, 10)');

        $this->runImport($src, $tgt, self::ALL);

        $newId = (int) $tgt->query("SELECT listingid FROM owned WHERE dbtable = 'moon'")->fetchColumn();
        self::assertNotSame(1, $newId);
        self::assertSame(10, (int) $tgt->query('SELECT members_per_page FROM lstr_fanlisting_settings WHERE fanlisting_id = 1')->fetchColumn());
        self::assertSame(77, (int) $tgt->query("SELECT members_per_page FROM lstr_fanlisting_settings WHERE fanlisting_id = {$newId}")->fetchColumn());
    }

    public function testMembersOnlyWithNoMatchCreatesNothing(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'moon', 'http://a.test/moon');
        $this->addFan($src, 'moon', ['email' => 'one@example.test']);

        $result = $this->runImport($src, $tgt, ['import_members' => '1']);

        self::assertFalse($this->tableExists($tgt, 'moon'));
        self::assertSame(0, $this->row($result, 'Members')['imported']);
        self::assertStringContainsString('Owned listings', implode(' ', $this->row($result, 'Listings not imported')['errors']));
    }

    public function testAFailedSaveStopsTheLaterPhasesForThatListing(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'moon', 'http://a.test/moon', ['emailsignup' => 'Hello']);
        $this->addFan($src, 'moon', ['email' => 'one@example.test']);
        $tgt->exec("CREATE TRIGGER no_saves BEFORE INSERT ON owned BEGIN SELECT RAISE(ABORT, 'refused'); END");

        $result = $this->runImport($src, $tgt, self::ALL);

        self::assertStringContainsString('refused', implode(' ', $this->row($result, 'Owned listings')['errors']));
        self::assertFalse($this->tableExists($tgt, 'moon'));
        self::assertSame(0, $this->rowCount($tgt, 'lstr_fanlisting_settings'));
        self::assertSame(0, $this->rowCount($tgt, 'lstr_email_templates'));
    }

    /** @return array<string, array{0: string}> */
    public static function strategies(): array
    {
        return ['skip' => ['skip'], 'overwrite' => ['overwrite']];
    }

    /** @dataProvider strategies */
    public function testTwoListingsSharingAnAddressAreBothLeftAlone(string $strategy): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'src_moon', 'http://a.test/shared', ['title' => 'From source', 'opened' => '2020-02-02']);
        $this->addFan($src, 'src_moon', ['email' => 'one@example.test']);
        $this->addTargetListing($tgt, 7, 'seven', 'http://a.test/shared');
        $this->addTargetListing($tgt, 9, 'nine', 'http://a.test/shared');

        $result = $this->runImport($src, $tgt, ['conflict_strategy' => $strategy] + self::ALL);

        foreach ([7 => 'seven', 9 => 'nine'] as $id => $table) {
            $row = $this->ownedRow($tgt, $id);
            self::assertSame("Target {$id}", $row['title']);
            self::assertNull($row['opened']);
            self::assertSame(0, $this->rowCount($tgt, $table));
        }
        self::assertSame(0, $this->rowCount($tgt, 'lstr_fanlisting_settings'));
        $errors = implode(' ', $this->row($result, 'Listings not imported')['errors']);
        self::assertStringContainsString('#7', $errors);
        self::assertStringContainsString('#9', $errors);
    }

    /** @return array<string, array{0: ?string}> */
    public static function blankAddresses(): array
    {
        return ['null' => [null], 'empty' => ['']];
    }

    /** @dataProvider blankAddresses */
    public function testABlankAddressFromAnotherDatabaseIsLeftOutWithAReason(?string $blank): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'src_blank', $blank, ['emailsignup' => 'Hello']);
        $this->addFan($src, 'src_blank', ['email' => 'one@example.test']);
        $this->addTargetListing($tgt, 1, 'stranger', 'http://a.test/stranger');

        $result = $this->runImport($src, $tgt, self::ALL);

        self::assertSame(1, $this->rowCount($tgt, 'owned'));
        self::assertFalse($this->tableExists($tgt, 'src_blank'));
        self::assertSame(0, $this->rowCount($tgt, 'lstr_fanlisting_settings'));
        self::assertSame(0, $this->rowCount($tgt, 'lstr_email_templates'));
        self::assertStringContainsString('web address', implode(' ', $this->row($result, 'Listings not imported')['errors']));
    }

    /** @dataProvider blankAddresses */
    public function testABlankAddressInASharedRegistryStillMatchesByItsId(?string $blank): void
    {
        $db = $this->sharedDb();
        $this->addSourceListing($db, 4, 'noaddress', $blank, ['emailsignup' => 'Hello']);

        $result = $this->runImport($db, $db, ['import_owned_listings' => '1', 'import_settings' => '1', 'import_templates' => '1']);

        self::assertSame(1, $this->rowCount($db, 'owned'), 'no second row');
        self::assertSame(77, (int) $db->query('SELECT members_per_page FROM lstr_fanlisting_settings WHERE fanlisting_id = 4')->fetchColumn());
        self::assertSame(1, $this->rowCount($db, 'lstr_email_templates'));
        self::assertNull($this->row($result, 'Listings not imported'));
    }

    public function testABadTableNameInASharedRegistryNeverGoesInAQuery(): void
    {
        $db = $this->sharedDb();
        $this->addSourceListing($db, 4, 'bad`name', 'http://a.test/bad', [], null);
        $db->statements = [];

        $preview = $this->previewImport($db, $db, ['import_members' => '1']);
        $result  = $this->runImport($db, $db, ['import_members' => '1']);

        self::assertSame([], $db->statementsMatching('/bad`name/'));
        self::assertSame(0, $this->row($preview, 'Members')['found']);
        self::assertSame(0, $this->row($result, 'Members')['imported']);
    }

    public function testASharedRegistryBackfillsByIdNotByAddress(): void
    {
        $db = $this->sharedDb();
        $this->addSourceListing($db, 4, 'four', 'http://a.test/same', ['opened' => null]);
        $this->addSourceListing($db, 5, 'five', 'http://a.test/same', ['opened' => '2021-01-01']);

        $this->runImport($db, $db, ['import_owned_listings' => '1']);

        self::assertNull($this->ownedRow($db, 4)['opened'], 'the other row with that address is not touched');
    }

    /** @return array<string, array{0: string}> */
    public static function protectedNames(): array
    {
        return [
            'a Luster table'         => ['lstr_admin_users'],
            'in capitals'            => ['LSTR_ADMIN_USERS'],
            'the registry'           => ['owned'],
            'category'               => ['category'],
            'settings'               => ['settings'],
            'joined'                 => ['joined'],
            'affiliates'             => ['affiliates'],
            'email templates'        => ['emailtemplate'],
            'error log'              => ['errorlog'],
            'the older registry'     => ['enth_fanlistings'],
        ];
    }

    /** @dataProvider protectedNames */
    public function testAProtectedTableNameIsRefused(string $table): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, $table, 'http://a.test/moon', [], null);

        $result = $this->runImport($src, $tgt, self::ALL);

        self::assertSame(0, $this->rowCount($tgt, 'owned'));
        self::assertNotNull($this->row($result, 'Listings not imported'));
    }

    public function testABadTableNameIsRefused(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'bad table!', 'http://a.test/moon', [], null);

        $result = $this->runImport($src, $tgt, self::ALL);

        self::assertSame(0, $this->rowCount($tgt, 'owned'));
        self::assertStringContainsString('bad table!', implode(' ', $this->row($result, 'Listings not imported')['errors']));
    }

    public function testAnExistingTableThatIsNotAMemberTableIsRefusedAndLeftAlone(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'notes', 'http://a.test/moon');
        $this->addFan($src, 'notes', ['email' => 'one@example.test']);
        $tgt->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT)');
        $tgt->exec("INSERT INTO notes (body) VALUES ('mine')");

        $result = $this->runImport($src, $tgt, self::ALL);

        self::assertSame(0, $this->rowCount($tgt, 'owned'));
        self::assertSame(1, $this->rowCount($tgt, 'notes'));
        self::assertNotNull($this->row($result, 'Listings not imported'));
    }

    public function testAnUnownedMemberShapedTableIsAllowedWithANote(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'moon', 'http://a.test/moon');
        $this->addFan($src, 'moon', ['email' => 'one@example.test']);
        $this->createTargetMemberTable($tgt, 'moon');
        $this->addFan($tgt, 'moon', ['email' => 'earlier@example.test']);

        $result = $this->runImport($src, $tgt, self::ALL);

        self::assertSame(1, $this->rowCount($tgt, 'owned'));
        self::assertSame(2, $this->rowCount($tgt, 'moon'));
        self::assertStringContainsString('already', $this->allText($result));
    }

    public function testASecondSourceListingCannotClaimTheSameAddress(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'moon', 'http://a.test/same');
        $this->addSourceListing($src, 2, 'star', 'http://a.test/same');

        $result = $this->runImport($src, $tgt, self::ALL);

        self::assertSame(1, $this->rowCount($tgt, 'owned'));
        self::assertFalse($this->tableExists($tgt, 'star'));
        self::assertNotNull($this->row($result, 'Listings not imported'));
    }

    public function testASecondSourceListingCannotClaimTheSameTable(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'moon', 'http://a.test/one');
        $this->addSourceListing($src, 2, 'moon', 'http://a.test/two', [], null);

        $result = $this->runImport($src, $tgt, self::ALL);

        self::assertSame(1, $this->rowCount($tgt, 'owned'));
        self::assertNotNull($this->row($result, 'Listings not imported'));
    }

    public function testASecondSourceListingCannotShareOneExistingListing(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'moon', 'http://a.test/same', ['title' => 'First']);
        $this->addSourceListing($src, 2, 'star', 'http://a.test/same', ['title' => 'Second']);
        $this->addFan($src, 'star', ['email' => 'two@example.test']);
        $this->addTargetListing($tgt, 9, 'nine', 'http://a.test/same');

        $this->runImport($src, $tgt, ['conflict_strategy' => 'overwrite'] + self::ALL);

        self::assertSame('First', $this->ownedRow($tgt, 9)['title']);
        self::assertSame(0, $this->rowCount($tgt, 'nine'));
    }

    /** @return array<string, array{0: array<string, string>}> */
    public static function scopesThatNeedTheRegistry(): array
    {
        return [
            'owned'     => [['import_owned_listings' => '1']],
            'members'   => [['import_members' => '1']],
            'settings'  => [['import_settings' => '1']],
            'templates' => [['import_templates' => '1']],
        ];
    }

    /**
     * @dataProvider scopesThatNeedTheRegistry
     * @param array<string, string> $scope
     */
    public function testAnUnreadableTargetRegistryRefusesBeforeAnythingIsWritten(array $scope): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $tgt->exec('DROP TABLE owned');
        $this->addSourceListing($src, 1, 'moon', 'http://a.test/moon');

        try {
            $this->runImport($src, $tgt, $scope);
            self::fail('The run should refuse.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString("listing table", $e->getMessage());
        }
        self::assertFalse($this->tableExists($tgt, 'moon'));
        self::assertSame(0, $this->rowCount($tgt, 'lstr_fanlisting_settings'));

        $this->expectException(\RuntimeException::class);
        $this->previewImport($src, $tgt, $scope);
    }

    public function testAFailedOverwriteStopsTheLaterPhasesForThatListing(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'src_moon', 'http://a.test/moon', ['emailsignup' => 'Hello']);
        $this->addFan($src, 'src_moon', ['email' => 'one@example.test']);
        $this->addTargetListing($tgt, 9, 'nine', 'http://a.test/moon');
        $tgt->exec("CREATE TRIGGER no_saves BEFORE UPDATE ON owned BEGIN SELECT RAISE(ABORT, 'refused'); END");

        $result = $this->runImport($src, $tgt, ['conflict_strategy' => 'overwrite'] + self::ALL);

        self::assertStringContainsString('refused', implode(' ', $this->row($result, 'Owned listings')['errors']));
        self::assertSame(0, $this->rowCount($tgt, 'nine'));
        self::assertSame(0, $this->rowCount($tgt, 'lstr_fanlisting_settings'));
        self::assertSame(0, $this->rowCount($tgt, 'lstr_email_templates'));
        self::assertNotNull($this->row($result, 'Listings not imported'));
    }

    public function testTheOlderLayoutInASharedDatabaseIsItsOwnRegistry(): void
    {
        $db = $this->targetDb();
        $db->exec("CREATE TABLE enth_fanlistings (id INTEGER PRIMARY KEY, dbprefix TEXT NOT NULL, name TEXT NOT NULL DEFAULT '', subject TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '', catid TEXT NOT NULL DEFAULT '', additional_info TEXT NOT NULL DEFAULT '', perpage INTEGER DEFAULT 77)");
        $db->exec("INSERT INTO enth_fanlistings (id, dbprefix, name, url) VALUES (1, 'fl_', 'From enth', 'http://a.test/enth')");
        $this->addTargetListing($db, 1, 'stranger', 'http://a.test/stranger');
        $db->exec('INSERT INTO lstr_fanlisting_settings (fanlisting_id, members_per_page) VALUES (1, 10)');

        $result = $this->runImport($db, $db, ['conflict_strategy' => 'overwrite', 'import_settings' => '1', '_dump_schema_variant' => 'enthusiast_enth']);

        self::assertSame(10, (int) $db->query('SELECT members_per_page FROM lstr_fanlisting_settings WHERE fanlisting_id = 1')->fetchColumn());
        self::assertNotNull($this->row($result, 'Listings not imported'));
    }

    public function testAnOverwriteOnlyEverChangesTheOneListingItMatched(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'src_moon', 'http://a.test/moon', ['title' => 'From source']);
        $this->addTargetListing($tgt, 9, 'nine', 'http://a.test/moon');
        $this->addTargetListing($tgt, 10, 'ten', 'http://a.test/ten');

        $this->runImport($src, $tgt, ['conflict_strategy' => 'overwrite', 'import_owned_listings' => '1']);

        self::assertSame('From source', $this->ownedRow($tgt, 9)['title']);
        self::assertSame('Target 10', $this->ownedRow($tgt, 10)['title']);
    }

    public function testAnUploadedFileIsNeverTheSameRegistry(): void
    {
        $db = $this->targetDb();
        $db->exec("CREATE TABLE tmp_category (catid INTEGER PRIMARY KEY, catname TEXT, parent INTEGER DEFAULT 0)");
        $db->exec(str_replace('CREATE TABLE owned', 'CREATE TABLE tmp_owned', self::ownedSourceDdl()));
        $db->prepare("INSERT INTO tmp_owned (listingid, dbtable, url, title) VALUES (1, 'moon', 'http://a.test/moon', 'Moon')")->execute();
        $this->createSourceMemberTable($db, 'tmp_moon');
        $this->addFan($db, 'tmp_moon', ['email' => 'one@example.test']);
        $this->addTargetListing($db, 1, 'stranger', 'http://a.test/stranger');

        $this->runImport($db, $db, self::ALL + ['_dump_table_prefix' => 'tmp_', '_dump_schema_variant' => 'enthusiast_unprefixed']);

        self::assertSame('Target 1', $this->ownedRow($db, 1)['title']);
        self::assertSame(1, $this->rowCount($db, 'moon'));
        self::assertSame(0, $this->rowCount($db, 'stranger'));
    }

    public function testPerListingAffiliatesFollowTheSameAnswer(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $src->exec("CREATE TABLE affiliates (affiliateid INTEGER PRIMARY KEY, listingid INTEGER NOT NULL DEFAULT 0, url TEXT NOT NULL DEFAULT '', title TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '', imagefile TEXT NOT NULL DEFAULT '')");
        $src->exec("INSERT INTO affiliates (listingid, url, title) VALUES (1, 'http://aff.test/one', 'One'), (2, 'http://aff.test/two', 'Two')");
        $this->addSourceListing($src, 1, 'src_moon', 'http://a.test/moon', [], null);
        $this->addSourceListing($src, 2, 'src_star', 'http://a.test/star', [], null);
        $this->addTargetListing($tgt, 9, 'nine', 'http://a.test/moon');
        $this->addTargetListing($tgt, 2, 'stranger', 'http://a.test/stranger');

        $this->runImport($src, $tgt, ['import_affiliates' => '1']);

        $rows = $tgt->query('SELECT fanlisting_id, url FROM lstr_affiliates')->fetchAll();
        self::assertCount(1, $rows, 'the affiliate of the unrelated listing 2 is not attached');
        self::assertSame(9, (int) $rows[0]['fanlisting_id']);
        self::assertSame('http://aff.test/one', $rows[0]['url']);
    }

    public function testAnUnreadableSourceRegistryIsReportedNotTakenAsEmpty(): void
    {
        $src = $this->sourceDb();
        $src->exec('DROP TABLE owned');
        $tgt = $this->targetDb();

        $result = $this->runImport($src, $tgt, ['import_members' => '1']);

        self::assertStringContainsString('Could not read listing registry', implode(' ', $this->row($result, 'Members')['errors']));
        self::assertStringContainsString('Could not read listing registry', implode(' ', $this->row($this->previewImport($src, $tgt, ['import_members' => '1']), 'Members')['warnings']));
    }

    public function testPreviewAndRunAgree(): void
    {
        $build = function (): array {
            $src = $this->sourceDb();
            $tgt = $this->targetDb();
            $this->addSourceListing($src, 1, 'newone', 'http://a.test/new');
            $this->addFan($src, 'newone', ['email' => 'a@example.test']);
            $this->addFan($src, 'newone', ['email' => 'b@example.test']);
            $this->addSourceListing($src, 2, 'src_two', 'http://a.test/two');
            $this->addFan($src, 'src_two', ['email' => 'c@example.test']);
            $this->addTargetListing($tgt, 9, 'nine', 'http://a.test/two');
            $this->addSourceListing($src, 3, 'orphan', '');
            $this->addFan($src, 'orphan', ['email' => 'd@example.test']);
            return [$src, $tgt];
        };

        [$src, $tgt] = $build();
        $preview = $this->previewImport($src, $tgt, self::ALL);
        self::assertSame(0, $this->rowCount($tgt, 'owned') - 1, 'preview wrote nothing');

        [$src2, $tgt2] = $build();
        $result = $this->runImport($src2, $tgt2, self::ALL);

        self::assertSame(1, $this->row($preview, 'Owned listings')['import']);
        self::assertSame($this->row($result, 'Owned listings')['imported'], $this->row($preview, 'Owned listings')['import']);
        self::assertSame(3, $this->row($preview, 'Members')['import']);
        self::assertSame($this->row($result, 'Members')['imported'], $this->row($preview, 'Members')['import']);
        self::assertSame(1, $this->row($preview, 'Listings not imported')['found']);
        self::assertSame(1, $this->row($result, 'Listings not imported')['skipped']);
        self::assertSame(2, $this->row($preview, 'Listing settings')['import']);
    }
}
