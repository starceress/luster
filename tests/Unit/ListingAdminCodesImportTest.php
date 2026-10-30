<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Import\ImportOptions;
use Luster\Import\ImportResult;
use Luster\Import\ListingAdminImportSource;
use Luster\Import\ListingAdminSourceKey;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Listing Admin codes (fan graphics) import — ListingAdminImportSource step 10.
 *
 * SQLite for both databases. Code images are tiny real PNGs written to a temp
 * "Listing Admin images" folder, and the importer is pointed at a temp public/
 * folder, so nothing touches the real uploads/.
 */
final class ListingAdminCodesImportTest extends TestCase
{
    private const ADM_HTTP = 'http://la.example.com/admin/';

    private string $tmp;
    private string $publicDir;
    private string $laImages;

    protected function setUp(): void
    {
        $this->tmp       = sys_get_temp_dir() . '/luster_la_codes_' . bin2hex(random_bytes(4));
        $this->publicDir = $this->tmp . '/public';
        $this->laImages  = $this->tmp . '/la_codes';
        mkdir($this->publicDir, 0777, true);
        mkdir($this->laImages, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
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

    // -----------------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------------

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    }

    /** A Listing Admin database with the Codes add-on turned on. */
    private function laSource(array $options = []): PDO
    {
        $src = $this->pdo();
        $src->exec("CREATE TABLE main (
            id INTEGER PRIMARY KEY, title TEXT NOT NULL DEFAULT '', subject TEXT NOT NULL DEFAULT '',
            url TEXT NOT NULL DEFAULT '', category TEXT NOT NULL DEFAULT '', since TEXT NULL, updated TEXT NULL,
            fave_fields TEXT NOT NULL DEFAULT '', trouble INTEGER NOT NULL DEFAULT 0, upcoming INTEGER NOT NULL DEFAULT 0,
            image TEXT NOT NULL DEFAULT ''
        )");
        $src->exec("CREATE TABLE categories (catid INTEGER PRIMARY KEY, catname TEXT NOT NULL DEFAULT '', parent INTEGER NOT NULL DEFAULT 0)");
        $src->exec("CREATE TABLE codes (
            cID INTEGER PRIMARY KEY, fNiq INTEGER NOT NULL DEFAULT 0, cName TEXT NOT NULL DEFAULT '', cFile TEXT NULL,
            cCategory TEXT NOT NULL DEFAULT '0', cSize INTEGER NOT NULL DEFAULT 1, cDonor INTEGER NOT NULL DEFAULT 0,
            cPending INTEGER NOT NULL DEFAULT 0, cAdded TEXT NOT NULL DEFAULT '2024-01-01 10:00:00'
        )");
        $src->exec("CREATE TABLE codes_sizes (sID INTEGER PRIMARY KEY, sName TEXT NOT NULL, sOrder INTEGER NOT NULL DEFAULT 0)");
        $src->exec("CREATE TABLE codes_donors (dID INTEGER PRIMARY KEY, dName TEXT NOT NULL, dEmail TEXT NOT NULL DEFAULT '', dURL TEXT NOT NULL DEFAULT '')");
        $src->exec("CREATE TABLE codes_categories (catID INTEGER PRIMARY KEY, fNiq TEXT NOT NULL DEFAULT '', catName TEXT NOT NULL, catParent INTEGER NOT NULL DEFAULT 0)");
        $src->exec("CREATE TABLE options (name TEXT PRIMARY KEY, text TEXT NOT NULL DEFAULT '')");
        $src->exec("INSERT INTO codes_sizes (sID, sName, sOrder) VALUES (1, '88x31', 1), (2, '100x35', 2), (6, 'Donated', 0)");

        $options += ['adm_http' => self::ADM_HTTP, 'codes_img_path' => $this->laImages];
        $stmt = $src->prepare('INSERT INTO options (name, text) VALUES (:n, :t)');
        foreach ($options as $name => $text) {
            if ($text !== null) {
                $stmt->execute(['n' => $name, 't' => $text]);
            }
        }
        return $src;
    }

    /** A Luster database: listing registry plus every table the codes step writes to. */
    private function target(): PDO
    {
        $tgt = $this->pdo();
        $tgt->exec("CREATE TABLE owned (
            listingid INTEGER PRIMARY KEY AUTOINCREMENT, dbtable TEXT NOT NULL DEFAULT '', title TEXT NOT NULL DEFAULT '',
            subject TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '',
            catid TEXT NOT NULL DEFAULT '', category_id INTEGER NULL, opened TEXT NULL, status INTEGER NOT NULL DEFAULT 2,
            imagefile TEXT NOT NULL DEFAULT '', additional TEXT NOT NULL DEFAULT '', dbserver TEXT NOT NULL DEFAULT '',
            dbuser TEXT NOT NULL DEFAULT '', dbpassword TEXT NOT NULL DEFAULT '', dbdatabase TEXT NOT NULL DEFAULT ''
        )");
        $tgt->exec("CREATE TABLE lstr_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT '',
            slug TEXT NOT NULL DEFAULT '', parent_id INTEGER NULL, source TEXT NOT NULL DEFAULT 'custom')");
        $tgt->exec("CREATE TABLE lstr_fanlisting_settings (
            fanlisting_id INTEGER PRIMARY KEY, members_per_page INTEGER NOT NULL DEFAULT 25, queue_updates INTEGER NOT NULL DEFAULT 0,
            members_template TEXT NULL, stats_template TEXT NULL, affiliates_section_template TEXT NULL, description TEXT NULL,
            form_url_join TEXT NOT NULL DEFAULT '', form_url_update TEXT NOT NULL DEFAULT '', form_url_reset TEXT NOT NULL DEFAULT '',
            image TEXT NULL, last_updated DATE NULL
        )");
        $tgt->exec("CREATE TABLE lstr_listing_external_refs (
            id INTEGER PRIMARY KEY AUTOINCREMENT, listing_id INTEGER NOT NULL, source TEXT NOT NULL, external_id TEXT NOT NULL,
            external_slug_or_url TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (source, external_id)
        )");
        $tgt->exec("CREATE TABLE lstr_code_sizes (id INTEGER PRIMARY KEY AUTOINCREMENT, fanlisting_id INTEGER NULL,
            label TEXT NOT NULL, width INTEGER NULL, height INTEGER NULL, sort_order INTEGER NOT NULL DEFAULT 0)");
        $tgt->exec("CREATE TABLE lstr_code_donors (id INTEGER PRIMARY KEY AUTOINCREMENT, fanlisting_id INTEGER NULL,
            name TEXT NOT NULL, email TEXT NULL, url TEXT NULL)");
        $tgt->exec("CREATE TABLE lstr_codes (
            id INTEGER PRIMARY KEY AUTOINCREMENT, fanlisting_id INTEGER NULL, filename TEXT NOT NULL, name TEXT NULL,
            width INTEGER NOT NULL DEFAULT 0, height INTEGER NOT NULL DEFAULT 0, size_id INTEGER NULL, donor_id INTEGER NULL,
            status TEXT NOT NULL DEFAULT 'approved', submitter_name TEXT NULL, submitter_email TEXT NULL, submitter_url TEXT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            import_ref TEXT NULL, import_source_file TEXT NULL
        )");
        $tgt->exec('CREATE UNIQUE INDEX uq_lstr_codes_import_ref ON lstr_codes (import_ref)');
        $tgt->exec("CREATE TABLE lstr_code_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, fanlisting_id INTEGER NULL,
            name TEXT NOT NULL, parent_id INTEGER NULL, sort_order INTEGER NOT NULL DEFAULT 0)");
        $tgt->exec('CREATE TABLE lstr_code_category_map (code_id INTEGER NOT NULL, category_id INTEGER NOT NULL, PRIMARY KEY (code_id, category_id))');
        return $tgt;
    }

    private function laListing(PDO $src, int $id, string $url, string $subject = 'Listing'): void
    {
        $src->prepare('INSERT INTO main (id, title, subject, url) VALUES (?, ?, ?, ?)')->execute([$id, $subject, $subject, $url]);
    }

    private function lusterListing(PDO $tgt, string $url, string $subject = 'Listing'): int
    {
        $tgt->prepare("INSERT INTO owned (dbtable, title, subject, url) VALUES ('t', ?, ?, ?)")->execute([$subject, $subject, $url]);
        return (int) $tgt->lastInsertId();
    }

    /** @param array<string,mixed> $values codes columns; cID required. */
    private function laCode(PDO $src, array $values): void
    {
        $columns = array_keys($values);
        $src->prepare('INSERT INTO codes (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')')
            ->execute($values);
    }

    /** Writes a real PNG of the given size into $dir (the LA images folder by default). */
    private function png(string $name, int $w = 88, int $h = 31, ?string $dir = null): void
    {
        $chunk = static fn(string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        $rows  = str_repeat("\0" . str_repeat("\xff\x88\x00", $w), $h);
        file_put_contents(
            ($dir ?? $this->laImages) . '/' . $name,
            "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0)) . $chunk('IDAT', gzcompress($rows)) . $chunk('IEND', '')
        );
    }

    private function adapter(): ListingAdminImportSource
    {
        return new ListingAdminImportSource($this->publicDir);
    }

    /** @param array<string,mixed> $post */
    private function options(array $post = []): ImportOptions
    {
        return ImportOptions::fromPost($post + [
            'source'            => 'listing-admin',
            'conflict_strategy' => 'skip',
            'import_codes'      => '1',
        ]);
    }

    /** @return array<string, array<string,mixed>> lstr_codes rows keyed by import_ref (or "id:N"). */
    private function codes(PDO $tgt): array
    {
        $rows = [];
        foreach ($tgt->query('SELECT * FROM lstr_codes ORDER BY id') as $r) {
            $rows[$r['import_ref'] ?? 'id:' . $r['id']] = $r;
        }
        return $rows;
    }

    private function ref(int $cID, string $admHttp = self::ADM_HTTP): string
    {
        $key = (string) ListingAdminSourceKey::resolve('', $admHttp, '', '')['key'];
        return ListingAdminSourceKey::refSource($key) . ':' . $cID;
    }

    /** @return array<string,mixed> */
    private function codesRow(ImportResult $result): array
    {
        foreach ($result->rows as $row) {
            if ($row['type'] === 'Codes') {
                return $row;
            }
        }
        $this->fail('No Codes row in the import result');
    }

    /** @return string[] */
    private function uploadedCodeFiles(): array
    {
        $dir = $this->publicDir . '/uploads/codes';
        if (!is_dir($dir)) {
            return [];
        }
        return array_values(array_filter(scandir($dir) ?: [], static fn(string $f): bool => str_ends_with($f, '.png')));
    }

    private function scalar(PDO $pdo, string $sql, array $params = []): mixed
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    // -----------------------------------------------------------------------
    // First import
    // -----------------------------------------------------------------------

    public function testCodesWithBlankOrSameNamesAllImportSeparately(): void
    {
        $src = $this->laSource();
        $tgt = $this->target();
        $this->laListing($src, 1, 'http://fl.example.com');
        $lid = $this->lusterListing($tgt, 'http://fl.example.com');

        foreach ([1 => '', 2 => '', 3 => '', 4 => 'Solstice', 5 => 'Solstice'] as $cID => $name) {
            $this->png("c{$cID}.png");
            $this->laCode($src, ['cID' => $cID, 'fNiq' => 1, 'cName' => $name, 'cFile' => "c{$cID}.png", 'cSize' => 1]);
        }

        $row   = $this->codesRow($this->adapter()->run($src, $tgt, $this->options()));
        $codes = $this->codes($tgt);

        $this->assertSame(5, $row['imported']);
        $this->assertCount(5, $codes);
        $this->assertCount(5, $this->uploadedCodeFiles());
        $this->assertNull($codes[$this->ref(1)]['name'], 'A blank name is stored as NULL');
        $this->assertSame('Solstice', $codes[$this->ref(4)]['name']);
        foreach ($codes as $code) {
            $this->assertSame($lid, (int) $code['fanlisting_id']);
            $this->assertSame('approved', $code['status']);
            $this->assertStringStartsWith('cd_', $code['filename']);
            $this->assertSame([88, 31], [(int) $code['width'], (int) $code['height']]);
        }
    }

    public function testSizesDonorsAndStatusAreMapped(): void
    {
        $src = $this->laSource();
        $tgt = $this->target();
        $this->laListing($src, 1, 'http://fl.example.com');
        $lid = $this->lusterListing($tgt, 'http://fl.example.com');
        $src->exec("INSERT INTO codes_donors (dID, dName, dEmail, dURL) VALUES (3, ' Jess ', 'JESS@Example.COM', 'https://jess.example.com/'), (5, 'Danielle', '', 'http://hoshi.example.com/')");

        $this->png('real.png', 100, 35);
        $this->png('donated.png', 50, 50);
        $this->laCode($src, ['cID' => 1, 'fNiq' => 1, 'cFile' => 'real.png', 'cSize' => 1, 'cDonor' => 3, 'cPending' => 1]);
        $this->laCode($src, ['cID' => 2, 'fNiq' => 1, 'cFile' => 'donated.png', 'cSize' => 6, 'cDonor' => 5]);
        $this->laCode($src, ['cID' => 3, 'fNiq' => 1, 'cFile' => 'missing.png', 'cSize' => 2, 'cDonor' => 0]);

        $this->adapter()->run($src, $tgt, $this->options());
        $codes = $this->codes($tgt);

        // Real image dimensions win over the size label ("88x31" here, the image is 100x35).
        $this->assertSame([100, 35], [(int) $codes[$this->ref(1)]['width'], (int) $codes[$this->ref(1)]['height']]);
        $this->assertSame('pending', $codes[$this->ref(1)]['status']);
        // No image: dimensions come from the "100x35" label instead.
        $this->assertSame([100, 35], [(int) $codes[$this->ref(3)]['width'], (int) $codes[$this->ref(3)]['height']]);
        $this->assertNull($codes[$this->ref(3)]['donor_id'], 'cDonor 0 means no donor');

        $size = $tgt->query('SELECT * FROM lstr_code_sizes WHERE id = ' . (int) $codes[$this->ref(2)]['size_id'])->fetch();
        $this->assertSame(['Donated', $lid, null, null, 0], [$size['label'], (int) $size['fanlisting_id'], $size['width'], $size['height'], (int) $size['sort_order']]);
        $this->assertSame('2', (string) $this->scalar($tgt, "SELECT sort_order FROM lstr_code_sizes WHERE label = '100x35'"), 'sOrder is copied');

        $jess = $tgt->query('SELECT * FROM lstr_code_donors WHERE id = ' . (int) $codes[$this->ref(1)]['donor_id'])->fetch();
        $this->assertSame(['Jess', 'jess@example.com', 'https://jess.example.com/'], [$jess['name'], $jess['email'], $jess['url']]);
        $danielle = $tgt->query('SELECT * FROM lstr_code_donors WHERE id = ' . (int) $codes[$this->ref(2)]['donor_id'])->fetch();
        $this->assertNull($danielle['email'], 'A blank donor email is stored as NULL');
    }

    public function testCodeImagesComeFromTheCodesFolderNotTheListingImagesFolder(): void
    {
        $listingImages = $this->tmp . '/la_listing_images';
        mkdir($listingImages);
        $src = $this->laSource(['img_path' => $listingImages]);
        $tgt = $this->target();
        $this->laListing($src, 1, 'http://fl.example.com');
        $this->lusterListing($tgt, 'http://fl.example.com');

        $this->png('in-codes-folder.png');
        $this->png('only-in-listing-folder.png', 88, 31, $listingImages);
        $this->laCode($src, ['cID' => 1, 'fNiq' => 1, 'cFile' => 'in-codes-folder.png']);
        $this->laCode($src, ['cID' => 2, 'fNiq' => 1, 'cFile' => 'only-in-listing-folder.png']);

        $this->adapter()->run($src, $tgt, $this->options());
        $codes = $this->codes($tgt);

        $this->assertSame('in-codes-folder.png', $codes[$this->ref(1)]['import_source_file']);
        $this->assertNull($codes[$this->ref(2)]['import_source_file'], 'Must not be found in the listing images folder');
        $this->assertSame('only-in-listing-folder.png', $codes[$this->ref(2)]['filename'], 'Placeholder until the image arrives');
    }

    public function testCodesFolderFallsBackToTheAdminFolder(): void
    {
        $adminDir = $this->tmp . '/la_admin';
        mkdir($adminDir);
        $src = $this->laSource(['codes_img_path' => null, 'adm_path' => $adminDir . '/']);
        $tgt = $this->target();
        $this->laListing($src, 1, 'http://fl.example.com');
        $this->lusterListing($tgt, 'http://fl.example.com');
        $this->png('a.png', 88, 31, $adminDir);
        $this->laCode($src, ['cID' => 1, 'fNiq' => 1, 'cFile' => 'a.png']);

        $this->adapter()->run($src, $tgt, $this->options());

        $this->assertSame('a.png', $this->codes($tgt)[$this->ref(1)]['import_source_file']);
    }

    public function testCodesOnlyImportUsesListingsAlreadyInLuster(): void
    {
        $src = $this->laSource();
        $tgt = $this->target();
        $this->laListing($src, 7, 'http://matched.example.com');
        $this->laListing($src, 8, 'http://not-in-luster.example.com', 'Elsewhere');
        $lid = $this->lusterListing($tgt, 'http://matched.example.com');

        $this->png('a.png');
        $this->laCode($src, ['cID' => 1, 'fNiq' => 7, 'cFile' => 'a.png']);
        $this->laCode($src, ['cID' => 2, 'fNiq' => 8, 'cFile' => 'a.png']);
        $this->laCode($src, ['cID' => 3, 'fNiq' => 99, 'cFile' => 'a.png']);

        $result = $this->adapter()->run($src, $tgt, $this->options());   // Owned listings not ticked
        $row    = $this->codesRow($result);
        $codes  = $this->codes($tgt);

        $this->assertSame(1, (int) $this->scalar($tgt, 'SELECT COUNT(*) FROM owned'), 'No listings are created');
        $this->assertSame([$this->ref(1)], array_keys($codes));
        $this->assertSame($lid, (int) $codes[$this->ref(1)]['fanlisting_id']);
        $this->assertSame(0, (int) $this->scalar($tgt, 'SELECT COUNT(*) FROM lstr_codes WHERE fanlisting_id IS NULL'),
            'An unmatched listing must never become a collective code');
        $this->assertSame(
            ['No Luster listing for Listing Admin listing #8', 'No Luster listing for Listing Admin listing #99'],
            array_column($row['skipped_items'], 'reason'),
        );
        // The matched listing gets its bridge links too.
        $this->assertSame($lid, \Luster\Support\ListingAdminBridgeResolver::resolve($tgt, 7, ''));
    }

    public function testCollectiveCodesGetSubmitterCreditAndNoSize(): void
    {
        $src = $this->laSource();
        $tgt = $this->target();
        $src->exec("INSERT INTO codes_donors (dID, dName, dEmail, dURL) VALUES (4, 'Medli', 'medli@example.com', 'https://medli.example.com/')");
        $src->exec("INSERT INTO codes_categories (catID, fNiq, catName, catParent) VALUES (3, '!0!', 'Donated', 0)");
        $this->png('col.png');
        $this->laCode($src, ['cID' => 1, 'fNiq' => 0, 'cFile' => 'col.png', 'cSize' => 1, 'cDonor' => 4, 'cCategory' => '3']);

        $this->adapter()->run($src, $tgt, $this->options());
        $code = $this->codes($tgt)[$this->ref(1)];

        $this->assertNull($code['fanlisting_id']);
        $this->assertNull($code['size_id']);
        $this->assertNull($code['donor_id']);
        $this->assertSame(['Medli', 'medli@example.com', 'https://medli.example.com/'],
            [$code['submitter_name'], $code['submitter_email'], $code['submitter_url']]);
        $this->assertSame(0, (int) $this->scalar($tgt, 'SELECT COUNT(*) FROM lstr_code_donors'));
        $this->assertSame('Donated', $this->scalar($tgt,
            'SELECT c.name FROM lstr_code_category_map m JOIN lstr_code_categories c ON c.id = m.category_id
              WHERE m.code_id = ? AND c.fanlisting_id IS NULL', [(int) $code['id']]));
    }

    public function testFirstImportPreviewCountsCodesForListingsThisRunCreates(): void
    {
        $src = $this->laSource();
        $tgt = $this->target();
        $this->laListing($src, 1, 'http://new.example.com', 'New one');
        $this->laCode($src, ['cID' => 1, 'fNiq' => 1, 'cFile' => 'a.png']);
        $this->laCode($src, ['cID' => 2, 'fNiq' => 1, 'cFile' => 'b.png']);

        $previewRow = function (array $post) use ($src, $tgt): array {
            foreach ($this->adapter()->preview($src, $tgt, $this->options($post))->rows as $row) {
                if ($row['type'] === 'Codes') {
                    return $row;
                }
            }
            $this->fail('No Codes preview row');
        };

        $withListings = $previewRow(['import_listings' => '1']);
        $this->assertSame([2, 2, 0], [$withListings['found'], $withListings['import'], $withListings['skip']]);

        $codesOnly = $previewRow([]);
        $this->assertSame([2, 0, 2], [$codesOnly['found'], $codesOnly['import'], $codesOnly['skip']]);
        $this->assertStringContainsString('New one', implode(' ', $codesOnly['warnings']));
    }

    public function testNoCodesTableMeansNothingToImport(): void
    {
        $src = $this->laSource();
        $src->exec('DROP TABLE codes');
        $tgt = $this->target();

        $row = $this->codesRow($this->adapter()->run($src, $tgt, $this->options()));

        $this->assertSame([0, 0, []], [$row['imported'], $row['skipped'], $row['errors']]);
        $this->assertStringContainsString('No codes table found', implode(' ', $row['warnings']));
    }

    // -----------------------------------------------------------------------
    // Source identity
    // -----------------------------------------------------------------------

    public function testCodesNeedASourceIdWhenTheInstallHasNoWebAddress(): void
    {
        $src = $this->laSource(['adm_http' => null]);
        $tgt = $this->target();
        $this->laListing($src, 1, 'http://fl.example.com');
        $this->lusterListing($tgt, 'http://fl.example.com');
        $this->png('a.png');
        $this->laCode($src, ['cID' => 1, 'fNiq' => 1, 'cFile' => 'a.png']);

        $blocked = $this->codesRow($this->adapter()->run($src, $tgt, $this->options()));
        $this->assertSame(0, $blocked['imported']);
        $this->assertStringContainsString('Enter a Source ID', implode(' ', $blocked['errors']));
        $this->assertSame([], $this->codes($tgt));

        $this->adapter()->run($src, $tgt, $this->options(['la_source_id' => 'old-collective']));
        $this->assertSame(['listing-admin:old-collective:1'], array_keys($this->codes($tgt)));
    }

    public function testTwoInstallsWithTheSameCodeIdStaySeparate(): void
    {
        $tgt = $this->target();
        $this->png('a.png');
        foreach (['http://first.example.com/admin', 'http://second.example.com/admin'] as $i => $adm) {
            $src = $this->laSource(['adm_http' => $adm]);
            $this->laListing($src, 1, "http://fl{$i}.example.com");
            $this->lusterListing($tgt, "http://fl{$i}.example.com");
            $this->laCode($src, ['cID' => 56, 'fNiq' => 1, 'cFile' => 'a.png']);
            $this->adapter()->run($src, $tgt, $this->options());
        }

        $codes = $this->codes($tgt);
        $this->assertCount(2, $codes);
        $this->assertArrayHasKey($this->ref(56, 'http://first.example.com/admin'), $codes);
        $this->assertArrayHasKey($this->ref(56, 'http://second.example.com/admin'), $codes);

        $this->expectException(\PDOException::class);
        $tgt->prepare("INSERT INTO lstr_codes (filename, import_ref) VALUES ('x.png', ?)")
            ->execute([$this->ref(56, 'http://first.example.com/admin')]);
    }

    // -----------------------------------------------------------------------
    // Re-runs and images
    // -----------------------------------------------------------------------

    public function testRerunsNeverDuplicateCodesInEitherMode(): void
    {
        $src = $this->laSource();
        $tgt = $this->target();
        $this->laListing($src, 1, 'http://fl.example.com');
        $this->lusterListing($tgt, 'http://fl.example.com');
        $this->png('a.png');
        $this->png('b.png');
        $this->laCode($src, ['cID' => 1, 'fNiq' => 1, 'cFile' => 'a.png']);
        $this->laCode($src, ['cID' => 2, 'fNiq' => 1, 'cFile' => 'b.png']);

        $this->adapter()->run($src, $tgt, $this->options());
        $ids = array_column($this->codes($tgt), 'id');

        $keep = $this->codesRow($this->adapter()->run($src, $tgt, $this->options()));
        $this->assertSame([0, 2], [$keep['imported'], $keep['skipped']]);
        $this->assertSame(['Already imported', 'Already imported'], array_column($keep['skipped_items'], 'reason'));

        $this->adapter()->run($src, $tgt, $this->options(['conflict_strategy' => 'overwrite']));

        $this->assertSame($ids, array_column($this->codes($tgt), 'id'));
        $this->assertCount(2, $this->uploadedCodeFiles(), 'Unchanged images are not copied again');
    }

    public function testKeepExistingRunFillsInImagesThatFailedWithoutChangingAnythingElse(): void
    {
        $src = $this->laSource();
        $tgt = $this->target();
        $this->laListing($src, 1, 'http://fl.example.com');
        $this->lusterListing($tgt, 'http://fl.example.com');
        $this->laCode($src, ['cID' => 1, 'fNiq' => 1, 'cName' => 'First', 'cFile' => 'one.png']);
        $this->laCode($src, ['cID' => 2, 'fNiq' => 1, 'cName' => 'Second', 'cFile' => 'two.png']);

        // First run: the images aren't in the LA folder, so both codes come in without them.
        $first = $this->adapter()->run($src, $tgt, $this->options());
        $this->assertSame(2, $this->codesRow($first)['imported']);
        $this->assertSame([], $this->uploadedCodeFiles());
        $before = $this->codes($tgt);
        $this->assertNull($before[$this->ref(1)]['import_source_file']);

        // Changed in LA since, but Keep existing must leave metadata alone.
        $src->exec("UPDATE codes SET cName = 'Renamed', cPending = 1");

        $zipPath = $this->tmp . '/codes.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        foreach (['one.png' => [88, 31], 'two.png' => [100, 35]] as $name => [$w, $h]) {
            $this->png($name, $w, $h, $this->tmp);
            $zip->addFile($this->tmp . '/' . $name, 'images/codes/' . $name);
        }
        $zip->close();

        $second = $this->adapter()->run($src, $tgt, $this->options(['_zip_path' => $zipPath]));
        $after  = $this->codes($tgt);

        $this->assertSame(0, $this->codesRow($second)['imported']);
        $this->assertSame(['Already imported (missing image added)', 'Already imported (missing image added)'],
            array_column($this->codesRow($second)['skipped_items'], 'reason'));
        $this->assertCount(2, $after);
        $this->assertCount(2, $this->uploadedCodeFiles());
        foreach ([1, 2] as $cID) {
            $ref = $this->ref($cID);
            $this->assertSame($before[$ref]['id'], $after[$ref]['id']);
            $this->assertSame($before[$ref]['name'], $after[$ref]['name']);
            $this->assertSame($before[$ref]['status'], $after[$ref]['status']);
            $this->assertSame($before[$ref]['sort_order'], $after[$ref]['sort_order']);
            $this->assertNotNull($after[$ref]['import_source_file']);
            $this->assertFileExists($this->publicDir . '/uploads/codes/' . $after[$ref]['filename']);
        }
        $this->assertSame([100, 35], [(int) $after[$this->ref(2)]['width'], (int) $after[$this->ref(2)]['height']]);
    }

    public function testUpdateMatchingKeepsTheCodeAndUpdatesItInPlace(): void
    {
        $src = $this->laSource();
        $tgt = $this->target();
        $this->laListing($src, 1, 'http://fl.example.com');
        $this->lusterListing($tgt, 'http://fl.example.com');
        $src->exec("INSERT INTO codes_categories (catID, fNiq, catName, catParent) VALUES (3, '!1!', 'Donated', 0)");
        $this->png('a.png');
        $this->laCode($src, ['cID' => 1, 'fNiq' => 1, 'cName' => 'Old', 'cFile' => 'a.png', 'cCategory' => '3']);

        $this->adapter()->run($src, $tgt, $this->options());
        $before = $this->codes($tgt)[$this->ref(1)];
        $this->assertSame(1, (int) $this->scalar($tgt, 'SELECT COUNT(*) FROM lstr_code_category_map'));

        $src->exec("UPDATE codes SET cName = 'New', cPending = 1, cCategory = '0'");
        $this->adapter()->run($src, $tgt, $this->options(['conflict_strategy' => 'overwrite']));
        $after = $this->codes($tgt)[$this->ref(1)];

        $this->assertSame($before['id'], $after['id']);
        $this->assertSame($before['sort_order'], $after['sort_order']);
        $this->assertSame(['New', 'pending'], [$after['name'], $after['status']]);
        $this->assertSame(0, (int) $this->scalar($tgt, 'SELECT COUNT(*) FROM lstr_code_category_map'), 'Categories LA no longer has are cleared');
        $this->assertSame($before['filename'], $after['filename'], 'Same source file: image kept');
        $this->assertCount(1, $this->uploadedCodeFiles());
    }

    public function testUpdateMatchingReplacesAChangedImageAndRemovesTheOldOne(): void
    {
        $src = $this->laSource();
        $tgt = $this->target();
        $this->laListing($src, 1, 'http://fl.example.com');
        $this->lusterListing($tgt, 'http://fl.example.com');
        $this->png('old.png');
        $this->laCode($src, ['cID' => 1, 'fNiq' => 1, 'cFile' => 'old.png']);
        $this->adapter()->run($src, $tgt, $this->options());
        $oldFile = $this->codes($tgt)[$this->ref(1)]['filename'];

        // A replacement that can't be copied keeps the working image and the old source name.
        $src->exec("UPDATE codes SET cFile = 'not-there.png'");
        $this->adapter()->run($src, $tgt, $this->options(['conflict_strategy' => 'overwrite']));
        $kept = $this->codes($tgt)[$this->ref(1)];
        $this->assertSame([$oldFile, 'old.png'], [$kept['filename'], $kept['import_source_file']]);
        $this->assertFileExists($this->publicDir . '/uploads/codes/' . $oldFile);

        // A replacement that copies swaps the image and removes the old file.
        $this->png('new.png', 100, 35);
        $src->exec("UPDATE codes SET cFile = 'new.png'");
        $this->adapter()->run($src, $tgt, $this->options(['conflict_strategy' => 'overwrite']));
        $replaced = $this->codes($tgt)[$this->ref(1)];

        $this->assertSame($kept['id'], $replaced['id']);
        $this->assertNotSame($oldFile, $replaced['filename']);
        $this->assertSame(['new.png', 100, 35], [$replaced['import_source_file'], (int) $replaced['width'], (int) $replaced['height']]);
        $this->assertFileDoesNotExist($this->publicDir . '/uploads/codes/' . $oldFile);
        $this->assertSame([$replaced['filename']], $this->uploadedCodeFiles());
    }

    // -----------------------------------------------------------------------
    // Atomicity
    // -----------------------------------------------------------------------

    /** Makes category assignment fail for one LA code (by its cID). */
    private function failCategoryAssignmentFor(PDO $tgt, int $cID): void
    {
        $tgt->exec("CREATE TRIGGER fail_category_assignment BEFORE INSERT ON lstr_code_category_map
            WHEN (SELECT import_ref FROM lstr_codes WHERE id = NEW.code_id) LIKE '%:{$cID}'
            BEGIN SELECT RAISE(ABORT, 'forced category failure'); END");
    }

    public function testAFailedCodeLeavesNothingBehindForTheNextOne(): void
    {
        $src = $this->laSource();
        $tgt = $this->target();
        $this->laListing($src, 1, 'http://fl.example.com');
        $this->lusterListing($tgt, 'http://fl.example.com');
        $src->exec("INSERT INTO codes_donors (dID, dName, dEmail, dURL) VALUES (3, 'Jess', '', '')");
        $src->exec("INSERT INTO codes_categories (catID, fNiq, catName, catParent) VALUES (3, '!1!', 'Donated', 0)");
        $this->png('first.png');
        $this->png('second.png');
        // Both share the same new donor, size, and category. The first fails after creating them.
        $this->laCode($src, ['cID' => 101, 'fNiq' => 1, 'cFile' => 'first.png',  'cDonor' => 3, 'cSize' => 1, 'cCategory' => '3', 'cAdded' => '2024-01-01 10:00:00']);
        $this->laCode($src, ['cID' => 102, 'fNiq' => 1, 'cFile' => 'second.png', 'cDonor' => 3, 'cSize' => 1, 'cCategory' => '3', 'cAdded' => '2024-01-02 10:00:00']);
        $this->failCategoryAssignmentFor($tgt, 101);

        $row   = $this->codesRow($this->adapter()->run($src, $tgt, $this->options()));
        $codes = $this->codes($tgt);

        $this->assertSame(1, $row['imported']);
        $this->assertStringContainsString('forced category failure', implode(' ', $row['errors']));
        $this->assertSame([$this->ref(102)], array_keys($codes));
        $this->assertCount(1, $this->uploadedCodeFiles(), "The failed code's copied image is removed");

        $second = $codes[$this->ref(102)];
        $this->assertSame(1, (int) $this->scalar($tgt, 'SELECT COUNT(*) FROM lstr_code_donors'));
        $this->assertSame(1, (int) $this->scalar($tgt, 'SELECT COUNT(*) FROM lstr_code_sizes'));
        $this->assertSame(1, (int) $this->scalar($tgt, 'SELECT COUNT(*) FROM lstr_code_categories'));
        $this->assertSame(1, (int) $this->scalar($tgt, 'SELECT COUNT(*) FROM lstr_code_donors WHERE id = ?', [$second['donor_id']]));
        $this->assertSame(1, (int) $this->scalar($tgt, 'SELECT COUNT(*) FROM lstr_code_sizes WHERE id = ?', [$second['size_id']]));
        $this->assertSame(1, (int) $this->scalar($tgt,
            'SELECT COUNT(*) FROM lstr_code_category_map m JOIN lstr_code_categories c ON c.id = m.category_id WHERE m.code_id = ?',
            [$second['id']]));
    }

    public function testAFailedUpdateKeepsTheCodesOldCategories(): void
    {
        $src = $this->laSource();
        $tgt = $this->target();
        $this->laListing($src, 1, 'http://fl.example.com');
        $this->lusterListing($tgt, 'http://fl.example.com');
        $src->exec("INSERT INTO codes_categories (catID, fNiq, catName, catParent) VALUES (3, '!1!', 'Old category', 0), (4, '!1!', 'New category', 0)");
        $this->png('a.png');
        $this->laCode($src, ['cID' => 101, 'fNiq' => 1, 'cFile' => 'a.png', 'cName' => 'Before', 'cCategory' => '3']);
        $this->adapter()->run($src, $tgt, $this->options());

        $src->exec("UPDATE codes SET cName = 'After', cCategory = '4'");
        $this->failCategoryAssignmentFor($tgt, 101);
        $row = $this->codesRow($this->adapter()->run($src, $tgt, $this->options(['conflict_strategy' => 'overwrite'])));

        $this->assertNotSame([], $row['errors']);
        $code = $this->codes($tgt)[$this->ref(101)];
        $this->assertSame('Before', $code['name'], 'The whole update rolls back');
        $this->assertSame('Old category', $this->scalar($tgt,
            'SELECT c.name FROM lstr_code_category_map m JOIN lstr_code_categories c ON c.id = m.category_id WHERE m.code_id = ?',
            [$code['id']]));
    }

    // -----------------------------------------------------------------------
    // Donors
    // -----------------------------------------------------------------------

    public function testDonorsWithTheSameNameStaySeparateAndAChangedDonorIsRelinked(): void
    {
        $src = $this->laSource();
        $tgt = $this->target();
        $this->laListing($src, 1, 'http://fl.example.com');
        $this->lusterListing($tgt, 'http://fl.example.com');
        $src->exec("INSERT INTO codes_donors (dID, dName, dEmail, dURL) VALUES (1, 'Amber', '', 'http://one.example.com'), (2, 'Amber', '', 'http://two.example.com')");
        $this->png('a.png');
        $this->laCode($src, ['cID' => 1, 'fNiq' => 1, 'cFile' => 'a.png', 'cDonor' => 1]);
        $this->laCode($src, ['cID' => 2, 'fNiq' => 1, 'cFile' => 'a.png', 'cDonor' => 2]);
        $this->adapter()->run($src, $tgt, $this->options());

        $this->assertSame(2, (int) $this->scalar($tgt, "SELECT COUNT(*) FROM lstr_code_donors WHERE name = 'Amber'"));
        $before = $this->codes($tgt);
        $this->assertNotSame($before[$this->ref(1)]['donor_id'], $before[$this->ref(2)]['donor_id']);

        $src->exec("UPDATE codes_donors SET dURL = 'http://moved.example.com' WHERE dID = 1");
        $this->adapter()->run($src, $tgt, $this->options(['conflict_strategy' => 'overwrite']));
        $after = $this->codes($tgt);

        $this->assertNotSame($before[$this->ref(1)]['donor_id'], $after[$this->ref(1)]['donor_id'], 'Code 1 points at a donor with the new URL');
        $this->assertSame('http://moved.example.com', $this->scalar($tgt, 'SELECT url FROM lstr_code_donors WHERE id = ?', [$after[$this->ref(1)]['donor_id']]));
        $this->assertSame('http://one.example.com', $this->scalar($tgt, 'SELECT url FROM lstr_code_donors WHERE id = ?', [$before[$this->ref(1)]['donor_id']]),
            'The old donor row is never edited');
        $this->assertSame($before[$this->ref(2)]['donor_id'], $after[$this->ref(2)]['donor_id']);
    }

    public function testDonorUrlThatOnlyChangesCaseIsTreatedAsADifferentUrl(): void
    {
        $src = $this->laSource();
        $tgt = $this->target();
        // MySQL/MariaDB compare text case-insensitively by default; NOCASE makes SQLite do the same.
        $tgt->exec('DROP TABLE lstr_code_donors');
        $tgt->exec('CREATE TABLE lstr_code_donors (id INTEGER PRIMARY KEY AUTOINCREMENT, fanlisting_id INTEGER NULL,
            name TEXT NOT NULL COLLATE NOCASE, email TEXT NULL COLLATE NOCASE, url TEXT NULL COLLATE NOCASE)');
        $this->laListing($src, 1, 'http://fl.example.com');
        $this->lusterListing($tgt, 'http://fl.example.com');
        $src->exec("INSERT INTO codes_donors (dID, dName, dEmail, dURL) VALUES (1, 'Jess', '', 'https://example.test/Fan')");
        $this->png('a.png');
        $this->laCode($src, ['cID' => 1, 'fNiq' => 1, 'cFile' => 'a.png', 'cDonor' => 1]);
        $this->adapter()->run($src, $tgt, $this->options());

        $src->exec("UPDATE codes_donors SET dURL = 'https://example.test/fan' WHERE dID = 1");
        $this->adapter()->run($src, $tgt, $this->options(['conflict_strategy' => 'overwrite']));

        $donorId = $this->codes($tgt)[$this->ref(1)]['donor_id'];
        $this->assertSame('https://example.test/fan', $this->scalar($tgt, 'SELECT url FROM lstr_code_donors WHERE id = ?', [$donorId]));
        $this->assertSame(
            ['https://example.test/Fan', 'https://example.test/fan'],
            $tgt->query('SELECT url FROM lstr_code_donors ORDER BY id')->fetchAll(PDO::FETCH_COLUMN),
            'The old donor row keeps its own URL',
        );
    }

    // -----------------------------------------------------------------------
    // Preview matches the run
    // -----------------------------------------------------------------------

    public function testRerunPreviewCountsMatchWhatTheRunDoes(): void
    {
        $src = $this->laSource();
        $tgt = $this->target();
        $this->laListing($src, 1, 'http://fl.example.com');
        $this->lusterListing($tgt, 'http://fl.example.com');
        $this->png('here.png');
        $this->laCode($src, ['cID' => 1, 'fNiq' => 1, 'cFile' => 'here.png']);
        $this->laCode($src, ['cID' => 2, 'fNiq' => 1, 'cFile' => 'not-yet.png']);   // imported without its image
        $this->adapter()->run($src, $tgt, $this->options());
        $this->laCode($src, ['cID' => 3, 'fNiq' => 1, 'cFile' => null]);            // new, but no image file

        foreach (['skip' => [0, 3], 'overwrite' => [2, 1]] as $mode => [$expectImport, $expectSkip]) {
            $preview = null;
            foreach ($this->adapter()->preview($src, $tgt, $this->options(['conflict_strategy' => $mode]))->rows as $r) {
                if ($r['type'] === 'Codes') {
                    $preview = $r;
                }
            }
            $this->assertNotNull($preview);
            $this->assertSame([3, $expectImport, $expectSkip], [$preview['found'], $preview['import'], $preview['skip']], "preview ({$mode})");

            $warnings = implode(' ', $preview['warnings']);
            if ($mode === 'skip') {
                $this->assertStringContainsString('2 code(s) were imported before and will be left as they are', $warnings);
                $this->assertStringContainsString('1 of those are missing their image', $warnings);
            } else {
                $this->assertStringContainsString('2 code(s) were imported before and will be updated in place', $warnings);
            }
            $this->assertStringContainsString('1 code(s) have no image file', $warnings);

            $run = $this->codesRow($this->adapter()->run($src, $tgt, $this->options(['conflict_strategy' => $mode])));
            $this->assertSame([$expectImport, $expectSkip], [$run['imported'], $run['skipped']], "run ({$mode}) matches its preview");
        }
    }

    public function testSlowDownloadNoteCountsUpdatesThatWillCopyImages(): void
    {
        $src = $this->laSource();
        $tgt = $this->target();
        $this->laListing($src, 1, 'http://fl.example.com');
        $this->lusterListing($tgt, 'http://fl.example.com');
        $this->png('a.png');
        $insert = $src->prepare("INSERT INTO codes (cID, fNiq, cFile) VALUES (?, 1, 'a.png')");
        for ($cID = 1; $cID <= 200; $cID++) {
            $insert->execute([$cID]);
        }
        $this->adapter()->run($src, $tgt, $this->options());
        $this->assertCount(200, $this->uploadedCodeFiles());

        $slowNote = function (string $mode) use ($src, $tgt): ?string {
            foreach ($this->adapter()->preview($src, $tgt, $this->options(['conflict_strategy' => $mode]))->rows as $r) {
                if ($r['type'] === 'Codes') {
                    foreach ($r['warnings'] as $w) {
                        if (str_contains($w, 'code images to copy')) {
                            return $w;
                        }
                    }
                    return null;
                }
            }
            $this->fail('No Codes preview row');
        };

        $this->assertNull($slowNote('overwrite'), 'Updates that keep their images download nothing');
        $this->assertNull($slowNote('skip'));

        // Every code now has a different file in LA: Update matching will try to copy each one.
        $src->exec("UPDATE codes SET cFile = 'b.png'");
        $this->assertStringContainsString("That's 200 code images to copy", (string) $slowNote('overwrite'));
        $this->assertNull($slowNote('skip'), 'Keep existing leaves changed images alone');

        $run    = $this->adapter()->run($src, $tgt, $this->options(['conflict_strategy' => 'overwrite']));
        $images = array_values(array_filter($run->imageRows, static fn(array $r): bool => $r['type'] === 'Images (codes)'))[0];
        $this->assertSame(200, $images['copied'] + $images['skipped'], 'The run attempts the copies the preview warned about');

        // Same file in LA again, but the local images are gone.
        $src->exec("UPDATE codes SET cFile = 'a.png'");
        foreach ($this->uploadedCodeFiles() as $file) {
            unlink($this->publicDir . '/uploads/codes/' . $file);
        }
        $this->assertStringContainsString("That's 200 code images to copy", (string) $slowNote('overwrite'));
    }

    public function testAFailedImageRepairIsCountedAsFailedNotCopied(): void
    {
        $src = $this->laSource();
        $tgt = $this->target();
        $this->laListing($src, 1, 'http://fl.example.com');
        $this->lusterListing($tgt, 'http://fl.example.com');
        $this->laCode($src, ['cID' => 1, 'fNiq' => 1, 'cFile' => 'late.png']);
        $this->adapter()->run($src, $tgt, $this->options());   // no image yet

        $this->png('late.png');
        $tgt->exec("CREATE TRIGGER fail_repair BEFORE UPDATE ON lstr_codes WHEN NEW.import_source_file IS NOT NULL
            BEGIN SELECT RAISE(ABORT, 'forced repair failure'); END");

        $result = $this->adapter()->run($src, $tgt, $this->options());
        $images = null;
        foreach ($result->imageRows as $r) {
            if ($r['type'] === 'Images (codes)') {
                $images = $r;
            }
        }

        $this->assertNotNull($images);
        $this->assertSame([0, 1], [$images['copied'], $images['skipped']]);
        $this->assertStringContainsString('forced repair failure', implode(' ', $this->codesRow($result)['errors']));
        $this->assertSame([], $this->uploadedCodeFiles(), 'The copied file is removed again');
        $this->assertNull($this->codes($tgt)[$this->ref(1)]['import_source_file'], 'Still waiting for its image');
    }

    // -----------------------------------------------------------------------
    // Categories
    // -----------------------------------------------------------------------

    /** @return string[] "child < parent" (or just "child") for one code's categories */
    private function categoriesOf(PDO $tgt, int $codeId): array
    {
        $stmt = $tgt->prepare(
            'SELECT c.name, p.name AS parent FROM lstr_code_category_map m
               JOIN lstr_code_categories c ON c.id = m.category_id
               LEFT JOIN lstr_code_categories p ON p.id = c.parent_id
              WHERE m.code_id = ? ORDER BY c.name'
        );
        $stmt->execute([$codeId]);
        return array_map(static fn(array $r): string => $r['parent'] !== null ? "{$r['name']} < {$r['parent']}" : $r['name'], $stmt->fetchAll());
    }

    public function testCategoryParentsAndFormats(): void
    {
        $src = $this->laSource();
        $tgt = $this->target();
        $this->laListing($src, 1, 'http://a.example.com');
        $this->laListing($src, 2, 'http://b.example.com');
        $listingA = $this->lusterListing($tgt, 'http://a.example.com');
        $listingB = $this->lusterListing($tgt, 'http://b.example.com');
        $src->exec("INSERT INTO codes_categories (catID, fNiq, catName, catParent) VALUES
            (1, '!1!', 'Donated', 0),
            (2, '!2!', 'From friends', 1),
            (3, '!1!', 'Icons', 0),
            (5, '!1!', 'Lost parent', 99),
            (6, '!1!', 'Loop A', 7), (7, '!1!', 'Loop B', 6),
            (8, '!1!', 'Grandchild', 9), (9, '!1!', 'Child', 10), (10, '!1!', 'Top', 0)");
        $this->png('a.png');
        $codes = [
            11 => [1, '1'],          // listing A: parent itself
            12 => [2, '2'],          // listing B: child whose parent LA filed under listing A
            13 => [1, '0'],          // no category
            14 => [1, ''],           // no category
            15 => [1, '!3!!1!'],     // two categories
            16 => [1, '!3!1!'],      // two categories, other separator style
            17 => [1, '5'],          // missing parent
            18 => [1, '6'],          // loop
            19 => [1, '8'],          // two-level chain
            20 => [1, '9'],          // one level: keeps its parent
            21 => [1, '42'],         // category that no longer exists
        ];
        foreach ($codes as $cID => [$fNiq, $cat]) {
            $this->laCode($src, ['cID' => $cID, 'fNiq' => $fNiq, 'cFile' => 'a.png', 'cCategory' => $cat]);
        }

        $row = $this->codesRow($this->adapter()->run($src, $tgt, $this->options()));
        $id  = fn(int $cID): int => (int) $this->codes($tgt)[$this->ref($cID)]['id'];

        $this->assertSame(['Donated'], $this->categoriesOf($tgt, $id(11)));
        $this->assertSame(['From friends < Donated'], $this->categoriesOf($tgt, $id(12)));
        $this->assertSame([], $this->categoriesOf($tgt, $id(13)));
        $this->assertSame([], $this->categoriesOf($tgt, $id(14)));
        $this->assertSame(['Donated', 'Icons'], $this->categoriesOf($tgt, $id(15)));
        $this->assertSame(['Donated', 'Icons'], $this->categoriesOf($tgt, $id(16)));
        $this->assertSame(['Lost parent'], $this->categoriesOf($tgt, $id(17)));
        $this->assertSame(['Loop A'], $this->categoriesOf($tgt, $id(18)));
        $this->assertSame(['Grandchild'], $this->categoriesOf($tgt, $id(19)));
        $this->assertSame(['Child < Top'], $this->categoriesOf($tgt, $id(20)));
        $this->assertSame([], $this->categoriesOf($tgt, $id(21)));

        // The parent LA filed under listing A is created separately under listing B.
        $this->assertSame(1, (int) $this->scalar($tgt, "SELECT COUNT(*) FROM lstr_code_categories WHERE name = 'Donated' AND fanlisting_id = ?", [$listingA]));
        $this->assertSame(1, (int) $this->scalar($tgt, "SELECT COUNT(*) FROM lstr_code_categories WHERE name = 'Donated' AND fanlisting_id = ?", [$listingB]));

        $warnings = implode(' ', $row['warnings']);
        $this->assertStringContainsString('3 code categories were put at the top level', $warnings);
        $this->assertStringContainsString('1 code category used by codes no longer exist', $warnings);
    }

    // -----------------------------------------------------------------------
    // Order and dates
    // -----------------------------------------------------------------------

    public function testOrderFollowsCodesOrderWithCodeIdBreakingTies(): void
    {
        foreach (['ASC' => [1, 2, 3], 'DESC' => [3, 2, 1]] as $order => $expected) {
            $src = $this->laSource(['codes_order' => $order]);
            $tgt = $this->target();
            $this->laListing($src, 1, 'http://fl.example.com');
            $this->lusterListing($tgt, 'http://fl.example.com');
            $this->png('a.png');
            // Codes 1 and 2 were uploaded together and share a timestamp.
            $this->laCode($src, ['cID' => 2, 'fNiq' => 1, 'cFile' => 'a.png', 'cAdded' => '2024-01-01 10:00:00']);
            $this->laCode($src, ['cID' => 1, 'fNiq' => 1, 'cFile' => 'a.png', 'cAdded' => '2024-01-01 10:00:00']);
            $this->laCode($src, ['cID' => 3, 'fNiq' => 1, 'cFile' => 'a.png', 'cAdded' => '2024-03-01 10:00:00']);

            $this->adapter()->run($src, $tgt, $this->options());

            $byPosition = $tgt->query('SELECT import_ref FROM lstr_codes ORDER BY sort_order')->fetchAll(PDO::FETCH_COLUMN);
            $this->assertSame(array_map(fn(int $c): string => $this->ref($c), $expected), $byPosition, "codes_order {$order}");
        }
    }

    public function testNewCodesGoAfterTheListingsExistingCodes(): void
    {
        $src = $this->laSource();
        $tgt = $this->target();
        $this->laListing($src, 1, 'http://fl.example.com');
        $lid = $this->lusterListing($tgt, 'http://fl.example.com');
        $tgt->prepare("INSERT INTO lstr_codes (fanlisting_id, filename, sort_order) VALUES (?, 'manual.png', 5)")->execute([$lid]);
        $this->png('a.png');
        $this->laCode($src, ['cID' => 1, 'fNiq' => 1, 'cFile' => 'a.png']);
        $this->laCode($src, ['cID' => 2, 'fNiq' => 1, 'cFile' => 'a.png', 'cAdded' => '2024-02-01 10:00:00']);

        $this->adapter()->run($src, $tgt, $this->options());
        $codes = $this->codes($tgt);

        $this->assertSame(['6', '7'], [(string) $codes[$this->ref(1)]['sort_order'], (string) $codes[$this->ref(2)]['sort_order']]);
    }

    public function testDatesKeepTheirTimeAndBadDatesUseTheDefault(): void
    {
        $src = $this->laSource();
        $tgt = $this->target();
        $this->laListing($src, 1, 'http://fl.example.com');
        $this->lusterListing($tgt, 'http://fl.example.com');
        $this->png('a.png');
        $dates = [1 => '2026-10-25 14:23:45', 2 => '', 3 => '0000-00-00 00:00:00', 4 => '1970-01-01 00:00:00', 5 => '2026-02-31 09:00:00'];
        foreach ($dates as $cID => $added) {
            $this->laCode($src, ['cID' => $cID, 'fNiq' => 1, 'cFile' => 'a.png', 'cAdded' => $added]);
        }

        $this->adapter()->run($src, $tgt, $this->options());
        $codes = $this->codes($tgt);

        $this->assertSame('2026-10-25 14:23:45', $codes[$this->ref(1)]['created_at']);
        foreach ([2, 3, 4, 5] as $cID) {
            $created = $codes[$this->ref($cID)]['created_at'];
            $this->assertNotSame($dates[$cID], $created);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $created, 'Database default');
            $this->assertGreaterThanOrEqual(2026, (int) substr($created, 0, 4));
        }

        // On an update, a bad date leaves the stored one alone.
        $tgt->exec("UPDATE lstr_codes SET created_at = '2020-05-05 05:05:05'");
        $this->adapter()->run($src, $tgt, $this->options(['conflict_strategy' => 'overwrite']));
        $updated = $this->codes($tgt);
        $this->assertSame('2026-10-25 14:23:45', $updated[$this->ref(1)]['created_at']);
        foreach ([2, 3, 4, 5] as $cID) {
            $this->assertSame('2020-05-05 05:05:05', $updated[$this->ref($cID)]['created_at']);
        }
    }

    // -----------------------------------------------------------------------
    // Earlier sources list
    // -----------------------------------------------------------------------

    public function testKnownSourcesCountImportedCodes(): void
    {
        $src = $this->laSource();
        $tgt = $this->target();
        $this->laListing($src, 1, 'http://fl.example.com');
        $this->lusterListing($tgt, 'http://fl.example.com');
        $this->png('a.png');
        $this->laCode($src, ['cID' => 1, 'fNiq' => 1, 'cFile' => 'a.png']);
        $this->laCode($src, ['cID' => 2, 'fNiq' => 1, 'cFile' => 'a.png']);
        $this->adapter()->run($src, $tgt, $this->options());

        $key = (string) ListingAdminSourceKey::resolve('', self::ADM_HTTP, '', '')['key'];
        $this->assertSame([['key' => $key, 'listings' => 1, 'codes' => 2]], ListingAdminSourceKey::knownSources($tgt));
    }
}
