<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Addons\Kim\KimMemberRepo;
use Luster\Repositories\AffiliateRepo;
use Luster\Tests\Unit\Support\FanaticallyImportFixture;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Fanatically import, KIM and affiliates:
 *  - each KIM entry keeps its own Fanatically approval by default, with
 *    explicit "all pending" / "all active" choices;
 *  - KIM notes (Luster has no notes field) never go public and are listed on
 *    the results page so they can be copied;
 *  - an affiliate whose listing_ids can't be read is skipped and reported,
 *    never turned into a site-wide affiliate, while empty/null/[] still mean
 *    collective on purpose.
 * Preview and results describe the same outcome.
 */
final class FanaticallyKimAndAffiliateImportTest extends TestCase
{
    use FanaticallyImportFixture;

    private function addKim(PDO $src, int $listingId, string $email, bool $approved, string $notes = ''): void
    {
        $src->prepare('INSERT INTO kim (listing_id, name, email, url, notes, is_approved, added_at) VALUES (:l, :n, :e, \'\', :no, :a, \'2024-05-01\')')
            ->execute(['l' => $listingId, 'n' => strtok($email, '@'), 'e' => $email, 'no' => $notes, 'a' => $approved ? 1 : 0]);
    }

    /** One listing with one approved and one unapproved KIM entry. */
    private function kimSource(): PDO
    {
        $src = $this->makeSource();
        $this->addSourceListing($src, 1, 'Moon FL', 'https://fans.test/moon');
        $this->addKim($src, 1, 'yes@example.com', true, 'Met at the con');
        $this->addKim($src, 1, 'wait@example.com', false);
        return $src;
    }

    /** @return array{visible: list<string>, waiting: list<string>} */
    private function kimLists(PDO $tgt): array
    {
        $repo      = new KimMemberRepo($this->database($tgt));
        $listingId = (int) $tgt->query('SELECT listingid FROM owned LIMIT 1')->fetchColumn();
        return [
            'visible' => array_map(static fn($m) => $m->email, $repo->getApprovedByFanlisting($listingId)),
            'waiting' => array_map(static fn($m) => $m->email, $repo->getPendingByFanlisting($listingId)),
        ];
    }

    // -----------------------------------------------------------------------
    // KIM approval
    // -----------------------------------------------------------------------

    public function testKimEntriesKeepTheirOwnApprovalByDefault(): void
    {
        $src = $this->kimSource();
        $tgt = $this->makeOwnedTarget();

        $this->runImport($src, $tgt, $this->options(['import_listings' => '1', 'import_kim' => '1']));

        self::assertSame(['visible' => ['yes@example.com'], 'waiting' => ['wait@example.com']], $this->kimLists($tgt),
            'An entry Fanatically hadn\'t approved must not appear on the public KIM list');
    }

    public function testKimAllPendingAndAllActiveChoices(): void
    {
        $src = $this->kimSource();

        $tgt = $this->makeOwnedTarget();
        $this->runImport($src, $tgt, $this->options(['import_listings' => '1', 'import_kim' => '1', 'fn_kim_status' => 'pending']));
        self::assertSame([], $this->kimLists($tgt)['visible']);

        $tgt = $this->makeOwnedTarget();
        $this->runImport($src, $tgt, $this->options(['import_listings' => '1', 'import_kim' => '1', 'fn_kim_status' => 'active']));
        self::assertCount(2, $this->kimLists($tgt)['visible']);
    }

    public function testOverwriteReappliesEachEntrysOwnApproval(): void
    {
        $src  = $this->kimSource();
        $tgt  = $this->makeOwnedTarget();
        $base = ['import_listings' => '1', 'import_kim' => '1'];

        $this->runImport($src, $tgt, $this->options($base + ['fn_kim_status' => 'active']));
        $this->runImport($src, $tgt, $this->options($base + ['conflict_strategy' => 'overwrite']));

        self::assertSame(['wait@example.com'], $this->kimLists($tgt)['waiting']);
    }

    public function testKimWithoutAnApprovalColumnCountsAsApproved(): void
    {
        // Fanatically versions from before per-entry approval had no is_approved column.
        $src = $this->makeSource();
        $src->exec('DROP TABLE kim');
        $src->exec("CREATE TABLE kim (id INTEGER PRIMARY KEY AUTOINCREMENT, listing_id INTEGER NOT NULL, name TEXT NOT NULL DEFAULT '',
            email TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '', added_at TEXT NULL)");
        $this->addSourceListing($src, 1, 'Moon FL', 'https://fans.test/moon');
        $src->exec("INSERT INTO kim (listing_id, name, email) VALUES (1, 'Old', 'old@example.com')");
        $tgt = $this->makeOwnedTarget();

        $this->runImport($src, $tgt, $this->options(['import_listings' => '1', 'import_kim' => '1']));

        self::assertSame(['old@example.com'], $this->kimLists($tgt)['visible']);
    }

