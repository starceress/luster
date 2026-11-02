<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Import\ImportOptions;
use Luster\Import\ListingAdminImportSource;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real public/compat/listing-admin/luster-bridge.php and checks which
 * listing it picks.
 *
 * A listing chosen by hand ($luster_fanlisting_id, then $luster_listing_id)
 * must always beat the links the Listing Admin import saves — otherwise a page
 * that was set up by hand could start showing a different listing once those
 * links exist.
 *
 * Each case runs in its own PHP process, since the bridge defines constants.
 * LUSTER_PATH points at a temporary folder whose bootstrap.php hands the bridge
 * a SQLite database holding these links for Listing Admin listing 7:
 *   shared 'listing-admin'           → Luster listing 12
 *   'listing-admin:second-site'      → Luster listing 40
 */
final class ListingAdminBridgeFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/luster_la_bridge_test_' . bin2hex(random_bytes(4));
        mkdir($this->dir);

        $db = new PDO('sqlite:' . $this->dir . '/refs.sqlite');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec(
            'CREATE TABLE lstr_listing_external_refs (
                id INTEGER PRIMARY KEY AUTOINCREMENT, listing_id INTEGER NOT NULL,
                source TEXT NOT NULL, external_id TEXT NOT NULL,
                external_slug_or_url TEXT NOT NULL DEFAULT \'\', UNIQUE (source, external_id)
            )'
        );
        $db->exec("INSERT INTO lstr_listing_external_refs (listing_id, source, external_id) VALUES
            (12, 'listing-admin', '7'), (40, 'listing-admin:second-site', '7')");
        $db = null;

        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        $dbFile   = var_export($this->dir . '/refs.sqlite', true);
        file_put_contents($this->dir . '/bootstrap.php', <<<PHP
            <?php
            require_once {$autoload};
            \$pdo = new PDO('sqlite:' . {$dbFile});
            \$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            // Stands in for Luster\Core\Application: the bridge only calls getDatabase()->pdo().
            return new class (\$pdo) {
                public function __construct(private PDO \$pdo) {}
                public function getDatabase(): object
                {
                    return new class (\$this->pdo) {
                        public function __construct(private PDO \$pdo) {}
                        public function pdo(): PDO { return \$this->pdo; }
                    };
                }
            };
            PHP);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testAutoDetectsFromSharedLink(): void
    {
        self::assertSame(12, $this->bridgePicks());
    }

    public function testManualListingIdBeatsSharedLink(): void
    {
        self::assertSame(30, $this->bridgePicks(manualId: '30'));
    }

    public function testManualListingIdBeatsKeyedLink(): void
    {
        self::assertSame(30, $this->bridgePicks(manualId: '30', laSource: 'second-site'));
    }

    public function testSourceIdUsesThatInstallsLink(): void
    {
        self::assertSame(40, $this->bridgePicks(laSource: 'second-site'));
    }

    public function testSourceIdWithoutItsOwnLinkNeverFallsBackToTheSharedOne(): void
    {
        self::assertSame(0, $this->bridgePicks(laSource: 'third-site'));
    }

    public function testManualListingIdStillWorksWhenSourceIdHasNoLink(): void
    {
        self::assertSame(30, $this->bridgePicks(manualId: '30', laSource: 'third-site'));
    }

    public function testFanlistingIdSetByThePageBeatsEverything(): void
    {
        self::assertSame(55, $this->bridgePicks(manualId: '30', fanlistingId: '55'));
    }

    public function testZeroOrNegativeManualIdIsIgnored(): void
    {
        self::assertSame(12, $this->bridgePicks(manualId: '0'));
        self::assertSame(12, $this->bridgePicks(manualId: '-3'));
    }

    public function testNoLinkAndNoManualIdLeavesZero(): void
    {
        self::assertSame(0, $this->bridgePicks(listid: 8));
    }

    public function testSourceIdFromBeforeProtocolRelativeAddressesWereFixedStillWorksAfterARerun(): void
    {
        // 1.0.0-3's key for an LA install at //Example.COM/fan/admin with no table prefix
        // (git show e3da9ea:src/Import/ListingAdminSourceKey.php). Today's key for that
        // address is different, so this is what a bridge page set up back then still uses.
        $oldKey = '34d05aee870f';

        $tgt = new PDO('sqlite:' . $this->dir . '/refs.sqlite');
        $tgt->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $tgt->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
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

        // LA listing 8, as that earlier import left it.
        $tgt->exec("INSERT INTO owned (dbtable, title, subject, url) VALUES ('a', 'A', 'A', 'http://a.example.com')");
        $idA = (int) $tgt->lastInsertId();
        $tgt->prepare("INSERT INTO lstr_listing_external_refs (listing_id, source, external_id) VALUES (?, 'listing-admin', '8'), (?, ?, '8')")
            ->execute([$idA, $idA, 'listing-admin:' . $oldKey]);

        $src = new PDO('sqlite::memory:');
        $src->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $src->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $src->exec("CREATE TABLE main (
            id INTEGER PRIMARY KEY, title TEXT NOT NULL DEFAULT '', subject TEXT NOT NULL DEFAULT '',
            url TEXT NOT NULL DEFAULT '', category TEXT NOT NULL DEFAULT '', since TEXT NULL, updated TEXT NULL,
            fave_fields TEXT NOT NULL DEFAULT '', trouble INTEGER NOT NULL DEFAULT 0, upcoming INTEGER NOT NULL DEFAULT 0,
            image TEXT NOT NULL DEFAULT ''
        )");
        $src->exec("CREATE TABLE categories (catid INTEGER PRIMARY KEY, catname TEXT NOT NULL DEFAULT '', parent INTEGER NOT NULL DEFAULT 0)");
        $src->exec("CREATE TABLE options (name TEXT PRIMARY KEY, text TEXT NOT NULL DEFAULT '')");
        $src->exec("INSERT INTO options (name, text) VALUES ('adm_http', '//Example.COM/fan/admin')");
        // Listing 9 is new, so its link is made by this run.
        $src->exec("INSERT INTO main (id, title, subject, url) VALUES (8, 'A', 'A', 'http://a.example.com'), (9, 'B', 'B', 'http://b.example.com')");

        (new ListingAdminImportSource())->run($src, $tgt, ImportOptions::fromPost([
            'source'            => 'listing-admin',
            'conflict_strategy' => 'skip',
            'import_listings'   => '1',
        ]));
        $idB = (int) $tgt->query("SELECT listingid FROM owned WHERE url = 'http://b.example.com'")->fetchColumn();
        $tgt = null;

        self::assertSame($idA, $this->bridgePicks(listid: 8, laSource: $oldKey));
        self::assertSame($idB, $this->bridgePicks(listid: 9, laSource: $oldKey));
        self::assertNotSame(0, $idB);
    }

    /**
     * Includes the real bridge the way a wrapper does and returns the
     * $luster_fanlisting_id it settles on. Manual values are PHP literals.
     */
    private function bridgePicks(int $listid = 7, ?string $manualId = null, ?string $laSource = null, ?string $fanlistingId = null): int
    {
        $bridge = var_export(dirname(__DIR__, 2) . '/public/compat/listing-admin/luster-bridge.php', true);

        $script = "<?php\n"
            . 'define(' . var_export('LUSTER_PATH', true) . ', ' . var_export($this->dir, true) . ");\n"
            . ($laSource !== null ? "define('LUSTER_LA_SOURCE', " . var_export($laSource, true) . ");\n" : '')
            . "\$listid = {$listid};\n"
            . ($manualId !== null ? "\$luster_listing_id = {$manualId};\n" : '')
            . ($fanlistingId !== null ? "\$luster_fanlisting_id = {$fanlistingId};\n" : '')
            . "require {$bridge};\n"
            . "echo 'PICKED=', var_export(\$luster_fanlisting_id, true);\n";

        $file = $this->dir . '/page.php';
        file_put_contents($file, $script);

        $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=1 ' . escapeshellarg($file) . ' 2>&1');
        self::assertMatchesRegularExpression('/PICKED=(-?\d+)$/', trim($out), "Bridge run failed:\n{$out}");
        preg_match('/PICKED=(-?\d+)$/', trim($out), $m);

        return (int) $m[1];
    }
}
