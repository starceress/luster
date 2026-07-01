<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Import\ZipTemplateReader;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Tests for ZipTemplateReader.
 *
 * Creates real in-memory (temp-file) ZIPs via ZipArchive so we exercise the full
 * path-classification and security-check pipeline without mocking anything.
 */
final class ZipTemplateReaderTest extends TestCase
{
    /** @var string[] Temp files to delete in tearDown */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->tempFiles = [];
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeZip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lstr_zip_test_');
        $this->tempFiles[] = $path;

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    private function readZip(array $entries): ZipTemplateReader
    {
        $path   = $this->makeZip($entries);
        $reader = new ZipTemplateReader();
        $reader->read($path);
        return $reader;
    }

    // -----------------------------------------------------------------------
    // Basic acceptance
    // -----------------------------------------------------------------------

    public function testReadsDisplayTemplate(): void
    {
        $reader = $this->readZip([
            'templates/display/stats.php' => '<p>{member_count}</p>',
        ]);

        self::assertNull($reader->getParseError());
        $entries = $reader->getEntries();
        self::assertCount(1, $entries);
        self::assertSame('templates/display/stats.php', $entries[0]['sourcePath']);
        self::assertSame('display', $entries[0]['category']);
        self::assertStringContainsString('{member_count}', $entries[0]['content']);
    }

    public function testReadsEmailTemplate(): void
    {
        $reader = $this->readZip([
            'templates/email/join.txt' => 'Hello {name}',
        ]);

        self::assertNull($reader->getParseError());
        $entries = $reader->getEntries();
        self::assertCount(1, $entries);
        self::assertSame('email', $entries[0]['category']);
    }

    public function testReadsWithRootWrapperDirectory(): void
    {
        $reader = $this->readZip([
            'fanatically-1.1.9/templates/display/stats.php' => '<p>stats</p>',
        ]);

        self::assertNull($reader->getParseError());
        $entries = $reader->getEntries();
        self::assertCount(1, $entries);
        self::assertSame('templates/display/stats.php', $entries[0]['sourcePath']);
    }

    public function testReadsPerListingTemplate(): void
    {
        $reader = $this->readZip([
            'listing-slug/templates/stats.php' => '<p>per-listing stats</p>',
        ]);

        self::assertNull($reader->getParseError());
        $entries = $reader->getEntries();
        self::assertCount(1, $entries);
        self::assertSame('per-listing', $entries[0]['category']);
    }

    public function testSkipsUnrecognisedPaths(): void
    {
        $reader = $this->readZip([
            'templates/display/stats.php' => '<p>stats</p>',
            'config/database.php'         => '<?php $pass = "secret";',
        ]);

        self::assertNull($reader->getParseError());
        self::assertCount(1, $reader->getEntries());
    }

    // -----------------------------------------------------------------------
    // defaults.json handling
    // -----------------------------------------------------------------------

    public function testParsesDefaultsJson(): void
    {
        $hash = hash('sha256', '<p>stats</p>');
        $reader = $this->readZip([
            'templates/display/stats.php'                   => '<p>stats</p>',
            'admin/default-templates/defaults.json'         => json_encode([
                'display/stats.php' => $hash,
            ]),
        ]);

        self::assertNotEmpty($reader->getDefaultHashes());
        self::assertSame($hash, $reader->getDefaultHashes()['display/stats.php'] ?? null);
    }

    public function testDefaultsJsonNotReturnedAsEntry(): void
    {
        $reader = $this->readZip([
            'admin/default-templates/defaults.json' => json_encode(['display/stats.php' => str_repeat('a', 64)]),
        ]);

        self::assertCount(0, $reader->getEntries());
    }

    // -----------------------------------------------------------------------
    // Security: root wrapper validation
    // -----------------------------------------------------------------------

    public function testRejectsUnsafeRootWrapper(): void
    {
        // An archive where detectRoot() would detect '..' as the root wrapper.
        // Without validation, this strips '..' before isPathSafe() runs, hiding the traversal.
        $path = tempnam(sys_get_temp_dir(), 'lstr_zip_test_');
        $this->tempFiles[] = $path;

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        // The path '../templates/display/stats.php' would have detectRoot() set root='..'.
        // Stripping that would normalize to 'templates/display/stats.php' — a valid path.
        $zip->addFromString('../templates/display/stats.php', '<p>stats</p>');
        $zip->close();

        $reader = new ZipTemplateReader();
        $ok = $reader->read($path);

        // The archive should be rejected outright (false return with a parse error).
        self::assertFalse($ok);
        self::assertStringContainsString('unsafe', (string) $reader->getParseError());
    }

