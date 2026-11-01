<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Controllers\Admin\ImportController;
use Luster\Import\ImageMigrator;
use Luster\Import\ImportOptions;
use Luster\Import\ImportPreview;
use Luster\Import\ListingAdminImportSource;
use Luster\Import\SqlDumpLoader;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Finding a Listing Admin install's table prefix, checking a chosen prefix against
 * the ticked import steps, and what the wizard shows for both.
 *
 * Listing Admin names every table prefix . name (trex_members …) and its listings
 * table is the prefix without underscores (trex); a blank prefix becomes
 * "listingadmin_". There's no "normal" unprefixed install, which the Options step
 * used to suggest — leaving it blank read tables that don't exist.
 */
final class ListingAdminPrefixDetectionTest extends TestCase
{
    /** A real 1×1 PNG, so finfo accepts it. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/luster_la_prefix_' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/public', 0777, true);
        mkdir($this->tmp . '/la_images', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
    }

    // -----------------------------------------------------------------------
    // Finding installs
    // -----------------------------------------------------------------------

    public function testFindsAPrefixedInstall(): void
    {
        $pdo = $this->pdo();
        $this->laInstall($pdo, 'trex_', listings: 3);

        $this->assertSame(
            [['prefix' => 'trex_', 'registry' => 'trex', 'listings' => 3]],
            ListingAdminImportSource::detectInstalls($pdo)
        );
    }

    public function testFindsListingAdminsOwnFallbackPrefix(): void
    {
        $pdo = $this->pdo();
        $this->laInstall($pdo, 'listingadmin_');

        $this->assertSame(['listingadmin_'], array_column(ListingAdminImportSource::detectInstalls($pdo), 'prefix'));
        $this->assertSame(['listingadmin'], array_column(ListingAdminImportSource::detectInstalls($pdo), 'registry'));
    }

    public function testTheListingsTableIsThePrefixWithoutAnyUnderscores(): void
    {
        $pdo = $this->pdo();
        $this->laInstall($pdo, 'my_la_');

        $this->assertSame(
            [['prefix' => 'my_la_', 'registry' => 'myla', 'listings' => 2]],
            ListingAdminImportSource::detectInstalls($pdo)
        );
    }

    public function testFindsEveryInstallInOneDatabase(): void
    {
        $pdo = $this->pdo();
        $this->laInstall($pdo, 'trex_', listings: 42);
        $this->laInstall($pdo, 'old_', listings: 3);

        $this->assertSame(
            [
                ['prefix' => 'old_', 'registry' => 'old', 'listings' => 3],
                ['prefix' => 'trex_', 'registry' => 'trex', 'listings' => 42],
            ],
            ListingAdminImportSource::detectInstalls($pdo)
        );
    }

    public function testFindsTheUnprefixedMainLayout(): void
    {
        $pdo = $this->pdo();
        $this->laInstall($pdo, '', listings: 1);

        $this->assertSame(
            [['prefix' => '', 'registry' => 'main', 'listings' => 1]],
            ListingAdminImportSource::detectInstalls($pdo)
        );
    }

    public function testIgnoresTablesThatOnlyLookLikeListingAdmin(): void
    {
        $pdo = $this->pdo();
        // An Enthusiast-style member table: no "flmusic" listings table.
        $pdo->exec('CREATE TABLE fl_music_members (email TEXT, name TEXT)');
        // Listings and members, but no options table.
        $this->laInstall($pdo, 'noopt_', without: ['options']);
        // Everything, but the listings table lacks dbtabl.
        $this->laInstall($pdo, 'nodb_');
        $pdo->exec('DROP TABLE nodb');
        $pdo->exec('CREATE TABLE nodb (id INTEGER PRIMARY KEY, title TEXT, subject TEXT)');
        // Luster's own tables in a shared database.
        $pdo->exec('CREATE TABLE lstr_kim_members (id INTEGER PRIMARY KEY, email TEXT)');
        $pdo->exec('CREATE TABLE owned (listingid INTEGER PRIMARY KEY)');

        $this->assertSame([], ListingAdminImportSource::detectInstalls($pdo));
    }

    public function testDropsAPrefixWithCharactersItCannotUse(): void
    {
        $pdo = $this->pdo();
        $this->laInstall($pdo, 'bad-x_');

        $this->assertSame([], ListingAdminImportSource::detectInstalls($pdo));
    }

    public function testAnUploadedDumpReportsItsOwnPrefixNotTheTemporaryOne(): void
    {
        $pdo = $this->pdo();
        $this->laInstall($pdo, 'trex_', temp: 'lstr_tmp_ab12_');
        $original = ['trex', 'trex_members', 'trex_options', 'trex_categories', 'trex_affiliates', 'trex_joined', 'trex_wishlist'];

        $this->assertSame(
            [['prefix' => 'trex_', 'registry' => 'trex', 'listings' => 2]],
            ListingAdminImportSource::detectInstalls($pdo, $original, 'lstr_tmp_ab12_')
        );
        $this->assertSame([], ListingAdminImportSource::detectInstalls($pdo, $original, 'bad prefix'));
    }

    /**
     * The table layout of the dump that showed the problem (columns only, no rows):
     * a trex_ install next to two leftover member tables from other scripts.
     */
    public function testTheReportedDumpsLayoutFindsExactlyOneInstall(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("CREATE TABLE portal2 (email TEXT PRIMARY KEY, name TEXT, country TEXT, url TEXT, pending INTEGER,
            password TEXT, showemail INTEGER, showurl INTEGER, added TEXT)");
        $pdo->exec("CREATE TABLE tidusyuna_members (id INTEGER PRIMARY KEY, name TEXT, email TEXT, country TEXT, url TEXT,
            pending INTEGER, added TEXT)");
        $pdo->exec("CREATE TABLE trex (id INTEGER PRIMARY KEY, title TEXT, subject TEXT, url TEXT, image TEXT, category TEXT,
            status INTEGER, show INTEGER, dbhost TEXT, dbuser TEXT, dbpass TEXT, dbname TEXT, dbtype TEXT, dbtabl TEXT,
            dblist INTEGER, dbaffs TEXT, dbflid INTEGER, dbhttp TEXT, dbpath TEXT, `desc` TEXT, stats TEXT, affiliates TEXT,
            wishlist TEXT, quotes TEXT, members TEXT, members_header TEXT, members_footer TEXT, updates TEXT,
            form_delete TEXT, form_form TEXT, form_join TEXT, form_join_comments INTEGER, form_join_rules TEXT,
            form_reset TEXT, form_update TEXT, fave_fields TEXT, previous TEXT, date TEXT, since TEXT, updated TEXT,
            granted INTEGER, markup TEXT)");
        $pdo->exec("CREATE TABLE trex_members (mID INTEGER PRIMARY KEY, mEmail TEXT, fNiq INTEGER, mName TEXT, mURL TEXT,
            mCountry TEXT, mPassword TEXT, mExtra TEXT, mVisible INTEGER, mPending INTEGER, mUpdate TEXT, mEdit TEXT, mAdd TEXT)");
        $pdo->exec("CREATE TABLE trex_options (name TEXT UNIQUE, text TEXT)");
        foreach (['affiliates', 'categories', 'codes', 'codes_categories', 'codes_donors', 'codes_sizes', 'errors', 'joined',
                  'kim', 'logs', 'success', 'templates', 'templates_emails', 'updates', 'updates_comments', 'wishlist'] as $t) {
            $pdo->exec("CREATE TABLE trex_{$t} (x INTEGER)");
        }
        $pdo->exec("INSERT INTO trex (id, title, subject) VALUES (1, 'A', 'A'), (2, 'B', 'B')");

        $this->assertSame(
            [['prefix' => 'trex_', 'registry' => 'trex', 'listings' => 2]],
            ListingAdminImportSource::detectInstalls($pdo)
        );
        $this->assertSame('listing_admin_3x', (new ListingAdminImportSource())->detectSchema($pdo));

        // An upload's table names alone still don't say "Listing Admin": that needs the
        // columns, so the controller asks detectInstalls() where the tables are loaded.
        $names = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame('unknown', SqlDumpLoader::inferSchemaVariant($names));
    }

