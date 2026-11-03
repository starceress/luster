<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Import\FanaticallyImportSource;
use Luster\Repositories\CodesRepo;
use Luster\Tests\Unit\Support\FanaticallyImportFixture;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Fanatically import, collective codes. Fanatically's "Collective" is
 * listing 0; it becomes a Luster collective code (fanlisting_id NULL) with no
 * size or donor row, the real width/height, and the donor credited on the code
 * (submitter_*), matching the Listing Admin import. A repeat import finds it
 * by name + width + height. It imports without Listings, while an unknown
 * positive listing is still skipped. Read back through CodesRepo, which the
 * public collective codes embed and the admin codes pages use.
 */
final class FanaticallyCollectiveCodesImportTest extends TestCase
{
    use FanaticallyImportFixture;

    private function addCode(PDO $src, int $listingId, string $size, string $file, bool $approved = true, string $donor = '', string $donorUrl = '', string $donorEmail = ''): void
    {
        $src->prepare(
            'INSERT INTO codes (listing_id, size_label, file_name, donor_name, donor_url, donor_email, is_approved)
             VALUES (:l, :s, :f, :d, :du, :de, :a)'
        )->execute(['l' => $listingId, 's' => $size, 'f' => $file, 'd' => $donor, 'du' => $donorUrl, 'de' => $donorEmail, 'a' => $approved ? 1 : 0]);
    }

    private function codes(PDO $tgt): CodesRepo
    {
        return new CodesRepo($this->database($tgt));
    }

    public function testCollectiveCodesKeepTheirSizeStatusAndDonorCredit(): void
    {
        $src = $this->makeSource();
        $this->addCode($src, 0, '88x31', 'button.png', true, 'Jane', 'https://jane.test', 'jane@example.com');
        $this->addCode($src, 0, '50x50', 'icon.png', false);
        $tgt = $this->makeOwnedTarget();

        $row = $this->resultRow($this->runImport($src, $tgt, $this->options(['import_codes' => '1'])), 'Codes');
        self::assertSame([], $row['errors']);
        self::assertSame(2, $row['imported']);

        $public = $this->codes($tgt)->getByListing(null, 'approved');
        self::assertCount(1, $public, 'Only the approved collective code is public');
        self::assertSame('button.png', $public[0]->name);
        self::assertSame([88, 31], [$public[0]->width, $public[0]->height]);
        self::assertNull($public[0]->sizeId);
        self::assertNull($public[0]->donorId);
        self::assertSame(['Jane', 'https://jane.test', 'jane@example.com'],
            [$public[0]->submitterName, $public[0]->submitterUrl, $public[0]->submitterEmail]);
        self::assertSame(1, $this->codes($tgt)->countPendingByListing(null));

        self::assertSame(0, (int) $tgt->query('SELECT COUNT(*) FROM lstr_code_sizes')->fetchColumn(), 'Collective codes get no size row');
        self::assertSame(0, (int) $tgt->query('SELECT COUNT(*) FROM lstr_code_donors')->fetchColumn(), 'Collective codes get no donor row');
    }

    public function testSameFilenameAtTwoSizesStaysTwoCodesAndRepeatImportsFindThem(): void
    {
        $src = $this->makeSource();
        $this->addCode($src, 0, '50x50', 'code.png');
        $this->addCode($src, 0, '88x31', 'code.png');
        $tgt = $this->makeOwnedTarget();

        $this->runImport($src, $tgt, $this->options(['import_codes' => '1']));
        self::assertCount(2, $this->codes($tgt)->getByListing(null));

        // Skip: both found, nothing added.
        $skip = $this->resultRow($this->runImport($src, $tgt, $this->options(['import_codes' => '1'])), 'Codes');
        self::assertSame(0, $skip['imported']);
        self::assertSame(2, $skip['skipped']);
        self::assertCount(2, $this->codes($tgt)->getByListing(null));

        // Overwrite: both found and updated, still two.
        $src->exec("UPDATE codes SET donor_name = 'Jane'");
        $this->runImport($src, $tgt, $this->options(['import_codes' => '1', 'conflict_strategy' => 'overwrite']));
        $codes = $this->codes($tgt)->getByListing(null);
        self::assertCount(2, $codes);
        self::assertSame(['Jane', 'Jane'], array_map(static fn($c) => $c->submitterName, $codes));
    }

