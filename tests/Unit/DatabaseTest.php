<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Config;
use Luster\Core\Database;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests Database::validatePrefix() — purely a regex check, so no live
 * database connection is ever established. The Database instance is
 * constructed with a minimal temp Config file; connect() is lazy and
 * is never called by validatePrefix().
 */
final class DatabaseTest extends TestCase
{
    private string   $tmpConfigPath;
    private Database $db;

    protected function setUp(): void
    {
        // Write a minimal config file; connect() will never be called here
        $this->tmpConfigPath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'luster_test_cfg_' . uniqid('', true) . '.php';

        file_put_contents($this->tmpConfigPath, '<?php return [];');

        $config   = new Config($this->tmpConfigPath);
        $this->db = new Database($config);
    }

    protected function tearDown(): void
    {
        if (is_file($this->tmpConfigPath)) {
            unlink($this->tmpConfigPath);
        }
    }

    // -----------------------------------------------------------------------
    // Valid prefixes — must be returned unchanged
    // -----------------------------------------------------------------------

    public function testValidLowercaseLettersPass(): void
    {
        $this->assertSame('members', $this->db->validatePrefix('members'));
    }

    public function testValidPrefixWithUnderscorePass(): void
    {
        $this->assertSame('fl_members', $this->db->validatePrefix('fl_members'));
    }

    public function testValidPrefixWithNumbersPass(): void
    {
        $this->assertSame('table2024', $this->db->validatePrefix('table2024'));
    }

    public function testValidUppercaseLettersPass(): void
    {
        $this->assertSame('FL_Music', $this->db->validatePrefix('FL_Music'));
    }

    public function testValidSingleLetterPass(): void
    {
        $this->assertSame('a', $this->db->validatePrefix('a'));
    }

    public function testValidMixedCaseWithUnderscoreAndNumbersPass(): void
    {
        $this->assertSame('My_Table_99', $this->db->validatePrefix('My_Table_99'));
    }

    public function testValidLeadingUnderscorePass(): void
    {
        $this->assertSame('_private', $this->db->validatePrefix('_private'));
    }

    // -----------------------------------------------------------------------
    // Invalid prefixes — must throw RuntimeException
    // -----------------------------------------------------------------------

    public function testEmptyStringThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->db->validatePrefix('');
    }

    public function testHyphenThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->db->validatePrefix('fl-members');
    }

    public function testDotThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->db->validatePrefix('schema.table');
    }

    public function testSpaceThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->db->validatePrefix('fl members');
    }

    public function testSemicolonThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->db->validatePrefix('fl; DROP TABLE members--');
    }

    public function testSingleQuoteThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->db->validatePrefix("fl'injection");
    }

    public function testDoubleQuoteThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->db->validatePrefix('fl"injection');
    }

    public function testParenthesesThrow(): void
    {
        $this->expectException(RuntimeException::class);
        $this->db->validatePrefix('members()');
    }

    public function testSlashThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->db->validatePrefix('../../etc/passwd');
    }

    public function testHashThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->db->validatePrefix('fl#comment');
    }

    public function testNewlineThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->db->validatePrefix("fl\nmembers");
    }

    public function testNullByteThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->db->validatePrefix("fl\0members");
    }
}
