<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Import\EnthusiastSourceFields;
use Luster\Tests\Unit\Support\EnthusiastImportFixture;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Field definitions and answers on new and repeat Enthusiast imports: one reader for
 * what a source listing's fields are, Overwrite merging definitions instead of replacing
 * them, and members being copied through the columns a listing really has.
 */
final class EnthusiastImportFieldsTest extends TestCase
{
    use EnthusiastImportFixture;

    private const SCOPE = ['import_owned_listings' => '1', 'import_members' => '1'];

    // -----------------------------------------------------------------------
    // The reader
    // -----------------------------------------------------------------------

    public function testACommaListSuppliesNamesAndNothingElse(): void
    {
        $fields = EnthusiastSourceFields::read('fave, era', 'enthusiast_unprefixed');

        self::assertSame(['fave', 'era'], $fields->names());
        self::assertSame([], $fields->notes);
        foreach ($fields->fields as $field) {
            self::assertSame($field['name'], $field['column']);
            self::assertNull($field['label']);
            self::assertNull($field['type']);
            self::assertNull($field['required']);
            self::assertNull($field['options']);
        }
    }

    public function testAPipeEntrySuppliesOnlyWhatItHas(): void
    {
        $fields = EnthusiastSourceFields::read('fave,Favourite|era,Era,select,1', 'enthusiast_enth')->fields;

        self::assertSame('additional_fave', $fields[0]['column']);
        self::assertSame('Favourite', $fields[0]['label']);
        self::assertNull($fields[0]['type']);
        self::assertNull($fields[0]['required']);
        self::assertSame('select', $fields[1]['type']);
        self::assertTrue($fields[1]['required']);
    }

    public function testASerializedListWithNamedKeysGivesColumnsToo(): void
    {
        $raw    = serialize([['name' => 'fave', 'label' => 'Fave', 'type' => 'dropdown', 'required' => 0, 'options' => 'A, B']]);
        $fields = EnthusiastSourceFields::read($raw, 'enthusiast_enth');

        self::assertSame(['fave'], $fields->names());
        self::assertSame('additional_fave', $fields->fields[0]['column']);
        self::assertSame('A, B', $fields->fields[0]['options']);
        self::assertFalse($fields->fields[0]['required']);
    }

    public function testAMalformedSerializedValueIsNotAnError(): void
    {
        self::assertSame([], EnthusiastSourceFields::read('a:9', 'enthusiast_unprefixed')->names());
        self::assertSame(['fave'], EnthusiastSourceFields::read('fave,Fave', 'enthusiast_enth')->names());
    }

    public function testDuplicatesAreDroppedIgnoringCaseWithANote(): void
    {
        $fields = EnthusiastSourceFields::read('Fave,fave,era', 'enthusiast_unprefixed');

        self::assertSame(['Fave', 'era'], $fields->names());
        self::assertStringContainsString('fave', implode(' ', $fields->notes));
    }

    public function testAnOddNameIsReportedAndNotCopied(): void
    {
        $fields = EnthusiastSourceFields::read('fave color,best-era,era', 'enthusiast_unprefixed');

        self::assertSame(['era'], $fields->names());
        $notes = implode(' ', $fields->notes);
        self::assertStringContainsString('fave color', $notes);
        self::assertStringContainsString('best-era', $notes);
    }

    public function testABuiltInListedAsAFieldIsDroppedOnTheBareLayoutOnly(): void
    {
        $bare = EnthusiastSourceFields::read('Name,era', 'enthusiast_unprefixed');
        self::assertSame(['era'], $bare->names());
        self::assertStringContainsString('Name', implode(' ', $bare->notes));

        $prefixed = EnthusiastSourceFields::read('name,Name,text,0', 'enthusiast_enth');
        self::assertSame(['name'], $prefixed->names());
        self::assertSame('additional_name', $prefixed->fields[0]['column']);
    }

    public function testANormalCommaListIsStoredExactlyAsItWas(): void
    {
        self::assertSame('fave,era', EnthusiastSourceFields::read('fave,era', 'enthusiast_unprefixed')->forNewListing());
        self::assertSame('fave, era', EnthusiastSourceFields::read('fave, era', 'enthusiast_unprefixed')->forNewListing());
        self::assertSame('', EnthusiastSourceFields::read('', 'enthusiast_unprefixed')->forNewListing());
        self::assertSame('era', EnthusiastSourceFields::read('name,era', 'enthusiast_unprefixed')->forNewListing());
    }