    public function testCollectiveCodesImportWithListingsUnchecked(): void
    {
        $src = $this->makeSource();
        $this->addSourceListing($src, 1, 'Moon FL', 'https://fans.test/moon');
        $this->addCode($src, 0, '88x31', 'collective.png');
        $this->addCode($src, 1, '88x31', 'moon.png');
        $tgt  = $this->makeOwnedTarget();
        $opts = $this->options(['import_codes' => '1']);

        $preview = $this->previewRow($src, $tgt, $opts, 'Codes');
        self::assertSame(1, $preview['import']);
        self::assertSame(1, $preview['skip']);
        self::assertSomeWarningContains($this->previewWarnings($src, $tgt, $opts), 'they still import without Listings');

        $row = $this->resultRow($this->runImport($src, $tgt, $opts), 'Codes');
        self::assertSame(1, $row['imported']);
        self::assertSame(1, $row['skipped']);
        self::assertSame(['moon.png'], array_column($row['skipped_items'], 'label'));
        self::assertSame(['collective.png'], array_map(static fn($c) => $c->name, $this->codes($tgt)->getByListing(null)));
    }

    public function testAnUnknownListingNeverBecomesACollectiveCode(): void
    {
        $src = $this->makeSource();
        $this->addSourceListing($src, 1, 'Moon FL', 'https://fans.test/moon');
        $this->addCode($src, 5, '88x31', 'orphan.png');
        $tgt = $this->makeOwnedTarget();

        $row = $this->resultRow($this->runImport($src, $tgt, $this->options(['import_listings' => '1', 'import_codes' => '1'])), 'Codes');

        self::assertSame(0, $row['imported']);
        self::assertSame('Listing not imported', $row['skipped_items'][0]['reason']);
        self::assertSame(0, $this->codes($tgt)->countByListing(null));
    }

    public function testCollectiveAndListingCodesStaySeparate(): void
    {
        $src = $this->makeSource();
        $this->addSourceListing($src, 1, 'Moon FL', 'https://fans.test/moon');
        $this->addCode($src, 1, '88x31', 'code.png');
        $this->addCode($src, 0, '88x31', 'code.png');
        $tgt = $this->makeOwnedTarget();

        $this->runImport($src, $tgt, $this->options(['import_listings' => '1', 'import_codes' => '1']));
        // And again, so matching can't cross between the two.
        $this->runImport($src, $tgt, $this->options(['import_listings' => '1', 'import_codes' => '1', 'conflict_strategy' => 'overwrite']));

        $listingId = (int) $tgt->query('SELECT listingid FROM owned LIMIT 1')->fetchColumn();
        self::assertCount(1, $this->codes($tgt)->getByListing($listingId));
        self::assertCount(1, $this->codes($tgt)->getByListing(null));
    }

    public function testSizesLusterCantReadImportOnceAndAreSkippedOnARepeat(): void
    {
        $src = $this->makeSource();
        $this->addCode($src, 0, 'small', 'code.png');
        $this->addCode($src, 0, 'big', 'code.png');
        $tgt  = $this->makeOwnedTarget();
        $opts = $this->options(['import_codes' => '1']);

        self::assertSomeWarningContains($this->previewWarnings($src, $tgt, $opts), 'importing again later will skip those copies');
        $first = $this->resultRow($this->runImport($src, $tgt, $opts), 'Codes');
        self::assertSame(2, $first['imported'], 'A first import keeps every copy');

        self::assertSomeWarningContains($this->previewWarnings($src, $tgt, $opts), 'is already in Luster, so those copies will be skipped');
        $again = $this->resultRow($this->runImport($src, $tgt, $this->options(['import_codes' => '1', 'conflict_strategy' => 'overwrite'])), 'Codes');
        self::assertSame(0, $again['imported']);
        self::assertSame(2, $again['skipped']);
        self::assertStringContainsString("can't tell which copy already in Luster is which", $again['skipped_items'][0]['reason']);
        self::assertCount(2, $this->codes($tgt)->getByListing(null), 'Neither stored copy is overwritten or duplicated');
    }

