<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

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