    // -----------------------------------------------------------------------
    // Overwrite merges
    // -----------------------------------------------------------------------

    /** The owner's dropdown, as Luster's Fields page saves it. */
    private static function ownerDefinitions(): string
    {
        return serialize([
            ['name' => 'fave', 'label' => "Owner's label", 'type' => 'dropdown', 'required' => true, 'options' => 'A, B'],
            ['name' => 'owner_only', 'label' => 'Added in Luster', 'type' => 'text', 'required' => false, 'options' => ''],
        ]);
    }

    /** @return array<string, array<string, mixed>> Field name => definition. */
    private function definitions(PDO $tgt, int $id): array
    {
        $raw  = (string) $this->ownedRow($tgt, $id)['additional'];
        $list = unserialize($raw, ['allowed_classes' => false]);
        $out  = [];
        foreach (is_array($list) ? $list : [] as $item) {
            $out[$item['name']] = $item;
        }
        return $out;
    }

    public function testAnOverwriteWithACommaListKeepsTheOwnersDefinitions(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'src_moon', 'http://a.test/moon', ['additional' => 'fave,era'], ['fave', 'era']);
        $this->addTargetListing($tgt, 9, 'nine', 'http://a.test/moon', ['additional' => self::ownerDefinitions()], ['fave', 'owner_only']);

        $result = $this->runImport($src, $tgt, ['conflict_strategy' => 'overwrite', 'import_owned_listings' => '1']);

