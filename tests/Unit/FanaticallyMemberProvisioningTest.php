<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Import\FanaticallyImportSource;
use Luster\Import\ImportOptions;
use Luster\Import\ImportResult;
use Luster\Repositories\FieldRepo;
use Luster\Repositories\MemberRepo;
use Luster\Tests\Unit\Support\FanaticallyImportFixture;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Fanatically import: every imported listing gets usable member storage and
 * field definitions, on both target schemas, whether or not it has members
 * and whether or not Members is ticked. Custom-field names are planned once
 * (reserved names and clashes renamed and reported) and that plan is used for
 * the member table, the definitions, member values and queued updates. Skip
 * leaves a listing that was already in Luster exactly as it was.
 *
 * Everything is read back through MemberRepo/FieldRepo, the same classes the
 * join form, member lists and stats use.
 */
final class FanaticallyMemberProvisioningTest extends TestCase
{
    use FanaticallyImportFixture;

    /** Fields that hit both kinds of clash: a reserved name, and two names that clean up the same. */
    private const FIELDS = [
        ['name' => 'Favorite Song', 'label' => 'Favorite song', 'options' => ['Golden', 'Takedown']],
        ['name' => 'Email',         'label' => 'Email again'],
        ['name' => 'a-b',           'label' => 'A dash B'],
        ['name' => 'a b',           'label' => 'A space B'],
    ];

    private function dbtableOf(PDO $tgt, bool $owned): string
    {
        return (string) $tgt->query($owned ? 'SELECT dbtable FROM owned LIMIT 1' : 'SELECT dbprefix FROM enth_fanlistings LIMIT 1')->fetchColumn();
    }

    /** @return list<string> */
    private function columnsOf(PDO $tgt, string $table): array
    {
        return array_column($tgt->query("PRAGMA table_info(`{$table}`)")->fetchAll(), 'name');
    }

    // -----------------------------------------------------------------------
    // Zero members / Members not ticked
    // -----------------------------------------------------------------------

    public function testListingWithNoMembersGetsAWorkingMemberTableAndFieldsOnTheUnprefixedSchema(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->addSourceListing($src, 1, 'Moon FL', 'https://fans.test/moon', self::FIELDS);

        $result = $this->runImport($src, $tgt, $this->options(['import_listings' => '1']));
        self::assertSame([], $this->resultRow($result, 'Owned Listings')['errors']);

        $table = $this->dbtableOf($tgt, true);
        self::assertContains('favorite_song', $this->columnsOf($tgt, $table));
        self::assertContains('email_2', $this->columnsOf($tgt, $table));

        $listingId = (int) $tgt->query('SELECT listingid FROM owned LIMIT 1')->fetchColumn();
        $fields    = (new FieldRepo($this->database($tgt), $this->config()))->getFieldsForListing($listingId);
        self::assertSame(['favorite_song', 'email_2', 'a_b', 'a_b_2'], array_map(static fn($f) => $f->name, $fields));
        self::assertSame('dropdown', $fields[0]->type);

        // A first fan joins: the same insert JoinController makes.
        $members = new MemberRepo($this->database($tgt), $this->config());
        self::assertSame(0, $members->countApproved($table));
        $members->insert($table, array_merge(
            ['name' => 'Fan', 'email' => 'fan@example.com', 'url' => '', 'country' => 'Canada', 'showurl' => 0,
             'password' => 'hash', 'added' => date('Y-m-d H:i:s'), 'favorite_song' => 'Golden'],
            $members->pendingJoinColumns(),
        ));
        $pending = $members->getPending($table);
        self::assertCount(1, $pending);
        self::assertSame('Golden', $pending[0]->extraColumns['favorite_song']);
    }

    // (The standard enth_ layout is refused before any write; see
    // FanaticallyImportSourceTest::testStandardEnthusiastLayoutIsRefusedBeforeAnythingIsWritten.)

    // -----------------------------------------------------------------------
    // Member values and approval
    // -----------------------------------------------------------------------

    public function testMembersLandInTheirPlannedColumnsWithTheirOwnApproval(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->addSourceListing($src, 1, 'Moon FL', 'https://fans.test/moon', self::FIELDS);
        $this->addSourceMember($src, 1, 'one@example.com', true, ['a-b' => 'dash', 'a b' => 'space', 'Email' => 'second@example.com']);
        $this->addSourceMember($src, 1, 'two@example.com', false);

        $result = $this->runImport($src, $tgt, $this->options(['import_listings' => '1', 'import_members' => '1']));
        $row    = $this->resultRow($result, 'Members');
        self::assertSame([], $row['errors']);
        self::assertSame(2, $row['imported']);

        $prefix   = $this->dbtableOf($tgt, true);
        $members  = new MemberRepo($this->database($tgt), $this->config());
        $approved = $members->getApproved($prefix);
        self::assertCount(1, $approved);
        self::assertSame('one@example.com', $approved[0]->email, 'The member keeps their own email');
        self::assertSame('dash', $approved[0]->extraColumns['a_b']);
        self::assertSame('space', $approved[0]->extraColumns['a_b_2'], 'Two fields that clean up the same stay apart');
        self::assertSame('second@example.com', $approved[0]->extraColumns['email_2']);
        self::assertSame(1, $members->countPending($prefix), 'An unapproved Fanatically member stays pending');
    }

    // -----------------------------------------------------------------------
    // Planning is shown before anything is written
    // -----------------------------------------------------------------------

    public function testPreviewAndResultsReportTheSameRenames(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->addSourceListing($src, 1, 'Moon FL', 'https://fans.test/moon', self::FIELDS);
        $opts = $this->options(['import_listings' => '1']);

        $preview = $this->previewWarnings($src, $tgt, $opts);
        self::assertSomeWarningContains($preview, 'the field "Email" is saved as "email_2"');
        self::assertSomeWarningContains($preview, 'the field "a b" is saved as "a_b_2"');

        $results = $this->resultRow($this->runImport($src, $tgt, $opts), 'Owned Listings')['warnings'];
        self::assertSomeWarningContains($results, 'the field "Email" is saved as "email_2"');
        self::assertSomeWarningContains($results, 'the field "a b" is saved as "a_b_2"');
    }

    public function testFieldWithNoUsableNameOrADuplicateNameIsLeftOutAndReported(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->addSourceListing($src, 1, 'Moon FL', 'https://fans.test/moon', [
            ['name' => '!!!', 'label' => 'Nothing usable'],
            ['name' => 'Color', 'label' => 'Colour'],
            ['name' => 'Color', 'label' => 'Colour again'],
        ]);

        $row = $this->resultRow($this->runImport($src, $tgt, $this->options(['import_listings' => '1'])), 'Owned Listings');
        self::assertSame([], $row['errors']);
        self::assertSomeWarningContains($row['warnings'], 'the field "!!!" has no letters or numbers');
        self::assertSomeWarningContains($row['warnings'], 'two fields called "Color"');
        self::assertSame(['color'], array_map(
            static fn($f) => $f->name,
            (new FieldRepo($this->database($tgt), $this->config()))->getFieldsForListing(1),
        ));
    }

    // -----------------------------------------------------------------------
    // Skip leaves an existing listing alone; Overwrite brings it up to date
    // -----------------------------------------------------------------------

    /** A listing already in Luster at the same address, with an older member table. */
    private function existingTargetListing(PDO $tgt): void
    {
        $tgt->exec("INSERT INTO owned (listingid, dbtable, title, subject, url, additional) VALUES (1, 'old_moon', 'Moon', 'Moon', 'https://fans.test/moon', '')");
        $tgt->exec("CREATE TABLE old_moon (
            email TEXT NOT NULL PRIMARY KEY, name TEXT NOT NULL DEFAULT '', country TEXT NOT NULL DEFAULT '',
            url TEXT NOT NULL DEFAULT '', pending INTEGER NOT NULL DEFAULT 0, password TEXT NOT NULL DEFAULT '',
            showemail INTEGER NOT NULL DEFAULT 1, showurl INTEGER NOT NULL DEFAULT 1, added TEXT NULL)");
    }

    public function testSkipLeavesAnExistingListingsTableAndFieldsAsTheyAre(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->existingTargetListing($tgt);
        $this->addSourceListing($src, 7, 'Moon FL', 'https://fans.test/moon', [['name' => 'Favorite Song', 'label' => 'Song']]);
        $this->addSourceMember($src, 7, 'fan@example.com', true, ['Favorite Song' => 'Golden']);

        $result = $this->runImport($src, $tgt, $this->options([
            'import_listings' => '1', 'import_members' => '1', 'conflict_strategy' => 'skip',
        ]));

        self::assertNotContains('favorite_song', $this->columnsOf($tgt, 'old_moon'), 'Skip must not change the table');
        self::assertSame('', $tgt->query('SELECT additional FROM owned WHERE listingid = 1')->fetchColumn(), 'Skip must not add field definitions');

        $members = $this->resultRow($result, 'Members');
        self::assertSame([], $members['errors']);
        self::assertSame(1, $members['imported'], 'The member still comes over');
        self::assertSomeWarningContains($members['warnings'], "custom field value(s) weren't imported");
    }

