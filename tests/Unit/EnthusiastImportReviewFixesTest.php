<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Tests\Unit\Support\EnthusiastImportFixture;
use Luster\Tests\Unit\Support\TracingPdo;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Three ways an existing or new listing could get past the destination plan's checks:
 * a source naming a table that isn't a member table, a destination table another listing
 * also uses, and a new listing's fields fighting over one column.
 */
final class EnthusiastImportReviewFixesTest extends TestCase
{
    use EnthusiastImportFixture;

    private const SCOPE = ['import_owned_listings' => '1', 'import_members' => '1'];

    // -----------------------------------------------------------------------
    // A source listing naming a table that isn't a member table
    // -----------------------------------------------------------------------

    /** A source table holding what no import should ever copy in as a fan. */
    private function secretTable(PDO $pdo, string $table): void
    {
        $pdo->exec("CREATE TABLE `{$table}` (email TEXT PRIMARY KEY, name TEXT NOT NULL DEFAULT '', password TEXT NOT NULL DEFAULT '', pending INTEGER NOT NULL DEFAULT 0)");
        $pdo->prepare("INSERT INTO `{$table}` (email, name, password) VALUES ('admin@example.test', 'Admin', 'secrethash')")->execute();
    }

    /** @return array<string, array{0: string}> */
    public static function strategies(): array
    {
        return ['skip' => ['skip'], 'overwrite' => ['overwrite']];
    }

    /** @dataProvider strategies */
    public function testAnExistingMatchCannotCopyFromAProtectedTable(string $strategy): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->secretTable($src, 'lstr_admin_users');
        $this->addSourceListing($src, 1, 'lstr_admin_users', 'http://a.test/moon', ['title' => 'From source'], null);
        $this->addTargetListing($tgt, 9, 'nine', 'http://a.test/moon');
        $src->statements = [];

        $result = $this->runImport($src, $tgt, ['conflict_strategy' => $strategy] + self::SCOPE);

