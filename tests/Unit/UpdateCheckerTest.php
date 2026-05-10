<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\UpdateChecker;
use Luster\Core\Version;
use Luster\Tests\Unit\Support\InMemorySettings;
use PHPUnit\Framework\TestCase;

final class UpdateCheckerTest extends TestCase
{
    // -----------------------------------------------------------------------
    // compareVersions — pure static logic
    // -----------------------------------------------------------------------

    public function testSameVersionIsNotAnUpdate(): void
    {
        self::assertFalse(UpdateChecker::compareVersions('1.0.0', '1.0.0'));
    }

    public function testNewerRemoteIsAnUpdate(): void
    {
        self::assertTrue(UpdateChecker::compareVersions('1.0.0', '1.0.1'));
        self::assertTrue(UpdateChecker::compareVersions('1.0.0', '1.1.0'));
        self::assertTrue(UpdateChecker::compareVersions('1.0.0', '2.0.0'));
    }

    public function testOlderRemoteIsNotAnUpdate(): void
    {
        self::assertFalse(UpdateChecker::compareVersions('1.0.1', '1.0.0'));
        self::assertFalse(UpdateChecker::compareVersions('2.0.0', '1.9.9'));
    }

    public function testVPrefixesAreStripped(): void
    {
        self::assertFalse(UpdateChecker::compareVersions('v1.0.0', 'v1.0.0'));
        self::assertTrue(UpdateChecker::compareVersions('v1.0.0', 'v1.0.1'));
        self::assertTrue(UpdateChecker::compareVersions('1.0.0',  'v1.0.1'));
        self::assertTrue(UpdateChecker::compareVersions('v1.0.0', '1.0.1'));
    }

    public function testMalformedInstalledVersionReturnsNull(): void
    {
        self::assertNull(UpdateChecker::compareVersions('beta', '1.0.1'));
        self::assertNull(UpdateChecker::compareVersions('',     '1.0.0'));
        self::assertNull(UpdateChecker::compareVersions('abc',  '2.0.0'));
    }

    public function testMalformedLatestVersionReturnsNull(): void
    {
        self::assertNull(UpdateChecker::compareVersions('1.0.0', 'beta'));
        self::assertNull(UpdateChecker::compareVersions('1.0.0', ''));
        self::assertNull(UpdateChecker::compareVersions('1.0.0', 'nightly'));
    }

    public function testCurrentVersionConstantIsValidSemver(): void
    {
        self::assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+/',
            Version::CURRENT,
            'Version::CURRENT must be a semver-like string',
        );
    }

    // -----------------------------------------------------------------------
    // getStatus — cache behaviour
    // -----------------------------------------------------------------------

    private function makeChecker(InMemorySettings $store, callable $fetcher): UpdateChecker
    {
        return new UpdateChecker($store, $fetcher);
    }

    private function successFetcher(string $version, string $title = 'Test release'): callable
    {
        return fn() => json_encode([
            'tag_name' => 'v' . $version,
            'html_url' => 'https://github.com/spyderfang/luster/releases/tag/v' . $version,
            'name'     => $title,
        ]);
    }

    private function failFetcher(): callable
    {
        return fn() => false;
    }

    public function testFreshCacheIsNotRefetched(): void
    {
        $store = new InMemorySettings();
        $store->set('update_latest_version', '9.9.9');
        $store->set('update_checked_at',     (string) time());
        $store->set('update_check_failed',   '0');

        $fetched = 0;
        $checker = $this->makeChecker($store, function () use (&$fetched) {
            $fetched++;
            return json_encode(['tag_name' => 'v1.0.0', 'html_url' => '', 'name' => '']);
        });

        $status = $checker->getStatus();

        self::assertSame(0, $fetched, 'HTTP fetcher must not be called when cache is fresh');
        self::assertSame('9.9.9', $status->latest);
    }

    public function testForceBypassesCache(): void
    {
        $store = new InMemorySettings();
        $store->set('update_latest_version', '0.0.1');
        $store->set('update_checked_at',     (string) time());
        $store->set('update_check_failed',   '0');

        $checker = $this->makeChecker($store, $this->successFetcher('1.2.3'));
        $status  = $checker->getStatus(force: true);

        self::assertSame('1.2.3', $status->latest);
    }

    public function testNeverCheckedStateWhenNoCacheExists(): void
    {
        $store   = new InMemorySettings();
        $checker = $this->makeChecker($store, $this->failFetcher());
        $status  = $checker->getStatus();

        self::assertTrue($status->neverChecked);
        self::assertNull($status->latest);
    }

    public function testSuccessfulFetchSetsUpdateAvailableWhenNewerVersionExists(): void
    {
        $store   = new InMemorySettings();
        $checker = $this->makeChecker($store, $this->successFetcher('99.0.0'));
        $status  = $checker->getStatus(force: true);

        self::assertFalse($status->checkFailed);
        self::assertFalse($status->neverChecked);
        self::assertTrue($status->updateAvailable);
        self::assertSame('99.0.0', $status->latest);
        self::assertNotNull($status->releaseUrl);
        self::assertSame('Test release', $status->releaseTitle);
    }

    public function testSuccessfulFetchSetsNotOutdatedWhenCurrentVersionMatches(): void
    {
        $store   = new InMemorySettings();
        $checker = $this->makeChecker($store, $this->successFetcher(Version::CURRENT));
        $status  = $checker->getStatus(force: true);

        self::assertFalse($status->checkFailed);
        self::assertFalse($status->updateAvailable);
    }

    public function testFailedFetchPreservesLastGoodCachedVersion(): void
    {
        $store = new InMemorySettings();
        $store->set('update_latest_version', '2.0.0');
        $store->set('update_release_url',    'https://example.com/release');
        $store->set('update_checked_at',     (string) (time() - 90000)); // stale
        $store->set('update_check_failed',   '0');

        $checker = $this->makeChecker($store, $this->failFetcher());
        $status  = $checker->getStatus(force: true);

        self::assertTrue($status->checkFailed);
        self::assertSame('2.0.0', $status->latest, 'Last good version must be preserved on failure');
        self::assertSame('https://example.com/release', $status->releaseUrl);
        self::assertNotNull($status->error);
    }

    public function testFailedFetchWithNoPriorCacheProducesSafeErrorState(): void
    {
        $store   = new InMemorySettings();
        $checker = $this->makeChecker($store, $this->failFetcher());
        $status  = $checker->getStatus(force: true);

        self::assertTrue($status->checkFailed);
        self::assertNull($status->latest);
        self::assertFalse($status->updateAvailable);
        self::assertNotNull($status->error);
    }

    public function testMalformedTagFromGitHubDoesNotFlagAsOutdated(): void
    {
        $store = new InMemorySettings();
        $checker = $this->makeChecker($store, fn() => json_encode([
            'tag_name' => 'nightly-build',
            'html_url' => 'https://example.com',
            'name'     => 'Nightly',
        ]));

        $status = $checker->getStatus(force: true);

        // A tag that doesn't look like semver should be treated as a fetch failure
        self::assertTrue($status->checkFailed);
        self::assertFalse($status->updateAvailable);
    }

    public function testResultIsCachedAfterSuccessfulFetch(): void
    {
        $store   = new InMemorySettings();
        $checker = $this->makeChecker($store, $this->successFetcher('5.0.0'));
        $checker->getStatus(force: true);

        self::assertSame('5.0.0', $store->get('update_latest_version'));
        self::assertSame('0',     $store->get('update_check_failed'));
        self::assertNotNull($store->get('update_checked_at'));
    }
}
