<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Checks the GitHub Releases API for newer Luster versions and caches the
 result in lstr_site_settings so the API is not contacted on every page
 load. Returns an UpdateStatus value object with the installed version,
 latest available version, release URL, and whether an update is available.
*/

declare(strict_types=1);

namespace Luster\Core;

use Luster\Repositories\SiteSettingsRepo;

/**
 * Checks whether a newer Luster release is available on GitHub.
 *
 * Results are cached in lstr_site_settings so the GitHub API is not
 * contacted on every page load. The cache is considered fresh for 24 hours;
 * passing force: true to getStatus() bypasses the TTL.
 *
 * Cache keys used (all stored via SiteSettingsRepo):
 *   update_latest_version  — tag with v-prefix stripped, e.g. "1.2.3"
 *   update_release_url     — HTML URL of the GitHub release page
 *   update_release_title   — release name/title from GitHub
 *   update_checked_at      — unix timestamp of last check attempt (string)
 *   update_check_failed    — "1" if the last attempt failed, "0" otherwise
 */
final class UpdateChecker
{
    private const CACHE_TTL    = 3600; // 1 hour
    private const GITHUB_API   = 'https://api.github.com/repos/' . Version::GITHUB_REPO . '/releases/latest';
    private const FETCH_TIMEOUT = 10;

    /** @var callable(string): string|false */
    private $httpFetcher;

    /**
     * @param callable(string): string|false|null $httpFetcher
     *   Injected for testing. Defaults to file_get_contents with a User-Agent context.
     */
    public function __construct(
        private readonly SiteSettingsRepo $repo,
        ?callable $httpFetcher = null,
    ) {
        $this->httpFetcher = $httpFetcher ?? $this->defaultFetcher();
    }

    /**
     * Returns the current update status, using cached data when fresh.
     *
     * @param bool $force Bypass the cache TTL and re-fetch from GitHub immediately.
     */
    public function getStatus(bool $force = false): UpdateStatus
    {
        $installed = Version::CURRENT;

        $cachedVersion = $this->repo->get('update_latest_version');
        $cachedUrl     = $this->repo->get('update_release_url');
        $cachedTitle   = $this->repo->get('update_release_title');
        $cachedAt      = $this->repo->get('update_checked_at');
        $cachedFailed  = $this->repo->get('update_check_failed', '0');

        $checkedAt   = ($cachedAt !== null) ? (int) $cachedAt : null;
        $cacheExists = $checkedAt !== null;
        $cacheAge    = $cacheExists ? (time() - $checkedAt) : PHP_INT_MAX;
        $cacheFresh  = !$force && $cacheExists && $cacheAge < self::CACHE_TTL;

        // Return immediately when the feature has never been run and no forced check was requested.
        // This avoids an outbound HTTP request on every page load for fresh installs.
        if (!$cacheExists && !$force) {
            return new UpdateStatus(
                installed:       $installed,
                latest:          null,
                releaseUrl:      null,
                releaseTitle:    null,
                checkedAt:       null,
                updateAvailable: false,
                checkFailed:     false,
                neverChecked:    true,
                error:           null,
            );
        }

        if ($cacheFresh) {
            return $this->buildStatus(
                $installed, $cachedVersion, $cachedUrl, $cachedTitle,
                $checkedAt, $cachedFailed === '1', null,
            );
        }

        [$fetchedVersion, $fetchedUrl, $fetchedTitle, $fetchError, $fetchedPublishedAt] = $this->fetchLatest();

        $now = time();

        if ($fetchError === null && $fetchedVersion !== null) {
            $this->repo->set('update_latest_version',  $fetchedVersion);
            $this->repo->set('update_release_url',     $fetchedUrl);
            $this->repo->set('update_release_title',   $fetchedTitle);
            $this->repo->set('update_checked_at',      (string) $now);
            $this->repo->set('update_check_failed',    '0');
            if ($fetchedPublishedAt !== null) {
                $this->repo->set('update_published_at', $fetchedPublishedAt);
            }

            return $this->buildStatus(
                $installed, $fetchedVersion, $fetchedUrl, $fetchedTitle,
                $now, false, null,
            );
        }

        // Failed — preserve last good cached version, update timestamp
        $this->repo->set('update_checked_at',   (string) $now);
        $this->repo->set('update_check_failed', '1');

        return $this->buildStatus(
            $installed, $cachedVersion, $cachedUrl, $cachedTitle,
            $now, true, $fetchError,
        );
    }

    /**
     * Returns true when an update notification email should be sent.
     *
     * Gated by: email notifications enabled, update available, not already
     * notified for this specific version.
     */
    public function shouldSendNotification(UpdateStatus $status): bool
    {
        if (($this->repo->get('update_email_notify') ?? '1') !== '1') {
            return false;
        }
        if (!$status->updateAvailable || $status->latest === null) {
            return false;
        }
        $notifiedVersion = $this->repo->get('update_notified_version');
        if ($notifiedVersion !== null && $notifiedVersion === $status->latest) {
            return false;
        }
        return true;
    }