        $defs = $this->definitions($tgt, 9);
        self::assertSame('dropdown', $defs['fave']['type']);
        self::assertSame('A, B', $defs['fave']['options']);
        self::assertSame("Owner's label", $defs['fave']['label']);
        self::assertTrue((bool) $defs['fave']['required']);
        self::assertArrayHasKey('owner_only', $defs);
        self::assertArrayHasKey('era', $defs);
        self::assertContains('era', array_column($tgt->query('PRAGMA table_info(nine)')->fetchAll(), 'name'));
        self::assertStringContainsString('owner_only', $this->allText($result));
    }

    public function testOnlyWhatTheSourceReallySuppliedReplacesAnything(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $src->exec('CREATE TABLE enth_fanlistings (id INTEGER PRIMARY KEY, dbprefix TEXT NOT NULL, name TEXT NOT NULL DEFAULT \'\', subject TEXT NOT NULL DEFAULT \'\', email TEXT NOT NULL DEFAULT \'\', url TEXT NOT NULL DEFAULT \'\', catid TEXT NOT NULL DEFAULT \'\', additional_info TEXT NOT NULL DEFAULT \'\')');
        $src->exec("INSERT INTO enth_fanlistings (id, dbprefix, url, additional_info) VALUES (1, 'src_', 'http://a.test/moon', 'fave,New label')");
        $this->createSourceMemberTable($src, 'src_members', ['additional_fave']);
        $this->addTargetListing($tgt, 9, 'nine', 'http://a.test/moon', ['additional' => self::ownerDefinitions()], ['fave', 'owner_only']);

        $this->runImport($src, $tgt, ['conflict_strategy' => 'overwrite', 'import_owned_listings' => '1']);

        $fave = $this->definitions($tgt, 9)['fave'];
        self::assertSame('New label', $fave['label']);
        self::assertSame('dropdown', $fave['type'], 'the parser\'s default never replaces it');
        self::assertTrue((bool) $fave['required']);
        self::assertSame('A, B', $fave['options']);
    }

    public function testASourceFieldInOtherCapitalsStillSuppliesItsDetails(): void
    {
        $src = $this->enthSource('Fave,New label', ['additional_Fave']);
        $tgt = $this->targetDb();
        $this->addTargetListing($tgt, 9, 'nine', 'http://a.test/moon', ['additional' => self::ownerDefinitions()], ['fave', 'owner_only']);

        $this->runImport($src, $tgt, ['conflict_strategy' => 'overwrite', 'import_owned_listings' => '1']);

        $defs = $this->definitions($tgt, 9);
        self::assertSame('New label', $defs['fave']['label']);
        self::assertArrayNotHasKey('Fave', $defs);
    }

    public function testANewListingKeepsTheOptionsASourceSupplied(): void
    {
        $src = $this->enthSource(serialize([['name' => 'fave', 'label' => 'Fave', 'type' => 'dropdown', 'required' => 0, 'options' => 'A, B']]), ['additional_fave']);
        $tgt = $this->targetDb();

        $this->runImport($src, $tgt, ['import_owned_listings' => '1']);

        self::assertSame('A, B', $this->definitions($tgt, 1)['fave']['options']);
    }

    public function testATypeLusterDoesNotKnowCannotReplaceADropdown(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $src->exec('CREATE TABLE enth_fanlistings (id INTEGER PRIMARY KEY, dbprefix TEXT NOT NULL, name TEXT NOT NULL DEFAULT \'\', subject TEXT NOT NULL DEFAULT \'\', email TEXT NOT NULL DEFAULT \'\', url TEXT NOT NULL DEFAULT \'\', catid TEXT NOT NULL DEFAULT \'\', additional_info TEXT NOT NULL DEFAULT \'\')');
        $src->exec("INSERT INTO enth_fanlistings (id, dbprefix, url, additional_info) VALUES (1, 'src_', 'http://a.test/moon', 'fave,Label,weird,0')");
        $this->createSourceMemberTable($src, 'src_members', ['additional_fave']);
        $this->addTargetListing($tgt, 9, 'nine', 'http://a.test/moon', ['additional' => self::ownerDefinitions()], ['fave', 'owner_only']);

        $this->runImport($src, $tgt, ['conflict_strategy' => 'overwrite', 'import_owned_listings' => '1']);

        self::assertSame('dropdown', $this->definitions($tgt, 9)['fave']['type']);
    }

    public function testADropdownCalledSelectIsNotAChange(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $src->exec('CREATE TABLE enth_fanlistings (id INTEGER PRIMARY KEY, dbprefix TEXT NOT NULL, name TEXT NOT NULL DEFAULT \'\', subject TEXT NOT NULL DEFAULT \'\', email TEXT NOT NULL DEFAULT \'\', url TEXT NOT NULL DEFAULT \'\', catid TEXT NOT NULL DEFAULT \'\', additional_info TEXT NOT NULL DEFAULT \'\')');
        $src->exec("INSERT INTO enth_fanlistings (id, dbprefix, url, additional_info) VALUES (1, 'src_', 'http://a.test/moon', 'fave,Same,select,1')");
        $this->createSourceMemberTable($src, 'src_members', ['additional_fave']);
        $owner = serialize([['name' => 'fave', 'label' => 'Same', 'type' => 'dropdown', 'required' => true, 'options' => 'A, B']]);
        $this->addTargetListing($tgt, 9, 'nine', 'http://a.test/moon', ['additional' => $owner], ['fave']);

        $this->runImport($src, $tgt, ['conflict_strategy' => 'overwrite', 'import_owned_listings' => '1']);

        self::assertSame($owner, $this->ownedRow($tgt, 9)['additional']);
    }

    public function testNothingChangingLeavesTheStoredValueExactlyAsItWas(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'src_moon', 'http://a.test/moon', ['additional' => 'fave, era'], ['fave', 'era']);
        $this->addTargetListing($tgt, 9, 'nine', 'http://a.test/moon', ['additional' => 'fave,era'], ['fave', 'era']);

        $this->runImport($src, $tgt, ['conflict_strategy' => 'overwrite', 'import_owned_listings' => '1']);

        self::assertSame('fave,era', $this->ownedRow($tgt, 9)['additional']);
    }

    public function testASkipChangesNoDefinitions(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'src_moon', 'http://a.test/moon', ['additional' => 'fave,era'], ['fave', 'era']);
        $this->addTargetListing($tgt, 9, 'nine', 'http://a.test/moon', ['additional' => 'fave'], ['fave']);

        $this->runImport($src, $tgt, ['import_owned_listings' => '1']);

        self::assertSame('fave', $this->ownedRow($tgt, 9)['additional']);
        self::assertNotContains('era', array_column($tgt->query('PRAGMA table_info(nine)')->fetchAll(), 'name'));
    }

    public function testAMatchIgnoresCaseAndKeepsTheListingsSpelling(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'src_moon', 'http://a.test/moon', ['additional' => 'Fave'], ['Fave']);
        $this->addFan($src, 'src_moon', ['email' => 'one@example.test', 'Fave' => 'Answer']);
        $this->addTargetListing($tgt, 9, 'nine', 'http://a.test/moon', ['additional' => 'fave'], ['fave']);

        $this->runImport($src, $tgt, ['conflict_strategy' => 'overwrite'] + self::SCOPE);

        self::assertSame('fave', $this->ownedRow($tgt, 9)['additional']);
        self::assertSame('Answer', $tgt->query("SELECT fave FROM nine WHERE email = 'one@example.test'")->fetchColumn());
    }

    /** @return array<string, array{0: string}> */
    public static function sourceOrders(): array
    {
        return ['bare one first' => ['x,additional_x'], 'prefixed one first' => ['additional_x,x']];
    }

    /** @dataProvider sourceOrders */
    public function testANewDefinitionCannotTakeAColumnFromAnExistingOne(string $sourceFields): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'src_moon', 'http://a.test/moon', ['additional' => $sourceFields], ['x', 'additional_x']);
        $this->addTargetListing($tgt, 9, 'nine', 'http://a.test/moon', ['additional' => 'x'], ['additional_x']);
        $this->addFan($tgt, 'nine', ['email' => 'kept@example.test', 'additional_x' => 'Mine']);

        $result = $this->runImport($src, $tgt, ['conflict_strategy' => 'overwrite', 'import_owned_listings' => '1']);

        $stored = (string) $this->ownedRow($tgt, 9)['additional'];
        self::assertStringNotContainsString('additional_x', $stored);
        self::assertStringContainsString('additional_x', $this->allText($result));
        self::assertSame('Mine', $tgt->query("SELECT additional_x FROM nine WHERE email = 'kept@example.test'")->fetchColumn());
    }

    public function testBareNamesGoFirstSoOrderCannotChangeWhoWins(): void
    {
        $build = function (string $list): string {
            $src = $this->sourceDb();
            $tgt = $this->targetDb();
            $this->addSourceListing($src, 1, 'src_moon', 'http://a.test/moon', ['additional' => $list], ['y', 'additional_y']);
            $this->addTargetListing($tgt, 9, 'nine', 'http://a.test/moon');
            $this->runImport($src, $tgt, ['conflict_strategy' => 'overwrite', 'import_owned_listings' => '1']);
            $names = array_column((array) unserialize((string) $this->ownedRow($tgt, 9)['additional'], ['allowed_classes' => false]), 'name');
            sort($names);
            return implode(',', $names);
        };

        self::assertSame($build('y,additional_y'), $build('additional_y,y'));
    }

    /** On the enth_ layout both orders really compete for one column, so order mustn't decide. */
    public function testOnTheEnthLayoutSourceOrderCannotDecideWhichFieldWins(): void
    {
        $build = function (string $info): string {
            $src = $this->enthSource($info, ['additional_x', 'additional_additional_x']);
            $tgt = $this->targetDb();
            $this->addTargetListing($tgt, 9, 'fl_members', 'http://a.test/moon');
            $this->runImport($src, $tgt, ['conflict_strategy' => 'overwrite', 'import_owned_listings' => '1']);
            return implode(',', array_column((array) unserialize((string) $this->ownedRow($tgt, 9)['additional'], ['allowed_classes' => false]), 'name'));
        };

        self::assertSame('x', $build('x,X,text,0|additional_x,Y,text,0'));
        self::assertSame('x', $build('additional_x,Y,text,0|x,X,text,0'));
    }

    public function testAFailedAlterIsReportedAndTheFieldIsNotAdded(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'src_moon', 'http://a.test/moon', ['additional' => 'fave,era'], ['fave', 'era']);
        $this->addTargetListing($tgt, 9, 'nine', 'http://a.test/moon', ['additional' => 'fave'], ['fave']);
        $tgt->failOn = '/ALTER TABLE/';

        $result = $this->runImport($src, $tgt, ['conflict_strategy' => 'overwrite', 'import_owned_listings' => '1']);

        self::assertStringContainsString('era', implode(' ', $this->row($result, 'Owned listings')['errors']));
        self::assertSame('fave', $this->ownedRow($tgt, 9)['additional']);
    }

    // -----------------------------------------------------------------------
    // Copying members
    // -----------------------------------------------------------------------

    public function testASourceThatGainedAFieldStillImportsNewMembersOnSkip(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'moon', 'http://a.test/moon', ['additional' => 'fave,era'], ['fave', 'era']);
        $this->addFan($src, 'moon', ['email' => 'new@example.test', 'fave' => 'F', 'era' => 'E']);
        $this->addTargetListing($tgt, 9, 'moon', 'http://a.test/moon', ['additional' => 'fave'], ['fave']);

        $result = $this->runImport($src, $tgt, self::SCOPE);

        self::assertSame(1, $this->row($result, 'Members')['imported']);
        self::assertSame([], $this->row($result, 'Members')['errors']);
        self::assertSame('F', $tgt->query("SELECT fave FROM moon WHERE email = 'new@example.test'")->fetchColumn());
        self::assertStringContainsString('era', implode(' ', $this->row($result, 'Members')['warnings']));
        self::assertStringContainsString('Overwrite', implode(' ', $this->row($result, 'Members')['warnings']));
    }

    public function testASourceThatGainedAFieldGetsItsColumnAndAnswersOnOverwrite(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'moon', 'http://a.test/moon', ['additional' => 'fave,era'], ['fave', 'era']);
        $this->addFan($src, 'moon', ['email' => 'fan@example.test', 'fave' => 'F', 'era' => 'E']);
        $this->addTargetListing($tgt, 9, 'moon', 'http://a.test/moon', ['additional' => 'fave'], ['fave']);
        $this->addFan($tgt, 'moon', ['email' => 'fan@example.test', 'fave' => 'old']);

        $result = $this->runImport($src, $tgt, ['conflict_strategy' => 'overwrite'] + self::SCOPE);

        self::assertSame([], $this->row($result, 'Members')['errors']);
        $row = $tgt->query("SELECT fave, era FROM moon WHERE email = 'fan@example.test'")->fetch();
        self::assertSame('F', $row['fave']);
        self::assertSame('E', $row['era']);
    }

    public function testNewFieldColumnsAreTextThatCanBeNull(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'moon', 'http://a.test/moon', ['additional' => 'fave'], ['fave']);

        $this->runImport($src, $tgt, ['import_owned_listings' => '1']);

        $columns = array_column($tgt->query('PRAGMA table_info(moon)')->fetchAll(), null, 'name');
        self::assertSame('TEXT', $columns['fave']['type']);
        self::assertSame(0, (int) $columns['fave']['notnull']);
        self::assertNull($columns['fave']['dflt_value']);
    }

    public function testNoGeneratedColumnHasATextDefault(): void
    {
        foreach (['EnthusiastImportSource.php', 'ListingAdminImportSource.php'] as $file) {
            $code = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Import/' . $file);
            self::assertStringNotContainsString("`{\$field}` TEXT NOT NULL DEFAULT", $code, $file);
        }
    }

    public function testAFieldMissingFromTheSourceTableCannotBlankAnAnswer(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'moon', 'http://a.test/moon', ['additional' => 'fave,era'], ['era']);
        $this->addFan($src, 'moon', ['email' => 'fan@example.test', 'era' => 'E']);
        $this->addTargetListing($tgt, 9, 'moon', 'http://a.test/moon', ['additional' => 'fave,era,mine'], ['fave', 'era', 'mine']);
        $this->addFan($tgt, 'moon', ['email' => 'fan@example.test', 'fave' => 'Keep', 'era' => 'old', 'mine' => 'Also keep']);

        $this->runImport($src, $tgt, ['conflict_strategy' => 'overwrite', 'import_members' => '1']);

        $row = $tgt->query("SELECT fave, era, mine FROM moon WHERE email = 'fan@example.test'")->fetch();
        self::assertSame('Keep', $row['fave']);
        self::assertSame('E', $row['era']);
        self::assertSame('Also keep', $row['mine']);
    }

    public function testANullAnswerAddressOrFieldImportsAsBlank(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'moon', 'http://a.test/moon', ['additional' => 'fave'], ['fave']);
        $this->addFan($src, 'moon', ['email' => 'fan@example.test', 'url' => null, 'fave' => null]);

        $result = $this->runImport($src, $tgt, self::SCOPE);

        self::assertSame(1, $this->row($result, 'Members')['imported']);
        self::assertSame('', $tgt->query("SELECT url FROM moon")->fetchColumn());
    }

    public function testAnUnknownSourceFieldIsNeverWritten(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'moon', 'http://a.test/moon', ['additional' => 'fave'], ['fave']);
        $this->addFan($src, 'moon', ['email' => 'fan@example.test', 'fave' => 'F']);
        $this->addTargetListing($tgt, 9, 'moon', 'http://a.test/moon', ['additional' => ''], []);

        $result = $this->runImport($src, $tgt, ['conflict_strategy' => 'overwrite', 'import_members' => '1']);

        self::assertSame(1, $this->row($result, 'Members')['imported']);
        self::assertSame([], $this->row($result, 'Members')['errors']);
    }

    public function testADefinitionWithNoColumnGetsOneOnAnyImport(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'moon', 'http://a.test/moon', ['additional' => 'fave'], ['fave']);
        $this->addFan($src, 'moon', ['email' => 'fan@example.test', 'fave' => 'F']);
        $this->addTargetListing($tgt, 9, 'moon', 'http://a.test/moon', ['additional' => 'fave,owner_only'], []);

        $this->runImport($src, $tgt, self::SCOPE);

        $columns = array_column($tgt->query('PRAGMA table_info(moon)')->fetchAll(), 'name');
        self::assertContains('fave', $columns);
        self::assertContains('owner_only', $columns);
        self::assertSame('F', $tgt->query('SELECT fave FROM moon')->fetchColumn());
    }

    public function testAFieldNamedLikeABuiltInGetsItsOwnColumnAndNeverTheRealOne(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'moon', 'http://a.test/moon');
        $this->addTargetListing($tgt, 9, 'moon', 'http://a.test/moon', ['additional' => 'name'], []);

        $this->runImport($src, $tgt, self::SCOPE);

        self::assertContains('additional_name', array_column($tgt->query('PRAGMA table_info(moon)')->fetchAll(), 'name'));
    }

    public function testRepeatedImportsDoNotDisturbANormalListing(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'moon', 'http://a.test/moon', ['additional' => 'fave'], ['fave']);
        $this->addFan($src, 'moon', ['email' => 'fan@example.test', 'name' => 'Fan', 'fave' => 'F']);

        $this->runImport($src, $tgt, self::SCOPE);
        $tgt->exec("UPDATE moon SET name = 'Edited in Luster'");
        $second = $this->runImport($src, $tgt, self::SCOPE);

        self::assertSame(0, $this->row($second, 'Members')['imported']);
        self::assertSame(1, $this->row($second, 'Members')['skipped']);
        self::assertSame('Edited in Luster', $tgt->query('SELECT name FROM moon')->fetchColumn());
        self::assertSame(1, $this->rowCount($tgt, 'owned'));
    }

    // -----------------------------------------------------------------------
    // The unconfirmed layout
    // -----------------------------------------------------------------------

    /** @param list<string> $columns */
    private function enthSource(string $info, array $columns, string $status = 'approved'): PDO
    {
        $src = $this->sourceDb();
        $src->exec("CREATE TABLE enth_fanlistings (id INTEGER PRIMARY KEY, dbprefix TEXT NOT NULL, name TEXT NOT NULL DEFAULT '', subject TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '', catid TEXT NOT NULL DEFAULT '', additional_info TEXT NOT NULL DEFAULT '', trouble INTEGER NOT NULL DEFAULT 0, upcoming INTEGER NOT NULL DEFAULT 0)");
        $src->prepare("INSERT INTO enth_fanlistings (id, dbprefix, name, url, additional_info) VALUES (1, 'fl_', 'Listing', 'http://a.test/moon', :i)")->execute(['i' => $info]);
        $cols = '';
        foreach ($columns as $column) {
            $cols .= ", `{$column}` TEXT NULL";
        }
        $state = $status === 'approved' ? 'approved INTEGER NOT NULL DEFAULT 0' : 'pending INTEGER NOT NULL DEFAULT 0';
        $src->exec("CREATE TABLE fl_members (email TEXT PRIMARY KEY, name TEXT NOT NULL DEFAULT '', country TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '', password TEXT NOT NULL DEFAULT '', added TEXT NULL, {$state}{$cols})");
        return $src;
    }

    /** @return array<string, array{0: int, 1: int}> */
    public static function approvals(): array
    {
        return ['waiting' => [0, 1], 'approved' => [1, 0]];
    }

    /** @dataProvider approvals */
    public function testStatusComesFromApprovedWhenTheSourceHasNoPending(int $approved, int $pending): void
    {
        $src = $this->enthSource('', []);
        $tgt = $this->targetDb();
        $src->exec("INSERT INTO fl_members (email, approved) VALUES ('fan@example.test', {$approved})");

        $this->runImport($src, $tgt, self::SCOPE);

        self::assertSame($pending, (int) $tgt->query('SELECT pending FROM fl_members')->fetchColumn());
    }

    public function testAnOverwriteAlsoRestoresStatusFromApproved(): void
    {
        $src = $this->enthSource('', []);
        $tgt = $this->targetDb();
        $src->exec("INSERT INTO fl_members (email, approved) VALUES ('fan@example.test', 0)");
        $this->addTargetListing($tgt, 9, 'fl_members', 'http://a.test/moon');
        $this->addFan($tgt, 'fl_members', ['email' => 'fan@example.test', 'pending' => 0]);

        $this->runImport($src, $tgt, ['conflict_strategy' => 'overwrite', 'import_members' => '1']);

        self::assertSame(1, (int) $tgt->query('SELECT pending FROM fl_members')->fetchColumn());
    }

    public function testDuplicateNamesNoLongerStopTheTableBeingMade(): void
    {
        $src = $this->enthSource('Fave,Fave,text,0|fave,Fave,text,0', ['additional_Fave']);
        $tgt = $this->targetDb();
        $src->exec("INSERT INTO fl_members (email, approved, additional_Fave) VALUES ('fan@example.test', 1, 'F')");

        $result = $this->runImport($src, $tgt, self::SCOPE);

        self::assertSame([], $this->row($result, 'Members')['errors']);
        self::assertSame('F', $tgt->query('SELECT additional_Fave FROM fl_members')->fetchColumn());
    }

    public function testSerializedDefinitionsWithNamedKeysKeepTheirAnswers(): void
    {
        $src = $this->enthSource(serialize([['name' => 'fave', 'label' => 'Fave', 'type' => 'text', 'required' => 0]]), ['additional_fave']);
        $tgt = $this->targetDb();
        $src->exec("INSERT INTO fl_members (email, approved, additional_fave) VALUES ('fan@example.test', 1, 'F')");

        $this->runImport($src, $tgt, self::SCOPE);

        self::assertSame('F', $tgt->query('SELECT additional_fave FROM fl_members')->fetchColumn());
    }

    public function testAFieldNamedLikeABuiltInStaysItsOwnField(): void
    {
        $src = $this->enthSource('name,Their name,text,0', ['additional_name']);
        $tgt = $this->targetDb();
        $src->exec("INSERT INTO fl_members (email, approved, name, additional_name) VALUES ('fan@example.test', 1, 'Real', 'Answer')");

        $this->runImport($src, $tgt, self::SCOPE);

        $row = $tgt->query('SELECT name, additional_name FROM fl_members')->fetch();
        self::assertSame('Real', $row['name']);
        self::assertSame('Answer', $row['additional_name']);
    }

    // -----------------------------------------------------------------------
    // Tokens, and what's left alone
    // -----------------------------------------------------------------------

    public function testPlainTokensBecomeRealOnes(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'moon', 'http://a.test/moon', [
            'additional' => 'fave', 'listtemplate' => '$$fan_url_plain$$ $$fan_email_plain$$ $$fan_fave$$',
        ], ['fave']);

        $this->runImport($src, $tgt, ['import_owned_listings' => '1', 'import_settings' => '1']);

        self::assertSame('{fan_url} {fan_email} {fan_fave}', $tgt->query('SELECT members_template FROM lstr_fanlisting_settings')->fetchColumn());
    }

    public function testTheQueueIsNeverTouched(): void
    {
        $src = $this->sourceDb();
        $tgt = $this->targetDb();
        $this->addSourceListing($src, 1, 'moon', 'http://a.test/moon', ['additional' => 'fave,era'], ['fave', 'era']);
        $this->addFan($src, 'moon', ['email' => 'fan@example.test']);
        $this->addTargetListing($tgt, 9, 'moon', 'http://a.test/moon', ['additional' => 'fave'], ['fave']);

        $this->runImport($src, $tgt, ['conflict_strategy' => 'overwrite'] + self::SCOPE);

        self::assertSame([], $tgt->statementsMatching('/lstr_pending_updates/'));
    }
}