    public function testImageSettingsFromAnOptionsTableLikeTheReportedOne(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('CREATE TABLE trex_options (name TEXT, text TEXT)');
        $pdo->exec("INSERT INTO trex_options (name, text) VALUES
            ('adm_path', '/usr/www/users/someone/fan/public/admin/inc/'),
            ('codes_img_path', '/usr/www/users/someone/fan/public/admin/images/codes/'),
            ('codes_img_http', 'http://old.example.test/fan/public/admin/images/codes/')");

        $settings = $this->invoke(new ListingAdminImportSource(), 'readImageSettings', [$pdo, 'trex_']);

        $this->assertSame('/usr/www/users/someone/fan/public/admin/images/codes', $settings['codes_dir']);
        $this->assertSame('http://old.example.test/fan/public/admin/images/codes', $settings['codes_url']);
    }

    // -----------------------------------------------------------------------
    // The check against the ticked steps
    // -----------------------------------------------------------------------

    public function testAFullInstallPassesWithEveryStepTickedEvenWithoutAddonTables(): void
    {
        $src = $this->pdo();
        $this->laInstall($src, 'trex_', without: ['codes', 'kim', 'updates']);
        $options = $this->options(['la_table_prefix' => 'trex_'] + $this->allSteps());

        $this->assertSame([], (new ListingAdminImportSource())->missingTables($src, $options));

        $rows = $this->previewRows($src, $options);
        $this->assertSame(['No Listing Admin codes table named "trex_codes" was found.'], $rows['Codes']['warnings']);
        $this->assertSame(['No Listing Admin updates table named "trex_updates" was found.'], $rows['Updates']['warnings']);
        $this->assertSame(['No Listing Admin KIM table named "trex_kim" was found.'], $rows['KIM List']['warnings']);
        $this->assertSame(2, $rows['Owned Listings']['found']);
    }

    public function testATypoWithOnlyAddonStepsTickedIsBlocked(): void
    {
        $src = $this->pdo();
        $this->laInstall($src, 'trex_');
        $options = $this->options(['la_table_prefix' => 'terx_', 'import_updates' => '1', 'import_kim' => '1']);

        $missing = (new ListingAdminImportSource())->missingTables($src, $options);

        $this->assertSame(
            [['table' => 'terx_updates', 'step' => 'Updates'], ['table' => 'terx_kim', 'step' => 'KIM List']],
            $missing
        );
    }

    public function testABlankPrefixAgainstAPrefixedInstallNamesTheUnprefixedTables(): void
    {
        $src = $this->pdo();
        $this->laInstall($src, 'trex_');
        $options = $this->options($this->allSteps());

        $message = ListingAdminImportSource::missingTablesMessage(
            (new ListingAdminImportSource())->missingTables($src, $options),
            ''
        );

        $this->assertStringStartsWith("Luster couldn't find these Listing Admin tables with no table prefix: ", $message);
        $this->assertStringContainsString('main (for Owned listings, Join Rules, Codes)', $message);
        $this->assertStringContainsString('members (for Members)', $message);
        $this->assertStringContainsString('categories (for Categories)', $message);
        $this->assertStringContainsString('Enter the start of your Listing Admin table names as the Table Prefix (for example trex_)', $message);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);
        (new ListingAdminImportSource())->preview($src, $this->pdo(), $options);
    }