    public function testAReadableSizeStillUpdatesWhenOtherCopiesHaveUnreadableSizes(): void
    {
        $src = $this->makeSource();
        $this->addCode($src, 0, 'small', 'code.png');
        $this->addCode($src, 0, 'big', 'code.png');
        $this->addCode($src, 0, '88x31', 'code.png');
        $tgt = $this->makeOwnedTarget();

        $first = $this->resultRow($this->runImport($src, $tgt, $this->options(['import_codes' => '1'])), 'Codes');
        self::assertSame(3, $first['imported']);

        $src->exec("UPDATE codes SET donor_name = 'Jane'");
        $again = $this->resultRow($this->runImport($src, $tgt, $this->options(['import_codes' => '1', 'conflict_strategy' => 'overwrite'])), 'Codes');
        self::assertSame(1, $again['imported'], 'The 88x31 copy can be told apart, so it updates');
        self::assertSame(2, $again['skipped']);

        $wide = array_values(array_filter($this->codes($tgt)->getByListing(null), static fn($c) => $c->width === 88));
        self::assertSame('Jane', $wide[0]->submitterName);
        self::assertCount(3, $this->codes($tgt)->getByListing(null));
    }

    public function testPreviewCountsTheSameSkipsTheImportMakes(): void
    {
        $src = $this->makeSource();
        $this->addCode($src, 0, 'small', 'code.png');
        $this->addCode($src, 0, 'big', 'code.png');
        $tgt  = $this->makeOwnedTarget();
        $opts = $this->options(['import_codes' => '1']);
        $this->runImport($src, $tgt, $opts);

        $preview = $this->previewRow($src, $tgt, $opts, 'Codes');
        $run     = $this->resultRow($this->runImport($src, $tgt, $opts), 'Codes');

        self::assertSame([0, 2], [$preview['import'], $preview['skip']]);
        self::assertSame([0, 2], [$run['imported'], $run['skipped']]);
    }

    public function testLongFilenamesAreFoundAgainAndSharedPrefixesStayApart(): void
    {
        // Longer than the 150 characters the name column keeps, sharing their first 150.
        $first  = str_repeat('a', 150) . '-one.png';
        $second = str_repeat('a', 150) . '-two.png';
        $src = $this->makeSource();
        $this->addCode($src, 0, '88x31', $first);
        $this->addCode($src, 0, '88x31', $second);
        $tgt = $this->makeOwnedTarget();

        $this->runImport($src, $tgt, $this->options(['import_codes' => '1']));
        self::assertCount(2, $this->codes($tgt)->getByListing(null), 'Two different files stay two codes');

        $again = $this->resultRow($this->runImport($src, $tgt, $this->options(['import_codes' => '1'])), 'Codes');
        self::assertSame(0, $again['imported']);
        self::assertSame(2, $again['skipped']);
        self::assertCount(2, $this->codes($tgt)->getByListing(null), 'A repeat import adds no copies');
    }

    public function testOverwriteKeepsAnImageThatWasAlreadyCopiedOver(): void
    {
        $src = $this->makeSource();
        $this->addSourceListing($src, 1, 'Moon FL', 'https://fans.test/moon');
        $this->addCode($src, 0, '88x31', 'code.png');
        $this->addCode($src, 1, '88x31', 'moon.png');
        $tgt  = $this->makeOwnedTarget();
        $opts = ['import_listings' => '1', 'import_codes' => '1'];

        $this->runImport($src, $tgt, $this->options($opts));
        // An earlier import copied the images under Luster's own names.
        $tgt->exec("UPDATE lstr_codes SET filename = 'fn_real_migrated.png' WHERE fanlisting_id IS NULL");
        $tgt->exec("UPDATE lstr_codes SET filename = 'fn_moon_migrated.png' WHERE fanlisting_id IS NOT NULL");

        // No image can be found this time (no image path), so nothing new is copied.
        $src->exec('UPDATE codes SET is_approved = 0');
        $this->runImport($src, $tgt, $this->options($opts + ['conflict_strategy' => 'overwrite']));

        $collective = $this->codes($tgt)->getByListing(null)[0];
        self::assertSame('fn_real_migrated.png', $collective->filename, 'A failed copy must not replace a working image');
        self::assertSame('pending', $collective->status, 'The rest of the code still updates');
        $listingId = (int) $tgt->query('SELECT listingid FROM owned LIMIT 1')->fetchColumn();
        self::assertSame('fn_moon_migrated.png', $this->codes($tgt)->getByListing($listingId)[0]->filename);
    }

