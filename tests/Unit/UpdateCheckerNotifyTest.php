<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\UpdateChecker;
use Luster\Core\UpdateStatus;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Tests\Unit\Support\InMemorySettings;
use PHPUnit\Framework\TestCase;

final class UpdateCheckerNotifyTest extends TestCase
{
    private function makeChecker(InMemorySettings $store): UpdateChecker
    {
        return new UpdateChecker($store, fn() => false);
    }

    private function statusWith(bool $available, ?string $latest): UpdateStatus
    {
        return new UpdateStatus(
            installed:       '1.0.0',
            latest:          $latest,
            releaseUrl:      null,
            releaseTitle:    null,
            checkedAt:       time(),
            updateAvailable: $available,
            checkFailed:     false,
            neverChecked:    false,
            error:           null,
        );
    }

    // -----------------------------------------------------------------------
    // shouldSendNotification — opt-in gating
    // -----------------------------------------------------------------------

    public function testNotificationOnByDefaultWhenSettingAbsent(): void
    {
        $store   = new InMemorySettings();
        $checker = $this->makeChecker($store);
        $status  = $this->statusWith(true, '2.0.0');

        self::assertTrue($checker->shouldSendNotification($status));
    }

    public function testNotificationOffWhenSettingIsZero(): void
    {
        $store = new InMemorySettings();
        $store->set('update_email_notify', '0');
        $checker = $this->makeChecker($store);
        $status  = $this->statusWith(true, '2.0.0');

        self::assertFalse($checker->shouldSendNotification($status));
    }

    public function testNotificationFiresWhenSettingIsOneAndNewVersionFound(): void
    {
        $store = new InMemorySettings();
        $store->set('update_email_notify', '1');
        $checker = $this->makeChecker($store);
        $status  = $this->statusWith(true, '2.0.0');

        self::assertTrue($checker->shouldSendNotification($status));
    }

    // -----------------------------------------------------------------------
    // shouldSendNotification — once-per-version guard
    // -----------------------------------------------------------------------

    public function testNotificationSuppressedWhenAlreadySentForSameVersion(): void
    {
        $store = new InMemorySettings();
        $store->set('update_email_notify',    '1');
        $store->set('update_notified_version', '2.0.0');
        $checker = $this->makeChecker($store);
        $status  = $this->statusWith(true, '2.0.0');

        self::assertFalse($checker->shouldSendNotification($status));
    }

    public function testNotificationFiresAgainForNewerVersion(): void
    {
        $store = new InMemorySettings();
        $store->set('update_email_notify',    '1');
        $store->set('update_notified_version', '2.0.0');
        $checker = $this->makeChecker($store);
        $status  = $this->statusWith(true, '3.0.0');

        self::assertTrue($checker->shouldSendNotification($status));
    }

    // -----------------------------------------------------------------------
    // shouldSendNotification — status guards
    // -----------------------------------------------------------------------

    public function testNotificationSkippedWhenNoUpdateAvailable(): void
    {
        $store = new InMemorySettings();
        $store->set('update_email_notify', '1');
        $checker = $this->makeChecker($store);
        $status  = $this->statusWith(false, '1.0.0');

        self::assertFalse($checker->shouldSendNotification($status));
    }

    public function testNotificationSkippedWhenLatestIsNull(): void
    {
        $store = new InMemorySettings();
        $store->set('update_email_notify', '1');
        $checker = $this->makeChecker($store);
        $status  = $this->statusWith(true, null);

        self::assertFalse($checker->shouldSendNotification($status));
    }

    // -----------------------------------------------------------------------
    // markNotified — persistence + idempotency
    // -----------------------------------------------------------------------

    public function testMarkNotifiedPersistsVersionInStore(): void
    {
        $store   = new InMemorySettings();
        $checker = $this->makeChecker($store);
        $checker->markNotified('2.0.0');

        self::assertSame('2.0.0', $store->get('update_notified_version'));
    }

    public function testShouldSendReturnsFalseAfterMarkNotified(): void
    {
        $store = new InMemorySettings();
        $store->set('update_email_notify', '1');
        $checker = $this->makeChecker($store);
        $status  = $this->statusWith(true, '2.0.0');

        self::assertTrue($checker->shouldSendNotification($status), 'should fire before marking');
        $checker->markNotified('2.0.0');
        self::assertFalse($checker->shouldSendNotification($status), 'should not fire after marking');
    }