    public function testTheUnprefixedMainLayoutStillWorks(): void
    {
        $src = $this->pdo();
        $this->laInstall($src, '');
        $options = $this->options($this->allSteps());

        $this->assertSame([], (new ListingAdminImportSource())->missingTables($src, $options));
        $this->assertSame(2, $this->previewRows($src, $options)['Owned Listings']['found']);
    }

    public function testUploadedTablesAreCheckedBehindTheirTemporaryPrefixButNamedWithout(): void
    {
        $src = $this->pdo();
        $this->laInstall($src, 'trex_', without: ['wishlist'], temp: 'lstr_tmp_ab12_');
        $options = $this->options([
            'la_table_prefix' => 'trex_', '_dump_table_prefix' => 'lstr_tmp_ab12_',
        ] + $this->allSteps());

        $this->assertSame(
            [['table' => 'trex_wishlist', 'step' => 'Wishlist']],
            (new ListingAdminImportSource())->missingTables($src, $options)
        );
    }

    public function testNothingTickedNeedsNoTables(): void
    {
        $this->assertSame([], (new ListingAdminImportSource())->missingTables($this->pdo(), $this->options(['la_table_prefix' => 'trex_'])));
    }

    public function testAnInvalidPrefixIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ListingAdminImportSource())->missingTables($this->pdo(), $this->options(['la_table_prefix' => 'tr`ex_', 'import_listings' => '1']));
    }

    public function testRunSaysPlainlyWhenUpdatesOrKimTablesAreMissingAndInstallsNothing(): void
    {
        $src     = $this->pdo();
        $tgt     = $this->pdo();
        $adapter = new ListingAdminImportSource();

        $live   = $this->options(['la_table_prefix' => 'trex_']);
        $upload = $this->options(['la_table_prefix' => 'trex_', '_dump_table_prefix' => 'lstr_tmp_ab12_']);

        $updates = $this->invoke($adapter, 'runUpdates', [$src, $tgt, $live, [], 'trex_']);
        $kim     = $this->invoke($adapter, 'runKim', [$src, $tgt, $upload, [], 'lstr_tmp_ab12_trex_']);

        $this->assertSame(['No Listing Admin updates table named "trex_updates" was found.'], $updates['warnings']);
        $this->assertSame(
            ['No Listing Admin KIM table named "trex_kim" was found. If you uploaded an SQL file, check that the export includes it.'],
            $kim['warnings']
        );
        $this->assertSame([[], []], [$updates['errors'], $kim['errors']]);
        $this->assertSame([], $tgt->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(), 'no add-on was installed');
    }

    // -----------------------------------------------------------------------
    // What the wizard shows
    // -----------------------------------------------------------------------

    public function testTestConnectionLabels(): void
    {
        $label = fn(array $installs): string => $this->invokeStatic(ImportController::class, 'laInstallsLabel', [$installs]);

        $this->assertSame('No Listing Admin tables found in this database.', $label([]));
        $this->assertSame(
            'Listing Admin (table prefix trex_) — 42 listing(s) found in `trex`',
            $label([['prefix' => 'trex_', 'registry' => 'trex', 'listings' => 42]])
        );
        $this->assertSame(
            'Listing Admin (no table prefix) — 1 listing(s) found in `main`',
            $label([['prefix' => '', 'registry' => 'main', 'listings' => 1]])
        );
        $this->assertSame(
            "Listing Admin — 2 installs found: old_ (3 listings), trex_ (42 listings). You'll choose one on the next step.",
            $label([
                ['prefix' => 'old_', 'registry' => 'old', 'listings' => 3],
                ['prefix' => 'trex_', 'registry' => 'trex', 'listings' => 42],
            ])
        );
    }

    public function testThePrefixComesFromTheFieldOrTheChosenInstall(): void
    {
        $prefix = static fn(array $post): string => ImportOptions::fromPost($post + ['source' => 'listing-admin'])->laTablePrefix;

        $this->assertSame('trex_', $prefix(['la_table_prefix' => ' trex_ ']));
        $this->assertSame('old_', $prefix(['la_table_prefix' => 'old_', 'la_table_prefix_other' => 'ignored_']));
        $this->assertSame('mine_', $prefix(['la_table_prefix' => ImportOptions::LA_PREFIX_OTHER, 'la_table_prefix_other' => ' mine_ ']));
        $this->assertSame('', $prefix([]));
    }

    public function testOptionsFillsInTheOnlyInstall(): void
    {
        $html = $this->renderOptions([['prefix' => 'trex_', 'registry' => 'trex', 'listings' => 42]]);

        $this->assertStringContainsString('name="la_table_prefix" class="form" style="max-width:220px"', $html);
        $this->assertMatchesRegularExpression('/id="la_table_prefix"[^>]*value="trex_"/s', $html);
        $this->assertStringContainsString('Found in your Listing Admin tables', $html);
        $this->assertStringContainsString('42 listings.', $html);
        $this->assertStringNotContainsString('Leave blank for a normal Listing Admin install', $html);
        $this->assertStringNotContainsString('leave blank for default install', $html);
    }

    public function testOptionsSaysWhenTheOnlyInstallHasNoPrefix(): void
    {
        $html = $this->renderOptions([['prefix' => '', 'registry' => 'main', 'listings' => 1]]);

        $this->assertMatchesRegularExpression('/id="la_table_prefix"[^>]*value=""/s', $html);
        $this->assertStringContainsString('Found Listing Admin tables with no prefix', $html);
        $this->assertStringContainsString('Leave this blank.', $html);
    }

    public function testOptionsListsSeveralInstalls(): void
    {
        $html = $this->renderOptions([
            ['prefix' => 'old_', 'registry' => 'old', 'listings' => 3],
            ['prefix' => 'trex_', 'registry' => 'trex', 'listings' => 42],
        ]);

        $this->assertStringContainsString('more than one Listing Admin install', $html);
        $this->assertMatchesRegularExpression('/type="radio" name="la_table_prefix" value="old_"\s*checked/', $html);
        $this->assertMatchesRegularExpression('/type="radio" name="la_table_prefix" value="trex_"\s*>/', $html);
        $this->assertStringContainsString('value="' . ImportOptions::LA_PREFIX_OTHER . '"', $html);
        $this->assertStringContainsString('name="la_table_prefix_other"', $html);
        $this->assertStringNotContainsString('id="la_table_prefix"', $html);
    }

    public function testOptionsKeepsAnEarlierChoice(): void
    {
        $two = [
            ['prefix' => 'old_', 'registry' => 'old', 'listings' => 3],
            ['prefix' => 'trex_', 'registry' => 'trex', 'listings' => 42],
        ];

        $html = $this->renderOptions($two, ['la_table_prefix' => 'trex_']);
        $this->assertMatchesRegularExpression('/value="trex_"\s*checked/', $html);

        $html = $this->renderOptions($two, ['la_table_prefix' => ImportOptions::LA_PREFIX_OTHER, 'la_table_prefix_other' => 'mine_']);
        $this->assertMatchesRegularExpression('/value="' . ImportOptions::LA_PREFIX_OTHER . '"\s*checked/', $html);
        $this->assertStringContainsString('value="mine_"', $html);

        $html = $this->renderOptions([['prefix' => 'trex_', 'registry' => 'trex', 'listings' => 42]], ['la_table_prefix' => 'typed_']);
        $this->assertMatchesRegularExpression('/id="la_table_prefix"[^>]*value="typed_"/s', $html);
    }

    public function testOptionsAsksWhenNothingWasFound(): void
    {
        $html = $this->renderOptions([]);

        $this->assertMatchesRegularExpression('/id="la_table_prefix"[^>]*value=""/s', $html);
        $this->assertStringContainsString("Luster couldn't find Listing Admin's tables automatically.", $html);
        $this->assertStringContainsString('rats.inc.php', $html);
    }

    public function testOptionsShowsTheTableErrorAsALastingCallout(): void
    {
        $html = $this->renderOptions([], [], 'Luster couldn\'t find these Listing Admin tables starting with "terx_": terx (for Codes).');

        $this->assertStringContainsString('class="callout callout-err"', $html);
        $this->assertStringContainsString('starting with &quot;terx_&quot;: terx (for Codes).', $html);
    }

    public function testFallbackHintsAreForListingAdminOnly(): void
    {
        $html = $this->renderOptions([]);
        $this->assertStringContainsString("Also tried when the folder saved in Listing Admin doesn't work.", $html);
        $this->assertStringContainsString("Also tried when the address saved in Listing Admin doesn't work.", $html);

        foreach (['enthusiast', 'codesort', 'fanatically'] as $source) {
            $html = $this->render('options.php', $this->optionsVars($source));
            $this->assertStringNotContainsString('saved in Listing Admin', $html, $source);
        }
    }

    public function testPreviewNamesAutomaticImageMigration(): void
    {
        $html = $this->render('preview.php', [
            'source'      => 'listing-admin',
            'source_name' => 'Listing Admin',
            'preview'     => new ImportPreview([], 'listing_admin_3x', false),
            'options'     => $this->options(['import_codes' => '1']),
            'error'       => '',
            'conn_type'   => 'SQL dump',
            'conn_host'   => 'SQL dump',
            'conn_db'     => '',
        ]);

        $this->assertStringContainsString('Automatic &mdash; Luster finds them from your Listing Admin settings', $html);
    }

    // -----------------------------------------------------------------------
    // Where an image is looked for (Listing Admin)
    // -----------------------------------------------------------------------

    public function testTheTypedServerPathIsTriedWhenListingAdminsFolderFails(): void
    {
        $this->writePng($this->tmp . '/la_images/a.png');
        [$migrator, $requests] = $this->recordingMigrator([]);

        $saved = $this->migrate($migrator, ['image_path' => $this->tmp . '/la_images', 'image_url' => 'https://1.1.1.1/x'], [
            'codes_dir' => $this->tmp . '/gone', 'codes_url' => 'https://93.184.215.14/codes',
        ]);

        $this->assertNotNull($saved);
        $this->assertSame([], $requests->list, 'a folder that works means no download');
    }

    public function testTheTypedAddressIsTriedWhenListingAdminsAddressFails(): void
    {
        [$migrator, $requests] = $this->recordingMigrator([
            ['code' => 404, 'location' => null, 'body' => 'Not Found'],
            ['code' => 200, 'location' => null, 'body' => base64_decode(self::PNG)],
        ]);

        $saved = $this->migrate($migrator, ['image_url' => 'https://1.1.1.1/new/'], [
            'codes_dir' => $this->tmp . '/gone', 'codes_url' => 'http://93.184.215.14/old',
        ]);

        $this->assertNotNull($saved);
        $this->assertSame(
            ['http://93.184.215.14/old/a.png', 'https://1.1.1.1/new/a.png'],
            array_column($requests->list, 'url')
        );
    }

    public function testBlankTypedFieldsAreSkipped(): void
    {
        [$migrator, $requests] = $this->recordingMigrator([]);

        $this->assertNull($this->migrate($migrator, [], ['codes_dir' => $this->tmp . '/gone', 'codes_url' => '']));
        $this->assertSame([], $requests->list);
    }

    public function testListingAdminsOwnFolderWinsWhenItWorks(): void
    {
        $this->writePng($this->tmp . '/la_images/a.png');
        [$migrator, $requests] = $this->recordingMigrator([]);

        $saved = $this->migrate($migrator, ['image_path' => $this->tmp . '/gone', 'image_url' => 'https://1.1.1.1/x'], [
            'codes_dir' => $this->tmp . '/la_images', 'codes_url' => 'https://93.184.215.14/codes',
        ]);

        $this->assertNotNull($saved);
        $this->assertSame([], $requests->list);
    }

    public function testTheSameAddressTypedAgainIsOnlyFetchedOnce(): void
    {
        [$migrator, $requests] = $this->recordingMigrator([['code' => 404, 'location' => null, 'body' => '']]);

        $this->assertNull($this->migrate($migrator, ['image_url' => 'https://93.184.215.14/codes/'], [
            'codes_dir' => '', 'codes_url' => 'https://93.184.215.14/codes',
        ]));
        $this->assertCount(1, $requests->list);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    }

    /**
     * A Listing Admin install's tables, named the way Listing Admin names them, with
     * the columns it creates. $without leaves tables out (by name without prefix);
     * $temp puts every table behind an upload's temporary prefix.
     *
     * @param list<string> $without
     */
    private function laInstall(PDO $pdo, string $prefix, array $without = [], int $listings = 2, string $temp = ''): void
    {
        $registry = $temp . ($prefix !== '' ? str_replace('_', '', $prefix) : 'main');
        $p        = $temp . $prefix;

        $tables = [
            'registry'   => "`{$registry}` (id INTEGER PRIMARY KEY, title TEXT NOT NULL DEFAULT '', subject TEXT NOT NULL DEFAULT '',
                             url TEXT NOT NULL DEFAULT '', image TEXT NOT NULL DEFAULT '', category TEXT NOT NULL DEFAULT '',
                             status INTEGER NOT NULL DEFAULT 0, dbtabl TEXT NOT NULL DEFAULT '', form_join_rules TEXT NOT NULL DEFAULT '',
                             since TEXT NULL, updated TEXT NULL)",
            'members'    => "`{$p}members` (mID INTEGER PRIMARY KEY, mEmail TEXT, fNiq INTEGER, mName TEXT, mURL TEXT, mCountry TEXT,
                             mPassword TEXT, mVisible INTEGER, mPending INTEGER, mAdd TEXT)",
            'options'    => "`{$p}options` (name TEXT PRIMARY KEY, text TEXT NOT NULL DEFAULT '')",
            'categories' => "`{$p}categories` (catid INTEGER PRIMARY KEY, catname TEXT, parent INTEGER NOT NULL DEFAULT 0)",
            'affiliates' => "`{$p}affiliates` (aID INTEGER PRIMARY KEY, fNiq TEXT, aSubject TEXT, aEmail TEXT, aURL TEXT, aImage TEXT, aAdd TEXT)",
            'joined'     => "`{$p}joined` (jID INTEGER PRIMARY KEY, jSubject TEXT, jURL TEXT, jImage TEXT, jCategory TEXT,
                             jMade TEXT, jStatus INTEGER, jAdd TEXT)",
            'wishlist'   => "`{$p}wishlist` (wID INTEGER PRIMARY KEY, wSubject TEXT, wURL TEXT, wImage TEXT, wCategory TEXT,
                             wDesc TEXT, wType TEXT)",
            'codes'      => "`{$p}codes` (cID INTEGER PRIMARY KEY, fNiq INTEGER, cName TEXT, cFile TEXT, cCategory TEXT,
                             cSize INTEGER, cDonor INTEGER, cPending INTEGER, cAdded TEXT)",
            'kim'        => "`{$p}kim` (mID INTEGER PRIMARY KEY, mEmail TEXT, fNiq INTEGER, mName TEXT, mURL TEXT, mPending INTEGER, mAdd TEXT)",
            'updates'    => "`{$p}updates` (uID INTEGER PRIMARY KEY, uTitle TEXT, uCategory TEXT, uEntry TEXT, uAdded TEXT)",
        ];
        foreach ($tables as $name => $ddl) {
            if (!in_array($name, $without, true)) {
                $pdo->exec('CREATE TABLE ' . $ddl);
            }
        }
        for ($id = 1; $id <= $listings; $id++) {
            $pdo->exec("INSERT INTO `{$registry}` (id, title, subject, url) VALUES ({$id}, 'L{$id}', 'L{$id}', 'http://fl{$id}.example.test')");
        }
    }

    /** @return array<string, string> Every Listing Admin step ticked, as the Options step does by default. */
    private function allSteps(): array
    {
        return array_fill_keys([
            'import_categories', 'import_listings', 'import_members', 'import_affiliates', 'import_joined',
            'import_updates', 'import_wishlist', 'import_kim', 'import_rules', 'import_codes',
        ], '1');
    }

    /** @param array<string, mixed> $post */
    private function options(array $post = []): ImportOptions
    {
        return ImportOptions::fromPost($post + ['source' => 'listing-admin', 'conflict_strategy' => 'skip']);
    }

    /** @return array<string, array<string, mixed>> preview rows keyed by type */
    private function previewRows(PDO $src, ImportOptions $options): array
    {
        $rows = [];
        foreach ((new ListingAdminImportSource())->preview($src, $this->pdo(), $options)->rows as $row) {
            $rows[$row['type']] = $row;
        }
        return $rows;
    }

    /** @param array<string, mixed> $vars */
    private function render(string $template, array $vars): string
    {
        $vars += ['base_url' => 'https://luster.test', 'csrf_token' => 'token'];
        extract($vars);
        ob_start();
        try {
            include dirname(__DIR__, 2) . '/templates/admin/import/import-wizard/' . $template;
        } finally {
            $html = (string) ob_get_clean();
        }
        return $html;
    }

    /** @return array<string, mixed> */
    private function optionsVars(string $source, array $installs = [], array $old = [], string $error = ''): array
    {
        return [
            'source' => $source, 'old' => $old, 'zip_ready' => false, 'zip_url' => '/zip',
            'enth_networks' => [], 'la_known_sources' => [], 'la_installs' => $installs, 'scope_error' => $error,
        ];
    }

    private function renderOptions(array $installs, array $old = [], string $error = ''): string
    {
        return $this->render('options.php', $this->optionsVars('listing-admin', $installs, $old, $error));
    }

    /**
     * An ImageMigrator whose HTTP transfer returns $responses in order and records each
     * request in the returned object's $list. The guard and redirect handling still run.
     *
     * @param list<array{code: int, location: ?string, body: string}> $responses
     * @return array{0: ImageMigrator, 1: object}
     */
    private function recordingMigrator(array $responses): array
    {
        $requests = new class { public array $list = []; };
        $migrator = new ImageMigrator($this->tmp . '/public', static function (array $request) use (&$responses, $requests): array {
            $requests->list[] = $request;
            return array_shift($responses) ?? ['code' => 0, 'location' => null, 'body' => ''];
        });
        return [$migrator, $requests];
    }

    /**
     * Runs ListingAdminImportSource::migrateImage() for a code image "a.png".
     *
     * @param array<string, string> $post     Options-step fields (image_path, image_url).
     * @param array<string, string> $settings What readImageSettings() found in Listing Admin.
     */
    private function migrate(ImageMigrator $migrator, array $post, array $settings): ?string
    {
        $imageMap = [];
        $copied   = 0;
        $skipped  = 0;
        $errors   = [];
        $method   = new \ReflectionMethod(ListingAdminImportSource::class, 'migrateImage');

        return $method->invokeArgs(new ListingAdminImportSource($this->tmp . '/public'), [
            $migrator, 'a.png', 'codes', 'cd_', $this->options($post), $settings,
            &$imageMap, &$copied, &$skipped, &$errors,
        ]);
    }

    private function writePng(string $path): void
    {
        file_put_contents($path, base64_decode(self::PNG));
    }

    /** @param list<mixed> $args */
    private function invoke(object $object, string $method, array $args): mixed
    {
        return (new \ReflectionMethod($object, $method))->invokeArgs($object, $args);
    }

    /** @param list<mixed> $args */
    private function invokeStatic(string $class, string $method, array $args): mixed
    {
        return (new \ReflectionMethod($class, $method))->invokeArgs(null, $args);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
