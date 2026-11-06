<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Controllers\Admin\SettingsController;
use Luster\Core\Application;
use Luster\Core\Database;
use Luster\Core\Request;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Saves the real SMTP & Email form. An empty Port box used to save as port 1, which
 * isn't a standard SMTP port, and nothing on the page said anything had gone wrong.
 *
 * Saving this form rewrites config/app.php under the app's base path, so everything
 * here runs in a throwaway folder and never reaches a real config file.
 */
final class SettingsSmtpPortTest extends TestCase
{
    private string $root;
    private Application $app;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_smtp_port_' . uniqid('', true);
        mkdir($this->root . '/config', 0777, true);
        file_put_contents($this->configPath(), '<?php return ' . var_export([
            'app_url' => 'https://luster.test',
        ], true) . ';');

        $this->app = new Application($this->root, $this->configPath());

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE lstr_activity_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                admin_user_id INTEGER NULL,
                action TEXT NOT NULL,
                description TEXT NULL,
                ip_address TEXT NULL
            )'
        );
        (new ReflectionProperty(Database::class, 'pdo'))->setValue($this->app->getDatabase(), $pdo);

        $_SESSION = ['lstr_admin_id' => 1];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        foreach ([$this->configPath(), $this->configPath() . '.tmp'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->root . '/config');
        rmdir($this->root);
    }

    /** @return array<string, array{string, string, int}> */
    public static function portCases(): array
    {
        return [
            'empty box with STARTTLS'        => ['', 'tls', 587],
            'empty box with SMTPS'           => ['', 'ssl', 465],
            'empty box with no encryption'   => ['', 'none', 587],
            'zero'                           => ['0', 'tls', 587],
            'negative with SMTPS'            => ['-5', 'ssl', 465],
            'too high'                       => ['70000', 'tls', 587],
            'a port someone chose is kept'   => ['2525', 'tls', 2525],
            'chosen port beats the SMTPS one' => ['2525', 'ssl', 2525],
            'highest real port is kept'      => ['65535', 'tls', 65535],
        ];
    }

    #[DataProvider('portCases')]
    public function testSavedPort(string $posted, string $encryption, int $expected): void
    {
        $this->saveForm(['smtp_port' => $posted, 'smtp_secure' => $encryption]);

        self::assertSame($expected, $this->savedConfig()['smtp_port']);
    }

    public function testMissingPortFieldUsesTheStandardPort(): void
    {
        $this->saveForm(['smtp_secure' => 'tls']);

        self::assertSame(587, $this->savedConfig()['smtp_port']);
    }

    /** @param array<string, string> $fields */
    private function saveForm(array $fields): void
    {
        $request = new Request([], $fields, [
            'REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'luster.test',
        ], [], []);

        (new SettingsController($this->app))->updateSmtp($request, []);
    }

    /** @return array<string, mixed> */
    private function savedConfig(): array
    {
        return require $this->configPath();
    }

    private function configPath(): string
    {
        return $this->root . '/config/app.php';
    }
}
