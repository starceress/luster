<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Tests\Unit\Support\FanaticallyImportFixture;
use Luster\Tests\Unit\Support\TracingPdo;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Fanatically import, listing codes. lstr_codes.name holds 150 characters and an admin
 * can rename or clear it, so a code is tracked by its full source filename. A re-run
 * finds it again and adds nothing, and the preview says what the run will do.
 */
final class FanaticallyListingCodesImportTest extends TestCase
{
    use FanaticallyImportFixture;

    private const SKIP      = ['import_listings' => '1', 'import_codes' => '1'];
    private const OVERWRITE = self::SKIP + ['conflict_strategy' => 'overwrite'];

    private static function longName(string $ending = ''): string
    {
        return str_repeat('a', 155) . $ending . '.png';
    }

    private static function capped(string $file): string
    {
        return mb_substr($file, 0, 150);
    }

    private function sourceWithListing(): PDO
    {
        $src = $this->makeSource();
        $this->addSourceListing($src, 1, 'Moon FL', 'https://fans.test/moon');
        return $src;
    }

    private function addCode(PDO $src, string $file, string $size = '88x31', string $donor = '', int $listing = 1): void
    {
        $src->prepare('INSERT INTO codes (listing_id, size_label, file_name, donor_name) VALUES (:l, :s, :f, :d)')
            ->execute(['l' => $listing, 's' => $size, 'f' => $file, 'd' => $donor]);
    }

    /** A target that already has the listing, so codes can be put there before an import. */
    private function targetWithListing(PDO $src, ?PDO $tgt = null): PDO
    {
        $tgt ??= $this->makeOwnedTarget();
        $this->runImport($src, $tgt, $this->options(['import_listings' => '1']));
        return $tgt;
    }

    private function sizeRow(PDO $tgt, string $label = '88x31', ?int $w = 88, ?int $h = 31, int $listing = 1): int
    {
        $tgt->prepare('INSERT INTO lstr_code_sizes (fanlisting_id, label, width, height) VALUES (:l, :label, :w, :h)')
            ->execute(['l' => $listing, 'label' => $label, 'w' => $w, 'h' => $h]);
        return (int) $tgt->lastInsertId();
    }