    public function testOverwriteAddsTheMissingColumnAndDefinitionToAnExistingListing(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->existingTargetListing($tgt);
        $this->addSourceListing($src, 7, 'Moon FL', 'https://fans.test/moon', [['name' => 'Favorite Song', 'label' => 'Song']]);
        $this->addSourceMember($src, 7, 'fan@example.com', true, ['Favorite Song' => 'Golden']);

        $result = $this->runImport($src, $tgt, $this->options([
            'import_listings' => '1', 'import_members' => '1', 'conflict_strategy' => 'overwrite',
        ]));
        self::assertSame([], $this->resultRow($result, 'Members')['errors']);

        self::assertContains('favorite_song', $this->columnsOf($tgt, 'old_moon'));
        $fields = (new FieldRepo($this->database($tgt), $this->config()))->getFieldsForListing(1);
        self::assertSame(['favorite_song'], array_map(static fn($f) => $f->name, $fields));
        $member = (new MemberRepo($this->database($tgt), $this->config()))->getApproved('old_moon')[0];
        self::assertSame('Golden', $member->extraColumns['favorite_song']);
    }

    public function testSkippedListingWithNoMemberTableSkipsItsMembersAndKeepsGoing(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $tgt->exec("INSERT INTO owned (listingid, dbtable, title, subject, url) VALUES (1, 'missing_table', 'Moon', 'Moon', 'https://fans.test/moon')");
        $this->addSourceListing($src, 7, 'Moon FL', 'https://fans.test/moon');
        $this->addSourceListing($src, 8, 'Sun FL', 'https://fans.test/sun');
        $this->addSourceMember($src, 7, 'moonfan@example.com');
        $this->addSourceMember($src, 8, 'sunfan@example.com');

        $result = $this->runImport($src, $tgt, $this->options(['import_listings' => '1', 'import_members' => '1']));

        self::assertSomeWarningContains($this->resultRow($result, 'Owned Listings')['warnings'], 'it has no member table');
        $members = $this->resultRow($result, 'Members');
        self::assertSame(1, $members['imported'], 'The other listing\'s member still imports');
        self::assertSame('moonfan@example.com', $members['skipped_items'][0]['label']);
        self::assertStringContainsString('no member table', $members['skipped_items'][0]['reason']);
    }

    // -----------------------------------------------------------------------
    // Queued member updates use the same plan
    // -----------------------------------------------------------------------

    public function testQueuedUpdateUsesThePlannedColumnName(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->addSourceListing($src, 1, 'Moon FL', 'https://fans.test/moon', self::FIELDS);
        $this->addSourceMember($src, 1, 'fan@example.com', true, null, json_encode([
            'name'                => 'New Name',
            'custom_field_values' => json_encode(['Email' => 'second@example.com', 'Unknown' => 'x']),
        ]));

        $this->runImport($src, $tgt, $this->options(['import_listings' => '1', 'import_members' => '1']));

        $changes = json_decode((string) $tgt->query('SELECT changes FROM lstr_pending_updates')->fetchColumn(), true);
        self::assertSame(['name' => 'New Name', 'email_2' => 'second@example.com'], $changes,
            'The custom value goes to the renamed column; a field the listing doesn\'t have is left out');
    }

    // -----------------------------------------------------------------------
    // Luster's own tables are never handed to a listing
    // -----------------------------------------------------------------------

