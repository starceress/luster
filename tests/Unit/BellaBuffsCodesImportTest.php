<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Import\BellaBuffsImportSource;
use Luster\Import\ImportOptions;
use Luster\Tests\Unit\Support\TracingPdo;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * BellaBuffs import, codes. A code is its source filename and dimensions, tracked the same
 * way as Fanatically's. Overwrite really overwrites, and the preview says what the run
 * will do. An image is still required, and a code without a good one is skipped.
 */
final class BellaBuffsCodesImportTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private string $folder;
    private string $buttonsFile;

    /** @var list<string> The uploads folder's bb_code_ images before this test. */
    private array $uploadsBefore;

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_bb_codes_' . uniqid('', true);
        mkdir($this->folder);
        $this->buttonsFile   = $this->folder . DIRECTORY_SEPARATOR . 'buttons.txt';
        $this->uploadsBefore = self::copiedImages();
    }

    protected function tearDown(): void
    {
        // The import really copies into public/uploads, which ends up in a release if it's left there.
        foreach (array_diff(self::copiedImages(), $this->uploadsBefore) as $copied) {
            unlink($copied);
        }
        foreach (glob($this->folder . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->folder);
    }

    /** @return list<string> */
    private static function copiedImages(): array
    {
        return glob(dirname(__DIR__, 2) . '/public/uploads/codes/bb_code_*') ?: [];
    }

    private static function longName(string $ending = ''): string
    {
        return str_repeat('a', 155) . $ending . '.png';
    }

    private function target(?PDO $pdo = null, bool $migrated = true): PDO
    {
        $pdo ??= TracingPdo::memory();
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("CREATE TABLE owned (listingid INTEGER PRIMARY KEY, dbtable TEXT NOT NULL, title TEXT NOT NULL DEFAULT '', subject TEXT NOT NULL DEFAULT '')");
        $pdo->exec("INSERT INTO owned (listingid, dbtable, title) VALUES (1, 'testfl', 'Test FL')");
        $pdo->exec('CREATE TABLE testfl (email TEXT PRIMARY KEY)');
        $source = $migrated ? ', import_source_file TEXT NULL COLLATE NOCASE' : '';
        $pdo->exec("CREATE TABLE lstr_codes (
            id INTEGER PRIMARY KEY AUTOINCREMENT, fanlisting_id INTEGER NULL, name TEXT NULL COLLATE NOCASE,
            filename TEXT NOT NULL, width INTEGER NOT NULL DEFAULT 0, height INTEGER NOT NULL DEFAULT 0,
            size_id INTEGER NULL, donor_id INTEGER NULL, sort_order INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'approved', submitter_name TEXT NULL, submitter_url TEXT NULL{$source})");
        $pdo->exec('CREATE TABLE lstr_code_sizes (
            id INTEGER PRIMARY KEY AUTOINCREMENT, fanlisting_id INTEGER NOT NULL, label TEXT NOT NULL COLLATE NOCASE,
            width INTEGER NULL, height INTEGER NULL, sort_order INTEGER NOT NULL DEFAULT 0)');
        $pdo->exec("CREATE TABLE lstr_code_donors (
            id INTEGER PRIMARY KEY AUTOINCREMENT, fanlisting_id INTEGER NOT NULL, name TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '')");

        return $pdo;
    }

    /** Puts these lines in buttons.txt, each with a real image unless it's told otherwise. */
    private function buttons(string ...$lines): void
    {
        file_put_contents($this->buttonsFile, implode("\n", $lines) . "\n");
        foreach ($lines as $line) {
            $this->image(explode(',', $line)[0]);
        }
    }

    private function image(string $file): void
    {
        if ($file !== '' && strlen($file) < 200) {
            file_put_contents($this->folder . DIRECTORY_SEPARATOR . $file, base64_decode(self::PNG));
        }
    }

    private function options(string $strategy = 'skip', ?string $imagesDir = null): ImportOptions
    {
        return ImportOptions::fromPost([
            'source'               => 'bellabuffs',
            'bb_target_listing_id' => '1',
            'bb_mode'              => 'upload',
            'conflict_strategy'    => $strategy,
            '_bb_approved_path'    => '',
            'bb_import_codes'      => '1',
            '_bb_buttons_path'     => $this->buttonsFile,
            '_bb_buttons_dir'      => $imagesDir ?? $this->folder,
        ]);
    }

    private function preview(PDO $pdo, ImportOptions $options): array
    {
        foreach ((new BellaBuffsImportSource())->preview($pdo, $pdo, $options)->rows as $row) {
            if ($row['type'] === 'Codes / Buttons') {
                return $row;
            }
        }
        self::fail('No Codes / Buttons row in the preview');
    }

    private function runCodes(PDO $pdo, ImportOptions $options): array
    {
        foreach ((new BellaBuffsImportSource())->run($pdo, $pdo, $options)->rows as $row) {
            if ($row['type'] === 'Codes / Buttons') {
                return $row;
            }
        }
        self::fail('No Codes / Buttons row in the result');
    }

    /** Imports after checking that the preview promises exactly what the run does. */
    private function import(PDO $pdo, ImportOptions $options): array
    {
        $preview = $this->preview($pdo, $options);
        $row     = $this->runCodes($pdo, $options);

        self::assertSame([], $row['errors']);
        self::assertSame([$row['imported'], $row['skipped']], [$preview['import'], $preview['skip']], 'What the preview said it would import and skip');

        return $row;
    }

    private function storedCode(PDO $pdo, ?string $name, ?string $sourceFile = null, int $w = 88, int $h = 31): int
    {
        $pdo->prepare(
            "INSERT INTO lstr_codes (fanlisting_id, name, filename, width, height, sort_order, status, import_source_file)
             VALUES (1, :n, 'existing.png', :w, :h, 7, 'pending', :src)"
        )->execute(['n' => $name, 'w' => $w, 'h' => $h, 'src' => $sourceFile]);

        return (int) $pdo->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    private function codes(PDO $pdo): array
    {
        return $pdo->query('SELECT * FROM lstr_codes ORDER BY id')->fetchAll();
    }

    private static function reasons(array $row): string
    {
        return implode("\n", array_column($row['skipped_items'], 'reason'));
    }

    // ------------------------------------------------------------------
    // Identity
    // ------------------------------------------------------------------

    public function testALongNameIsStoredCappedAndFoundAgain(): void
    {
        $this->buttons(self::longName() . ',88,31,no,,');
        $pdo = $this->target();

        self::assertSame(1, $this->import($pdo, $this->options())['imported']);
        [$code] = $this->codes($pdo);
        self::assertSame(mb_substr(self::longName(), 0, 150), $code['name']);
        self::assertSame(self::longName(), $code['import_source_file']);

        $skip = $this->import($pdo, $this->options());
        self::assertSame([0, 1], [$skip['imported'], $skip['skipped']]);
        self::assertStringContainsString('already exists', self::reasons($skip));

        self::assertSame(1, $this->import($pdo, $this->options('overwrite'))['imported']);
        self::assertCount(1, $this->codes($pdo));
    }

    public function testARenamedCodeIsStillFound(): void
    {
        $this->buttons('code.png,88,31,no,,');
        $pdo = $this->target();
        $this->import($pdo, $this->options());
        $pdo->exec("UPDATE lstr_codes SET name = 'A Pretty Name'");

        $skip = $this->import($pdo, $this->options());

        self::assertSame([0, 1], [$skip['imported'], $skip['skipped']]);
        self::assertCount(1, $this->codes($pdo));
    }

    public function testFilesDifferingOnlyInCapitalsAreTwoCodes(): void
    {
        $this->buttons('Code.png,88,31,no,,', 'code.png,88,31,no,,');
        $pdo = $this->target();

        self::assertSame(2, $this->import($pdo, $this->options())['imported']);

        $skip = $this->import($pdo, $this->options());
        self::assertSame([0, 2], [$skip['imported'], $skip['skipped']]);
        self::assertCount(2, $this->codes($pdo));
    }

    public function testTheSameFileAtOtherDimensionsIsAnotherCode(): void
    {
        $this->buttons('code.png,88,31,no,,', 'code.png,50,50,no,,');
        $pdo = $this->target();

        self::assertSame(2, $this->import($pdo, $this->options())['imported']);
        self::assertCount(2, $this->codes($pdo));
    }

    public function testTwoLongNamesSharingTheirStartStayTwoCodes(): void
    {
        $this->buttons(self::longName('1') . ',88,31,no,,', self::longName('2') . ',88,31,no,,');
        $pdo = $this->target();

        self::assertSame(2, $this->import($pdo, $this->options())['imported']);

        $skip = $this->import($pdo, $this->options());
        self::assertSame([0, 2], [$skip['imported'], $skip['skipped']]);
        self::assertCount(2, $this->codes($pdo));
    }

    public function testTheSameTwoBesideAnUntaggedRowAreSkippedAndTheRowIsLeftAlone(): void
    {
        foreach (['skip', 'overwrite'] as $strategy) {
            $this->buttons(self::longName('1') . ',88,31,no,,', self::longName('2') . ',88,31,no,,');
            $pdo    = $this->target();
            $legacy = $this->storedCode($pdo, mb_substr(self::longName(), 0, 150));

            $row = $this->import($pdo, $this->options($strategy));

            self::assertSame([0, 2], [$row['imported'], $row['skipped']], $strategy);
            self::assertStringContainsString('first 150 characters', self::reasons($row));
            self::assertCount(1, $this->codes($pdo));
            self::assertSame($legacy, (int) $this->codes($pdo)[0]['id']);
            self::assertSame('existing.png', $this->codes($pdo)[0]['filename']);
        }
    }

    public function testACollisionOnlyCountsAtTheSameDimensions(): void
    {
        $this->buttons(self::longName('1') . ',88,31,no,,', self::longName('2') . ',50,50,no,,');
        $pdo = $this->target();
        $this->storedCode($pdo, mb_substr(self::longName(), 0, 150));

        $row = $this->import($pdo, $this->options('overwrite'));

        self::assertSame([2, 0], [$row['imported'], $row['skipped']]);
        self::assertSame(self::longName('1'), $this->codes($pdo)[0]['import_source_file'], 'It has the row to itself at this size');
    }

    public function testTwoUntaggedRowsWithTheSameNameCantBeToldApart(): void
    {
        $this->buttons('code.png,88,31,no,,');
        $pdo = $this->target();
        $this->storedCode($pdo, 'code.png');
        $this->storedCode($pdo, 'code.png');

        $row = $this->import($pdo, $this->options('overwrite'));

        self::assertSame([0, 1], [$row['imported'], $row['skipped']]);
        self::assertStringContainsString("can't be told apart", self::reasons($row));
        self::assertSame(['existing.png', 'existing.png'], array_column($this->codes($pdo), 'filename'));
    }

    public function testAnOverlongNameIsReported(): void
    {
        $this->buttons(str_repeat('a', 252) . '.png,88,31,no,,');
        $pdo = $this->target();

        $row = $this->import($pdo, $this->options());

        self::assertSame([0, 1], [$row['imported'], $row['skipped']]);
        self::assertStringContainsString('longer than 255', self::reasons($row));
    }

    public function testABlankFilenameIsCountedAsSkipped(): void
    {
        $this->buttons(',88,31,no,,', 'code.png,88,31,no,,');
        $pdo = $this->target();

        $row = $this->import($pdo, $this->options());

        self::assertSame([1, 1], [$row['imported'], $row['skipped']]);
    }

    public function testARepeatedButtonFindsTheRowItJustAdded(): void
    {
        $this->buttons('code.png,88,31,no,,', 'code.png,88,31,no,,');
        $pdo = $this->target();

        $row = $this->import($pdo, $this->options());

        self::assertSame([1, 1], [$row['imported'], $row['skipped']]);
        self::assertCount(1, $this->codes($pdo));
    }

    // ------------------------------------------------------------------
    // Overwrite
    // ------------------------------------------------------------------

    public function testOverwriteUpdatesTheSameRowFromTheFile(): void
    {
        $this->buttons('code.png,88,31,yes,Jane,https://jane.test');
        $pdo = $this->target();
        $id  = $this->storedCode($pdo, 'Renamed', 'code.png');

        self::assertSame(1, $this->import($pdo, $this->options('overwrite'))['imported']);

        self::assertCount(1, $this->codes($pdo));
        [$code] = $this->codes($pdo);
        self::assertSame($id, (int) $code['id']);
        self::assertStringStartsWith('bb_code_', $code['filename'], 'The new image');
        self::assertSame('Jane', $code['submitter_name']);
        self::assertSame('https://jane.test', $code['submitter_url']);
        self::assertNotNull($code['donor_id']);
        self::assertNotNull($code['size_id']);
        self::assertSame('Renamed', $code['name'], 'These stay as they are in Luster');
        self::assertSame('pending', $code['status']);
        self::assertSame(7, (int) $code['sort_order']);
    }

    public function testKeepLeavesTheRowAlone(): void
    {
        $this->buttons('code.png,88,31,yes,Jane,https://jane.test');
        $pdo = $this->target();
        $this->storedCode($pdo, 'Renamed', 'code.png');

        $row = $this->import($pdo, $this->options());

        self::assertSame([0, 1], [$row['imported'], $row['skipped']]);
        self::assertSame('existing.png', $this->codes($pdo)[0]['filename']);
        self::assertNull($this->codes($pdo)[0]['submitter_name']);
    }

    public function testOneUntaggedRowIsAdoptedByOverwriteAndLeftByKeep(): void
    {
        $this->buttons('code.png,88,31,no,,');
        $pdo = $this->target();
        $id  = $this->storedCode($pdo, 'code.png');

        $this->import($pdo, $this->options());
        self::assertNull($this->codes($pdo)[0]['import_source_file']);

        self::assertSame(1, $this->import($pdo, $this->options('overwrite'))['imported']);
        self::assertCount(1, $this->codes($pdo));
        self::assertSame($id, (int) $this->codes($pdo)[0]['id']);
        self::assertSame('code.png', $this->codes($pdo)[0]['import_source_file']);
    }

    // ------------------------------------------------------------------
    // Images are still required
    // ------------------------------------------------------------------

    public function testAMissingImageIsStillSkipped(): void
    {
        file_put_contents($this->buttonsFile, "missing.png,88,31,no,,\n");
        $pdo = $this->target();

        $row = $this->import($pdo, $this->options());

        self::assertSame([0, 1], [$row['imported'], $row['skipped']]);
        self::assertStringContainsString('Image file not found', self::reasons($row));
        self::assertSame([], $this->codes($pdo));
        self::assertStringContainsString('missing', implode(' ', $this->preview($pdo, $this->options())['warnings']));
    }

    public function testAFileThatIsntReallyAnImageIsStillSkipped(): void
    {
        file_put_contents($this->buttonsFile, "fake.png,88,31,no,,\n");
        file_put_contents($this->folder . DIRECTORY_SEPARATOR . 'fake.png', '<?php echo "not an image";');
        $pdo = $this->target();

        $row = $this->import($pdo, $this->options());

        self::assertSame([0, 1], [$row['imported'], $row['skipped']]);
        self::assertSame([], $this->codes($pdo));
    }

    public function testOverwriteWithoutAGoodImageLeavesTheCodeAsItIs(): void
    {
        file_put_contents($this->buttonsFile, "code.png,88,31,yes,Jane,https://jane.test\n");
        $pdo = $this->target();
        $this->storedCode($pdo, 'code.png', 'code.png');

        $row = $this->import($pdo, $this->options('overwrite'));

        self::assertSame([0, 1], [$row['imported'], $row['skipped']]);
        self::assertSame('existing.png', $this->codes($pdo)[0]['filename']);
        self::assertNull($this->codes($pdo)[0]['submitter_name']);
    }

    public function testNoImageFolderMeansEveryCodeIsSkippedAndThePreviewSaysSo(): void
    {
        file_put_contents($this->buttonsFile, "code.png,88,31,no,,\n");
        $pdo = $this->target();

        $row = $this->import($pdo, $this->options('skip', ''));

        self::assertSame([0, 1], [$row['imported'], $row['skipped']]);
        self::assertStringContainsString('skipped', implode(' ', $this->preview($pdo, $this->options('skip', ''))['warnings']));
    }

    // ------------------------------------------------------------------
    // Without Migration 104
    // ------------------------------------------------------------------

    public function testWithoutMigration104LongNamesAreReportedAndShortNamesMatchAsBefore(): void
    {
        $this->buttons(self::longName() . ',88,31,no,,', 'short.png,88,31,no,,', 'new.png,88,31,no,,');
        $pdo = $this->target(null, false);
        $pdo->exec("INSERT INTO lstr_codes (fanlisting_id, name, filename, width, height) VALUES (1, 'short.png', 'existing.png', 88, 31)");

        $row = $this->import($pdo, $this->options('overwrite'));

        self::assertSame([2, 1], [$row['imported'], $row['skipped']]);
        self::assertStringContainsString('Migration 104', self::reasons($row));
        self::assertSame([self::longName()], array_column($row['skipped_items'], 'label'));
        self::assertSame(['short.png', 'new.png'], array_column($this->codes($pdo), 'name'), 'Never merged');
    }

    // ------------------------------------------------------------------
    // Failed reads never become permission to insert
    // ------------------------------------------------------------------

    public function testAFailedMigrationCheckStopsTheStep(): void
    {
        $this->buttons('code.png,88,31,no,,');
        $pdo = $this->target();
        $pdo->failOn = '/table_info\(`lstr_codes`\)/';

        self::assertStringContainsString("Couldn't read", implode(' ', $this->preview($pdo, $this->options())['warnings']));

        $row = $this->runCodes($pdo, $this->options());
        $pdo->failOn = null;

        self::assertSame(0, $row['imported']);
        self::assertStringContainsString("Couldn't check", implode(' ', $row['errors']));
        self::assertSame([], $this->codes($pdo));
    }

    public function testAFailedMatchQueryCostsOnlyThatCode(): void
    {
        $this->buttons('first.png,88,31,no,,', 'second.png,88,31,no,,');
        $pdo = $this->target();
        $pdo->failOn    = '/FROM lstr_codes WHERE fanlisting_id = :fid/';
        $pdo->failTimes = 1;

        $row = $this->runCodes($pdo, $this->options());
        $pdo->failOn = null;

        self::assertSame(1, $row['imported']);
        self::assertCount(1, $row['errors']);
        self::assertStringContainsString('first.png', $row['errors'][0]);
        self::assertSame(['second.png'], array_column($this->codes($pdo), 'name'));
    }

    public function testAnUnreadableTargetGetsAPreviewWarning(): void
    {
        $this->buttons('code.png,88,31,no,,');
        $pdo = $this->target();
        $pdo->failOn = '/FROM lstr_codes WHERE fanlisting_id = :fid/';

        self::assertStringContainsString("Couldn't read", implode(' ', $this->preview($pdo, $this->options())['warnings']));
    }
}