    private function storedCode(PDO $tgt, int $sizeId, ?string $name, ?string $sourceFile = null, string $filename = 'existing.png'): int
    {
        $tgt->prepare(
            "INSERT INTO lstr_codes (fanlisting_id, size_id, name, import_source_file, filename, status, width, height)
             VALUES (1, :s, :n, :src, :f, 'approved', 88, 31)"
        )->execute(['s' => $sizeId, 'n' => $name, 'src' => $sourceFile, 'f' => $filename]);
        return (int) $tgt->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    private function codes(PDO $tgt): array
    {
        return $tgt->query('SELECT * FROM lstr_codes ORDER BY id')->fetchAll();
    }

    /** Imports after checking that the preview promises exactly what the run does. @param array<string,string> $options */
    private function import(PDO $src, PDO $tgt, array $options): array
    {
        $opts    = $this->options($options);
        $preview = $this->previewRow($src, $tgt, $opts, 'Codes');
        $row     = $this->resultRow($this->runImport($src, $tgt, $opts), 'Codes');

        self::assertSame([], $row['errors']);
        self::assertSame([$row['imported'], $row['skipped']], [$preview['import'], $preview['skip']], 'What the preview said it would import and skip');

        return $row;
    }

    private static function reasons(array $row): string
    {
        return implode("\n", array_column($row['skipped_items'], 'reason'));
    }

    // ------------------------------------------------------------------
    // Long names
    // ------------------------------------------------------------------

    public function testALongNameIsStoredCappedAndFoundAgain(): void
    {
        $file = self::longName();
        $src  = $this->sourceWithListing();
        $this->addCode($src, $file);
        $tgt = $this->makeOwnedTarget();

        self::assertSame(1, $this->import($src, $tgt, self::SKIP)['imported']);
        [$code] = $this->codes($tgt);
        self::assertSame(self::capped($file), $code['name']);
        self::assertSame($file, $code['import_source_file']);

        $skip = $this->import($src, $tgt, self::SKIP);
        self::assertSame([0, 1], [$skip['imported'], $skip['skipped']]);
        self::assertCount(1, $this->codes($tgt));

        $src->exec("UPDATE codes SET donor_name = 'Jane'");
        self::assertSame(1, $this->import($src, $tgt, self::OVERWRITE)['imported']);
        self::assertCount(1, $this->codes($tgt));
        self::assertSame($code['id'], $this->codes($tgt)[0]['id']);
        self::assertNotNull($this->codes($tgt)[0]['donor_id']);
    }

    public function testEveryNewCodeRemembersItsSourceFile(): void
    {
        $src = $this->sourceWithListing();
        $this->addCode($src, 'short.png');
        $tgt = $this->makeOwnedTarget();

        $this->import($src, $tgt, self::SKIP);

        self::assertSame('short.png', $this->codes($tgt)[0]['import_source_file']);
    }

    public function testACodeThatWasRenamedOrHadItsNameClearedIsStillFound(): void
    {
        foreach (['A Pretty Name', '', null] as $name) {
            $src = $this->sourceWithListing();
            $this->addCode($src, 'code.png');
            $tgt = $this->makeOwnedTarget();
            $this->import($src, $tgt, self::SKIP);
            $tgt->prepare('UPDATE lstr_codes SET name = :n')->execute(['n' => $name]);

            $skip = $this->import($src, $tgt, self::SKIP);
            self::assertSame([0, 1], [$skip['imported'], $skip['skipped']]);

            self::assertSame(1, $this->import($src, $tgt, self::OVERWRITE)['imported']);
            self::assertCount(1, $this->codes($tgt));
            self::assertSame($name, $this->codes($tgt)[0]['name'], 'Overwrite leaves the name an admin chose');
        }
    }

    public function testATaggedRowBeatsAManualRowNamedLikeTheFile(): void
    {
        $src = $this->sourceWithListing();
        $this->addCode($src, 'code.png', '88x31', 'Jane');
        $tgt    = $this->targetWithListing($src);
        $size   = $this->sizeRow($tgt);
        $manual = $this->storedCode($tgt, $size, 'code.png', null, 'manual.png');
        $tagged = $this->storedCode($tgt, $size, 'Renamed', 'code.png', 'tagged.png');

        self::assertSame(1, $this->import($src, $tgt, self::OVERWRITE)['imported']);

        $codes = array_column($this->codes($tgt), null, 'id');
        self::assertCount(2, $codes);
        self::assertNull($codes[$manual]['donor_id'], 'The manual row is left alone');
        self::assertNull($codes[$manual]['import_source_file']);
        self::assertNotNull($codes[$tagged]['donor_id'], 'The tagged row is the one updated');
    }

    public function testTwoLongNamesSharingTheirStartStayTwoCodes(): void
    {
        $src = $this->sourceWithListing();
        $this->addCode($src, self::longName('1'));
        $this->addCode($src, self::longName('2'));
        $tgt = $this->makeOwnedTarget();

        self::assertSame(2, $this->import($src, $tgt, self::SKIP)['imported']);
        $ids = array_column($this->codes($tgt), 'id');
        self::assertCount(2, $ids);
        self::assertSame(self::capped(self::longName('1')), self::capped(self::longName('2')));

        $skip = $this->import($src, $tgt, self::SKIP);
        self::assertSame([0, 2], [$skip['imported'], $skip['skipped']]);

        self::assertSame(2, $this->import($src, $tgt, self::OVERWRITE)['imported']);
        self::assertSame($ids, array_column($this->codes($tgt), 'id'));
    }

    public function testTheSameTwoBesideAnUntaggedRowAreSkippedAndTheRowIsLeftAlone(): void
    {
        foreach ([self::SKIP, self::OVERWRITE] as $options) {
            $src = $this->sourceWithListing();
            $this->addCode($src, self::longName('1'));
            $this->addCode($src, self::longName('2'));
            $tgt    = $this->targetWithListing($src);
            $legacy = $this->storedCode($tgt, $this->sizeRow($tgt), self::capped(self::longName()));

            $row = $this->import($src, $tgt, $options);

            self::assertSame([0, 2], [$row['imported'], $row['skipped']]);
            self::assertStringContainsString('first 150 characters', self::reasons($row));
            self::assertCount(1, $this->codes($tgt));
            self::assertSame($legacy, (int) $this->codes($tgt)[0]['id']);
            self::assertNull($this->codes($tgt)[0]['import_source_file']);
        }
    }

    public function testATaggedRowIsReusedAndTheOtherFileIsAdded(): void
    {
        $src = $this->sourceWithListing();
        $this->addCode($src, self::longName('1'));
        $this->addCode($src, self::longName('2'));
        $tgt    = $this->targetWithListing($src);
        $tagged = $this->storedCode($tgt, $this->sizeRow($tgt), self::capped(self::longName('1')), self::longName('1'));

        $row = $this->import($src, $tgt, self::OVERWRITE);

        self::assertSame(2, $row['imported']);
        $codes = $this->codes($tgt);
        self::assertCount(2, $codes);
        self::assertSame($tagged, (int) $codes[0]['id']);
        self::assertSame(self::longName('2'), $codes[1]['import_source_file']);
    }

    public function testATaggedRowIsReusedButTheOtherFileCantClaimAnUntaggedRow(): void
    {
        $src = $this->sourceWithListing();
        $this->addCode($src, self::longName('1'), '88x31', 'Jane');
        $this->addCode($src, self::longName('2'));
        $tgt    = $this->targetWithListing($src);
        $size   = $this->sizeRow($tgt);
        $tagged = $this->storedCode($tgt, $size, self::capped(self::longName('1')), self::longName('1'));
        $legacy = $this->storedCode($tgt, $size, self::capped(self::longName('1')));

        $row = $this->import($src, $tgt, self::OVERWRITE);

        self::assertSame([1, 1], [$row['imported'], $row['skipped']]);
        self::assertSame([self::longName('2')], array_column($row['skipped_items'], 'label'));
        $codes = array_column($this->codes($tgt), null, 'id');
        self::assertCount(2, $codes);
        self::assertNotNull($codes[$tagged]['donor_id']);
        self::assertNull($codes[$legacy]['import_source_file']);
    }

    public function testTwoUntaggedRowsWithTheSameNameCantBeToldApart(): void
    {
        foreach ([self::SKIP, self::OVERWRITE] as $options) {
            $src = $this->sourceWithListing();
            $this->addCode($src, 'code.png', '88x31', 'Jane');
            $tgt  = $this->targetWithListing($src);
            $size = $this->sizeRow($tgt);
            $this->storedCode($tgt, $size, 'code.png');
            $this->storedCode($tgt, $size, 'code.png');

            $row = $this->import($src, $tgt, $options);

            self::assertSame([0, 1], [$row['imported'], $row['skipped']]);
            self::assertStringContainsString("can't be told apart", self::reasons($row));
            self::assertCount(2, $this->codes($tgt));
            self::assertSame([null, null], array_column($this->codes($tgt), 'donor_id'));
        }
    }

    public function testTwoRowsTaggedWithTheSameFileCantBeToldApart(): void
    {
        $src = $this->sourceWithListing();
        $this->addCode($src, 'code.png', '88x31', 'Jane');
        $tgt  = $this->targetWithListing($src);
        $size = $this->sizeRow($tgt);
        $this->storedCode($tgt, $size, 'One', 'code.png');
        $this->storedCode($tgt, $size, 'Two', 'code.png');

        $row = $this->import($src, $tgt, self::OVERWRITE);

        self::assertSame([0, 1], [$row['imported'], $row['skipped']]);
        self::assertStringContainsString("can't be told apart", self::reasons($row));
        self::assertSame([null, null], array_column($this->codes($tgt), 'donor_id'));
    }

    public function testOneUntaggedRowIsAdoptedByOverwriteAndLeftByKeep(): void
    {
        $src = $this->sourceWithListing();
        $this->addCode($src, 'code.png', '88x31', 'Jane');
        $tgt  = $this->targetWithListing($src);
        $code = $this->storedCode($tgt, $this->sizeRow($tgt), 'code.png');

        $skip = $this->import($src, $tgt, self::SKIP);
        self::assertSame([0, 1], [$skip['imported'], $skip['skipped']]);
        self::assertNull($this->codes($tgt)[0]['import_source_file'], 'Skip changes nothing');

        self::assertSame(1, $this->import($src, $tgt, self::OVERWRITE)['imported']);
        self::assertCount(1, $this->codes($tgt));
        self::assertSame($code, (int) $this->codes($tgt)[0]['id']);
        self::assertSame('code.png', $this->codes($tgt)[0]['import_source_file']);

        // Tagged now, so an admin can rename it without losing track of it.
        $tgt->exec("UPDATE lstr_codes SET name = 'Renamed'");
        self::assertSame(1, $this->import($src, $tgt, self::OVERWRITE)['imported']);
        self::assertCount(1, $this->codes($tgt));
    }

    public function testARepeatedFileInOneImportFindsTheRowItJustAdded(): void
    {
        $src = $this->sourceWithListing();
        $this->addCode($src, self::longName());
        $this->addCode($src, self::longName());
        $tgt = $this->makeOwnedTarget();

        $row = $this->import($src, $tgt, self::SKIP);

        self::assertSame([1, 1], [$row['imported'], $row['skipped']]);
        self::assertCount(1, $this->codes($tgt));
    }

    public function testFilesDifferingOnlyInCapitalsAreTwoCodes(): void
    {
        $src = $this->sourceWithListing();
        $this->addCode($src, 'Code.png');
        $this->addCode($src, 'code.png');
        $tgt = $this->makeOwnedTarget();

        self::assertSame(2, $this->import($src, $tgt, self::SKIP)['imported']);
        self::assertSame(['Code.png', 'code.png'], array_column($this->codes($tgt), 'name'));

        $skip = $this->import($src, $tgt, self::SKIP);
        self::assertSame([0, 2], [$skip['imported'], $skip['skipped']]);
        self::assertCount(2, $this->codes($tgt));
    }

    public function testSourceListingsSharingALusterListingCollideTogether(): void
    {
        $src = $this->sourceWithListing();
        $this->addSourceListing($src, 2, 'Moon FL again', 'https://fans.test/moon');
        $this->addCode($src, self::longName('1'), '88x31', '', 1);
        $this->addCode($src, self::longName('2'), '88x31', '', 2);
        $tgt = $this->targetWithListing($src);
        $this->storedCode($tgt, $this->sizeRow($tgt), self::capped(self::longName()));

        $row = $this->import($src, $tgt, self::OVERWRITE);

        self::assertSame([0, 2], [$row['imported'], $row['skipped']]);
        self::assertCount(1, $this->codes($tgt));
    }

    public function testANameOver255CharactersIsReportedAndAddsNoSize(): void
    {
        $tooLong = str_repeat('a', 252) . '.png';
        foreach ([self::SKIP, self::OVERWRITE] as $options) {
            $src = $this->sourceWithListing();
            $this->addCode($src, $tooLong);
            $tgt = $this->makeOwnedTarget();

            $row = $this->import($src, $tgt, $options);

            self::assertSame([0, 1], [$row['imported'], $row['skipped']]);
            self::assertStringContainsString('longer than 255', self::reasons($row));
            self::assertSame([], $this->codes($tgt));
            self::assertSame(0, (int) $tgt->query('SELECT COUNT(*) FROM lstr_code_sizes')->fetchColumn());
        }
    }

    public function testTheLimitsCountCharactersNotBytes(): void
    {
        // 255 characters, all two bytes. Both columns hold characters, so this fits.
        $file = str_repeat('é', 251) . '.png';
        $src  = $this->sourceWithListing();
        $this->addCode($src, $file);
        $tgt = $this->makeOwnedTarget();

        self::assertSame(1, $this->import($src, $tgt, self::SKIP)['imported']);
        self::assertSame(str_repeat('é', 150), $this->codes($tgt)[0]['name']);
        self::assertSame($file, $this->codes($tgt)[0]['import_source_file']);
    }

    public function testThePreviewSaysWhyACodeWillBeSkipped(): void
    {
        $src = $this->sourceWithListing();
        $this->addCode($src, str_repeat('a', 252) . '.png');
        $this->addCode($src, 'fine.png');
        $tgt = $this->makeOwnedTarget();

        self::assertSomeWarningContains($this->previewWarnings($src, $tgt, $this->options(self::SKIP)), 'longer than 255');
    }

    // ------------------------------------------------------------------
    // Without Migration 104
    // ------------------------------------------------------------------

    public function testWithoutMigration104LongNamesAreReportedAndShortNamesMatchAsBefore(): void
    {
        $src = $this->sourceWithListing();
        $this->addCode($src, self::longName());
        $this->addCode($src, 'short.png', '88x31', 'Jane');
        $tgt = $this->targetWithListing($src);
        $tgt->exec('ALTER TABLE lstr_codes DROP COLUMN import_source_file');
        $short = $this->storedCodeWithoutTheColumn($tgt, $this->sizeRow($tgt), 'short.png');

        $row = $this->import($src, $tgt, self::OVERWRITE);

        self::assertSame([1, 1], [$row['imported'], $row['skipped']]);
        self::assertStringContainsString('Migration 104', self::reasons($row));
        self::assertSame([self::longName()], array_column($row['skipped_items'], 'label'));
        self::assertCount(1, $this->codes($tgt), 'Never merged, never added');
        self::assertSame($short, (int) $this->codes($tgt)[0]['id']);
        self::assertNotNull($this->codes($tgt)[0]['donor_id']);
    }

    public function testWithoutMigration104ANewShortNameIsStillAdded(): void
    {
        $src = $this->sourceWithListing();
        $this->addCode($src, 'short.png');
        $tgt = $this->targetWithListing($src);
        $tgt->exec('ALTER TABLE lstr_codes DROP COLUMN import_source_file');

        self::assertSame(1, $this->import($src, $tgt, self::SKIP)['imported']);
        $skip = $this->import($src, $tgt, self::SKIP);

        self::assertSame([0, 1], [$skip['imported'], $skip['skipped']]);
        self::assertCount(1, $this->codes($tgt));
    }

    private function storedCodeWithoutTheColumn(PDO $tgt, int $sizeId, string $name): int
    {
        $tgt->prepare("INSERT INTO lstr_codes (fanlisting_id, size_id, name, filename, status, width, height) VALUES (1, :s, :n, 'existing.png', 'approved', 88, 31)")
            ->execute(['s' => $sizeId, 'n' => $name]);
        return (int) $tgt->lastInsertId();
    }

    // ------------------------------------------------------------------
    // Failed reads never become permission to insert
    // ------------------------------------------------------------------

    private function tracingTarget(PDO $src): TracingPdo
    {
        $tgt = $this->makeTracingPdo();
        $this->makeOwnedTarget($tgt);
        $this->targetWithListing($src, $tgt);
        return $tgt;
    }

    public function testAFailedMigrationCheckStopsTheStep(): void
    {
        $src = $this->sourceWithListing();
        $this->addCode($src, 'listing.png');
        $this->addCode($src, 'collective.png', '88x31', '', 0);
        $tgt = $this->tracingTarget($src);
        $tgt->failOn = '/table_info\(`lstr_codes`\)/';

        $warnings = $this->previewWarnings($src, $tgt, $this->options(self::SKIP));
        self::assertSomeWarningContains($warnings, "Couldn't check");
        foreach ($warnings as $warning) {
            self::assertStringNotContainsString('Migration 104', $warning, 'A failed check is not a missing column');
        }

        $row = $this->resultRow($this->runImport($src, $tgt, $this->options(self::SKIP)), 'Codes');
        $tgt->failOn = null;

        self::assertSame(0, $row['imported']);
        self::assertStringContainsString("Couldn't check", implode(' ', $row['errors']));
        self::assertSame([], $this->codes($tgt));
    }

    public function testAFailedReadOfTheSourceFilenamesStopsTheStep(): void
    {
        $src = TracingPdo::memory();
        $this->makeSource($src);
        $this->addSourceListing($src, 1, 'Moon FL', 'https://fans.test/moon');
        $this->addCode($src, 'code.png');
        $tgt = $this->makeOwnedTarget();
        $src->failOn = '/SELECT listing_id, file_name, size_label FROM/';

        self::assertSomeWarningContains($this->previewWarnings($src, $tgt, $this->options(self::SKIP)), "Couldn't read");

        $row = $this->resultRow($this->runImport($src, $tgt, $this->options(self::SKIP)), 'Codes');

        self::assertSame(0, $row['imported']);
        self::assertStringContainsString('Could not read codes', implode(' ', $row['errors']));
        self::assertSame([], $this->codes($tgt));
    }

    public function testAFailedMatchQueryCostsOnlyThatCode(): void
    {
        $src = $this->sourceWithListing();
        $this->addCode($src, 'first.png');
        $this->addCode($src, 'second.png');
        $tgt = $this->tracingTarget($src);
        $tgt->failOn    = '/FROM lstr_codes WHERE fanlisting_id = :fid/';
        $tgt->failTimes = 1;

        $row = $this->resultRow($this->runImport($src, $tgt, $this->options(self::SKIP)), 'Codes');
        $tgt->failOn = null;

        self::assertSame(1, $row['imported']);
        self::assertCount(1, $row['errors']);
        self::assertStringContainsString('first.png', $row['errors'][0]);
        self::assertSame(['second.png'], array_column($this->codes($tgt), 'name'));

        // Nothing was lost: the next import picks it up.
        $again = $this->resultRow($this->runImport($src, $tgt, $this->options(self::SKIP)), 'Codes');
        self::assertSame(1, $again['imported']);
        self::assertCount(2, $this->codes($tgt));
    }

    public function testAFailedSizeLookupCostsOnlyThatCode(): void
    {
        $src = $this->sourceWithListing();
        $this->addCode($src, 'first.png', 'small');
        $this->addCode($src, 'second.png', 'small');
        $this->addCode($src, 'third.png', 'big');
        $tgt = $this->tracingTarget($src);
        $tgt->failOn    = '/FROM lstr_code_sizes/';
        $tgt->failTimes = 1;

        $row = $this->resultRow($this->runImport($src, $tgt, $this->options(self::SKIP)), 'Codes');
        $tgt->failOn = null;

        self::assertSame(2, $row['imported']);
        self::assertCount(1, $row['errors']);
        self::assertStringContainsString('first.png', $row['errors'][0]);
        self::assertSame(['second.png', 'third.png'], array_column($this->codes($tgt), 'name'));
    }

    public function testAFailedSizeInsertAddsNoCode(): void
    {
        $src = $this->sourceWithListing();
        $this->addCode($src, 'code.png', 'small');
        $tgt = $this->tracingTarget($src);
        $tgt->failOn = '/INSERT INTO lstr_code_sizes/';

        $row = $this->resultRow($this->runImport($src, $tgt, $this->options(self::SKIP)), 'Codes');
        $tgt->failOn = null;

        self::assertSame(0, $row['imported']);
        self::assertStringContainsString('code.png', implode(' ', $row['errors']));
        self::assertSame([], $this->codes($tgt), 'It must not land with no size, where it would be a different code next time');
    }

    public function testAnUnreadableTargetGetsAPreviewWarning(): void
    {
        $src = $this->sourceWithListing();
        $this->addCode($src, 'code.png');
        $tgt = $this->tracingTarget($src);
        $tgt->failOn = '/FROM lstr_code_sizes/';

        self::assertSomeWarningContains($this->previewWarnings($src, $tgt, $this->options(self::SKIP)), "Couldn't read");
    }

    // ------------------------------------------------------------------
    // Images
    // ------------------------------------------------------------------

    public function testWithNoImageCopyingAnInsertStoresTheFilenameAndOverwriteKeepsTheFile(): void
    {
        $src = $this->sourceWithListing();
        $this->addCode($src, self::longName());
        $tgt = $this->makeOwnedTarget();

        $this->import($src, $tgt, self::SKIP);
        self::assertSame(self::longName(), $this->codes($tgt)[0]['filename']);

        $tgt->exec("UPDATE lstr_codes SET filename = 'fn_real_migrated.png'");
        $this->import($src, $tgt, self::OVERWRITE);
        self::assertSame('fn_real_migrated.png', $this->codes($tgt)[0]['filename']);
    }

    // ------------------------------------------------------------------
    // Size labels
    // ------------------------------------------------------------------

    public function testLabelsDifferingOnlyInCapitalsAreTwoSizes(): void
    {
        $src = $this->sourceWithListing();
        $this->addCode($src, 'Code.png', 'Small');
        $this->addCode($src, 'Code.png', 'small');
        $tgt = $this->makeOwnedTarget();

        self::assertSame(2, $this->import($src, $tgt, self::SKIP)['imported']);
        self::assertSame(['Small', 'small'], $tgt->query('SELECT label FROM lstr_code_sizes ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        self::assertCount(2, array_unique(array_column($this->codes($tgt), 'size_id')));

        $skip = $this->import($src, $tgt, self::SKIP);
        self::assertSame([0, 2], [$skip['imported'], $skip['skipped']]);
        self::assertCount(2, $this->codes($tgt));
        self::assertSame(2, (int) $tgt->query('SELECT COUNT(*) FROM lstr_code_sizes')->fetchColumn());
    }

    public function testWidthByHeightLabelsStayOneSizeWhateverTheirCapitals(): void
    {
        $src = $this->sourceWithListing();
        $this->addCode($src, 'Code.png', '100x35');
        $this->addCode($src, 'Code.png', '100X35');
        $tgt = $this->makeOwnedTarget();

        $row = $this->import($src, $tgt, self::SKIP);

        self::assertSame([1, 1], [$row['imported'], $row['skipped']]);
        self::assertSame(1, (int) $tgt->query('SELECT COUNT(*) FROM lstr_code_sizes')->fetchColumn());
        self::assertCount(1, $this->codes($tgt));
    }

    public function testABlankLabelMeansNoSizeAndIsFoundAgain(): void
    {
        $src = $this->sourceWithListing();
        $this->addCode($src, 'code.png', '');
        $tgt = $this->makeOwnedTarget();

        self::assertSame(1, $this->import($src, $tgt, self::SKIP)['imported']);
        self::assertNull($this->codes($tgt)[0]['size_id']);
        self::assertSame(0, (int) $tgt->query('SELECT COUNT(*) FROM lstr_code_sizes')->fetchColumn(), "A size label is required, so a blank one can't be a row");

        $skip = $this->import($src, $tgt, self::SKIP);
        self::assertSame([0, 1], [$skip['imported'], $skip['skipped']]);
        self::assertSame(1, $this->import($src, $tgt, self::OVERWRITE)['imported']);
        self::assertCount(1, $this->codes($tgt));
    }

    public function testBlankZeroAndLabelsThatLookLikeBookkeepingStayApart(): void
    {
        $labels = ['', '0', '__blank__', '__dims'];
        foreach ([$labels, array_reverse($labels)] as $order) {
            $src = $this->sourceWithListing();
            foreach ($order as $label) {
                $this->addCode($src, 'Code.png', $label);
            }
            $tgt = $this->makeOwnedTarget();

            self::assertSame(4, $this->import($src, $tgt, self::SKIP)['imported']);
            self::assertCount(4, array_unique(array_map('strval', array_column($this->codes($tgt), 'size_id'))));
            $stored = $tgt->query('SELECT label FROM lstr_code_sizes ORDER BY label')->fetchAll(PDO::FETCH_COLUMN);
            self::assertSame(['0', '__blank__', '__dims'], $stored, 'Three size rows, and blank has none');

            foreach ([self::SKIP, self::OVERWRITE] as $options) {
                $this->import($src, $tgt, $options);
                self::assertCount(4, $this->codes($tgt), 'No copies');
                self::assertSame(3, (int) $tgt->query('SELECT COUNT(*) FROM lstr_code_sizes')->fetchColumn());
            }
        }
    }

    public function testALabelTooLongForItsColumnIsReportedRatherThanCut(): void
    {
        $src = $this->sourceWithListing();
        $this->addCode($src, 'fits.png', str_repeat('s', 100));
        $this->addCode($src, 'too-long.png', str_repeat('s', 101));
        $tgt = $this->makeOwnedTarget();

        $row = $this->import($src, $tgt, self::SKIP);

        self::assertSame([1, 1], [$row['imported'], $row['skipped']]);
        self::assertStringContainsString('longer than 100', self::reasons($row));
        self::assertSame(['fits.png'], array_column($this->codes($tgt), 'name'));
        self::assertSame(1, (int) $tgt->query('SELECT COUNT(*) FROM lstr_code_sizes')->fetchColumn());
    }

    public function testASizeTooBigForItsColumnIsReportedRatherThanCut(): void
    {
        $src = $this->sourceWithListing();
        $this->addCode($src, 'fits.png', '65535x35');
        $this->addCode($src, 'too-wide.png', '65536x35');
        $this->addCode($src, 'too-tall.png', '35x65536');
        $tgt = $this->makeOwnedTarget();

        $row = $this->import($src, $tgt, self::SKIP);

        self::assertSame([1, 2], [$row['imported'], $row['skipped']]);
        self::assertStringContainsString('too big', self::reasons($row));
        self::assertSame(['fits.png'], array_column($this->codes($tgt), 'name'));
    }

    public function testAnExistingSizeRowIsFoundByItsExactLabel(): void
    {
        $src = $this->sourceWithListing();
        $this->addCode($src, 'code.png', 'small');
        $tgt = $this->targetWithListing($src);
        $this->sizeRow($tgt, 'Small', null, null);
        $small = $this->sizeRow($tgt, 'small', null, null);

        $this->import($src, $tgt, self::SKIP);

        self::assertSame($small, (int) $this->codes($tgt)[0]['size_id']);
        self::assertSame(2, (int) $tgt->query('SELECT COUNT(*) FROM lstr_code_sizes')->fetchColumn());
    }

    // ------------------------------------------------------------------
    // Different labels for one stored size
    // ------------------------------------------------------------------

    public function testTwoLabelsForOneStoredSizeShareItsCodes(): void
    {
        $src = $this->sourceWithListing();
        $this->addCode($src, 'A.png', 'Small');
        $this->addCode($src, 'B.png', '88x31');
        $this->addCode($src, 'B.png', 'Small');
        $tgt = $this->targetWithListing($src);
        $this->sizeRow($tgt, 'Small', 88, 31);

        $row = $this->import($src, $tgt, self::SKIP);

        self::assertSame([2, 1], [$row['imported'], $row['skipped']]);
        self::assertSame(['A.png', 'B.png'], array_column($this->codes($tgt), 'name'), 'B.png under either label is one code');
        self::assertSame(1, (int) $tgt->query('SELECT COUNT(*) FROM lstr_code_sizes')->fetchColumn());

        $skip = $this->import($src, $tgt, self::SKIP);
        self::assertSame([0, 3], [$skip['imported'], $skip['skipped']]);

        self::assertSame(3, $this->import($src, $tgt, self::OVERWRITE)['imported']);
        self::assertCount(2, $this->codes($tgt));
    }

    public function testThePreviewCountsACodeUnderTwoLabelsForOneSizeOnce(): void
    {
        foreach ([['Small', '88x31'], ['88x31', 'Small']] as [$first, $second]) {
            $src = $this->sourceWithListing();
            $this->addCode($src, 'same.png', $first);
            $this->addCode($src, 'same.png', $second);
            $tgt = $this->targetWithListing($src);
            $this->sizeRow($tgt, 'Small', 88, 31);

            $row = $this->import($src, $tgt, self::SKIP);

            self::assertSame([1, 1], [$row['imported'], $row['skipped']]);
            self::assertCount(1, $this->codes($tgt));
        }
    }

    public function testLongNamesUnderTwoLabelsForOneSizeStillCollide(): void
    {
        foreach ([self::SKIP, self::OVERWRITE] as $options) {
            $src = $this->sourceWithListing();
            $this->addCode($src, self::longName('1'), 'Small');
            $this->addCode($src, self::longName('2'), '88x31');
            $tgt    = $this->targetWithListing($src);
            $legacy = $this->storedCode($tgt, $this->sizeRow($tgt, 'Small', 88, 31), self::capped(self::longName()));

            $row = $this->import($src, $tgt, $options);

            self::assertSame([0, 2], [$row['imported'], $row['skipped']]);
            self::assertStringContainsString('first 150 characters', self::reasons($row));
            self::assertCount(1, $this->codes($tgt));
            self::assertSame($legacy, (int) $this->codes($tgt)[0]['id']);
            self::assertNull($this->codes($tgt)[0]['import_source_file'], 'The ambiguous row is left alone');
        }
    }

    public function testACodeInAnotherSizeRowWithTheSameMeasurementsStaysItsOwnCode(): void
    {
        $src = $this->sourceWithListing();
        $this->addCode($src, 'code.png', '88x31');
        $this->addCode($src, 'code.png', 'Banner');
        $tgt   = $this->targetWithListing($src);
        $small = $this->sizeRow($tgt, 'Small', 88, 31);
        $wide  = $this->sizeRow($tgt, 'Banner', 88, 31);

        self::assertSame(2, $this->import($src, $tgt, self::SKIP)['imported']);

        self::assertSame([$small, $wide], array_map('intval', array_column($this->codes($tgt), 'size_id')), 'WxH takes the first row with those measurements, as it always has');
    }

    public function testAWidthByHeightLabelNeedsBothMeasurementsToMatch(): void
    {
        $src = $this->sourceWithListing();
        $this->addCode($src, 'taller.png', '88x50');
        $this->addCode($src, 'wider.png', '50x31');
        $tgt    = $this->targetWithListing($src);
        $stored = $this->sizeRow($tgt);

        self::assertSame(2, $this->import($src, $tgt, self::SKIP)['imported']);

        self::assertNotContains($stored, array_map('intval', array_column($this->codes($tgt), 'size_id')));
        self::assertSame(3, (int) $tgt->query('SELECT COUNT(*) FROM lstr_code_sizes')->fetchColumn());
    }

    public function testAStoredSizeWithNoMeasurementsIsntAWidthByHeightMatch(): void
    {
        $src = $this->sourceWithListing();
        $this->addCode($src, 'code.png', '88x31');
        $tgt      = $this->targetWithListing($src);
        $labelled = $this->sizeRow($tgt, '88x31', null, null);

        $this->import($src, $tgt, self::SKIP);

        self::assertNotSame($labelled, (int) $this->codes($tgt)[0]['size_id']);
        self::assertSame(2, (int) $tgt->query('SELECT COUNT(*) FROM lstr_code_sizes')->fetchColumn());

        $skip = $this->import($src, $tgt, self::SKIP);
        self::assertSame([0, 1], [$skip['imported'], $skip['skipped']]);
        self::assertSame(2, (int) $tgt->query('SELECT COUNT(*) FROM lstr_code_sizes')->fetchColumn());
    }

    public function testLabelsForANewListingAreTwoSizesInThePreviewAndTheRun(): void
    {
        $src = $this->sourceWithListing();
        $this->addCode($src, 'code.png', 'Small');
        $this->addCode($src, 'code.png', '88x31');
        $tgt = $this->makeOwnedTarget();

        self::assertSame(2, $this->import($src, $tgt, self::SKIP)['imported']);
        self::assertSame(2, (int) $tgt->query('SELECT COUNT(*) FROM lstr_code_sizes')->fetchColumn());
    }

    // ------------------------------------------------------------------
    // Without the listings
    // ------------------------------------------------------------------

    public function testACodeAddedToTheSourceDuringTheImportGetsAnErrorRatherThanAGuess(): void
    {
        $src = TracingPdo::memory();
        $this->makeSource($src);
        $this->addSourceListing($src, 1, 'Moon FL', 'https://fans.test/moon');
        $this->addCode($src, 'first.png');
        $fired = false;
        $src->beforeStatement = static function (string $sql) use ($src, &$fired): void {
            if (!$fired && str_contains($sql, 'SELECT * FROM `codes`')) {
                $fired = true;
                $src->quiet("INSERT INTO codes (listing_id, size_label, file_name) VALUES (1, 'Brand New', 'late.png')");
            }
        };
        $tgt = $this->makeOwnedTarget();

        $row = $this->resultRow($this->runImport($src, $tgt, $this->options(self::SKIP)), 'Codes');

        self::assertSame(1, $row['imported']);
        self::assertCount(1, $row['errors']);
        self::assertStringContainsString('late.png', $row['errors'][0]);
        self::assertSame(['first.png'], array_column($this->codes($tgt), 'name'));
    }

    public function testACodeForAListingThatIsntImportedIsCountedAsSkipped(): void
    {
        $src = $this->sourceWithListing();
        $this->addCode($src, 'orphan.png', '88x31', '', 99);
        $this->addCode($src, 'moon.png');
        $tgt = $this->makeOwnedTarget();

        $row = $this->import($src, $tgt, self::SKIP);

        self::assertSame([1, 1], [$row['imported'], $row['skipped']]);
        self::assertSame(['moon.png'], array_column($this->codes($tgt), 'name'));
    }

    public function testABlankFilenameIsCountedAsSkipped(): void
    {
        $src = $this->sourceWithListing();
        $this->addCode($src, '');
        $this->addCode($src, 'moon.png');
        $tgt = $this->makeOwnedTarget();

        $row = $this->import($src, $tgt, self::SKIP);

        self::assertSame([1, 1], [$row['imported'], $row['skipped']]);
    }
}
