<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Application;
use Luster\Core\Database;
use Luster\Core\UpdateChecker;
use Luster\Repositories\SiteSettingsRepo;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Runs the real scheduled checks with a mail server that can't be reached. A failed
 * update email used to try again on every admin page, and each try made the page wait
 * for the mail server, so the whole admin panel looked frozen.
 */
final class UpdateNotificationRetryTest extends TestCase
{
    private Application $app;
    private PDO $pdo;

    protected function setUp(): void
    {
        // A blank SMTP host fails straight away, with no network involved.
        $cfgPath = tempnam(sys_get_temp_dir(), 'lstr_update_retry_');
        file_put_contents($cfgPath, '<?php return ' . var_export([
            'smtp_enabled' => '1',
            'smtp_host'    => '',
            'owner_email'  => 'owner@example.com',
            'site_name'    => 'Test Site',
        ], true) . ';');
        $this->app = new Application(dirname(__DIR__, 2), $cfgPath);
        unlink($cfgPath);

        // SiteSettingsRepo's save is written for MySQL, so swap in SQLite's spelling of it.
        $this->pdo = new class ('sqlite::memory:') extends PDO {
            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                return parent::prepare(str_replace(
                    'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
                    'ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value',
                    $query,
                ), $options);
            }
        };
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec(
            'CREATE TABLE lstr_site_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NULL)'
        );
        $this->pdo->exec(
            "CREATE TABLE lstr_error_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                level TEXT NOT NULL DEFAULT 'error',
                message TEXT NOT NULL,
                source TEXT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );

        (new ReflectionProperty(Database::class, 'pdo'))->setValue($this->app->getDatabase(), $this->pdo);

        // Only the update email is under test, and a fresh check means no call to GitHub.
        $this->setSetting('overdue_notify_enabled', '0');
        $this->setSetting('pending_digest_enabled', '0');
        $this->setSetting('update_latest_version', '99.0.0');
        $this->setSetting('update_checked_at', (string) time());
    }

    public function testFailedEmailIsOnlyTriedOnceAcrossPageLoads(): void
    {
        $this->app->runScheduledNotifications();
        $this->app->runScheduledNotifications();
        $this->app->runScheduledNotifications();

        self::assertSame(1, $this->failedTries());
        self::assertNotNull($this->setting('update_notify_last_attempt'));
        self::assertNull($this->setting('update_notified_version'));
    }

    public function testFailedEmailIsTriedAgainAfterADay(): void
    {
        $this->app->runScheduledNotifications();
        $this->setSetting('update_notify_last_attempt', (string) (time() - 86401));

        $this->app->runScheduledNotifications();

        self::assertSame(2, $this->failedTries());
        self::assertNull($this->setting('update_notified_version'));
    }

    public function testRecentFailedTryFromBeforeThisPageLoadIsRespected(): void
    {
        $this->setSetting('update_notify_last_attempt', (string) (time() - 3600));

        $this->app->runScheduledNotifications();

        self::assertSame(0, $this->failedTries());
    }

    /**
     * The email goes out, then saving which version it was about fails. The saved try
     * is all that's left to stop the next page load from sending it again.
     */
    public function testFailedVersionSaveKeepsTheWaitSoTheEmailIsNotSentAgain(): void
    {
        $this->pdo->exec(
            "CREATE TRIGGER reject_notified_version BEFORE INSERT ON lstr_site_settings
             WHEN NEW.setting_key = 'update_notified_version'
             BEGIN SELECT RAISE(ABORT, 'write denied'); END"
        );
        $checker = new UpdateChecker(new SiteSettingsRepo($this->app->getDatabase()), fn() => false);
        $status  = $checker->getStatus();
        $sends   = 0;

        for ($pageLoad = 0; $pageLoad < 3; $pageLoad++) {
            if (!$checker->shouldSendNotification($status)) {
                continue;
            }

            try {
                $checker->sendNotification((string) $status->latest, static function () use (&$sends): void {
                    $sends++;
                });
            } catch (\PDOException) {
                // Application::runUpdateNotificationCheck() logs this.
            }
        }

        self::assertSame(1, $sends);
        self::assertNotNull($this->setting('update_notify_last_attempt'));
        self::assertNull($this->setting('update_notified_version'));
    }

    private function failedTries(): int
    {
        return (int) $this->pdo
            ->query("SELECT COUNT(*) FROM lstr_error_log WHERE source = 'Application::runUpdateNotificationCheck'")
            ->fetchColumn();
    }

    private function setting(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT setting_value FROM lstr_site_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : $value;
    }

    private function setSetting(string $key, string $value): void
    {
        $this->pdo->prepare('REPLACE INTO lstr_site_settings (setting_key, setting_value) VALUES (?, ?)')
            ->execute([$key, $value]);
    }
}
