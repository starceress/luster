<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\UpdateApplier;
use Luster\Core\Version;
use Luster\Tests\Unit\Support\InMemorySettings;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

final class UpdateApplierTest extends TestCase
{
    private string $stagingDir;

    /** Represents the live install (basePath) - kept separate from $stagingDir, which represents the extracted new release. */
    private string $liveDir;

    /**
     * A GitHub "latest release" response tagged at the currently-installed version,
     * published after Version::BUILT_AT (always true in practice, since a release is
     * published some time after its build is stamped).
     */
    private function equalVersionReleaseJson(): string
    {
        return json_encode([
            'tag_name'     => 'v' . Version::CURRENT,
            'html_url'     => 'https://github.com/' . Version::GITHUB_REPO . '/releases/tag/v' . Version::CURRENT,
            'published_at' => date('c', strtotime(Version::BUILT_AT) + 3600),
            'assets'       => [],
        ]);
    }

    public function testPrepareReportsUpToDateWhenReleaseVersionEqualsInstalled(): void
    {
        $applier = new UpdateApplier(
            $this->stagingDir,
            SqliteDatabase::make(''),
            fn (string $url): string => $this->equalVersionReleaseJson(),
        );

        $result = $applier->prepare();

        self::assertFalse($result['ok']);
        self::assertTrue($result['up_to_date']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->stagingDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_update_test_' . uniqid('', true);
        mkdir($this->stagingDir, 0777, true);

        $this->liveDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_update_live_' . uniqid('', true);
        mkdir($this->liveDir, 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->deleteDir($this->stagingDir);
        $this->deleteDir($this->liveDir);
    }

    /**
     * Builds a minimal but valid release package shape at $dir: src/,
     * templates/, public/, composer.json, and (by default) a staged
     * vendor/autoload.php and public/.htaccess - the two files
     * packageIsComplete()/assertPackageComplete() require.
     */
    private function scaffoldReleasePackage(
        string $dir,
        bool $includeVendorAutoload = true,
        bool $includeHtaccess = true,
        string $htaccessContent = "default-htaccess-content\n",
    ): void {
        mkdir($dir . '/src', 0777, true);
        mkdir($dir . '/templates', 0777, true);
        mkdir($dir . '/public', 0777, true);
        file_put_contents($dir . '/composer.json', '{}');

        if ($includeVendorAutoload) {
            mkdir($dir . '/vendor', 0777, true);
            file_put_contents($dir . '/vendor/autoload.php', "<?php\n");
        }
        if ($includeHtaccess) {
            file_put_contents($dir . '/public/.htaccess', $htaccessContent);
        }
    }

    /** @param array<int,array<string,mixed>> $results */
    private function findCheck(array $results, string $key): array
    {
        foreach ($results as $row) {
            if (($row['key'] ?? null) === $key) {
                return $row;
            }
        }

        self::fail("No preflight check found with key '{$key}'.");
    }

    /**
     * Mirrors the package shape produced by .github/workflows/release.yml:
     * src/, templates/, public/, and composer.json at the package root.
     */
    public function testStagingIsValidAcceptsReleasePackageShape(): void
    {
        mkdir($this->stagingDir . '/src', 0777, true);
        mkdir($this->stagingDir . '/templates', 0777, true);
        mkdir($this->stagingDir . '/public', 0777, true);
        file_put_contents($this->stagingDir . '/composer.json', '{}');

        $applier = new UpdateApplier($this->stagingDir, SqliteDatabase::make(''));

        self::assertTrue($applier->stagingIsValid($this->stagingDir));
    }

    public function testStagingIsValidRejectsPackageMissingComposerJson(): void
    {
        mkdir($this->stagingDir . '/src', 0777, true);
        mkdir($this->stagingDir . '/templates', 0777, true);
        mkdir($this->stagingDir . '/public', 0777, true);

        $applier = new UpdateApplier($this->stagingDir, SqliteDatabase::make(''));

        self::assertFalse($applier->stagingIsValid($this->stagingDir));
    }

    private function deleteDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($child)) {
                $this->deleteDir($child);
                continue;
            }

            unlink($child);
        }

