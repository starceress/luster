<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Import\LegacyConfigReader;
use PHPUnit\Framework\TestCase;

final class LegacyConfigReaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/luster_lcr_test_' . uniqid('', true);
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmpDir . '/*') ?: []);
        @rmdir($this->tmpDir);
    }

    private function write(string $name, string $content): string
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, $content);
        return $path;
    }

    // -----------------------------------------------------------------------
    // Enthusiast-style config ($db_server, $db_database, $db_user, $db_password)
    // -----------------------------------------------------------------------

    public function testParsesEnthusiastConfig(): void
    {
        $path = $this->write('config.php', <<<'PHP'
<?php
$db_server   = "localhost";
$db_user     = "enth_user";
$db_password = "s3cr3t";
$db_database = "enth_db";
PHP);

        $result = LegacyConfigReader::parse($path);
        self::assertSame('localhost',  $result['host']);
        self::assertSame('enth_db',    $result['name']);
        self::assertSame('enth_user',  $result['user']);
        self::assertSame('s3cr3t',     $result['pass']);
        self::assertNull($result['prefix']);
    }

    // -----------------------------------------------------------------------
    // FanUpdate / CodeSort-style config ($db_host, $db_name, $db_pass)
    // -----------------------------------------------------------------------

    public function testParsesFanUpdateConfig(): void
    {
        $path = $this->write('config.php', <<<'PHP'
<?php
$db_host = 'db.example.com';
$db_name = 'fanupdate';
$db_user = 'fuuser';
$db_pass = 'fupass';
PHP);

        $result = LegacyConfigReader::parse($path);
        self::assertSame('db.example.com', $result['host']);
        self::assertSame('fanupdate',      $result['name']);
        self::assertSame('fuuser',         $result['user']);
        self::assertSame('fupass',         $result['pass']);
    }

    // -----------------------------------------------------------------------
    // define() constant style
    // -----------------------------------------------------------------------

    public function testParsesDefineConstants(): void
    {
        $path = $this->write('config.php', <<<'PHP'
<?php
define('DB_HOST',     'myhost');
define('DB_NAME',     'mydb');
define('DB_USER',     'myuser');
define('DB_PASSWORD', 'mypass');
PHP);

        $result = LegacyConfigReader::parse($path);
        self::assertSame('myhost', $result['host']);
        self::assertSame('mydb',   $result['name']);
        self::assertSame('myuser', $result['user']);
        self::assertSame('mypass', $result['pass']);
    }

    // -----------------------------------------------------------------------
    // Table prefix (Fanatically / WordPress-style)
    // -----------------------------------------------------------------------

    public function testParsesTablePrefix(): void
    {
        $path = $this->write('config.php', <<<'PHP'
<?php
$db_host      = 'localhost';
$db_name      = 'fanatic';
$db_user      = 'root';
$db_pass      = '';
$table_prefix = 'fanatic_';
PHP);

        $result = LegacyConfigReader::parse($path);
        self::assertSame('fanatic_', $result['prefix']);
    }

    // -----------------------------------------------------------------------
    // Single vs double quotes
    // -----------------------------------------------------------------------

    public function testSingleAndDoubleQuotesBothWork(): void
    {
        $path = $this->write('config.php', <<<'PHP'
<?php
$db_host = 'single';
$db_name = "double";
PHP);

        $result = LegacyConfigReader::parse($path);
        self::assertSame('single', $result['host']);
        self::assertSame('double', $result['name']);
    }

    // -----------------------------------------------------------------------
    // Partial config — some fields absent
    // -----------------------------------------------------------------------

    public function testMissingFieldsAreNull(): void
    {
        $path = $this->write('config.php', <<<'PHP'
<?php
$db_host = 'localhost';
PHP);

        $result = LegacyConfigReader::parse($path);
        self::assertSame('localhost', $result['host']);
        self::assertNull($result['name']);
        self::assertNull($result['user']);
        self::assertNull($result['pass']);
        self::assertNull($result['prefix']);
    }

    // -----------------------------------------------------------------------
    // Variable name must start at the beginning of the line — no partial matches
    // -----------------------------------------------------------------------

    public function testNoPartialVariableMatch(): void
    {
        // $db_user_extra is NOT in the recognised list.
        // The $db_user pattern must not match it (lookahead blocks the underscore suffix).
        $path = $this->write('config.php', <<<'PHP'
<?php
$db_user_extra = 'wrong';
PHP);

        $result = LegacyConfigReader::parse($path);
        self::assertNull($result['user'],
            '$db_user must not partially match $db_user_extra');
    }

    // -----------------------------------------------------------------------
    // Error cases
    // -----------------------------------------------------------------------

    public function testThrowsOnMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found');
        LegacyConfigReader::parse($this->tmpDir . '/does_not_exist.php');
    }

    public function testThrowsOnOversizedFile(): void
    {
        // Write a file just over 1 MB
        $path = $this->write('big.php', str_repeat('x', 1_048_577));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('too large');
        LegacyConfigReader::parse($path);
    }
}
