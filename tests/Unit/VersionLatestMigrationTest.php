<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Version;
use PHPUnit\Framework\TestCase;

/**
 * Guards against Version::LATEST_MIGRATION going stale. It's a hardcoded
 * constant with nothing enforcing it stays in sync with the actual highest
 * migration defined in public/migrate/index.php — the Hosting Health panel's
 * "Migration state" badge compares a site's applied migration count against
 * this constant, so a stale value makes an out-of-date site incorrectly
 * report "Up to date".
 */
final class VersionLatestMigrationTest extends TestCase
{
    public function testLatestMigrationConstantMatchesHighestMigrationInRunner(): void
    {
        $path = dirname(__DIR__, 2) . '/public/migrate/index.php';
        $source = file_get_contents($path);
        self::assertNotFalse($source, 'Could not read public/migrate/index.php');

        preg_match_all('/^\/\/ (?:--- )?Migration (\d+)/m', $source, $matches);
        self::assertNotEmpty($matches[1], 'Found no "// Migration N" comments in public/migrate/index.php');

        $highest = max(array_map('intval', $matches[1]));

        self::assertSame(
            $highest,
            Version::LATEST_MIGRATION,
            'Version::LATEST_MIGRATION (' . Version::LATEST_MIGRATION . ') does not match the highest '
            . "migration defined in public/migrate/index.php ({$highest}). Bump the constant when adding a migration."
        );
    }
}
