<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\UpdateChecker;
use Luster\Core\UpdateStatus;
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

    public function testNotificationOffByDefaultWhenSettingAbsent(): void
    {
        $store   = new InMemorySettings();
        $checker = $this->makeChecker($store);
        $status  = $this->statusWith(true, '2.0.0');

        self::assertFalse($checker->shouldSendNotification($status));
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
}