        self::assertSame(0, $this->rowCount($tgt, 'nine'), 'nothing copied in as a fan');
        self::assertSame(0, $this->row($result, 'Members')['imported']);
        self::assertSame('Target 9', $this->ownedRow($tgt, 9)['title'], 'and the listing is left alone');
        self::assertStringContainsString('lstr_admin_users', implode(' ', $this->row($result, 'Listings not imported')['errors']));
        self::assertSame([], $src->statementsMatching('/\blstr_admin_users\b/'), 'it was never even opened');
    }

    /** @dataProvider strategies */
    public function testAnUploadedFileCannotCopyFromAProtectedTableEither(string $strategy): void
    {
        $db = $this->targetDb();
        $db->exec('CREATE TABLE tmp_category (catid INTEGER PRIMARY KEY, catname TEXT, parent INTEGER DEFAULT 0)');
        $db->exec(str_replace('CREATE TABLE owned', 'CREATE TABLE tmp_owned', self::ownedSourceDdl()));
        $db->exec("INSERT INTO tmp_owned (listingid, dbtable, url, title) VALUES (1, 'lstr_admin_users', 'http://a.test/moon', 'From source')");
        $this->secretTable($db, 'tmp_lstr_admin_users');
        $this->addTargetListing($db, 9, 'nine', 'http://a.test/moon');

        $post = ['conflict_strategy' => $strategy, '_dump_table_prefix' => 'tmp_', '_dump_schema_variant' => 'enthusiast_unprefixed'] + self::SCOPE;
        $preview = $this->previewImport($db, $db, $post);
        $result  = $this->runImport($db, $db, $post);

        self::assertSame(0, $this->row($preview, 'Members')['found']);
        self::assertNull($preview->memberConfirmation);
        self::assertSame(0, $this->rowCount($db, 'nine'));
        self::assertNotNull($this->row($result, 'Listings not imported'));
    }

    /** @return array<string, array{0: string}> */
    public static function tablesThatAreNotMemberTables(): array
    {
        return [
            'capitals'           => ['LSTR_ADMIN_USERS'],
            'the registry'       => ['owned'],
            'enthusiast login'   => ['settings'],
            'the older registry' => ['enth_fanlistings'],
        ];
    }

    /** @dataProvider tablesThatAreNotMemberTables */
    public function testNoProtectedNameGetsThroughAnExistingMatch(string $table): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        if ($table !== 'owned') {
            $this->secretTable($src, $table);
        }
        $this->addSourceListing($src, 1, $table, 'http://a.test/moon', [], null);
        $this->addTargetListing($tgt, 9, 'nine', 'http://a.test/moon');

        // Pinned, because a source table called enth_fanlistings would otherwise be taken
        // as that whole layout.
        $result = $this->runImport($src, $tgt, self::SCOPE + ['_dump_schema_variant' => 'enthusiast_unprefixed']);

        self::assertSame(0, $this->rowCount($tgt, 'nine'));
        self::assertNotNull($this->row($result, 'Listings not imported'));
    }

    public function testABadTableNameOnAnExistingMatchNeverGoesInAQuery(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'bad`name', 'http://a.test/moon', ['title' => 'From source'], null);
        $this->addTargetListing($tgt, 9, 'nine', 'http://a.test/moon');
        $src->statements = [];

        $result = $this->runImport($src, $tgt, ['conflict_strategy' => 'overwrite'] + self::SCOPE);

        self::assertSame([], $src->statementsMatching('/bad`name/'));
        self::assertSame('Target 9', $this->ownedRow($tgt, 9)['title']);
        self::assertNotNull($this->row($result, 'Listings not imported'));
    }

    public function testASharedRegistryRowCannotReadAProtectedTableEither(): void
    {
        $db = $this->sharedDb();
        $this->secretTable($db, 'lstr_admin_users');
        $this->addSourceListing($db, 4, 'lstr_admin_users', 'http://a.test/moon', ['additional' => ''], null);
        $db->statements = [];

        $preview = $this->previewImport($db, $db, ['import_members' => '1', 'import_settings' => '1']);
        $result  = $this->runImport($db, $db, ['import_members' => '1', 'import_settings' => '1']);

        self::assertSame([], $db->statementsMatching('/(COUNT|MAX|SELECT 1 FROM).*\blstr_admin_users\b/s'));
        self::assertSame(0, $this->row($preview, 'Members')['found']);
        self::assertStringNotContainsString('already reading', $this->allText($result));
        self::assertNotNull($this->row($result, 'Listings not imported'));
    }

    public function testAnExistingMatchWithABlankSourceTableKeepsItsDetailsAndReadsNoMembers(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, '', 'http://a.test/moon', ['title' => 'From source'], null);
        $this->addTargetListing($tgt, 9, 'nine', 'http://a.test/moon');

        $result = $this->runImport($src, $tgt, ['conflict_strategy' => 'overwrite'] + self::SCOPE);

        self::assertSame('From source', $this->ownedRow($tgt, 9)['title']);
        self::assertSame(0, $this->row($result, 'Members')['imported']);
        self::assertStringContainsString('From source', implode(' ', $this->row($result, 'Members')['errors']));
    }

    // -----------------------------------------------------------------------
    // A destination table another listing also uses
    // -----------------------------------------------------------------------

    /** @return array{0: TracingPdo, 1: TracingPdo} A source for listing #7, which shares its table with #9. */
    private function sharedDestination(string $otherSpelling = 'shared_members', string $additional = ''): array
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'src_seven', 'http://a.test/seven', ['title' => 'From source', 'additional' => $additional], $additional === '' ? [] : [$additional]);
        $this->addTargetListing($tgt, 7, 'shared_members', 'http://a.test/seven');
        $this->addTargetListing($tgt, 9, $otherSpelling, 'http://a.test/nine', [], null);
        return [$src, $tgt];
    }

    public function testSkipAddsNoNewMemberToATableAnotherListingUses(): void
    {
        [$src, $tgt] = $this->sharedDestination();
        $this->addFan($src, 'src_seven', ['email' => 'new@example.test']);

        $result = $this->runImport($src, $tgt, self::SCOPE);

        self::assertSame(0, $this->rowCount($tgt, 'shared_members'));
        $errors = implode(' ', $this->row($result, 'Listings not imported')['errors']);
        self::assertStringContainsString('#7', $errors);
        self::assertStringContainsString('#9', $errors);
        self::assertStringContainsString('shared_members', $errors);
    }

    public function testOverwriteChangesNoMemberInATableAnotherListingUses(): void
    {
        [$src, $tgt] = $this->sharedDestination();
        $this->addFan($src, 'src_seven', ['email' => 'fan@example.test', 'name' => 'Changed']);
        $this->addFan($tgt, 'shared_members', ['email' => 'fan@example.test', 'name' => 'Original']);

        $this->runImport($src, $tgt, ['conflict_strategy' => 'overwrite'] + self::SCOPE);

        self::assertSame('Original', $tgt->query('SELECT name FROM shared_members')->fetchColumn());
        self::assertSame('Target 7', $this->ownedRow($tgt, 7)['title']);
    }

    public function testAMembersOnlyImportChangesNothingInASharedTableEither(): void
    {
        [$src, $tgt] = $this->sharedDestination();
        $this->addFan($src, 'src_seven', ['email' => 'fan@example.test', 'name' => 'Changed']);
        $this->addFan($tgt, 'shared_members', ['email' => 'fan@example.test', 'name' => 'Original']);

        $this->runImport($src, $tgt, ['conflict_strategy' => 'overwrite', 'import_members' => '1']);

        self::assertSame('Original', $tgt->query('SELECT name FROM shared_members')->fetchColumn());
    }

    public function testNoFieldColumnIsAddedToASharedTable(): void
    {
        [$src, $tgt] = $this->sharedDestination('shared_members', 'era');

        $this->runImport($src, $tgt, ['conflict_strategy' => 'overwrite'] + self::SCOPE);

        self::assertNotContains('era', array_column($tgt->query('PRAGMA table_info(shared_members)')->fetchAll(), 'name'));
        self::assertSame('', $this->ownedRow($tgt, 7)['additional']);
    }

    public function testSharingIsFoundWhateverTheCapitals(): void
    {
        [$src, $tgt] = $this->sharedDestination('SHARED_MEMBERS');
        $this->addFan($src, 'src_seven', ['email' => 'new@example.test']);

        $result = $this->runImport($src, $tgt, self::SCOPE);

        self::assertSame(0, $this->rowCount($tgt, 'shared_members'));
        self::assertNotNull($this->row($result, 'Listings not imported'));
    }

    public function testThePreviewSaysTheSameThing(): void
    {
        [$src, $tgt] = $this->sharedDestination();
        $this->addFan($src, 'src_seven', ['email' => 'new@example.test']);

        $preview = $this->previewImport($src, $tgt, self::SCOPE);

        self::assertSame(0, $this->row($preview, 'Members')['import']);
        self::assertStringContainsString('#9', implode(' ', $this->row($preview, 'Listings not imported')['warnings']));
    }

    public function testASharedRegistryRowOnASharedTableWritesNothingAndIsNotRefused(): void
    {
        $db = $this->sharedDb();
        $this->addSourceListing($db, 4, 'shared_members', 'http://a.test/four', ['opened' => '2021-01-01']);
        $this->addSourceListing($db, 5, 'shared_members', 'http://a.test/five', [], null);
        $db->statements = [];

        $result = $this->runImport($db, $db, ['import_members' => '1']);

        self::assertNull($this->row($result, 'Listings not imported'));
        self::assertSame([], $db->statementsMatching('/^\s*(INSERT|UPDATE|ALTER|CREATE)\b.*shared_members/is'));
    }

    public function testInOneDatabaseASourceTableAnotherListingOwnsIsNotCopiedFrom(): void
    {
        $db = $this->targetDb();
        $db->exec("CREATE TABLE enth_fanlistings (id INTEGER PRIMARY KEY, dbprefix TEXT NOT NULL, name TEXT NOT NULL DEFAULT '', subject TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '', catid TEXT NOT NULL DEFAULT '', additional_info TEXT NOT NULL DEFAULT '')");
        $db->exec("INSERT INTO enth_fanlistings (id, dbprefix, name, url) VALUES (1, 'other_', 'Enth', 'http://a.test/nine')");
        $this->addTargetListing($db, 3, 'other_members', 'http://a.test/three');
        $this->addFan($db, 'other_members', ['email' => 'threes@example.test']);
        $this->addTargetListing($db, 9, 'nine', 'http://a.test/nine');

        $result = $this->runImport($db, $db, ['import_members' => '1', '_dump_schema_variant' => 'enthusiast_enth']);

        self::assertSame(0, $this->rowCount($db, 'nine'), "listing 3's fans don't become listing 9's");
        self::assertStringContainsString('#3', implode(' ', $this->row($result, 'Listings not imported')['errors']));
    }

    public function testInOneDatabaseATableInOtherCapitalsIsStillTheSameTable(): void
    {
        $db = $this->sharedDb();
        $db->exec("CREATE TABLE enth_fanlistings (id INTEGER PRIMARY KEY, dbprefix TEXT NOT NULL, name TEXT NOT NULL DEFAULT '', subject TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '', catid TEXT NOT NULL DEFAULT '', additional_info TEXT NOT NULL DEFAULT '')");
        $db->exec("INSERT INTO enth_fanlistings (id, dbprefix, name, url) VALUES (1, 'fl_', 'Enth', 'http://a.test/nine')");
        $this->createTargetMemberTable($db, 'FL_MEMBERS');
        $this->addFan($db, 'FL_MEMBERS', ['email' => 'fan@example.test']);
        $db->exec("INSERT INTO owned (listingid, dbtable, url) VALUES (9, 'FL_MEMBERS', 'http://a.test/nine')");

        $result = $this->runImport($db, $db, ['import_members' => '1', '_dump_schema_variant' => 'enthusiast_enth']);

        self::assertSame(0, $this->row($result, 'Members')['imported']);
        self::assertStringContainsString('keep this database', $this->allText($result));
    }

    // -----------------------------------------------------------------------
    // A new listing's fields fighting over one column
    // -----------------------------------------------------------------------

    private function collidingSource(string $info): TracingPdo
    {
        $src = $this->sourceDb();
        $src->exec("CREATE TABLE enth_fanlistings (id INTEGER PRIMARY KEY, dbprefix TEXT NOT NULL, name TEXT NOT NULL DEFAULT '', subject TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '', catid TEXT NOT NULL DEFAULT '', additional_info TEXT NOT NULL DEFAULT '', trouble INTEGER NOT NULL DEFAULT 0, upcoming INTEGER NOT NULL DEFAULT 0)");
        $src->prepare("INSERT INTO enth_fanlistings (id, dbprefix, name, url, additional_info) VALUES (1, 'fl_', 'Listing', 'http://a.test/moon', :i)")->execute(['i' => $info]);
        $src->exec("CREATE TABLE fl_members (email TEXT PRIMARY KEY, name TEXT NOT NULL DEFAULT '', pending INTEGER NOT NULL DEFAULT 0, additional_x TEXT NULL, additional_additional_x TEXT NULL)");
        $src->exec("INSERT INTO fl_members (email, name, additional_x, additional_additional_x) VALUES ('fan@example.test', 'Fan', 'X answer', 'Y answer')");
        return $src;
    }

    /** @return list<string> Field names stored on the listing. */
    private function storedFields(PDO $tgt, int $id): array
    {
        $raw = (string) $this->ownedRow($tgt, $id)['additional'];
        return array_column((array) unserialize($raw, ['allowed_classes' => false]), 'name');
    }

    /** @return array<string, array{0: string}> */
    public static function fieldOrders(): array
    {
        return [
            'x first'            => ['x,X,text,0|additional_x,Y,text,0'],
            'additional_x first' => ['additional_x,Y,text,0|x,X,text,0'],
        ];
    }

    /** @dataProvider fieldOrders */
    public function testANewListingKeepsTheAnswerItCanKeepAndSaysWhatItLeftOut(string $info): void
    {
        $src = $this->collidingSource($info);
        $tgt = $this->targetDb();

        $result = $this->runImport($src, $tgt, self::SCOPE);

        self::assertSame(['x'], $this->storedFields($tgt, 1));
        $columns = array_column($tgt->query('PRAGMA table_info(fl_members)')->fetchAll(), 'name');
        self::assertContains('additional_x', $columns);
        self::assertNotContains('additional_additional_x', $columns);
        self::assertSame('X answer', $tgt->query('SELECT additional_x FROM fl_members')->fetchColumn());
        self::assertSame([], $this->row($result, 'Members')['errors']);
        self::assertStringContainsString('"additional_x"', implode(' ', $this->row($result, 'Owned listings')['warnings']));
    }

    /** @dataProvider fieldOrders */
    public function testThePreviewSaysTheSameBeforeAnythingIsWritten(string $info): void
    {
        $src = $this->collidingSource($info);
        $tgt = $this->targetDb();

        $preview = $this->previewImport($src, $tgt, self::SCOPE);

        self::assertStringContainsString('"additional_x"', implode(' ', $this->row($preview, 'Owned listings')['warnings']));
        self::assertFalse($this->tableExists($tgt, 'fl_members'));
        self::assertSame(0, $this->rowCount($tgt, 'owned'));
    }

    /** @dataProvider fieldOrders */
    public function testARetryOnSkipChangesNothing(string $info): void
    {
        $src = $this->collidingSource($info);
        $tgt = $this->targetDb();
        $this->runImport($src, $tgt, self::SCOPE);

        $result = $this->runImport($src, $tgt, self::SCOPE);

        self::assertSame(['x'], $this->storedFields($tgt, 1));
        self::assertSame(1, $this->row($result, 'Members')['skipped']);
        self::assertSame('X answer', $tgt->query('SELECT additional_x FROM fl_members')->fetchColumn());
    }

    /** @dataProvider fieldOrders */
    public function testARetryOnOverwriteKeepsEachAnswerWithItsField(string $info): void
    {
        $src = $this->collidingSource($info);
        $tgt = $this->targetDb();
        $this->runImport($src, $tgt, self::SCOPE);
        $src->exec("UPDATE fl_members SET additional_x = 'X changed'");

        $result = $this->runImport($src, $tgt, ['conflict_strategy' => 'overwrite'] + self::SCOPE);

        self::assertSame(['x'], $this->storedFields($tgt, 1));
        self::assertSame('X changed', $tgt->query('SELECT additional_x FROM fl_members')->fetchColumn());
        self::assertNotContains('additional_additional_x', array_column($tgt->query('PRAGMA table_info(fl_members)')->fetchAll(), 'name'));
        self::assertSame([], $this->row($result, 'Members')['errors']);
    }

    public function testANewListingOnALeftoverTableCannotChangeWhatItsColumnsMean(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'moon', 'http://a.test/moon', ['additional' => 'x,additional_x'], ['x', 'additional_x']);
        $this->addFan($src, 'moon', ['email' => 'fan@example.test', 'x' => 'X answer', 'additional_x' => 'Y answer']);
        $this->createTargetMemberTable($tgt, 'moon', ['x', 'additional_x']);

        $this->runImport($src, $tgt, self::SCOPE);

        $row = $tgt->query('SELECT x, additional_x FROM moon')->fetch();
        self::assertSame('X answer', $row['x']);
        self::assertSame('Y answer', $row['additional_x']);
    }

    public function testALeftoverTableCannotPointANewFieldAtTheWrongColumn(): void
    {
        $src = $this->collidingSource('x,X,text,0');
        $tgt = $this->targetDb();
        // A bare `x` here would take the field away from the column its answers come from.
        $this->createTargetMemberTable($tgt, 'fl_members', ['x']);
        $this->addFan($tgt, 'fl_members', ['email' => 'earlier@example.test', 'x' => 'Not the same answer']);

        $result = $this->runImport($src, $tgt, self::SCOPE);

        self::assertSame('', $this->ownedRow($tgt, 1)['additional'], 'left out, not bound to the wrong column');
        self::assertSame('Not the same answer', $tgt->query("SELECT x FROM fl_members WHERE email = 'earlier@example.test'")->fetchColumn());
        self::assertNull($tgt->query("SELECT x FROM fl_members WHERE email = 'fan@example.test'")->fetchColumn());
        self::assertStringContainsString('"x"', implode(' ', $this->row($result, 'Owned listings')['warnings']));
    }

    public function testANewListingWithABlankTableNameIsRefused(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, '', 'http://a.test/moon', [], null);

        $result = $this->runImport($src, $tgt, self::SCOPE);

        self::assertSame(0, $this->rowCount($tgt, 'owned'));
        self::assertStringContainsString('no member table name', implode(' ', $this->row($result, 'Listings not imported')['errors']));
    }

    public function testNormalFieldsOnANewListingAreStoredExactlyAsBefore(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'moon', 'http://a.test/moon', ['additional' => 'fave, era'], ['fave', 'era']);

        $this->runImport($src, $tgt, ['import_owned_listings' => '1']);

        self::assertSame('fave, era', $this->ownedRow($tgt, 1)['additional']);
    }

    public function testRealEnthusiastFieldsWithSimilarNamesBothSurvive(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'moon', 'http://a.test/moon', ['additional' => 'x,additional_x'], ['x', 'additional_x']);
        $this->addFan($src, 'moon', ['email' => 'fan@example.test', 'x' => 'X answer', 'additional_x' => 'Y answer']);

        $this->runImport($src, $tgt, self::SCOPE);

        self::assertSame('x,additional_x', $this->ownedRow($tgt, 1)['additional']);
        $row = $tgt->query('SELECT x, additional_x FROM moon')->fetch();
        self::assertSame('X answer', $row['x']);
        self::assertSame('Y answer', $row['additional_x']);
    }
}