    public function testPreviewSaysHowManyKimEntriesShowAndWait(): void
    {
        $warnings = $this->previewWarnings($this->kimSource(), $this->makeOwnedTarget(), $this->options(['import_listings' => '1', 'import_kim' => '1']));
        self::assertSomeWarningContains($warnings, '1 KIM entr(ies) will show on the public KIM list and 1 will wait for approval');
    }

    // -----------------------------------------------------------------------
    // KIM notes: private and recoverable
    // -----------------------------------------------------------------------

    public function testKimNotesAreListedOnTheResultsPageAndNowhereElse(): void
    {
        $src = $this->kimSource();
        // An entry that can't be imported keeps its note too.
        $this->addKim($src, 99, 'orphan@example.com', true, 'From the old forum');
        $tgt = $this->makeOwnedTarget();
        $opts = $this->options(['import_listings' => '1', 'import_kim' => '1']);

        self::assertSomeWarningContains($this->previewWarnings($src, $tgt, $opts), "2 KIM note(s) can't be imported");

        $row = $this->resultRow($this->runImport($src, $tgt, $opts), 'KIM List');
        self::assertSomeWarningContains($row['warnings'], 'download the import report before you leave this page');
        self::assertSomeWarningContains($row['warnings'], 'KIM note for yes@example.com (Moon FL): Met at the con');
        self::assertSomeWarningContains($row['warnings'], 'KIM note for orphan@example.com (listing #99): From the old forum');

        // Nothing public carries the note.
        $columns = array_column($tgt->query('PRAGMA table_info(lstr_kim_members)')->fetchAll(), 'name');
        $values  = $tgt->query('SELECT ' . implode(', ', $columns) . ' FROM lstr_kim_members')->fetchAll(PDO::FETCH_NUM);
        self::assertStringNotContainsString('Met at the con', json_encode($values));
    }

    // -----------------------------------------------------------------------
    // Affiliates: unreadable listing_ids
    // -----------------------------------------------------------------------

    private function addAffiliate(PDO $src, string $url, ?string $listingIds): void
    {
        $src->prepare('INSERT INTO affiliates (name, url, listing_ids, is_approved) VALUES (:n, :u, :l, 1)')
            ->execute(['n' => parse_url($url, PHP_URL_HOST), 'u' => $url, 'l' => $listingIds]);
    }

    public function testUnreadableListingIdsAreSkippedAndReportedNotMadeCollective(): void
    {
        $src = $this->makeSource();
        $this->addSourceListing($src, 1, 'Moon FL', 'https://fans.test/moon');
        $this->addAffiliate($src, 'https://broken-json.test', '[1,');
        $this->addAffiliate($src, 'https://bare-number.test', '1');
        $this->addAffiliate($src, 'https://words.test', '["moon"]');
        // JSON objects, even ones that look like lists once decoded as arrays.
        $this->addAffiliate($src, 'https://empty-object.test', '{}');
        $this->addAffiliate($src, 'https://zero-key.test', '{"0":0}');
        $this->addAffiliate($src, 'https://one-key.test', '{"0":1}');
        $this->addAffiliate($src, 'https://listing.test', '[1]');
        $tgt  = $this->makeOwnedTarget();
        $opts = $this->options(['import_listings' => '1', 'import_affiliates' => '1']);

        $preview = $this->previewRow($src, $tgt, $opts, 'Affiliates');
        self::assertSame(1, $preview['import']);
        self::assertSame(6, $preview['skip']);
        self::assertSomeWarningContains($preview['warnings'], "6 affiliate(s) have a list of fanlistings (listing_ids) Luster can't read");

        $row = $this->resultRow($this->runImport($src, $tgt, $opts), 'Affiliates');
        self::assertSame(1, $row['imported']);
        self::assertSame(6, $row['skipped']);
        self::assertSomeWarningContains($row['warnings'], '6 affiliate(s) were skipped');
        self::assertSame(
            ['https://broken-json.test', 'https://bare-number.test', 'https://words.test',
             'https://empty-object.test', 'https://zero-key.test', 'https://one-key.test'],
            array_column($row['skipped_items'], 'label'),
        );

        $repo = new AffiliateRepo($this->database($tgt));
        self::assertSame(0, $repo->getCollective()['total'], 'No broken affiliate may become site-wide');
        self::assertSame(1, $repo->getByListing(1)['total']);
    }

    /** @return array<string, array{0: ?string}> */
    public static function collectiveListingIdsProvider(): array
    {
        return ['NULL' => [null], 'empty' => [''], 'null text' => ['null'], 'empty list' => ['[]'], 'zero' => ['[0]']];
    }

    /**
     * @dataProvider collectiveListingIdsProvider
     */
    public function testEmptyListingIdsStillMeanTheWholeCollective(?string $listingIds): void
    {
        $src = $this->makeSource();
        $this->addAffiliate($src, 'https://friend.test', $listingIds);
        $tgt = $this->makeOwnedTarget();

        $row = $this->resultRow($this->runImport($src, $tgt, $this->options(['import_affiliates' => '1'])), 'Affiliates');

        self::assertSame(1, $row['imported']);
        self::assertSame(1, (new AffiliateRepo($this->database($tgt)))->getCollective()['total']);
    }
}