    public function testListingsNamedAfterLustersOwnTablesGetTheirOwnTable(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->addSourceListing($src, 1, 'owned', 'https://fans.test/owned', [['name' => 'favorite', 'label' => 'Favorite']]);
        $this->addSourceListing($src, 2, 'lstr codes', 'https://fans.test/codes', [['name' => 'favorite', 'label' => 'Favorite']]);
        $ownedBefore = $this->columnsOf($tgt, 'owned');
        $codesBefore = $this->columnsOf($tgt, 'lstr_codes');

        // Members unticked: provisioning still runs, so this is where it used to go wrong.
        $row = $this->resultRow($this->runImport($src, $tgt, $this->options(['import_listings' => '1'])), 'Owned Listings');
        self::assertSame([], $row['errors']);

        self::assertSame($ownedBefore, $this->columnsOf($tgt, 'owned'), 'The registry table must not change');
        self::assertSame($codesBefore, $this->columnsOf($tgt, 'lstr_codes'), 'lstr_codes must not change');
        $dbtables = $tgt->query('SELECT dbtable FROM owned ORDER BY listingid')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['owned_2', 'fl_lstr_codes'], $dbtables);
        self::assertContains('favorite', $this->columnsOf($tgt, 'owned_2'));
    }

    /** @return array<string, array{0: string}> */
    public static function foreignTableProvider(): array
    {
        return ['a Luster table' => ['lstr_codes'], 'an unrelated table' => ['guestbook']];
    }

    /**
     * @dataProvider foreignTableProvider
     */
    public function testExistingListingPointingAtANonMemberTableIsLeftUntouched(string $table): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $tgt->exec("CREATE TABLE IF NOT EXISTS guestbook (id INTEGER PRIMARY KEY, message TEXT NOT NULL DEFAULT '')");
        $tgt->prepare("INSERT INTO owned (listingid, dbtable, title, subject, url) VALUES (1, :t, 'Moon', 'Moon', 'https://fans.test/moon')")
            ->execute(['t' => $table]);
        $before = $this->columnsOf($tgt, $table);
        $this->addSourceListing($src, 7, 'Moon FL', 'https://fans.test/moon', [['name' => 'favorite', 'label' => 'Favorite']]);
        $this->addSourceMember($src, 7, 'fan@example.com', true, ['favorite' => 'x']);

        $result = $this->runImport($src, $tgt, $this->options([
            'import_listings' => '1', 'import_members' => '1', 'conflict_strategy' => 'overwrite',
        ]));

        self::assertSame($before, $this->columnsOf($tgt, $table), 'Nothing may be added to a table that isn\'t a member table');
        self::assertSomeWarningContains($this->resultRow($result, 'Owned Listings')['errors'], 'nothing was changed');
        $members = $this->resultRow($result, 'Members');
        self::assertSame(0, $members['imported']);
        self::assertSame('fan@example.com', $members['skipped_items'][0]['label']);
    }

    // -----------------------------------------------------------------------
    // Each field keeps its name from one import to the next
    // -----------------------------------------------------------------------

    /**
     * @param list<array<string,mixed>> $fields
     * @param array<string,string>      $values
     */
    private function importFields(PDO $src, PDO $tgt, array $fields, array $values, string $strategy): ImportResult
    {
        $src->exec('DELETE FROM listings');
        $src->exec('DELETE FROM members');
        $this->addSourceListing($src, 1, 'Moon FL', 'https://fans.test/moon', $fields);
        $this->addSourceMember($src, 1, 'fan@example.com', true, $values);
        $result = $this->runImport($src, $tgt, $this->options([
            'import_listings' => '1', 'import_members' => '1', 'conflict_strategy' => $strategy,
        ]));
        self::assertSame([], $this->resultRow($result, 'Members')['errors']);
        return $result;
    }

    /** @return array<string,string> The source field → Luster field names stored with a Fanatically listing's import record */
    private function fieldMap(PDO $tgt, int $srcId = 1): array
    {
        $st = $tgt->prepare("SELECT metadata_json FROM lstr_listing_external_refs WHERE source = 'fanatically' AND external_id = :id");
        $st->execute(['id' => (string) $srcId]);
        $meta = json_decode((string) $st->fetchColumn(), true);
        return is_array($meta) ? ($meta['field_map'] ?? []) : [];
    }

    /** @return array<string,string> field name => label */
    private function fieldLabels(PDO $tgt): array
    {
        $labels = [];
        foreach ((new FieldRepo($this->database($tgt), $this->config()))->getFieldsForListing(1) as $field) {
            $labels[$field->name] = $field->label;
        }
        return $labels;
    }

    public function testReorderedFieldsKeepTheirColumnsOnARepeatImport(): void
    {
        $src    = $this->makeSource();
        $tgt    = $this->makeOwnedTarget();
        $dash   = ['name' => 'a-b', 'label' => 'Dash'];
        $space  = ['name' => 'a b', 'label' => 'Space'];
        $values = ['a-b' => 'DASH', 'a b' => 'SPACE'];

        $this->importFields($src, $tgt, [$dash, $space], $values, 'skip');
        $this->importFields($src, $tgt, [$space, $dash], $values, 'overwrite');

        $member = (new MemberRepo($this->database($tgt), $this->config()))->getApproved($this->dbtableOf($tgt, true))[0];
        self::assertSame('DASH', $member->extraColumns['a_b']);
        self::assertSame('SPACE', $member->extraColumns['a_b_2']);
        self::assertSame(['a_b' => 'Dash', 'a_b_2' => 'Space'], $this->fieldLabels($tgt), 'Labels and values still agree');
    }

    public function testANewClashingFieldDoesNotTakeAnExistingFieldsColumn(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();

        $this->importFields($src, $tgt, [['name' => 'a-b', 'label' => 'Dash']], ['a-b' => 'DASH'], 'skip');
        // The source later gains "a b", listed first.
        $this->importFields($src, $tgt, [['name' => 'a b', 'label' => 'Space'], ['name' => 'a-b', 'label' => 'Dash']],
            ['a-b' => 'DASH', 'a b' => 'SPACE'], 'overwrite');

        $member = (new MemberRepo($this->database($tgt), $this->config()))->getApproved($this->dbtableOf($tgt, true))[0];
        self::assertSame('DASH', $member->extraColumns['a_b'], 'The original field keeps its column');
        self::assertSame('SPACE', $member->extraColumns['a_b_2']);
        self::assertSame(['a_b' => 'Dash', 'a_b_2' => 'Space'], $this->fieldLabels($tgt));
    }

    public function testQueuedUpdatesFollowTheStoredNamesToo(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->importFields($src, $tgt, [['name' => 'a-b', 'label' => 'Dash'], ['name' => 'a b', 'label' => 'Space']], [], 'skip');

        $src->exec('DELETE FROM listings');
        $src->exec('DELETE FROM members');
        $this->addSourceListing($src, 1, 'Moon FL', 'https://fans.test/moon', [['name' => 'a b', 'label' => 'Space'], ['name' => 'a-b', 'label' => 'Dash']]);
        $this->addSourceMember($src, 1, 'fan@example.com', true, null, json_encode([
            'custom_field_values' => json_encode(['a-b' => 'NEW DASH']),
        ]));
        $tgt->exec('DELETE FROM lstr_pending_updates');
        $this->runImport($src, $tgt, $this->options(['import_listings' => '1', 'import_members' => '1', 'conflict_strategy' => 'overwrite']));

        $changes = json_decode((string) $tgt->query('SELECT changes FROM lstr_pending_updates')->fetchColumn(), true);
        self::assertSame(['a_b' => 'NEW DASH'], $changes);
    }

    // -----------------------------------------------------------------------
    // Fields Luster would otherwise read wrongly, or lose without a word
    // -----------------------------------------------------------------------

    public function testFieldStartingWithAdditionalIsRenamedSoItsValueShows(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->addSourceListing($src, 1, 'Moon FL', 'https://fans.test/moon', [['name' => 'additional_color', 'label' => 'Colour']]);
        $this->addSourceMember($src, 1, 'fan@example.com', true, ['additional_color' => 'blue']);

        $result = $this->runImport($src, $tgt, $this->options(['import_listings' => '1', 'import_members' => '1']));

        self::assertSomeWarningContains($this->resultRow($result, 'Owned Listings')['warnings'], 'the field "additional_color" is saved as "color"');
        self::assertSame(['color' => 'Colour'], $this->fieldLabels($tgt));
        $member = (new MemberRepo($this->database($tgt), $this->config()))->getApproved($this->dbtableOf($tgt, true))[0];
        self::assertSame('blue', $member->extraColumns['color'], 'The value is read back under the field\'s name');
    }

    public function testUnreadableFieldEntriesAndListsAreReported(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->addSourceListing($src, 1, 'Moon FL', 'https://fans.test/moon', ['not a field', ['label' => 'No name'], ['name' => 'song', 'label' => 'Song']]);
        $this->addSourceListing($src, 2, 'Sun FL', 'https://fans.test/sun');
        $src->exec("UPDATE listings SET custom_fields = '[{\"name\":' WHERE id = 2");
        $opts = $this->options(['import_listings' => '1']);

        foreach ([$this->previewWarnings($src, $tgt, $opts), $this->resultRow($this->runImport($src, $tgt, $opts), 'Owned Listings')['warnings']] as $warnings) {
            self::assertSomeWarningContains($warnings, "Moon FL: custom field entry #1 couldn't be read");
            self::assertSomeWarningContains($warnings, 'Moon FL: custom field entry #2 has no name');
            self::assertSomeWarningContains($warnings, "Sun FL: its list of custom fields couldn't be read");
        }
        self::assertSame(['song' => 'Song'], $this->fieldLabels($tgt));
    }

    // -----------------------------------------------------------------------
    // A field's column belongs to that field: a removed field keeps it, and a
    // field the listing already had is never written over
    // -----------------------------------------------------------------------

    /** @return list<string> Every table in the target */
    private function tableNames(PDO $tgt): array
    {
        return $tgt->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    public function testAFieldReplacedInFanaticallyDoesNotTakeOverTheOldFieldsColumn(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->importFields($src, $tgt, [['name' => 'a-b', 'label' => 'Dash']], ['a-b' => 'DASH'], 'skip');

        // Fanatically drops "a-b" and gets "a b", which cleans up to the same name.
        $result = $this->importFields($src, $tgt, [['name' => 'a b', 'label' => 'Space']], ['a b' => 'SPACE'], 'overwrite');

        self::assertSomeWarningContains($this->resultRow($result, 'Owned Listings')['warnings'],
            'the field "a b" is saved as "a_b_2" because an earlier import used "a_b" for a field that\'s no longer in Fanatically');
        $member = (new MemberRepo($this->database($tgt), $this->config()))->getApproved($this->dbtableOf($tgt, true))[0];
        self::assertSame('DASH', $member->extraColumns['a_b'], 'The old field keeps its value');
        self::assertSame('SPACE', $member->extraColumns['a_b_2']);
        self::assertSame(['a_b' => 'Dash', 'a_b_2' => 'Space'], $this->fieldLabels($tgt), 'Labels and values still agree');
        self::assertSame(['a-b' => 'a_b', 'a b' => 'a_b_2'], $this->fieldMap($tgt), 'The removed field keeps its name for later imports');
    }

    public function testANameKeptForARemovedFieldIsNotReusedEvenAfterItsColumnIsDeleted(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->importFields($src, $tgt, [['name' => 'a-b', 'label' => 'Dash']], ['a-b' => 'DASH'], 'skip');

        // The owner deletes the field in Luster, column and all.
        $table = $this->dbtableOf($tgt, true);
        $tgt->exec("ALTER TABLE `{$table}` DROP COLUMN a_b");
        $tgt->exec("UPDATE owned SET additional = '' WHERE listingid = 1");

        $this->importFields($src, $tgt, [['name' => 'a b', 'label' => 'Space']], ['a b' => 'SPACE'], 'overwrite');

        self::assertSame(['a-b' => 'a_b', 'a b' => 'a_b_2'], $this->fieldMap($tgt),
            "\"a_b\" still belongs to \"a-b\", so the names on record never collide");
        self::assertSame(['a_b_2' => 'Space'], $this->fieldLabels($tgt));
    }

    public function testAFieldTheOwnerAddedInLusterKeepsItsValues(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->importFields($src, $tgt, [['name' => 'Song', 'label' => 'Song']], ['Song' => 'Golden'], 'skip');

        // The owner adds a field of their own on the Fields page and fills it in.
        $table  = $this->dbtableOf($tgt, true);
        $fields = new FieldRepo($this->database($tgt), $this->config());
        $defs   = unserialize((string) $tgt->query('SELECT additional FROM owned WHERE listingid = 1')->fetchColumn());
        $defs[] = ['name' => 'color', 'label' => 'Colour (mine)', 'type' => 'text', 'required' => false];
        $fields->saveFields(1, $defs);
        $fields->addColumn($table, 'color');
        $tgt->exec("UPDATE `{$table}` SET color = 'red'");

        // Fanatically later gets a field that cleans up to the same name.
        $result = $this->importFields($src, $tgt, [['name' => 'Song', 'label' => 'Song'], ['name' => 'Color', 'label' => 'Colour']],
            ['Song' => 'Golden', 'Color' => 'blue'], 'overwrite');

        self::assertSomeWarningContains($this->resultRow($result, 'Owned Listings')['warnings'],
            'the field "Color" is saved as "color_2" because the listing in Luster already has a field called "color"');
        $member = (new MemberRepo($this->database($tgt), $this->config()))->getApproved($table)[0];
        self::assertSame('red', $member->extraColumns['color'], "The owner's value stays");
        self::assertSame('blue', $member->extraColumns['color_2']);
        self::assertSame(['song' => 'Song', 'color' => 'Colour (mine)', 'color_2' => 'Colour'], $this->fieldLabels($tgt));
    }

    /** The existing Moon listing, with a "fave" column of its own and a member who filled it in. */
    private function existingListingWithAFaveColumn(PDO $tgt): void
    {
        $this->existingTargetListing($tgt);
        $tgt->exec('ALTER TABLE old_moon ADD COLUMN fave TEXT NULL');
        $tgt->exec("INSERT INTO old_moon (email, name, fave) VALUES ('fan@example.com', 'Fan', 'from before')");
    }

    public function testAColumnTheListingAlreadyHadIsNotWrittenOverOnOverwrite(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->existingListingWithAFaveColumn($tgt);
        $this->addSourceListing($src, 7, 'Moon FL', 'https://fans.test/moon', [['name' => 'fave', 'label' => 'Favourite']]);
        $this->addSourceMember($src, 7, 'fan@example.com', true, ['fave' => 'from Fanatically']);

        $result = $this->runImport($src, $tgt, $this->options([
            'import_listings' => '1', 'import_members' => '1', 'conflict_strategy' => 'overwrite',
        ]));

        self::assertSomeWarningContains($this->resultRow($result, 'Owned Listings')['warnings'],
            'the field "fave" is saved as "fave_2" because the listing in Luster already has a field called "fave"');
        $member = (new MemberRepo($this->database($tgt), $this->config()))->getApproved('old_moon')[0];
        self::assertSame('from before', $member->extraColumns['fave'], "The listing's own value stays");
        self::assertSame('from Fanatically', $member->extraColumns['fave_2']);
    }

    public function testAColumnTheListingAlreadyHadIsLeftAloneOnSkip(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->existingListingWithAFaveColumn($tgt);
        $before = $this->columnsOf($tgt, 'old_moon');
        $this->addSourceListing($src, 7, 'Moon FL', 'https://fans.test/moon', [['name' => 'fave', 'label' => 'Favourite']]);
        $this->addSourceMember($src, 7, 'other@example.com', true, ['fave' => 'from Fanatically']);

        $result = $this->runImport($src, $tgt, $this->options([
            'import_listings' => '1', 'import_members' => '1', 'conflict_strategy' => 'skip',
        ]));

        self::assertSame($before, $this->columnsOf($tgt, 'old_moon'), 'Skip must not change the table');
        $members = $this->resultRow($result, 'Members');
        self::assertSame(1, $members['imported']);
        self::assertSomeWarningContains($members['warnings'], "custom field value(s) weren't imported");
        self::assertNull($tgt->query("SELECT fave FROM old_moon WHERE email = 'other@example.com'")->fetchColumn(),
            "Fanatically's value doesn't go into the listing's own field");
    }

    public function testNamesStoredForAnotherListingAreIgnored(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->existingTargetListing($tgt);
        $tgt->exec('ALTER TABLE old_moon ADD COLUMN color TEXT NULL');
        $tgt->exec("INSERT INTO owned (listingid, dbtable, title, subject, url) VALUES (2, 'sun', 'Sun', 'Sun', 'https://fans.test/sun')");
        // An earlier import recorded Fanatically listing 7 against Sun, with Sun's names.
        $tgt->exec("INSERT INTO lstr_listing_external_refs (listing_id, source, external_id, metadata_json)
            VALUES (2, 'fanatically', '7', '{\"field_map\":{\"Color\":\"color\"}}')");
        $this->addSourceListing($src, 7, 'Moon FL', 'https://fans.test/moon', [['name' => 'Color', 'label' => 'Colour']]);

        $row = $this->resultRow($this->runImport($src, $tgt, $this->options([
            'import_listings' => '1', 'conflict_strategy' => 'overwrite',
        ])), 'Owned Listings');

        self::assertSame([], $row['errors']);
        self::assertContains('color_2', $this->columnsOf($tgt, 'old_moon'), "Sun's name doesn't claim Moon's column");
        self::assertSame(['Color' => 'color_2'], $this->fieldMap($tgt, 7));
        self::assertSame(1, (int) $tgt->query("SELECT listing_id FROM lstr_listing_external_refs WHERE external_id = '7'")->fetchColumn());
    }

    // -----------------------------------------------------------------------
    // Fanatically listings that share an address share one Luster listing
    // -----------------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function strategyProvider(): array
    {
        return ['Skip' => ['skip'], 'Overwrite' => ['overwrite']];
    }

    /**
     * @dataProvider strategyProvider
     */
    public function testTwoFanaticallyListingsWithOneAddressShareOneMemberTable(string $strategy): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->addSourceListing($src, 1, 'Moon FL', 'https://fans.test/moon',
            [['name' => 'Song', 'label' => 'Song'], ['name' => 'Color', 'label' => 'Colour A']]);
        $this->addSourceListing($src, 2, 'Moon FL again', 'https://fans.test/moon/',
            [['name' => 'Color', 'label' => 'Colour B'], ['name' => 'Mood', 'label' => 'Mood']]);
        $this->addSourceMember($src, 1, 'one@example.com', true, ['Song' => 'Golden', 'Color' => 'pink']);
        $this->addSourceMember($src, 2, 'two@example.com', true, ['Color' => 'blue', 'Mood' => 'happy']);
        $tablesBefore = $this->tableNames($tgt);
        $opts = $this->options(['import_listings' => '1', 'import_members' => '1', 'conflict_strategy' => $strategy]);

        $result = $this->runImport($src, $tgt, $opts);
        self::assertSame([], $this->resultRow($result, 'Owned Listings')['errors']);
        self::assertSame([], $this->resultRow($result, 'Members')['errors']);
        self::assertSomeWarningContains($this->resultRow($result, 'Owned Listings')['warnings'],
            'the field "Color" is saved as "color_2" because another Fanatically listing going into the same Luster listing already uses "color"');

        self::assertSame(1, (int) $tgt->query('SELECT COUNT(*) FROM owned')->fetchColumn(), 'One address, one Luster listing');
        $table = $this->dbtableOf($tgt, true);
        self::assertSame([$table], array_values(array_diff($this->tableNames($tgt), $tablesBefore)), 'One member table and no orphan');

        $labels = ['song' => 'Song', 'color' => 'Colour A', 'color_2' => 'Colour B', 'mood' => 'Mood'];
        self::assertSame($labels, $this->fieldLabels($tgt), "Both listings' fields are defined");
        $members = [];
        foreach ((new MemberRepo($this->database($tgt), $this->config()))->getApproved($table) as $member) {
            $members[$member->email] = $member->extraColumns;
        }
        self::assertSame(['one@example.com', 'two@example.com'], array_keys($members), 'Both members are in the listing');
        self::assertSame(['Golden', 'pink'], [$members['one@example.com']['song'], $members['one@example.com']['color']]);
        self::assertSame(['blue', 'happy'], [$members['two@example.com']['color_2'], $members['two@example.com']['mood']]);

        // A repeat import changes nothing.
        $columns = $this->columnsOf($tgt, $table);
        $maps    = [$this->fieldMap($tgt, 1), $this->fieldMap($tgt, 2)];
        $again   = $this->runImport($src, $tgt, $opts);
        self::assertSame([], $this->resultRow($again, 'Owned Listings')['errors']);
        self::assertSame([$table], array_values(array_diff($this->tableNames($tgt), $tablesBefore)));
        self::assertSame($columns, $this->columnsOf($tgt, $table));
        self::assertSame($maps, [$this->fieldMap($tgt, 1), $this->fieldMap($tgt, 2)]);
        self::assertSame($labels, $this->fieldLabels($tgt));
        self::assertCount(2, (new MemberRepo($this->database($tgt), $this->config()))->getApproved($table));
    }

    public function testASecondListingWithTheSameAddressIsGivenTheFirstOnesTable(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->addSourceListing($src, 1, 'Moon FL', 'https://fans.test/moon');
        $this->addSourceListing($src, 2, 'Moon FL again', 'https://fans.test/moon');
        $adapter = new FanaticallyImportSource();
        $caps    = (new \ReflectionMethod($adapter, 'detectCapabilities'))->invoke($adapter, $src, '');
        $state   = (new \ReflectionMethod($adapter, 'readListingTargetState'))->invoke($adapter, $tgt, 'enthusiast_unprefixed', true);

        [, $idMap, $dbtables, , $outcomes] = (new \ReflectionMethod($adapter, 'runListings'))->invoke(
            $adapter, $src, $tgt, 'enthusiast_unprefixed', $this->options(['import_listings' => '1', 'conflict_strategy' => 'overwrite']),
            '', $caps, [], null, $state,
        );

        self::assertSame($idMap[1], $idMap[2]);
        self::assertSame(['created', 'updated'], [$outcomes[1], $outcomes[2]]);
        self::assertSame($dbtables[1], $dbtables[2], 'The second one writes into the table the listing actually has');
    }

    // -----------------------------------------------------------------------
    // An import record that moves to another listing leaves its names behind
    // -----------------------------------------------------------------------

    public function testAMovedImportRecordCantClaimItsNewListingsColumnsEvenIfSettingUpFails(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        // Fanatically listing 7 went into Sun before, where its "Color" became "color".
        $tgt->exec("INSERT INTO owned (listingid, dbtable, title, subject, url) VALUES (1, 'sun', 'Sun', 'Sun', 'https://fans.test/sun')");
        $tgt->exec("INSERT INTO lstr_listing_external_refs (listing_id, source, external_id, metadata_json)
            VALUES (1, 'fanatically', '7', '{\"field_map\":{\"Color\":\"color\"}}')");
        // Moon's table has the owner's own "color", but lacks a column a member table needs.
        $tgt->exec("INSERT INTO owned (listingid, dbtable, title, subject, url) VALUES (2, 'moon_tbl', 'Moon', 'Moon', 'https://fans.test/moon')");
        $tgt->exec("CREATE TABLE moon_tbl (
            email TEXT NOT NULL PRIMARY KEY, name TEXT NOT NULL DEFAULT '', country TEXT NOT NULL DEFAULT '',
            url TEXT NOT NULL DEFAULT '', pending INTEGER NOT NULL DEFAULT 0, showemail INTEGER NOT NULL DEFAULT 1,
            showurl INTEGER NOT NULL DEFAULT 1, added TEXT NULL, color TEXT NULL)");
        $tgt->exec("INSERT INTO moon_tbl (email, name, color) VALUES ('owner@example.com', 'Owner', 'red')");
        // Fanatically listing 7 now has Moon's address.
        $this->addSourceListing($src, 7, 'Moon FL', 'https://fans.test/moon', [['name' => 'Color', 'label' => 'Colour']]);
        $this->addSourceMember($src, 7, 'fan@example.com', true, ['Color' => 'blue']);
        $opts = $this->options(['import_listings' => '1', 'import_members' => '1', 'conflict_strategy' => 'overwrite']);

        $first = $this->runImport($src, $tgt, $opts);
        self::assertSomeWarningContains($this->resultRow($first, 'Owned Listings')['errors'], "doesn't look like a member table");
        self::assertSame(2, (int) $tgt->query("SELECT listing_id FROM lstr_listing_external_refs WHERE external_id = '7'")->fetchColumn(),
            'The import record moved to Moon');
        self::assertSame([], $this->fieldMap($tgt, 7), "Sun's field names didn't come along");

        // The owner repairs the table and imports again.
        $tgt->exec("ALTER TABLE moon_tbl ADD COLUMN password TEXT NOT NULL DEFAULT ''");
        $again = $this->runImport($src, $tgt, $opts);

        self::assertSame([], $this->resultRow($again, 'Owned Listings')['errors']);
        self::assertSame('red', $tgt->query("SELECT color FROM moon_tbl WHERE email = 'owner@example.com'")->fetchColumn(), "The owner's column is untouched");
        self::assertNull($tgt->query("SELECT color FROM moon_tbl WHERE email = 'fan@example.com'")->fetchColumn());
        self::assertSame('blue', $tgt->query("SELECT color_2 FROM moon_tbl WHERE email = 'fan@example.com'")->fetchColumn());
        self::assertSame(['Color' => 'color_2'], $this->fieldMap($tgt, 7));
    }

    public function testAMovedImportRecordWithNoFieldsLosesItsOldNames(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->existingTargetListing($tgt);
        $tgt->exec("INSERT INTO owned (listingid, dbtable, title, subject, url) VALUES (2, 'sun', 'Sun', 'Sun', 'https://fans.test/sun')");
        $tgt->exec("INSERT INTO lstr_listing_external_refs (listing_id, source, external_id, metadata_json)
            VALUES (2, 'fanatically', '7', '{\"field_map\":{\"Color\":\"color\"}}')");
        $this->addSourceListing($src, 7, 'Moon FL', 'https://fans.test/moon');

        $this->runImport($src, $tgt, $this->options(['import_listings' => '1']));

        self::assertSame(1, (int) $tgt->query("SELECT listing_id FROM lstr_listing_external_refs WHERE external_id = '7'")->fetchColumn());
        self::assertSame([], $this->fieldMap($tgt, 7));
    }

    public function testSavingAPlanWithNoFieldsClearsNamesStoredBefore(): void
    {
        $tgt = $this->makeOwnedTarget();
        $tgt->exec("INSERT INTO lstr_listing_external_refs (listing_id, source, external_id, metadata_json)
            VALUES (1, 'fanatically', '7', '{\"field_map\":{\"Color\":\"color\"},\"source_status\":\"closed\"}')");

        (new \ReflectionMethod(FanaticallyImportSource::class, 'saveFieldMap'))
            ->invoke(new FanaticallyImportSource(), $tgt, 7, 1, ['label' => 'Moon FL', 'fields' => [], 'bySource' => [], 'retained' => []]);

        $meta = json_decode((string) $tgt->query("SELECT metadata_json FROM lstr_listing_external_refs WHERE external_id = '7'")->fetchColumn(), true);
        self::assertSame(['source_status' => 'closed'], $meta, 'The names go; everything else stays');
    }

    // -----------------------------------------------------------------------
    // Field names PHP would turn into numbers
    // -----------------------------------------------------------------------

    public function testNumericFieldNamesImportAndKeepTheirNames(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->addSourceListing($src, 1, 'Moon FL', 'https://fans.test/moon',
            [['name' => '123', 'label' => 'Lucky number'], ['name' => '0', 'label' => 'Zero']]);
        $this->addSourceMember($src, 1, 'fan@example.com', true, ['123' => '7', '0' => 'none']);
        $this->addSourceMember($src, 1, 'queued@example.com', true, null, json_encode([
            'custom_field_values' => json_encode(['123' => '8']),
        ]));
        $opts = $this->options(['import_listings' => '1', 'import_members' => '1']);

        self::assertIsArray($this->previewWarnings($src, $tgt, $opts), 'The preview works');
        $result = $this->runImport($src, $tgt, $opts);
        self::assertSame([], $this->resultRow($result, 'Owned Listings')['errors']);
        self::assertSame([], $this->resultRow($result, 'Members')['errors']);

        $table = $this->dbtableOf($tgt, true);
        self::assertSame(['f_123' => 'Lucky number', 'f_0' => 'Zero'], $this->fieldLabels($tgt));
        $fan = (new MemberRepo($this->database($tgt), $this->config()))->getByEmail($table, 'fan@example.com');
        self::assertSame(['7', 'none'], [$fan->extraColumns['f_123'], $fan->extraColumns['f_0']]);
        $changes = json_decode((string) $tgt->query('SELECT changes FROM lstr_pending_updates')->fetchColumn(), true);
        self::assertSame(['f_123' => '8'], $changes, 'A queued update reaches the column too');

        self::assertSame(['123' => 'f_123', '0' => 'f_0'], $this->fieldMap($tgt));
        self::assertStringContainsString('"field_map":{"123":"f_123","0":"f_0"}',
            (string) $tgt->query("SELECT metadata_json FROM lstr_listing_external_refs WHERE external_id = '1'")->fetchColumn(),
            'Stored as names, not as a list');

        // A repeat import finds the same names.
        $columns = $this->columnsOf($tgt, $table);
        $this->runImport($src, $tgt, $this->options(['import_listings' => '1', 'import_members' => '1', 'conflict_strategy' => 'overwrite']));
        self::assertSame($columns, $this->columnsOf($tgt, $table));
        self::assertSame(['f_123' => 'Lucky number', 'f_0' => 'Zero'], $this->fieldLabels($tgt));
    }

    // -----------------------------------------------------------------------
    // A listing that moved to a new address is matched by its new one
    // -----------------------------------------------------------------------

    /**
     * Imports Moon at /old, then moves it to /new in the source and adds Sun at
     * $sunUrl. Both have a Color field and one member.
     */
    private function moonMovesAndSunArrives(PDO $src, PDO $tgt, string $sunUrl, string $strategy): void
    {
        $this->addSourceListing($src, 1, 'Moon FL', 'https://fans.test/old', [['name' => 'Color', 'label' => 'Colour']]);
        $this->addSourceMember($src, 1, 'moon@example.com', true, ['Color' => 'blue']);
        $this->runImport($src, $tgt, $this->options(['import_listings' => '1', 'import_members' => '1', 'conflict_strategy' => $strategy]));

        $src->exec("UPDATE listings SET url = 'https://fans.test/new' WHERE id = 1");
        $this->addSourceListing($src, 2, 'Sun FL', $sunUrl, [['name' => 'Color', 'label' => 'Colour']]);
        $this->addSourceMember($src, 2, 'sun@example.com', true, ['Color' => 'yellow']);
    }

    /** @return array<string,int> Fanatically listing id => the Luster listing its import record points at */
    private function importRecords(PDO $tgt): array
    {
        return $tgt->query("SELECT external_id, listing_id FROM lstr_listing_external_refs WHERE source = 'fanatically' ORDER BY external_id")
            ->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function testANewListingAtAMovedListingsOldAddressIsNotMergedIntoIt(): void
    {
        $src  = $this->makeSource();
        $tgt  = $this->makeOwnedTarget();
        $this->moonMovesAndSunArrives($src, $tgt, 'https://fans.test/old', 'overwrite');
        $opts = $this->options(['import_listings' => '1', 'import_members' => '1', 'conflict_strategy' => 'overwrite']);

        foreach (['import', 'repeat import'] as $pass) {
            $result = $this->runImport($src, $tgt, $opts);
            self::assertSame([], $this->resultRow($result, 'Owned Listings')['errors'], $pass);

            $listings = $tgt->query('SELECT listingid, title, url, dbtable FROM owned ORDER BY listingid')->fetchAll();
            self::assertSame([['Moon FL', 'https://fans.test/new'], ['Sun FL', 'https://fans.test/old']],
                array_map(static fn(array $l): array => [$l['title'], $l['url']], $listings), "{$pass}: Sun is a listing of its own");
            self::assertEquals(['1' => 1, '2' => 2], $this->importRecords($tgt), $pass);

            $members = new MemberRepo($this->database($tgt), $this->config());
            $moon    = $members->getApproved($listings[0]['dbtable']);
            $sun     = $members->getApproved($listings[1]['dbtable']);
            self::assertSame([['moon@example.com', 'blue']], array_map(static fn($m) => [$m->email, $m->extraColumns['color']], $moon), $pass);
            self::assertSame([['sun@example.com', 'yellow']], array_map(static fn($m) => [$m->email, $m->extraColumns['color']], $sun), $pass);
        }
    }

    public function testANewListingAtAMovedListingsNewAddressSharesIt(): void
    {
        $src  = $this->makeSource();
        $tgt  = $this->makeOwnedTarget();
        $this->moonMovesAndSunArrives($src, $tgt, 'https://fans.test/new', 'overwrite');
        $opts = $this->options(['import_listings' => '1', 'import_members' => '1', 'conflict_strategy' => 'overwrite']);

        foreach (['import', 'repeat import'] as $pass) {
            $result = $this->runImport($src, $tgt, $opts);
            self::assertSame([], $this->resultRow($result, 'Owned Listings')['errors'], $pass);

            self::assertSame(['https://fans.test/new'], $tgt->query('SELECT url FROM owned')->fetchAll(PDO::FETCH_COLUMN),
                "{$pass}: one address, one listing");
            self::assertEquals(['1' => 1, '2' => 1], $this->importRecords($tgt), $pass);
            $emails = array_map(static fn($m) => $m->email,
                (new MemberRepo($this->database($tgt), $this->config()))->getApproved($this->dbtableOf($tgt, true)));
            sort($emails);
            self::assertSame(['moon@example.com', 'sun@example.com'], $emails, $pass);
        }
    }

    /** @return array<string, array{0: string}> */
    public static function sunAddressProvider(): array
    {
        return ["at Moon's old address" => ['https://fans.test/old'], "at Moon's new address" => ['https://fans.test/new']];
    }

    /**
     * @dataProvider sunAddressProvider
     */
    public function testAFailedMoveStopsTheListingsAfterItUntilItCanBeSaved(string $sunUrl): void
    {
        $src  = $this->makeSource();
        $tgt  = $this->makeOwnedTarget($this->makeTracingPdo());
        $this->moonMovesAndSunArrives($src, $tgt, $sunUrl, 'overwrite');
        $opts = $this->options(['import_listings' => '1', 'import_members' => '1', 'conflict_strategy' => 'overwrite']);
        $moonTable = $this->dbtableOf($tgt, true);

        // Saving Moon's new address fails.
        $tgt->failOn = '/UPDATE `owned` SET title/';
        $errors = $this->resultRow($this->runImport($src, $tgt, $opts), 'Owned Listings')['errors'];

        self::assertSomeWarningContains($errors, "Listing 'Moon FL'");
        self::assertSomeWarningContains($errors, "The 1 listing(s) after it weren't imported");
        self::assertSame([['Moon FL', 'https://fans.test/old']],
            array_map(static fn(array $l): array => [$l['title'], $l['url']], $tgt->query('SELECT title, url FROM owned')->fetchAll()),
            'Sun went nowhere: not into Moon, and not as a copy of it');
        self::assertEquals(['1' => 1], $this->importRecords($tgt));
        self::assertSame([['moon@example.com', 'blue']],
            $tgt->query("SELECT email, color FROM `{$moonTable}`")->fetchAll(PDO::FETCH_NUM), "Moon's members are untouched");

        // Once it can be saved, the retry puts both where they belong.
        $tgt->failOn = null;
        $again = $this->runImport($src, $tgt, $opts);

        self::assertSame([], $this->resultRow($again, 'Owned Listings')['errors']);
        self::assertSame('blue', $tgt->query("SELECT color FROM `{$moonTable}` WHERE email = 'moon@example.com'")->fetchColumn());
        if ($sunUrl === 'https://fans.test/old') {
            self::assertEquals(['1' => 1, '2' => 2], $this->importRecords($tgt), 'Sun is a listing of its own');
        } else {
            self::assertEquals(['1' => 1, '2' => 1], $this->importRecords($tgt), 'Sun shares Moon\'s new address');
            self::assertSame('yellow', $tgt->query("SELECT color_2 FROM `{$moonTable}` WHERE email = 'sun@example.com'")->fetchColumn());
        }
    }

    public function testSkipKeepsTheOldAddressSoANewListingThereSharesTheListing(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->moonMovesAndSunArrives($src, $tgt, 'https://fans.test/old', 'skip');

        $result = $this->runImport($src, $tgt, $this->options(['import_listings' => '1', 'import_members' => '1', 'conflict_strategy' => 'skip']));

        // Skip leaves Luster's listing at /old, so Sun (at /old) goes into it, and
        // the field plan has to know that too: Sun's Color can't take Moon's column.
        self::assertSame(['https://fans.test/old'], $tgt->query('SELECT url FROM owned')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSomeWarningContains($this->resultRow($result, 'Owned Listings')['warnings'],
            'the field "Color" is saved as "color_2" because another Fanatically listing going into the same Luster listing already uses "color"');
        $table = $this->dbtableOf($tgt, true);
        self::assertSame('blue', $tgt->query("SELECT color FROM `{$table}` WHERE email = 'moon@example.com'")->fetchColumn());
        self::assertNull($tgt->query("SELECT color FROM `{$table}` WHERE email = 'sun@example.com'")->fetchColumn(),
            "Sun's answer doesn't go into Moon's field");
        self::assertSomeWarningContains($this->resultRow($result, 'Members')['warnings'], "custom field value(s) weren't imported");
    }

    // -----------------------------------------------------------------------
    // Answers saved under a field's old name stay with that field
    // -----------------------------------------------------------------------

    public function testAnswersSavedUnderARenamedFieldStayWithThatField(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->importFields($src, $tgt, [['name' => 'a-b', 'label' => 'Dash']], ['a-b' => 'DASH'], 'skip');

        // Fanatically renames the field's definition to "a b", but members' saved
        // answers keep the old name.
        $src->exec('DELETE FROM listings');
        $src->exec('DELETE FROM members');
        $this->addSourceListing($src, 1, 'Moon FL', 'https://fans.test/moon', [['name' => 'a b', 'label' => 'Space']]);
        $this->addSourceMember($src, 1, 'fan@example.com', true, ['a-b' => 'DASH again']);
        $this->addSourceMember($src, 1, 'both@example.com', true, ['a-b' => 'OLD', 'a b' => 'NEW']);
        $this->addSourceMember($src, 1, 'queued@example.com', true, null, json_encode([
            'custom_field_values' => json_encode(['a-b' => 'QUEUED OLD', 'a b' => 'QUEUED NEW']),
        ]));
        $tgt->exec('DELETE FROM lstr_pending_updates');

        $result = $this->runImport($src, $tgt, $this->options(['import_listings' => '1', 'import_members' => '1', 'conflict_strategy' => 'overwrite']));
        self::assertSame([], $this->resultRow($result, 'Members')['errors']);

        $members = [];
        foreach ((new MemberRepo($this->database($tgt), $this->config()))->getApproved($this->dbtableOf($tgt, true)) as $member) {
            $members[$member->email] = [$member->extraColumns['a_b'] ?? '', $member->extraColumns['a_b_2'] ?? ''];
        }
        self::assertSame(['DASH again', ''], $members['fan@example.com'], 'The old answer stays in the old field');
        self::assertSame(['OLD', 'NEW'], $members['both@example.com'], 'Each answer goes to its own field');
        $changes = json_decode((string) $tgt->query('SELECT changes FROM lstr_pending_updates')->fetchColumn(), true);
        self::assertSame(['a_b' => 'QUEUED OLD', 'a_b_2' => 'QUEUED NEW'], $changes, 'Queued updates follow the same rule');
    }

    public function testAnAnswerThatCouldBelongToEitherFieldIsLeftOutAndCounted(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->importFields($src, $tgt, [['name' => 'a-b', 'label' => 'Dash']], ['a-b' => 'DASH'], 'skip');

        // "A-B" isn't either field's name exactly; it cleans up the same as both
        // the old "a-b" and the new "a b", so Luster can't tell whose it is.
        $result = $this->importFields($src, $tgt, [['name' => 'a b', 'label' => 'Space']], ['A-B' => 'WHOSE?'], 'overwrite');

        $member = (new MemberRepo($this->database($tgt), $this->config()))->getApproved($this->dbtableOf($tgt, true))[0];
        self::assertSame('DASH', $member->extraColumns['a_b'] ?? '');
        self::assertSame('', $member->extraColumns['a_b_2'] ?? '', 'It isn\'t guessed into the new field');
        self::assertSomeWarningContains($this->resultRow($result, 'Members')['warnings'], "custom field value(s) weren't imported");
    }

    public function testAnswersForAFieldWhoseColumnIsGoneAreLeftOutAndCounted(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->importFields($src, $tgt, [['name' => 'a-b', 'label' => 'Dash']], ['a-b' => 'DASH'], 'skip');
        $tgt->exec('ALTER TABLE `' . $this->dbtableOf($tgt, true) . '` DROP COLUMN a_b');

        $result = $this->importFields($src, $tgt, [['name' => 'a b', 'label' => 'Space']], ['a-b' => 'DASH again'], 'overwrite');

        $member = (new MemberRepo($this->database($tgt), $this->config()))->getApproved($this->dbtableOf($tgt, true))[0];
        self::assertSame('', $member->extraColumns['a_b_2'] ?? '', 'The old answer never lands in the new field');
        self::assertSomeWarningContains($this->resultRow($result, 'Members')['warnings'], "custom field value(s) weren't imported");
    }

    // -----------------------------------------------------------------------
    // Nothing is set up under names Luster couldn't remember
    // -----------------------------------------------------------------------

    public function testFieldNamesThatCantBeSavedStopTheListingUntilTheyCan(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget($this->makeTracingPdo());
        $this->addSourceListing($src, 1, 'Moon FL', 'https://fans.test/moon', [['name' => 'Color', 'label' => 'Colour']]);
        $this->addSourceMember($src, 1, 'fan@example.com', true, ['Color' => 'blue']);
        $opts   = $this->options(['import_listings' => '1', 'import_members' => '1', 'conflict_strategy' => 'overwrite']);
        $before = $this->tableNames($tgt);

        $tgt->failOn = '/UPDATE lstr_listing_external_refs SET metadata_json/';
        $first = $this->runImport($src, $tgt, $opts);

        self::assertSomeWarningContains($this->resultRow($first, 'Owned Listings')['errors'], "Luster couldn't save which of its custom fields is which");
        self::assertSame($before, $this->tableNames($tgt), 'No member table was set up under names that weren\'t saved');
        self::assertSame(0, $this->resultRow($first, 'Members')['imported']);

        // Once the names can be saved, the retry uses the same ones.
        $tgt->failOn = null;
        $again = $this->runImport($src, $tgt, $opts);

        self::assertSame([], $this->resultRow($again, 'Owned Listings')['errors']);
        $table = $this->dbtableOf($tgt, true);
        self::assertContains('color', $this->columnsOf($tgt, $table));
        self::assertNotContains('color_2', $this->columnsOf($tgt, $table));
        self::assertSame('blue', (new MemberRepo($this->database($tgt), $this->config()))->getApproved($table)[0]->extraColumns['color']);
        self::assertSame(['Color' => 'color'], $this->fieldMap($tgt));
    }

    public function testFieldNamesThatCantBeReadStopTheImport(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget($this->makeTracingPdo());
        $this->importFields($src, $tgt, [['name' => 'Color', 'label' => 'Colour']], ['Color' => 'blue'], 'skip');
        $table   = $this->dbtableOf($tgt, true);
        $columns = $this->columnsOf($tgt, $table);
        $opts    = $this->options(['import_listings' => '1', 'import_members' => '1', 'conflict_strategy' => 'overwrite']);

        // Reading the names earlier imports saved fails.
        $tgt->failOn = '/SELECT external_id, listing_id, metadata_json FROM lstr_listing_external_refs/';
        self::assertSomeWarningContains($this->previewRow($src, $tgt, $opts, 'Custom fields')['warnings'], "couldn't read the custom field names");
        $errors = $this->resultRow($this->runImport($src, $tgt, $opts), 'Custom fields')['errors'];
        $tgt->failOn = null;

        self::assertSomeWarningContains($errors, 'Nothing was imported.');
        self::assertSame($columns, $this->columnsOf($tgt, $table), 'No new column under a guessed name');
        self::assertSame(['Color' => 'color'], $this->fieldMap($tgt));
        self::assertSame('blue', $tgt->query("SELECT color FROM `{$table}`")->fetchColumn());

        // The retry reads them and keeps "color".
        $this->importFields($src, $tgt, [['name' => 'Color', 'label' => 'Colour']], ['Color' => 'green'], 'overwrite');
        self::assertSame($columns, $this->columnsOf($tgt, $table));
        self::assertSame(['Color' => 'color'], $this->fieldMap($tgt));
        self::assertSame('green', $tgt->query("SELECT color FROM `{$table}`")->fetchColumn());
    }

    public function testAListingsOwnFieldsThatCantBeReadStopTheImport(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget($this->makeTracingPdo());
        // Moon has a "fave" field defined in Luster (its column not added yet).
        $this->existingTargetListing($tgt);
        $tgt->prepare('UPDATE owned SET additional = :a WHERE listingid = 1')
            ->execute(['a' => serialize([['name' => 'fave', 'label' => 'Favourite', 'type' => 'text', 'required' => false]])]);
        $before = $this->columnsOf($tgt, 'old_moon');
        $this->addSourceListing($src, 7, 'Moon FL', 'https://fans.test/moon', [['name' => 'fave', 'label' => 'Fave']]);
        $opts = $this->options(['import_listings' => '1', 'conflict_strategy' => 'overwrite']);

        $tgt->failOn = '/SELECT additional FROM `owned` WHERE listingid = :id/';
        $errors = $this->resultRow($this->runImport($src, $tgt, $opts), 'Custom fields')['errors'];
        $tgt->failOn = null;

        self::assertSomeWarningContains($errors, "couldn't read the custom field names");
        self::assertSame($before, $this->columnsOf($tgt, 'old_moon'), "Fanatically's field doesn't take Moon's own name");

        // Once Moon's fields can be read, Fanatically's gets a name of its own.
        $this->runImport($src, $tgt, $opts);
        self::assertSame([...$before, 'fave_2'], $this->columnsOf($tgt, 'old_moon'));
    }

    public function testARecordThatCantMoveKeepsItsNamesAndHoldsBackItsNewListing(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget($this->makeTracingPdo());
        // Fanatically listing 7 went into Sun before, where its "Color" became "color".
        $tgt->exec("INSERT INTO owned (listingid, dbtable, title, subject, url) VALUES (1, 'sun', 'Sun', 'Sun', 'https://fans.test/sun')");
        $tgt->exec("INSERT INTO lstr_listing_external_refs (listing_id, source, external_id, metadata_json)
            VALUES (1, 'fanatically', '7', '{\"field_map\":{\"Color\":\"color\"}}')");
        // Moon's own member table has a "color" of its own.
        $tgt->exec("INSERT INTO owned (listingid, dbtable, title, subject, url) VALUES (2, 'moon_tbl', 'Moon', 'Moon', 'https://fans.test/moon')");
        $tgt->exec("CREATE TABLE moon_tbl (
            email TEXT NOT NULL PRIMARY KEY, name TEXT NOT NULL DEFAULT '', country TEXT NOT NULL DEFAULT '',
            url TEXT NOT NULL DEFAULT '', pending INTEGER NOT NULL DEFAULT 0, password TEXT NOT NULL DEFAULT '',
            showemail INTEGER NOT NULL DEFAULT 1, showurl INTEGER NOT NULL DEFAULT 1, added TEXT NULL, color TEXT NULL)");
        $tgt->exec("INSERT INTO moon_tbl (email, name, color) VALUES ('owner@example.com', 'Owner', 'red')");
        // Fanatically listing 7 now has Moon's address.
        $this->addSourceListing($src, 7, 'Moon FL', 'https://fans.test/moon', [['name' => 'Color', 'label' => 'Colour']]);
        $this->addSourceMember($src, 7, 'fan@example.com', true, ['Color' => 'blue']);
        $opts   = $this->options(['import_listings' => '1', 'import_members' => '1', 'conflict_strategy' => 'overwrite']);
        $before = $this->columnsOf($tgt, 'moon_tbl');

        // Moving the record from Sun to Moon fails.
        $tgt->failOn = '/REPLACE INTO lstr_listing_external_refs/';
        $first = $this->runImport($src, $tgt, $opts);
        $tgt->failOn = null;

        $errors = $this->resultRow($first, 'Owned Listings')['errors'];
        self::assertSomeWarningContains($errors, "couldn't update its record of which Fanatically listing it came from");
        self::assertSomeWarningContains($errors, "couldn't save which of its custom fields is which");
        self::assertSame($before, $this->columnsOf($tgt, 'moon_tbl'), "Moon's table isn't changed");
        self::assertSame(1, (int) $tgt->query("SELECT listing_id FROM lstr_listing_external_refs WHERE external_id = '7'")->fetchColumn());
        self::assertSame(['Color' => 'color'], $this->fieldMap($tgt, 7), "Sun's names are left as they were");
        self::assertFalse($tgt->query("SELECT 1 FROM moon_tbl WHERE email = 'fan@example.com'")->fetchColumn(), 'No member was written');

        // Once the record can move, the retry sets Moon up once.
        $again = $this->runImport($src, $tgt, $opts);

        self::assertSame([], $this->resultRow($again, 'Owned Listings')['errors']);
        self::assertSame([...$before, 'color_2'], $this->columnsOf($tgt, 'moon_tbl'));
        self::assertSame(['Color' => 'color_2'], $this->fieldMap($tgt, 7));
        self::assertSame('red', $tgt->query("SELECT color FROM moon_tbl WHERE email = 'owner@example.com'")->fetchColumn());
        self::assertSame('blue', $tgt->query("SELECT color_2 FROM moon_tbl WHERE email = 'fan@example.com'")->fetchColumn());
    }

    // -----------------------------------------------------------------------
    // A read that fails isn't taken as "nothing there", even if it fails once
    // -----------------------------------------------------------------------

    /** Moon is already in Luster with a "color" of its own; Fanatically's Moon has a Color too. */
    private function moonWithItsOwnColor(PDO $src, PDO $tgt): void
    {
        $this->existingTargetListing($tgt);
        $tgt->exec('ALTER TABLE old_moon ADD COLUMN color TEXT NULL');
        $tgt->exec("INSERT INTO old_moon (email, name, color) VALUES ('fan@example.com', 'Fan', 'LOCAL')");
        $this->addSourceListing($src, 7, 'Moon FL', 'https://fans.test/moon', [['name' => 'Color', 'label' => 'Colour']]);
        $this->addSourceMember($src, 7, 'fan@example.com', true, ['Color' => 'IMPORTED']);
    }

    /** After a refused import, the retry gives Fanatically's Color a column of its own. */
    private function assertRetryKeepsMoonsOwnColor(PDO $src, PDO $tgt, ImportOptions $opts): void
    {
        $again = $this->runImport($src, $tgt, $opts);
        self::assertSame([], $this->resultRow($again, 'Owned Listings')['errors']);
        self::assertSame(['LOCAL', 'IMPORTED'],
            $tgt->query("SELECT color, color_2 FROM old_moon WHERE email = 'fan@example.com'")->fetch(PDO::FETCH_NUM));
        self::assertSame(['Color' => 'color_2'], $this->fieldMap($tgt, 7));
    }

    public function testAListingsColumnsThatCantBeReadOnceStopTheImport(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget($this->makeTracingPdo());
        $this->moonWithItsOwnColor($src, $tgt);
        $opts   = $this->options(['import_listings' => '1', 'import_members' => '1', 'conflict_strategy' => 'overwrite']);
        $before = $this->columnsOf($tgt, 'old_moon');

        $tgt->failOn    = '/PRAGMA table_info\(`old_moon`\)/';
        $tgt->failTimes = 1;
        $errors = $this->resultRow($this->runImport($src, $tgt, $opts), 'Custom fields')['errors'];
        $tgt->failOn = null;

        self::assertSomeWarningContains($errors, "couldn't read the custom field names");
        self::assertSame($before, $this->columnsOf($tgt, 'old_moon'));
        self::assertSame('LOCAL', $tgt->query("SELECT color FROM old_moon WHERE email = 'fan@example.com'")->fetchColumn());
        self::assertSame([], $this->fieldMap($tgt, 7), 'Moon\'s "color" wasn\'t claimed');

        $this->assertRetryKeepsMoonsOwnColor($src, $tgt, $opts);
    }

    /** @return array<string, array{0: string}> */
    public static function listingLookupProvider(): array
    {
        return [
            'the listings'       => ['/SELECT listingid, url, dbtable FROM `owned`/'],
            'the import records' => ["/SELECT listing_id, external_id FROM lstr_listing_external_refs WHERE source = 'fanatically'/"],
        ];
    }

    /**
     * @dataProvider listingLookupProvider
     */
    public function testListingsThatCantBeLookedUpOnceStopTheImport(string $failingRead): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget($this->makeTracingPdo());
        $this->moonWithItsOwnColor($src, $tgt);
        $opts = $this->options(['import_listings' => '1', 'import_members' => '1', 'conflict_strategy' => 'overwrite']);

        $tgt->failOn    = $failingRead;
        $tgt->failTimes = 1;
        self::assertSomeWarningContains($this->previewRow($src, $tgt, $opts, 'Database')['warnings'], "couldn't read part of its own database");
        $tgt->failTimes = 1;
        $errors = $this->resultRow($this->runImport($src, $tgt, $opts), 'Database')['errors'];
        $tgt->failOn = null;

        self::assertSomeWarningContains($errors, 'Nothing was imported.');
        self::assertSame(1, (int) $tgt->query('SELECT COUNT(*) FROM owned')->fetchColumn());
        self::assertSame('LOCAL', $tgt->query("SELECT color FROM old_moon WHERE email = 'fan@example.com'")->fetchColumn());

        $this->assertRetryKeepsMoonsOwnColor($src, $tgt, $opts);
    }

    public function testTablesThatCantBeListedOnceStopTheImport(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget($this->makeTracingPdo());
        // An unrelated member list that happens to have the name a new "Moon FL" would get.
        $tgt->exec("CREATE TABLE moon_fl (
            email TEXT NOT NULL PRIMARY KEY, name TEXT NOT NULL DEFAULT '', country TEXT NOT NULL DEFAULT '',
            url TEXT NOT NULL DEFAULT '', pending INTEGER NOT NULL DEFAULT 0, password TEXT NOT NULL DEFAULT '',
            showemail INTEGER NOT NULL DEFAULT 1, showurl INTEGER NOT NULL DEFAULT 1, added TEXT NULL)");
        $tgt->exec("INSERT INTO moon_fl (email, name) VALUES ('stranger@example.com', 'Stranger')");
        $this->addSourceListing($src, 1, 'Moon FL', 'https://fans.test/moon', [['name' => 'Color', 'label' => 'Colour']]);
        $this->addSourceMember($src, 1, 'fan@example.com', true, ['Color' => 'blue']);
        $opts   = $this->options(['import_listings' => '1', 'import_members' => '1']);
        $before = $this->columnsOf($tgt, 'moon_fl');

        $tgt->failOn    = "/SELECT name FROM sqlite_master WHERE type = 'table'$/";
        $tgt->failTimes = 1;
        $errors = $this->resultRow($this->runImport($src, $tgt, $opts), 'Database')['errors'];
        $tgt->failOn = null;

        self::assertSomeWarningContains($errors, "couldn't read part of its own database");
        self::assertSame(0, (int) $tgt->query('SELECT COUNT(*) FROM owned')->fetchColumn(), 'No listing was pointed at that table');

        $again = $this->runImport($src, $tgt, $opts);
        self::assertSame([], $this->resultRow($again, 'Owned Listings')['errors']);
        self::assertSame('moon_fl_2', $this->dbtableOf($tgt, true), 'The new listing gets a table of its own');
        self::assertSame($before, $this->columnsOf($tgt, 'moon_fl'));
        self::assertSame(['stranger@example.com'], $tgt->query('SELECT email FROM moon_fl')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testImportRecordsThatCantBeCheckedOnceStopTheImport(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget($this->makeTracingPdo());
        $this->importFields($src, $tgt, [['name' => 'Color', 'label' => 'Colour']], ['Color' => 'blue'], 'skip');
        // Fanatically no longer has Color; Luster keeps its name on record.
        $src->exec("UPDATE listings SET custom_fields = '[]'");
        $opts = $this->options(['import_listings' => '1', 'import_members' => '1', 'conflict_strategy' => 'overwrite']);

        $tgt->failOn    = '/PRAGMA table_info\(`lstr_listing_external_refs`\)/';
        $tgt->failTimes = 1;
        $errors = $this->resultRow($this->runImport($src, $tgt, $opts), 'Database')['errors'];
        $tgt->failOn = null;

        self::assertSomeWarningContains($errors, 'Nothing was imported.');
        self::assertSame(['Color' => 'color'], $this->fieldMap($tgt), 'The name on record is still there');

        $again = $this->runImport($src, $tgt, $opts);
        self::assertSame([], $this->resultRow($again, 'Owned Listings')['errors']);
        self::assertSame(['Color' => 'color'], $this->fieldMap($tgt), 'And it stays kept for the removed field');
    }

    public function testFanaticallyListingsThatCantBeReadOnceStopTheImport(): void
    {
        $src = $this->makeSource($this->makeTracingPdo());
        $tgt = $this->makeOwnedTarget();
        $this->addSourceListing($src, 1, 'Moon FL', 'https://fans.test/moon', [['name' => 'Color', 'label' => 'Colour']]);
        $this->addSourceMember($src, 1, 'fan@example.com', true, ['Color' => 'blue']);
        $opts = $this->options(['import_listings' => '1', 'import_members' => '1']);

        $src->failOn    = '/SELECT \* FROM `listings` ORDER BY id ASC/';
        $src->failTimes = 1;
        $errors = $this->resultRow($this->runImport($src, $tgt, $opts), 'Custom fields')['errors'];
        $src->failOn = null;

        self::assertSomeWarningContains($errors, "couldn't read your Fanatically listings");
        self::assertSame(0, (int) $tgt->query('SELECT COUNT(*) FROM owned')->fetchColumn());

        $again = $this->runImport($src, $tgt, $opts);
        self::assertSame([], $this->resultRow($again, 'Members')['errors']);
        $member = (new MemberRepo($this->database($tgt), $this->config()))->getApproved($this->dbtableOf($tgt, true))[0];
        self::assertSame('blue', $member->extraColumns['color'], 'The listing comes with its fields and answers');
    }

    public function testCustomFieldsWaitForMigration91(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $tgt->exec('ALTER TABLE lstr_listing_external_refs DROP COLUMN metadata_json');
        $this->addSourceListing($src, 1, 'Moon FL', 'https://fans.test/moon', [['name' => 'Color', 'label' => 'Colour']]);
        $opts = $this->options(['import_listings' => '1', 'import_members' => '1']);

        self::assertSomeWarningContains($this->previewRow($src, $tgt, $opts, 'Database update')['warnings'], 'Luster needs Migration 91');
        $errors = $this->resultRow($this->runImport($src, $tgt, $opts), 'Database update')['errors'];
        self::assertSomeWarningContains($errors, 'Run the migration page first');
        self::assertSomeWarningContains($errors, 'Nothing was imported.');
        self::assertSame(0, (int) $tgt->query('SELECT COUNT(*) FROM owned')->fetchColumn());

        // A source with no custom fields isn't held up.
        $src->exec('UPDATE listings SET custom_fields = NULL');
        $row = $this->resultRow($this->runImport($src, $tgt, $opts), 'Owned Listings');
        self::assertSame([], $row['errors']);
        self::assertSame(1, (int) $tgt->query('SELECT COUNT(*) FROM owned')->fetchColumn());
    }

    // -----------------------------------------------------------------------
    // A kept name belongs to its own source listing, whichever row comes first
    // -----------------------------------------------------------------------

    public function testANameKeptByALaterListingIsReservedBeforeAnEarlierOneIsNamed(): void
    {
        $src = $this->makeSource();
        $tgt = $this->makeOwnedTarget();
        $this->addSourceListing($src, 1, 'Moon FL', 'https://fans.test/moon', [['name' => 'Song', 'label' => 'Song']]);
        $this->addSourceListing($src, 2, 'Moon FL again', 'https://fans.test/moon', [['name' => 'Color', 'label' => 'Colour']]);
        $this->addSourceMember($src, 1, 'one@example.com', true, ['Song' => 'Golden']);
        $this->addSourceMember($src, 2, 'two@example.com', true, ['Color' => 'blue']);
        $opts = $this->options(['import_listings' => '1', 'import_members' => '1', 'conflict_strategy' => 'overwrite']);
        $this->runImport($src, $tgt, $opts);
        self::assertSame(['Color' => 'color'], $this->fieldMap($tgt, 2));

        // The second listing drops Color, the owner deletes that field in Luster,
        // and the first listing gets a Color of its own.
        $src->exec("UPDATE listings SET custom_fields = '[]' WHERE id = 2");
        $src->exec("UPDATE listings SET custom_fields = '" . json_encode([['name' => 'Song', 'label' => 'Song'], ['name' => 'Color', 'label' => 'Colour 1']]) . "' WHERE id = 1");
        $src->exec("UPDATE members SET custom_field_values = '" . json_encode(['Song' => 'Golden', 'Color' => 'pink']) . "' WHERE id = 1");
        $table = $this->dbtableOf($tgt, true);
        $tgt->exec("ALTER TABLE `{$table}` DROP COLUMN color");
        $defs = array_values(array_filter(
            unserialize((string) $tgt->query('SELECT additional FROM owned WHERE listingid = 1')->fetchColumn()),
            static fn(array $d): bool => $d['name'] !== 'color',
        ));
        (new FieldRepo($this->database($tgt), $this->config()))->saveFields(1, $defs);

        $result = $this->runImport($src, $tgt, $opts);

        self::assertSomeWarningContains($this->resultRow($result, 'Owned Listings')['warnings'],
            'the field "Color" is saved as "color_2" because another Fanatically listing going into the same Luster listing already uses "color"');
        self::assertSame(['Song' => 'song', 'Color' => 'color_2'], $this->fieldMap($tgt, 1));
        self::assertSame(['Color' => 'color'], $this->fieldMap($tgt, 2), 'The second listing still owns "color"');
        $one = (new MemberRepo($this->database($tgt), $this->config()))->getByEmail($table, 'one@example.com');
        self::assertSame('pink', $one->extraColumns['color_2']);
    }
}