    public function testRemovingOneSizeDoesNotMakeARepeatImportUpdateTheWrongCopy(): void
    {
        $src = $this->makeSource();
        $this->addCode($src, 0, 'small', 'code.png', true, 'Small donor');
        $this->addCode($src, 0, 'big', 'code.png', true, 'Big donor');
        $tgt = $this->makeOwnedTarget();
        $this->runImport($src, $tgt, $this->options(['import_codes' => '1']));
        self::assertCount(2, $this->codes($tgt)->getByListing(null));

        // "small" is deleted in Fanatically and "big" gets a new donor.
        $src->exec("DELETE FROM codes WHERE size_label = 'small'");
        $src->exec("UPDATE codes SET donor_name = 'Changed'");
        $opts = $this->options(['import_codes' => '1', 'conflict_strategy' => 'overwrite']);

        $preview = $this->previewRow($src, $tgt, $opts, 'Codes');
        self::assertSame([0, 1], [$preview['import'], $preview['skip']]);

        $row = $this->resultRow($this->runImport($src, $tgt, $opts), 'Codes');
        self::assertSame([], $row['errors']);
        self::assertSame([0, 1], [$row['imported'], $row['skipped']]);
        self::assertStringContainsString('Luster already has 2 copies of this collective code', $row['skipped_items'][0]['reason']);
        $donors = array_map(static fn($c) => $c->submitterName, $this->codes($tgt)->getByListing(null));
        sort($donors);
        self::assertSame(['Big donor', 'Small donor'], $donors, 'Neither stored copy is changed');
    }

    public function testFilenamesThatDifferOnlyInCaseStayTwoCodes(): void
    {
        $src = $this->makeSource();
        $this->addCode($src, 0, '88x31', 'Code.png', true, 'Upper');
        $this->addCode($src, 0, '88x31', 'code.png', true, 'Lower');
        $tgt = $this->makeOwnedTarget();

        $first = $this->resultRow($this->runImport($src, $tgt, $this->options(['import_codes' => '1'])), 'Codes');
        self::assertSame(2, $first['imported'], 'Two files, two codes');

        $skip = $this->resultRow($this->runImport($src, $tgt, $this->options(['import_codes' => '1'])), 'Codes');
        self::assertSame([0, 2], [$skip['imported'], $skip['skipped']]);

        $src->exec("UPDATE codes SET donor_name = donor_name || ' again'");
        $this->runImport($src, $tgt, $this->options(['import_codes' => '1', 'conflict_strategy' => 'overwrite']));
        $donors = [];
        foreach ($tgt->query('SELECT import_source_file, submitter_name FROM lstr_codes ORDER BY id')->fetchAll() as $code) {
            $donors[$code['import_source_file']] = $code['submitter_name'];
        }
        self::assertSame(['Code.png' => 'Upper again', 'code.png' => 'Lower again'], $donors, 'Each code updates from its own file');
    }

    public function testACodeAddedInLusterDoesNotAbsorbOneWhoseNameDiffersInCase(): void
    {
        $src = $this->makeSource();
        $this->addCode($src, 0, '88x31', 'code.png', true, 'Jane');
        $tgt = $this->makeOwnedTarget();
        $tgt->exec("INSERT INTO lstr_codes (fanlisting_id, name, filename, width, height) VALUES (NULL, 'Code.png', 'mine.png', 88, 31)");

        $this->runImport($src, $tgt, $this->options(['import_codes' => '1', 'conflict_strategy' => 'overwrite']));

        $codes = $tgt->query('SELECT name, import_source_file, submitter_name FROM lstr_codes ORDER BY id')->fetchAll();
        self::assertCount(2, $codes);
        self::assertSame(['Code.png', null, null], array_values($codes[0]), 'The hand-added code is untouched');
        self::assertSame(['code.png', 'code.png', 'Jane'], array_values($codes[1]));
    }