        rmdir($path);
    }

    public function testApplySkipListIncludesHtaccessAndConfigApp(): void
    {
        $applier = new UpdateApplier(sys_get_temp_dir(), SqliteDatabase::make(''));
        $method  = new \ReflectionMethod(UpdateApplier::class, 'shouldSkipApplyPath');
        $method->setAccessible(true);

        // Must be skipped regardless of install/ existence
        self::assertTrue($method->invoke($applier, 'config/app.php', true));
        self::assertTrue($method->invoke($applier, 'public/.htaccess', true));
        self::assertTrue($method->invoke($applier, 'storage/logs/error.log', true));
        self::assertTrue($method->invoke($applier, 'public/uploads/codes/image.jpg', true));

        // vendor/ is no longer skipped - it's fully managed like src/ or templates/ now
        self::assertFalse($method->invoke($applier, 'vendor/autoload.php', true));

        // install/... (and the bare 'install' entry) is skipped only when it
        // did not exist live before this apply() run started
        self::assertTrue($method->invoke($applier, 'install', false));
        self::assertTrue($method->invoke($applier, 'install/index.php', false));
        self::assertFalse($method->invoke($applier, 'install', true));
        self::assertFalse($method->invoke($applier, 'install/index.php', true));

        // Must NOT be skipped
        self::assertFalse($method->invoke($applier, 'src/Core/View.php', true));
        self::assertFalse($method->invoke($applier, 'public/assets/css/admin.css', true));
        self::assertFalse($method->invoke($applier, 'config/routes.php', true));
        self::assertFalse($method->invoke($applier, 'public/assets/.htaccess', true));
    }

    public function testApplySkipListIsCaseInsensitive(): void
    {
        // A differently-cased staged entry must not be able to bypass the
        // skip list - config/app.php, public/.htaccess, storage/, and
        // public/uploads/ resolve case-insensitively on Windows/macOS
        // filesystems, so the guard has to match on the same basis.
        $applier = new UpdateApplier(sys_get_temp_dir(), SqliteDatabase::make(''));
        $method  = new \ReflectionMethod(UpdateApplier::class, 'shouldSkipApplyPath');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($applier, 'Config/App.php', true));
        self::assertTrue($method->invoke($applier, 'CONFIG/APP.PHP', true));
        self::assertTrue($method->invoke($applier, 'Public/.htaccess', true));
        self::assertTrue($method->invoke($applier, 'Storage/Logs/error.log', true));
        self::assertTrue($method->invoke($applier, 'Public/Uploads/codes/image.jpg', true));
        self::assertTrue($method->invoke($applier, 'INSTALL/index.php', false));
        self::assertFalse($method->invoke($applier, 'INSTALL/index.php', true));
    }

    public function testHardExcludeFromDeletionIsCaseInsensitive(): void
    {
        $applier = new UpdateApplier(sys_get_temp_dir(), SqliteDatabase::make(''));
        $method  = new \ReflectionMethod(UpdateApplier::class, 'isHardExcludedFromDeletion');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($applier, 'Storage/logs/error.log'));
        self::assertTrue($method->invoke($applier, 'Public/Uploads/codes/image.jpg'));
        self::assertTrue($method->invoke($applier, 'Public/Assets/CSS/Skins/mycustom.css'));
        self::assertTrue($method->invoke($applier, 'CONFIG/APP.PHP'));
        self::assertTrue($method->invoke($applier, 'Public/.htaccess'));
        self::assertFalse($method->invoke($applier, 'Src/Core/View.php'));
    }

    public function testChecksumParserMatchesNamedZipAsset(): void
    {
        $hash = str_repeat('a', 64);
        $contents = "ignored\n{$hash}  luster-v1.2.3.zip\n";

        self::assertSame($hash, UpdateApplier::parseChecksumForAsset($contents, 'luster-v1.2.3.zip'));
    }

    public function testChecksumParserAllowsSingleBareHash(): void
    {
        $hash = str_repeat('b', 64);

        self::assertSame($hash, UpdateApplier::parseChecksumForAsset($hash, 'luster-v1.2.3.zip'));
    }

    public function testChecksumParserRejectsWrongAssetNameWhenAmbiguous(): void
    {
        $contents = str_repeat('c', 64) . "  other.zip\n"
            . str_repeat('d', 64) . "  another.zip\n";

        self::assertNull(UpdateApplier::parseChecksumForAsset($contents, 'luster-v1.2.3.zip'));
    }

    public function testArchivePathValidationAllowsNormalRelativePaths(): void
    {
        self::assertTrue(UpdateApplier::isSafeArchivePath('luster/src/Core/View.php'));
        self::assertTrue(UpdateApplier::isSafeArchivePath('public/assets/css/admin.css'));
    }

    public function testArchivePathValidationRejectsTraversalAndAbsolutePaths(): void
    {
        self::assertFalse(UpdateApplier::isSafeArchivePath('../config/app.php'));
        self::assertFalse(UpdateApplier::isSafeArchivePath('luster/../config/app.php'));
        self::assertFalse(UpdateApplier::isSafeArchivePath('/tmp/luster.zip'));
        self::assertFalse(UpdateApplier::isSafeArchivePath('C:/tmp/luster.zip'));
        self::assertFalse(UpdateApplier::isSafeArchivePath('luster//src/View.php'));
    }

    // -----------------------------------------------------------------------
    // planObsoleteFiles()
    // -----------------------------------------------------------------------

    public function testPlanObsoleteFilesReportsFileMissingFromMatchingStagedDirectory(): void
    {
        $this->scaffoldReleasePackage($this->stagingDir);
        mkdir($this->stagingDir . '/src/Core', 0777, true);
        file_put_contents($this->stagingDir . '/src/Core/Kept.php', 'staged');

        mkdir($this->liveDir . '/src/Core', 0777, true);
        file_put_contents($this->liveDir . '/src/Core/Kept.php', 'live');
        file_put_contents($this->liveDir . '/src/Core/Old.php', 'live-only');

        $applier = new UpdateApplier($this->liveDir, SqliteDatabase::make(''));
        $obsolete = $applier->planObsoleteFiles($this->stagingDir);

        self::assertContains('src/Core/Old.php', $obsolete);
        self::assertNotContains('src/Core/Kept.php', $obsolete);
    }

    public function testPlanObsoleteFilesLeavesTopLevelPathsAbsentFromStagingUntouched(): void
    {
        $this->scaffoldReleasePackage($this->stagingDir);

        // .git/ and tests/ are never part of a release package - simulate a
        // dev checkout of this very repo, which must never be touched.
        mkdir($this->liveDir . '/.git', 0777, true);
        file_put_contents($this->liveDir . '/.git/HEAD', 'ref: refs/heads/main');
        mkdir($this->liveDir . '/tests', 0777, true);
        file_put_contents($this->liveDir . '/tests/Foo.php', 'dev-only');

        $applier = new UpdateApplier($this->liveDir, SqliteDatabase::make(''));
        $obsolete = $applier->planObsoleteFiles($this->stagingDir);

        self::assertSame([], $obsolete);
    }

    public function testPlanObsoleteFilesHardExcludesCommunitySkins(): void
    {
        $this->scaffoldReleasePackage($this->stagingDir);
        // Staging ships public/assets/css/ but never a skins/ subdirectory.
        mkdir($this->stagingDir . '/public/assets/css', 0777, true);

        mkdir($this->liveDir . '/public/assets/css/skins', 0777, true);
        file_put_contents($this->liveDir . '/public/assets/css/skins/mycustom.css', '.skin{}');

        $applier = new UpdateApplier($this->liveDir, SqliteDatabase::make(''));
        $obsolete = $applier->planObsoleteFiles($this->stagingDir);

        self::assertSame([], $obsolete);
    }

    public function testPlanObsoleteFilesReportsRemovedVendorFile(): void
    {
        $this->scaffoldReleasePackage($this->stagingDir); // staged vendor/ has only autoload.php

        mkdir($this->liveDir . '/vendor', 0777, true);
        file_put_contents($this->liveDir . '/vendor/autoload.php', 'live-autoload');
        file_put_contents($this->liveDir . '/vendor/legacy-file.php', 'gone');

        $applier = new UpdateApplier($this->liveDir, SqliteDatabase::make(''));
        $obsolete = $applier->planObsoleteFiles($this->stagingDir);

        self::assertContains('vendor/legacy-file.php', $obsolete);
        self::assertNotContains('vendor/autoload.php', $obsolete);
    }

    public function testPlanObsoleteFilesReportsFileDirectoryTypeMismatch(): void
    {
        $this->scaffoldReleasePackage($this->stagingDir);
        // New release turns public/widget.php into a directory.
        mkdir($this->stagingDir . '/public/widget.php', 0777, true);
        file_put_contents($this->stagingDir . '/public/widget.php/index.php', 'new');

        mkdir($this->liveDir . '/public', 0777, true);
        file_put_contents($this->liveDir . '/public/widget.php', 'old');

        $applier = new UpdateApplier($this->liveDir, SqliteDatabase::make(''));
        $obsolete = $applier->planObsoleteFiles($this->stagingDir);

        self::assertContains('public/widget.php', $obsolete);
    }

    public function testPlanObsoleteFilesDiffsInstallNormallyWhenPresentLive(): void
    {
        $this->scaffoldReleasePackage($this->stagingDir);
        mkdir($this->stagingDir . '/install', 0777, true);
        file_put_contents($this->stagingDir . '/install/index.php', 'new');

        mkdir($this->liveDir . '/install', 0777, true);
        file_put_contents($this->liveDir . '/install/index.php', 'old');
        file_put_contents($this->liveDir . '/install/removed-step.php', 'gone');

        $applier = new UpdateApplier($this->liveDir, SqliteDatabase::make(''));
        $obsolete = $applier->planObsoleteFiles($this->stagingDir);

        self::assertContains('install/removed-step.php', $obsolete);
        self::assertNotContains('install/index.php', $obsolete);
    }

    // -----------------------------------------------------------------------
    // runPreflight() — package_complete / deletable
    // -----------------------------------------------------------------------

    public function testRunPreflightPackageCompletePassesForCompletePackage(): void
    {
        $this->scaffoldReleasePackage($this->stagingDir);

        $applier = new UpdateApplier($this->liveDir, SqliteDatabase::make(''));
        $check = $this->findCheck($applier->runPreflight(1000, $this->stagingDir), 'package_complete');

        self::assertTrue($check['ok']);
    }

    public function testRunPreflightPackageCompleteFailsWhenVendorAutoloadMissing(): void
    {
        $this->scaffoldReleasePackage($this->stagingDir, includeVendorAutoload: false);

        $applier = new UpdateApplier($this->liveDir, SqliteDatabase::make(''));
        $check = $this->findCheck($applier->runPreflight(1000, $this->stagingDir), 'package_complete');

        self::assertFalse($check['ok']);
    }

    public function testRunPreflightPackageCompleteFailsWhenHtaccessMissing(): void
    {
        $this->scaffoldReleasePackage($this->stagingDir, includeHtaccess: false);

        $applier = new UpdateApplier($this->liveDir, SqliteDatabase::make(''));
        $check = $this->findCheck($applier->runPreflight(1000, $this->stagingDir), 'package_complete');

        self::assertFalse($check['ok']);
    }

    public function testRunPreflightDeletableFailsWhenObsoleteFileParentNotWritable(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX permission bits are not reliably enforced on Windows.');
        }

        $this->scaffoldReleasePackage($this->stagingDir);

        mkdir($this->liveDir . '/src/Old', 0777, true);
        file_put_contents($this->liveDir . '/src/Old/Legacy.php', 'gone');

        chmod($this->liveDir . '/src', 0555);

        try {
            $applier = new UpdateApplier($this->liveDir, SqliteDatabase::make(''));
            $check = $this->findCheck($applier->runPreflight(1000, $this->stagingDir), 'deletable');

            self::assertFalse($check['ok']);
        } finally {
            chmod($this->liveDir . '/src', 0755);
        }
    }

    // -----------------------------------------------------------------------
    // public/.htaccess reconciliation
    // -----------------------------------------------------------------------

    public function testHtaccessStatusNoBaselineDifferingContentIsKeptCustomized(): void
    {
        $this->scaffoldReleasePackage($this->stagingDir, htaccessContent: "staged-default\n");
        mkdir($this->liveDir . '/public', 0777, true);
        file_put_contents($this->liveDir . '/public/.htaccess', "live-custom\n");

        $applier = new UpdateApplier($this->liveDir, SqliteDatabase::make(''), null, '', new InMemorySettings());

        self::assertSame('kept_customized', $applier->htaccessStatus($this->stagingDir));
    }

    public function testReconcileHtaccessNoBaselineWritesReviewCopyWithoutTouchingLiveOrSeedingBaseline(): void
    {
        $this->scaffoldReleasePackage($this->stagingDir, htaccessContent: "staged-default\n");
        mkdir($this->liveDir . '/public', 0777, true);
        file_put_contents($this->liveDir . '/public/.htaccess', "live-custom\n");

        $settings = new InMemorySettings();
        $applier = new UpdateApplier($this->liveDir, SqliteDatabase::make(''), null, '', $settings);

        $status = $applier->reconcileHtaccess($this->stagingDir);

        self::assertSame('kept_customized', $status);
        self::assertSame("live-custom\n", file_get_contents($this->liveDir . '/public/.htaccess'));
        self::assertSame("staged-default\n", file_get_contents($this->liveDir . '/storage/cache/public.htaccess.new'));
        // The specific bug fixed in this revision: a differing file with no
        // recorded baseline must NOT get one seeded from its own (possibly
        // customized) content - that would authorize a silent overwrite next time.
        self::assertNull($settings->get('update_htaccess_baseline_hash'));
    }

    public function testReconcileHtaccessAutoUpdatesWhenLiveMatchesBaseline(): void
    {
        $this->scaffoldReleasePackage($this->stagingDir, htaccessContent: "staged-new\n");
        mkdir($this->liveDir . '/public', 0777, true);
        file_put_contents($this->liveDir . '/public/.htaccess', "luster-wrote-this\n");

        $settings = new InMemorySettings();
        $settings->set('update_htaccess_baseline_hash', hash('sha256', "luster-wrote-this\n"));
        $settings->set('update_htaccess_reviewed_live_hash', 'stale-live-hash-from-an-older-customization');
        $settings->set('update_htaccess_reviewed_staged_hash', 'stale-staged-hash-from-an-older-release');

        mkdir($this->liveDir . '/storage/cache', 0777, true);
        file_put_contents($this->liveDir . '/storage/cache/public.htaccess.new', 'stale-review-copy');

        $applier = new UpdateApplier($this->liveDir, SqliteDatabase::make(''), null, '', $settings);
        $status = $applier->reconcileHtaccess($this->stagingDir);

        self::assertSame('updated', $status);
        self::assertSame("staged-new\n", file_get_contents($this->liveDir . '/public/.htaccess'));
        self::assertSame(hash('sha256', "staged-new\n"), $settings->get('update_htaccess_baseline_hash'));
        self::assertNull($settings->get('update_htaccess_reviewed_live_hash'));
        self::assertNull($settings->get('update_htaccess_reviewed_staged_hash'));
        self::assertFalse(is_file($this->liveDir . '/storage/cache/public.htaccess.new'));
    }

    public function testReconcileHtaccessKeepsCustomizedFileWhenBaselineMismatches(): void
    {
        $this->scaffoldReleasePackage($this->stagingDir, htaccessContent: "staged-new\n");
        mkdir($this->liveDir . '/public', 0777, true);
        file_put_contents($this->liveDir . '/public/.htaccess', "admin-customized\n");

        $settings = new InMemorySettings();
        $settings->set('update_htaccess_baseline_hash', hash('sha256', "some-older-luster-default\n"));

        $applier = new UpdateApplier($this->liveDir, SqliteDatabase::make(''), null, '', $settings);
        $status = $applier->reconcileHtaccess($this->stagingDir);

        self::assertSame('kept_customized', $status);
        self::assertSame("admin-customized\n", file_get_contents($this->liveDir . '/public/.htaccess'));
        self::assertSame(hash('sha256', "some-older-luster-default\n"), $settings->get('update_htaccess_baseline_hash'));
        self::assertSame("staged-new\n", file_get_contents($this->liveDir . '/storage/cache/public.htaccess.new'));
    }

    public function testReconcileHtaccessReportsReviewedWhenAdminAcceptedCurrentContent(): void
    {
        $this->scaffoldReleasePackage($this->stagingDir, htaccessContent: "staged-new\n");
        mkdir($this->liveDir . '/public', 0777, true);
        file_put_contents($this->liveDir . '/public/.htaccess', "admin-customized\n");

        $settings = new InMemorySettings();
        $settings->set('update_htaccess_baseline_hash', hash('sha256', "some-older-luster-default\n"));
        // Reviewed pairing must match BOTH the live content and the exact
        // staged default it was reviewed against.
        $settings->set('update_htaccess_reviewed_live_hash', hash('sha256', "admin-customized\n"));
        $settings->set('update_htaccess_reviewed_staged_hash', hash('sha256', "staged-new\n"));

        $applier = new UpdateApplier($this->liveDir, SqliteDatabase::make(''), null, '', $settings);

        self::assertSame('kept_customized_reviewed', $applier->htaccessStatus($this->stagingDir));
    }

    public function testReconcileHtaccessDoesNotStayQuietWhenALaterReleaseShipsADifferentDefault(): void
    {
        // Reproduces the exact scenario a review pass caught: accepting the
        // diff for one release's staged default must not silently carry that
        // "reviewed" verdict forward onto a LATER release's different diff -
        // the admin never saw that one. Matching on the live hash alone
        // (without also pinning the staged hash) would let this happen even
        // though the live file was never touched.
        mkdir($this->liveDir . '/public', 0777, true);
        file_put_contents($this->liveDir . '/public/.htaccess', "admin-customized\n");

        $settings = new InMemorySettings();
        $applier = new UpdateApplier($this->liveDir, SqliteDatabase::make(''), null, '', $settings);

        // Release A ships a default; admin reviews and accepts it.
        $stagingA = $this->stagingDir . '_a';
        mkdir($stagingA, 0777, true);
        $this->scaffoldReleasePackage($stagingA, htaccessContent: "release-a-default\n");
        self::assertSame('kept_customized', $applier->htaccessStatus($stagingA));
        $applier->acceptHtaccessCustomization($stagingA);
        self::assertSame('kept_customized_reviewed', $applier->htaccessStatus($stagingA));

        // Release B ships a DIFFERENT default. Live content hasn't changed.
        $stagingB = $this->stagingDir . '_b';
        mkdir($stagingB, 0777, true);
        $this->scaffoldReleasePackage($stagingB, htaccessContent: "release-b-default\n");

        self::assertSame('kept_customized', $applier->htaccessStatus($stagingB));

        $this->deleteDir($stagingA);
        $this->deleteDir($stagingB);
    }

    public function testReconcileHtaccessUnchangedWhenContentAlreadyMatchesStaged(): void
    {
        $this->scaffoldReleasePackage($this->stagingDir, htaccessContent: "same-content\n");
        mkdir($this->liveDir . '/public', 0777, true);
        file_put_contents($this->liveDir . '/public/.htaccess', "same-content\n");

        $settings = new InMemorySettings();
        $applier = new UpdateApplier($this->liveDir, SqliteDatabase::make(''), null, '', $settings);

        $status = $applier->reconcileHtaccess($this->stagingDir);

        self::assertSame('unchanged', $status);
        self::assertFalse(is_file($this->liveDir . '/storage/cache/public.htaccess.new'));
        self::assertSame(hash('sha256', "same-content\n"), $settings->get('update_htaccess_baseline_hash'));
    }

    public function testAcceptHtaccessCustomizationNeverAuthorizesFutureOverwrite(): void
    {
        $this->scaffoldReleasePackage($this->stagingDir, htaccessContent: "staged-new\n");
        mkdir($this->liveDir . '/public', 0777, true);
        file_put_contents($this->liveDir . '/public/.htaccess', "admin-customized\n");

        $settings = new InMemorySettings();
        $applier = new UpdateApplier($this->liveDir, SqliteDatabase::make(''), null, '', $settings);

        self::assertSame('kept_customized', $applier->htaccessStatus($this->stagingDir));

        $applier->acceptHtaccessCustomization($this->stagingDir);

        // The critical invariant: accepting a customization must never make
        // it eligible for a silent overwrite on a later update.
        self::assertNull($settings->get('update_htaccess_baseline_hash'));
        self::assertSame(hash('sha256', "admin-customized\n"), $settings->get('update_htaccess_reviewed_live_hash'));
        self::assertSame(hash('sha256', "staged-new\n"), $settings->get('update_htaccess_reviewed_staged_hash'));
        self::assertSame('kept_customized_reviewed', $applier->htaccessStatus($this->stagingDir));
    }

    public function testAcceptHtaccessCustomizationPairsWithActiveStagingNotStaleReviewCopy(): void
    {
        mkdir($this->liveDir . '/public', 0777, true);
        file_put_contents($this->liveDir . '/public/.htaccess', "admin-customized\n");

        // Stale review copy left over from a previously-applied, different release.
        mkdir($this->liveDir . '/storage/cache', 0777, true);
        file_put_contents($this->liveDir . '/storage/cache/public.htaccess.new', "release-a-default\n");

        $settings = new InMemorySettings();
        $applier = new UpdateApplier($this->liveDir, SqliteDatabase::make(''), null, '', $settings);

        // Release B is the one actually staged and being reviewed right now.
        $this->scaffoldReleasePackage($this->stagingDir, htaccessContent: "release-b-default\n");

        self::assertSame('kept_customized', $applier->htaccessStatus($this->stagingDir));

        $applier->acceptHtaccessCustomization($this->stagingDir);

        // Accepting must pair with release B's actual staged content, not
        // the stale leftover .new from release A - otherwise the banner for
        // the release actually in front of the admin never actually quiets.
        self::assertSame(hash('sha256', "release-b-default\n"), $settings->get('update_htaccess_reviewed_staged_hash'));
        self::assertSame('kept_customized_reviewed', $applier->htaccessStatus($this->stagingDir));
    }

    public function testHtaccessReviewContentPrefersActiveStagingOverStaleReviewCopy(): void
    {
        // A stale .new left behind by a previously-applied release must not
        // shadow the release that's actually staged and being reviewed now -
        // this was the priority-order bug: it previously checked the cached
        // copy first regardless of whether a newer release was staged.
        mkdir($this->liveDir . '/storage/cache', 0777, true);
        file_put_contents($this->liveDir . '/storage/cache/public.htaccess.new', "release-a-default\n");

        $this->scaffoldReleasePackage($this->stagingDir, htaccessContent: "release-b-default\n");

        $applier = new UpdateApplier($this->liveDir, SqliteDatabase::make(''));

        self::assertSame("release-b-default\n", $applier->htaccessReviewContent($this->stagingDir));
    }

    public function testHtaccessReviewContentFallsBackToCachedCopyWithNoActiveStagingDir(): void
    {
        mkdir($this->liveDir . '/storage/cache', 0777, true);
        file_put_contents($this->liveDir . '/storage/cache/public.htaccess.new', 'from-disk');

        $applier = new UpdateApplier($this->liveDir, SqliteDatabase::make(''));

        self::assertSame('from-disk', $applier->htaccessReviewContent(null));
    }

    public function testHtaccessReviewContentFallsBackToStagedFileWhenNoReviewCopyExists(): void
    {
        $this->scaffoldReleasePackage($this->stagingDir, htaccessContent: "staged-preview\n");

        $applier = new UpdateApplier($this->liveDir, SqliteDatabase::make(''));

        self::assertSame("staged-preview\n", $applier->htaccessReviewContent($this->stagingDir));
    }

    public function testHtaccessReviewContentReturnsNullWithNoSourceAvailable(): void
    {
        $applier = new UpdateApplier($this->liveDir, SqliteDatabase::make(''));

        self::assertNull($applier->htaccessReviewContent(null));
    }

    // -----------------------------------------------------------------------
    // apply() end-to-end
    // -----------------------------------------------------------------------

    public function testApplyThrowsWhenStagedPackageIsIncomplete(): void
    {
        mkdir($this->stagingDir . '/src', 0777, true);
        mkdir($this->stagingDir . '/templates', 0777, true);
        mkdir($this->stagingDir . '/public', 0777, true);
        file_put_contents($this->stagingDir . '/composer.json', '{}');
        // No vendor/autoload.php, no public/.htaccess - assertPackageComplete() must
        // catch this itself rather than relying on the controller having already
        // run preflight against this same staging dir.

        $applier = new UpdateApplier($this->liveDir, SqliteDatabase::make(''));

        $this->expectException(\RuntimeException::class);
        $applier->apply($this->stagingDir);
    }

    public function testApplyDeletesObsoleteFilesCopiesNewOnesAndPreservesUserContent(): void
    {
        $stagingDir = $this->liveDir . '/storage/cache/lstr_test_staging_apply';
        mkdir($stagingDir, 0777, true);
        $this->scaffoldReleasePackage($stagingDir, htaccessContent: "same-htaccess\n");
        mkdir($stagingDir . '/src/Core', 0777, true);
        file_put_contents($stagingDir . '/src/Core/Kept.php', 'staged-version');
        mkdir($stagingDir . '/vendor/pkg', 0777, true);
        file_put_contents($stagingDir . '/vendor/pkg/Stays.php', 'staged-vendor');

        mkdir($this->liveDir . '/src/Core', 0777, true);
        file_put_contents($this->liveDir . '/src/Core/Kept.php', 'old-version');
        file_put_contents($this->liveDir . '/src/Core/Old.php', 'obsolete');
        mkdir($this->liveDir . '/vendor/pkg', 0777, true);
        file_put_contents($this->liveDir . '/vendor/pkg/Stays.php', 'old-vendor');
        file_put_contents($this->liveDir . '/vendor/pkg/Removed.php', 'obsolete-vendor');
        mkdir($this->liveDir . '/public', 0777, true);
        file_put_contents($this->liveDir . '/public/.htaccess', "same-htaccess\n");
        mkdir($this->liveDir . '/public/assets/css/skins', 0777, true);
        file_put_contents($this->liveDir . '/public/assets/css/skins/custom.css', '.mine{}');

        $applier = new UpdateApplier($this->liveDir, SqliteDatabase::make(''), null, '', new InMemorySettings());
        $result = $applier->apply($stagingDir);

        self::assertFalse(is_file($this->liveDir . '/src/Core/Old.php'), 'obsolete src file should be removed');
        self::assertSame('staged-version', file_get_contents($this->liveDir . '/src/Core/Kept.php'));
        self::assertFalse(is_file($this->liveDir . '/vendor/pkg/Removed.php'), 'obsolete vendor file should be removed');
        self::assertSame('staged-vendor', file_get_contents($this->liveDir . '/vendor/pkg/Stays.php'));
        self::assertTrue(is_file($this->liveDir . '/public/assets/css/skins/custom.css'), 'community skin must survive');
        self::assertGreaterThanOrEqual(2, $result['deleted']);
        self::assertSame('unchanged', $result['htaccess']);
    }

    public function testApplyHonorsInstallExistedSnapshotEvenWhenObsoleteSweepEmptiesIt(): void
    {
        $stagingDir = $this->liveDir . '/storage/cache/lstr_test_staging_install_kept';
        mkdir($stagingDir, 0777, true);
        $this->scaffoldReleasePackage($stagingDir);
        mkdir($stagingDir . '/install', 0777, true);
        file_put_contents($stagingDir . '/install/index.php', 'new-installer');

        // Live install/'s only file is obsolete under the new release - the
        // obsolete sweep will delete it and prune install/ down to nothing
        // before the copy loop runs.
        mkdir($this->liveDir . '/install', 0777, true);
        file_put_contents($this->liveDir . '/install/old-entry.php', 'old-installer');

        $applier = new UpdateApplier($this->liveDir, SqliteDatabase::make(''));
        $applier->apply($stagingDir);

        // install/ existed before this apply() run started, so even though
        // the sweep emptied it mid-run, the copy loop must still repopulate
        // it from the new release rather than treating it as admin-deleted -
        // proves the $installExisted snapshot is honored, not a live re-check.
        self::assertTrue(is_file($this->liveDir . '/install/index.php'));
    }

    public function testApplyNeverRecreatesInstallDirectoryDeletedByAdmin(): void
    {
        $stagingDir = $this->liveDir . '/storage/cache/lstr_test_staging_install_gone';
        mkdir($stagingDir, 0777, true);
        $this->scaffoldReleasePackage($stagingDir);
        mkdir($stagingDir . '/install', 0777, true);
        file_put_contents($stagingDir . '/install/index.php', 'new-installer');

        // No install/ live at all - the admin deleted it after setup.

        $applier = new UpdateApplier($this->liveDir, SqliteDatabase::make(''));
        $applier->apply($stagingDir);

        self::assertFalse(is_dir($this->liveDir . '/install'));
    }
}