    public function testAcceptsLegitimateRootWrapper(): void
    {
        // A normal distribution ZIP with a single top-level directory should work fine.
        $reader = $this->readZip([
            'fanatically-1.1.9/templates/display/stats.php' => '<p>stats</p>',
            'fanatically-1.1.9/templates/email/join.txt'    => 'Hello {name}',
        ]);

        self::assertNull($reader->getParseError());
        self::assertCount(2, $reader->getEntries());
    }

    // -----------------------------------------------------------------------
    // Security: path safety
    // -----------------------------------------------------------------------

    public function testRejectsPathTraversal(): void
    {
        $reader = $this->readZip([
            '../etc/passwd' => 'root:x:0:0',
        ]);

        self::assertNull($reader->getParseError());
        self::assertCount(0, $reader->getEntries());
    }

    public function testRejectsAbsolutePath(): void
    {
        $reader = $this->readZip([
            '/etc/passwd' => 'root:x:0:0',
        ]);

        self::assertNull($reader->getParseError());
        self::assertCount(0, $reader->getEntries());
    }

    public function testRejectsDotPrefixComponentInsideNormalizedPath(): void
    {
        // .hidden is NOT a valid root wrapper (no templates/display/ under it),
        // so detectRoot() returns '' and the path is evaluated as-is.
        // isPathSafe() splits on '/' and rejects the '.hidden' component.
        $reader = $this->readZip([
            'templates/.hidden/stats.php' => '<p>stats</p>',
        ]);

        self::assertCount(0, $reader->getEntries());
        self::assertNotEmpty($reader->getRejected());
        self::assertStringContainsString('dot', $reader->getRejected()[0]['reason']);
    }

    // -----------------------------------------------------------------------
    // Security: binary / invalid UTF-8 content
    // -----------------------------------------------------------------------

    public function testRejectsBinaryContent(): void
    {
        $reader = $this->readZip([
            'templates/display/stats.php' => "valid start\x00binary content",
        ]);

        self::assertCount(0, $reader->getEntries());
        self::assertNotEmpty($reader->getRejected());
    }

    // -----------------------------------------------------------------------
    // Duplicate / case-collision detection
    // -----------------------------------------------------------------------

    public function testMultipleDistinctTemplatesAllAccepted(): void
    {
        // Verify that multiple distinct known templates in one ZIP are all accepted.
        $reader = $this->readZip([
            'templates/display/stats.php'            => '<p>stats</p>',
            'templates/display/collective-stats.php' => '<p>cstats</p>',
            'templates/display/owned-listings.php'   => '<p>owned</p>',
            'templates/email/join.txt'               => 'Dear {name},',
        ]);

        self::assertNull($reader->getParseError());
        self::assertCount(4, $reader->getEntries());
        self::assertEmpty($reader->getRejected());
    }

    // -----------------------------------------------------------------------
    // Entry count limit
    // -----------------------------------------------------------------------

    public function testRejectsTooManyEntries(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'lstr_zip_test_');
        $this->tempFiles[] = $path;

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        for ($i = 0; $i <= ZipTemplateReader::MAX_ENTRIES; $i++) {
            $zip->addFromString("file_{$i}.txt", 'x');
        }
        $zip->close();

        $reader = new ZipTemplateReader();
        $ok = $reader->read($path);

        self::assertFalse($ok);
        self::assertStringContainsString('exceeds the limit', (string) $reader->getParseError());
    }

    // -----------------------------------------------------------------------
    // sha256 in entry
    // -----------------------------------------------------------------------

    public function testEntrySha256MatchesContent(): void
    {
        $content = '<p>{member_count}</p>';
        $reader  = $this->readZip(['templates/display/stats.php' => $content]);
        $entries = $reader->getEntries();

        self::assertSame(hash('sha256', $content), $entries[0]['sha256']);
    }
}