    public function testAnImportedCopyIsMatchedBeforeACodeAddedByHand(): void
    {
        $src = $this->makeSource();
        $this->addCode($src, 0, '88x31', 'code.png', true, 'Jane');
        $tgt = $this->makeOwnedTarget();
        $tgt->exec("INSERT INTO lstr_codes (fanlisting_id, name, filename, width, height) VALUES (NULL, 'code.png', 'mine.png', 88, 31)");
        $tgt->exec("INSERT INTO lstr_codes (fanlisting_id, name, filename, width, height, import_source_file)
            VALUES (NULL, 'code.png', 'imported.png', 88, 31, 'code.png')");

        $row = $this->resultRow($this->runImport($src, $tgt, $this->options(['import_codes' => '1', 'conflict_strategy' => 'overwrite'])), 'Codes');

        self::assertSame([1, 0], [$row['imported'], $row['skipped']]);
        $donors = $tgt->query('SELECT filename, submitter_name FROM lstr_codes ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame(['mine.png' => null, 'imported.png' => 'Jane'], $donors, 'Only the imported copy is updated');
    }

    public function testListingCodesThatDifferOnlyInCaseStayApart(): void
    {
        $src = $this->makeSource();
        $this->addSourceListing($src, 1, 'Moon FL', 'https://fans.test/moon');
        $this->addCode($src, 1, '88x31', 'Code.png', true, 'Upper');
        $this->addCode($src, 1, '88x31', 'code.png', true, 'Lower');
        $tgt  = $this->makeOwnedTarget();
        $opts = ['import_listings' => '1', 'import_codes' => '1'];

        $first = $this->resultRow($this->runImport($src, $tgt, $this->options($opts)), 'Codes');
        self::assertSame(2, $first['imported']);

        $src->exec("UPDATE codes SET donor_name = donor_name || ' again'");
        $this->runImport($src, $tgt, $this->options($opts + ['conflict_strategy' => 'overwrite']));

        $donors = $tgt->query(
            'SELECT c.name, d.name FROM lstr_codes c JOIN lstr_code_donors d ON d.id = c.donor_id ORDER BY c.id'
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame(['Code.png' => 'Upper again', 'code.png' => 'Lower again'], $donors);
    }

    public function testCollectiveCodesWaitForMigration104(): void
    {
        $src = $this->makeSource();
        $this->addCode($src, 0, '88x31', 'code.png');
        $tgt = $this->makeOwnedTarget();
        $tgt->exec('ALTER TABLE lstr_codes DROP COLUMN import_source_file');
        $opts = $this->options(['import_codes' => '1']);

        self::assertSomeWarningContains($this->previewWarnings($src, $tgt, $opts), 'Luster needs Migration 104');
        $row = $this->resultRow($this->runImport($src, $tgt, $opts), 'Codes');
        self::assertSame(0, $row['imported']);
        self::assertStringContainsString('Luster needs Migration 104', $row['skipped_items'][0]['reason']);
    }

    public function testCollectiveCodeImagesAreFoundUnderFanaticallysListingZeroFolder(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_fan_codes_' . uniqid('', true);
        mkdir($base . '/images/codes/0/88x31', 0777, true);
        mkdir($base . '/images/codes/0/50x50', 0777, true);
        file_put_contents($base . '/images/codes/0/88x31/code.png', 'wide');
        file_put_contents($base . '/images/codes/0/50x50/code.png', 'square');

        try {
            $resolve = new \ReflectionMethod(FanaticallyImportSource::class, 'resolveCodeImagePath');
            $options = $this->options(['import_codes' => '1', 'image_path' => $base]);
            $adapter = new FanaticallyImportSource();

            $wide   = (string) $resolve->invoke($adapter, 'code.png', '88x31', 0, $options);
            $square = (string) $resolve->invoke($adapter, 'code.png', '50x50', 0, $options);
            self::assertSame('wide', file_get_contents($wide), 'Same name, different size: each finds its own file');
            self::assertSame('square', file_get_contents($square));
        } finally {
            foreach (['88x31', '50x50'] as $size) {
                unlink($base . "/images/codes/0/{$size}/code.png");
                rmdir($base . "/images/codes/0/{$size}");
            }
            rmdir($base . '/images/codes/0');
            rmdir($base . '/images/codes');
            rmdir($base . '/images');
            rmdir($base);
        }
    }
}