    // -----------------------------------------------------------------------
    // shouldSendNotification — waiting after a failed try
    // -----------------------------------------------------------------------

    public function testNotificationWaitsAfterARecentFailedTry(): void
    {
        $store = new InMemorySettings();
        $store->set('update_notify_last_attempt', (string) (time() - 3600));
        $checker = $this->makeChecker($store);

        self::assertFalse($checker->shouldSendNotification($this->statusWith(true, '2.0.0')));
    }

    public function testWaitAfterAFailedTryAlsoCoversANewerVersion(): void
    {
        $store = new InMemorySettings();
        $store->set('update_notified_version', '2.0.0');
        $store->set('update_notify_last_attempt', (string) (time() - 3600));
        $checker = $this->makeChecker($store);

        self::assertFalse($checker->shouldSendNotification($this->statusWith(true, '3.0.0')));
    }

    public function testNotificationTriesAgainOnceADayHasPassed(): void
    {
        $store = new InMemorySettings();
        $store->set('update_notify_last_attempt', (string) (time() - 86401));
        $checker = $this->makeChecker($store);

        self::assertTrue($checker->shouldSendNotification($this->statusWith(true, '2.0.0')));
    }

    // -----------------------------------------------------------------------
    // sendNotification — what gets saved, and when
    // -----------------------------------------------------------------------

    public function testTheTryIsSavedBeforeTheEmailIsSent(): void
    {
        $store   = new InMemorySettings();
        $checker = $this->makeChecker($store);
        $savedWhenSending  = null;
        $markedWhenSending = null;

        $checker->sendNotification('2.0.0', function () use ($store, &$savedWhenSending, &$markedWhenSending): void {
            $savedWhenSending  = $store->get('update_notify_last_attempt');
            $markedWhenSending = $store->get('update_notified_version');
        });

        self::assertNotNull($savedWhenSending, 'a killed request must still leave the try behind');
        self::assertNull($markedWhenSending, 'the version only counts as notified after sending');
    }

    public function testAFailedSendKeepsTheTryAndDoesNotCountAsNotified(): void
    {
        $store   = new InMemorySettings();
        $checker = $this->makeChecker($store);
        $status  = $this->statusWith(true, '2.0.0');

        try {
            $checker->sendNotification('2.0.0', static function (): void {
                throw new \RuntimeException('Email send failed');
            });
            self::fail('the send failure should reach the caller, so it gets logged');
        } catch (\RuntimeException $e) {
            self::assertSame('Email send failed', $e->getMessage());
        }

        self::assertNull($store->get('update_notified_version'));
        self::assertNotNull($store->get('update_notify_last_attempt'));
        self::assertFalse($checker->shouldSendNotification($status), 'should wait before trying again');
    }

    public function testASuccessfulSendDoesNotDelayTheNextVersion(): void
    {
        $store   = new InMemorySettings();
        $checker = $this->makeChecker($store);

        $checker->sendNotification('2.0.0', static function (): void {
        });

        self::assertSame('2.0.0', $store->get('update_notified_version'));
        self::assertNull($store->get('update_notify_last_attempt'));
        self::assertFalse($checker->shouldSendNotification($this->statusWith(true, '2.0.0')));
        self::assertTrue($checker->shouldSendNotification($this->statusWith(true, '3.0.0')));
    }

    public function testNothingIsSentWhenTheTryCannotBeSaved(): void
    {
        $store = new class () extends SiteSettingsRepo {
            public function __construct()
            {
                // No database needed: every write below fails on purpose.
            }

            public function setStrict(string $key, ?string $value): void
            {
                throw new \PDOException('INSERT command denied');
            }
        };
        $checker = new UpdateChecker($store, fn() => false);
        $sent    = false;

        try {
            $checker->sendNotification('2.0.0', static function () use (&$sent): void {
                $sent = true;
            });
            self::fail('the failed save should reach the caller, so it gets logged');
        } catch (\PDOException $e) {
            self::assertSame('INSERT command denied', $e->getMessage());
        }

        self::assertFalse($sent);
    }
}