    /**
     * Records that the admin has been notified about $version so that
     * shouldSendNotification() returns false for the same version.
     */
    public function markNotified(string $version): void
    {
        $this->repo->set('update_notified_version', $version);
    }

    /**
     * Compares two version strings and returns whether $latest is newer than $installed.
     *
     * Returns null when either string is not a recognisable semver-like value
     * (e.g. empty, non-numeric) so callers can show "unable to compare" instead
     * of incorrectly flagging the install as outdated.
     *
     * Leading "v" prefixes are stripped before comparison.
     */
    public static function compareVersions(string $installed, string $latest): ?bool
    {
        $a = ltrim($installed, 'v');
        $b = ltrim($latest,    'v');

        if (!preg_match('/^\d+\.\d+/', $a) || !preg_match('/^\d+\.\d+/', $b)) {
            return null;
        }

        return version_compare($b, $a, '>');
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string, 3: ?string, 4: ?string}
     *   [version, releaseUrl, releaseTitle, errorMessage, publishedAt]
     */
    private function fetchLatest(): array
    {
        try {
            $body = ($this->httpFetcher)(self::GITHUB_API);
        } catch (\Throwable) {
            $body = false;
        }

        if ($body === false || $body === '') {
            return [null, null, null, 'Could not reach GitHub — check your server\'s outbound internet access.', null];
        }

        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            return [null, null, null, 'GitHub returned an unexpected response.', null];
        }

        if (isset($data['message'])) {
            // "Not Found" means the repo has no releases yet — not a network error.
            // Return empty-string version (sentinel: checked OK, nothing to compare) with no error.
            if ((string) $data['message'] === 'Not Found') {
                return ['', null, null, null, null];
            }
            return [null, null, null, 'GitHub: ' . (string) $data['message'], null];
        }

        $tag = isset($data['tag_name']) ? ltrim((string) $data['tag_name'], 'v') : '';
        if ($tag === '' || !preg_match('/^\d+\.\d+/', $tag)) {
            return [null, null, null, 'GitHub returned an unrecognised version format: ' . ($tag ?: '(empty)'), null];
        }

        $releaseUrl   = isset($data['html_url'])     ? (string) $data['html_url']     : null;
        $releaseTitle = isset($data['name'])         ? (string) $data['name']         : null;
        $publishedAt  = isset($data['published_at']) ? (string) $data['published_at'] : null;

        return [$tag, $releaseUrl, $releaseTitle, null, $publishedAt];
    }

    private function buildStatus(
        string  $installed,
        ?string $latest,
        ?string $url,
        ?string $title,
        ?int    $checkedAt,
        bool    $failed,
        ?string $error,
    ): UpdateStatus {
        // Normalise empty-string sentinel (no releases published yet) to null.
        $latest = ($latest !== null && $latest !== '') ? $latest : null;

        $updateAvailable = false;
        if ($latest !== null && !$failed) {
            $cmp = self::compareVersions($installed, $latest);
            if ($cmp === true) {
                $updateAvailable = true;
            } elseif ($cmp === false) {
                // Remote is <= installed by semver. Only apply the date fallback for a
                // genuine version-reset (remote strictly less than installed, e.g. 1.0.18 → 1.0.0
                // with a newer build). When the versions are equal there is nothing to update.
                if (version_compare(ltrim($latest, 'v'), ltrim($installed, 'v'), '<')) {
                    $publishedAt = $this->repo->get('update_published_at');
                    if ($publishedAt !== null) {
                        $remoteTs = strtotime($publishedAt);
                        $builtTs  = strtotime(Version::BUILT_AT);
                        if ($remoteTs !== false && $builtTs !== false && $remoteTs > $builtTs) {
                            $updateAvailable = true;
                        }
                    }
                }
            }
        }

        return new UpdateStatus(
            installed:       $installed,
            latest:          $latest,
            releaseUrl:      $url,
            releaseTitle:    $title,
            checkedAt:       $checkedAt,
            updateAvailable: $updateAvailable,
            checkFailed:     $failed,
            neverChecked:    $checkedAt === null,
            error:           $error,
        );
    }

    private function defaultFetcher(): \Closure
    {
        return function (string $url): string|false {
            $ua = 'Luster/' . Version::CURRENT . ' (update-check)';

            if (ini_get('allow_url_fopen')) {
                $context = stream_context_create([
                    'http' => [
                        'timeout'       => self::FETCH_TIMEOUT,
                        'user_agent'    => $ua,
                        'ignore_errors' => true,
                    ],
                    'ssl'  => [
                        'verify_peer'      => true,
                        'verify_peer_name' => true,
                    ],
                ]);
                $result = @file_get_contents($url, false, $context);
                if ($result !== false) {
                    return $result;
                }
            }

            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS      => 3,
                    CURLOPT_TIMEOUT        => self::FETCH_TIMEOUT,
                    CURLOPT_CONNECTTIMEOUT => self::FETCH_TIMEOUT,
                    CURLOPT_USERAGENT      => $ua,
                    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
                ]);
                $result = curl_exec($ch);
                $code   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if (is_string($result) && $code > 0) {
                    return $result;
                }
            }

            return false;
        };
    }
}
