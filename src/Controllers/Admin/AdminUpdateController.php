<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Handles the in-app update workflow for Luster itself. Checks the GitHub releases
 API for newer versions, downloads and stages the release ZIP, runs preflight
 checks (PHP version, permissions, backup confirmation), and applies the update
 by swapping files from the staging directory. Session state tracks wizard
 progress so each step can be resumed if interrupted.
*/

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\UpdateApplier;
use Luster\Core\UpdateChecker;
use Luster\Core\UpdateStatus;
use Luster\Core\Version;
use Luster\Repositories\ErrorLogRepo;
use Luster\Repositories\SiteSettingsRepo;

/**
 * Manages the in-app update wizard for Luster itself.
 *
 * Covers the full lifecycle: checking GitHub for a newer release, downloading
 * and staging the ZIP, running preflight checks, generating a backup, and
 * applying the update. Session keys track wizard state so each step can
 * resume without re-downloading if the admin navigates away mid-flow.
 */
final class AdminUpdateController extends AdminController
{
    private const KEY_STAGING = 'lstr_update_staging_dir';
    private const KEY_VERSION = 'lstr_update_version';
    private const KEY_PREFLIGHT = 'lstr_update_preflight';
    private const KEY_BACKUP_CONFIRMED = 'lstr_update_backup_confirmed';
    private const KEY_DOWNLOAD = 'lstr_update_download_path';
    private const KEY_RELEASE_URL = 'lstr_update_release_url';
    private const KEY_RELEASE_TITLE = 'lstr_update_release_title';
    private const KEY_MANUAL_REASON = 'lstr_update_manual_reason';
    private const KEY_MANUAL_VERSION = 'lstr_update_manual_version';
    private const KEY_MANUAL_URL = 'lstr_update_manual_url';
    private const KEY_BACKUP_DOWNLOADED = 'lstr_update_backup_downloaded';
    private const KEY_OBSOLETE_PREVIEW = 'lstr_update_obsolete_preview';
    private const KEY_HTACCESS_PREVIEW = 'lstr_update_htaccess_preview';

    /**
     * Shows the update wizard page.
     *
     * If a staged update is already in session, re-runs preflight checks on
     * every load so the admin can fix blocking issues and refresh without
     * re-downloading. Accepts a ?preview= query param for UI state previews.
     */
    public function index(Request $request, array $params): Response
    {
        $preview = (string) $request->query('preview', '');
        if ($preview !== '') {
            return $this->renderPreviewState($preview);
        }

        $session = $this->app->getSession();

        $success = (string) $session->get('lstr_flash_success', '');
        $error = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        $applier = $this->applier();
        $applier->cleanupStale();
        $stagingDir = (string) $session->get(self::KEY_STAGING, '');
        $downloadPath = (string) $session->get(self::KEY_DOWNLOAD, '');
        $stagingValid = $stagingDir !== '' && $applier->stagingIsValid($stagingDir);

        $preflight = $this->preflightFromSession();
        $obsoletePreview = $this->obsoletePreviewFromSession();
        $htaccessStatus = $this->htaccessPreviewFromSession();
        if ($stagingValid) {
            $zipSize = is_file($downloadPath) ? (int) filesize($downloadPath) : 0;
            $preflight = $applier->runPreflight($zipSize, $stagingDir);
            $session->set(self::KEY_PREFLIGHT, json_encode($preflight));

            $obsoletePreview = $applier->planObsoleteFiles($stagingDir);
            $session->set(self::KEY_OBSOLETE_PREVIEW, json_encode(array_slice($obsoletePreview, 0, 300)));

            $htaccessStatus = $applier->htaccessStatus($stagingDir);
            $session->set(self::KEY_HTACCESS_PREVIEW, $htaccessStatus);
        } elseif ($stagingDir !== '') {
            $error = $error !== ''
                ? $error
                : 'The staged update could not be found. Discard it and prepare the update again.';
        }

        $siteRepo = new SiteSettingsRepo($this->app->getDatabase());
        $updateStatus = (new UpdateChecker($siteRepo))->getStatus();

        $htaccessPreviewContent = null;
        if ($htaccessStatus === 'kept_customized' || $htaccessStatus === 'kept_customized_reviewed') {
            $htaccessPreviewContent = $applier->htaccessReviewContent($stagingDir !== '' ? $stagingDir : null);
        }

        return $this->adminLayout('admin/update/index.php', 'Luster Update', 'settings', [
            'success' => $success,
            'error' => $error,
            'update_status' => $updateStatus,
            'staging_valid' => $stagingValid,
            'staging_dir' => $stagingDir,
            'update_version' => (string) $session->get(self::KEY_VERSION, ''),
            'release_url' => (string) $session->get(self::KEY_RELEASE_URL, ''),
            'release_title' => (string) $session->get(self::KEY_RELEASE_TITLE, ''),
            'preflight' => $preflight,
            'preflight_ok' => $this->preflightOk($preflight),
            'backup_downloaded' => $session->get(self::KEY_BACKUP_DOWNLOADED, '') === '1',
            'manual_reason' => (string) $session->get(self::KEY_MANUAL_REASON, ''),
            'manual_version' => (string) $session->get(self::KEY_MANUAL_VERSION, ''),
            'manual_url' => (string) $session->get(self::KEY_MANUAL_URL, ''),
            'lock_exists' => $applier->lockExists(),
            'obsolete_count' => count($obsoletePreview),
            'obsolete_paths' => array_slice($obsoletePreview, 0, 300),
            'htaccess_status' => $htaccessStatus,
            'htaccess_preview_content' => $htaccessPreviewContent,
        ], '', [
            $this->crumb('Settings', $this->adminUrl('/admin/settings#panel-system')),
        ]);
    }

    /**
     * Renders the update page with mock data for a given preview state.
     *
     * Usage: GET /admin/update?preview=STATE
     *
     * States:
     *   up-to-date        — current version matches latest, no update
     *   update-available   — newer version exists, not yet prepared
     *   check-failed       — GitHub check failed with an error
     *   never-checked      — no update check has ever been run
     *   staged-ok          — update prepared, all preflight checks pass
     *   staged-ok-htaccess-customized — same, but public/.htaccess is customized
     *   staged-blocked     — update prepared, some preflight checks fail
     *   manual-required    — update cannot be auto-applied
     *   lock-exists        — stale update lock file present
     *   success            — flash success message shown
     *   error              — flash error message shown
     */
    private function renderPreviewState(string $state): Response
    {
        $current = Version::CURRENT;
        $next    = $this->bumpPatch($current);

        $baseStatus = new UpdateStatus(
            installed: $current, latest: $current, releaseUrl: 'https://github.com/' . Version::GITHUB_REPO . '/releases/tag/v' . $current,
            releaseTitle: 'Luster v' . $current, checkedAt: time(), updateAvailable: false, checkFailed: false, neverChecked: false, error: null,
        );

        $defaults = [
            'success'           => '',
            'error'             => '',
            'update_status'     => $baseStatus,
            'staging_valid'     => false,
            'staging_dir'       => '',
            'update_version'    => '',
            'release_url'       => '',
            'release_title'     => '',
            'preflight'         => [],
            'preflight_ok'      => false,
            'backup_downloaded' => false,
            'manual_reason'     => '',
            'manual_version'    => '',
            'manual_url'        => '',
            'lock_exists'       => false,
            'obsolete_count'    => 0,
            'obsolete_paths'    => [],
            'htaccess_status'   => '',
            'htaccess_preview_content' => null,
        ];

        $passChecks = [
            ['label' => 'PHP version', 'detail' => 'PHP ' . PHP_VERSION . ' meets the minimum requirement.', 'ok' => true],
            ['label' => 'Disk space', 'detail' => 'Sufficient free space available.', 'ok' => true],
            ['label' => 'File permissions', 'detail' => 'Application directory is writable.', 'ok' => true],
            ['label' => 'Database connection', 'detail' => 'Database is reachable and responding.', 'ok' => true],
        ];

        $failChecks = [
            ['label' => 'PHP version', 'detail' => 'PHP ' . PHP_VERSION . ' meets the minimum requirement.', 'ok' => true],
            ['label' => 'Disk space', 'detail' => 'Only 12 MB free — at least 50 MB required.', 'ok' => false],
            ['label' => 'File permissions', 'detail' => 'Application directory is writable.', 'ok' => true],
            ['label' => 'Database connection', 'detail' => 'Connection refused — check credentials in config/app.php.', 'ok' => false],
        ];

        $overrides = match ($state) {
            'up-to-date' => [],

            'update-available' => [
                'update_status' => new UpdateStatus(
                    installed: $current, latest: $next, releaseUrl: 'https://github.com/' . Version::GITHUB_REPO . '/releases/tag/v' . $next,
                    releaseTitle: 'Luster v' . $next, checkedAt: time(), updateAvailable: true, checkFailed: false, neverChecked: false, error: null,
                ),
            ],

            'check-failed' => [
                'update_status' => new UpdateStatus(
                    installed: $current, latest: null, releaseUrl: null,
                    releaseTitle: null, checkedAt: time(), updateAvailable: false, checkFailed: true, neverChecked: false,
                    error: 'cURL error 28: Connection timed out after 10001 milliseconds.',
                ),
            ],

            'never-checked' => [
                'update_status' => new UpdateStatus(
                    installed: $current, latest: null, releaseUrl: null,
                    releaseTitle: null, checkedAt: null, updateAvailable: false, checkFailed: false, neverChecked: true, error: null,
                ),
            ],

            'staged-ok' => [
                'staging_valid'  => true,
                'update_version' => $next,
                'release_url'    => 'https://github.com/' . Version::GITHUB_REPO . '/releases/tag/v' . $next,
                'release_title'  => 'Luster v' . $next . ' — Bug fixes and performance improvements',
                'preflight'      => $passChecks,
                'preflight_ok'   => true,
                'backup_downloaded' => true,
                'obsolete_count' => 6,
                'obsolete_paths' => [
                    'src/Addons/OldAddon/OldAddon.php',
                    'src/Addons/OldAddon/OldAddonRepo.php',
                    'templates/default/old-template.php',
                    'vendor/some/removed-package/src/Old.php',
                    'vendor/some/removed-package/composer.json',
                    'public/embed/old-endpoint.php',
                ],
                'htaccess_status' => 'updated',
            ],

            'staged-ok-htaccess-customized' => [
                'staging_valid'  => true,
                'update_version' => $next,
                'release_url'    => 'https://github.com/' . Version::GITHUB_REPO . '/releases/tag/v' . $next,
                'release_title'  => 'Luster v' . $next . ' — Bug fixes and performance improvements',
                'preflight'      => $passChecks,
                'preflight_ok'   => true,
                'backup_downloaded' => true,
                'obsolete_count' => 2,
                'obsolete_paths' => [
                    'src/Addons/OldAddon/OldAddon.php',
                    'templates/default/old-template.php',
                ],
                'htaccess_status' => 'kept_customized',
                'htaccess_preview_content' => "# This is a preview of the release's new default public/.htaccess.\nOptions -Indexes -MultiViews\n…",
            ],

            'staged-blocked' => [
                'staging_valid'  => true,
                'update_version' => $next,
                'release_url'    => 'https://github.com/' . Version::GITHUB_REPO . '/releases/tag/v' . $next,
                'release_title'  => 'Luster v' . $next . ' — Bug fixes and performance improvements',
                'preflight'      => $failChecks,
                'preflight_ok'   => false,
                'obsolete_count' => 6,
                'obsolete_paths' => [
                    'src/Addons/OldAddon/OldAddon.php',
                    'src/Addons/OldAddon/OldAddonRepo.php',
                    'templates/default/old-template.php',
                    'vendor/some/removed-package/src/Old.php',
                    'vendor/some/removed-package/composer.json',
                    'public/embed/old-endpoint.php',
                ],
                'htaccess_status' => 'unchanged',
            ],

            'manual-required' => [
                'update_status'  => new UpdateStatus(
                    installed: $current, latest: $next, releaseUrl: 'https://github.com/' . Version::GITHUB_REPO . '/releases/tag/v' . $next,
                    releaseTitle: 'Luster v' . $next, checkedAt: time(), updateAvailable: true, checkFailed: false, neverChecked: false, error: null,
                ),
                'manual_reason'  => 'This release raises the minimum PHP version to 8.3. Please update PHP on your server before applying the update, then download the release manually.',
                'manual_version' => $next,
                'manual_url'     => 'https://github.com/' . Version::GITHUB_REPO . '/releases/tag/v' . $next,
            ],

            'lock-exists' => [
                'lock_exists' => true,
            ],

            'success' => [
                'success' => 'Update applied successfully! Luster has been updated to version ' . $next . '.',
            ],

            'error' => [
                'error' => 'The staged update could not be found. Discard it and prepare the update again.',
            ],

            default => [],
        };

        $data = array_merge($defaults, $overrides);

        return $this->adminLayout('admin/update/index.php', 'Luster Update (Preview: ' . $state . ')', 'settings', $data, '', [
            $this->crumb('Settings', $this->adminUrl('/admin/settings#panel-system')),
        ]);
    }

    /**
     * Increments the patch segment of a semver string for preview purposes.
     *
     * For example, "1.2.3" becomes "1.2.4". Used only to generate realistic
     * fake version numbers in the preview states — never touches real versions.
     */
    private function bumpPatch(string $version): string
    {
        $parts = explode('.', $version);
        if (count($parts) === 3) {
            $parts[2] = (string) ((int) $parts[2] + 1);
        }
        return implode('.', $parts);
    }

    /**
     * Downloads and stages the latest release, then re-runs preflight checks.
     *
     * Redirects back to the update page with a success or error flash. Sets
     * a manual-update flag in session if the release cannot be auto-applied
     * (e.g. requires a PHP version the server doesn't meet).
     */
    public function prepare(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        if ((string) $session->get(self::KEY_STAGING, '') !== '') {
            $session->set('lstr_flash_error', 'An update is already prepared. Apply it or discard it before preparing another one.');
            return Response::redirect($baseUrl . '/admin/update');
        }

        $this->clearManualSession();

        try {
            $result = $this->applier()->prepare();

            if (($result['up_to_date'] ?? false) === true) {
                $this->clearUpdateSession();
                $session->set('lstr_flash_success', 'Luster is already up to date.');
                return Response::redirect($baseUrl . '/admin/update');
            }

            if (($result['manual_required'] ?? false) === true) {
                $session->set(self::KEY_MANUAL_REASON, (string) ($result['manual_reason'] ?? 'This release cannot be applied automatically.'));
                $session->set(self::KEY_MANUAL_VERSION, (string) ($result['version'] ?? ''));
                $session->set(self::KEY_MANUAL_URL, (string) ($result['release_url'] ?? ''));
                $session->set('lstr_flash_error', 'Manual update required. The release is missing a verifiable updater package.');
                return Response::redirect($baseUrl . '/admin/update');
            }

            $preflight = is_array($result['preflight'] ?? null) ? $result['preflight'] : [];
            $preview = is_array($result['preview'] ?? null) ? $result['preview'] : [];

            $session->set(self::KEY_STAGING, (string) $result['staging_dir']);
            $session->set(self::KEY_VERSION, (string) $result['version']);
            $session->set(self::KEY_PREFLIGHT, json_encode($preflight));
            $session->set(self::KEY_DOWNLOAD, (string) $result['download_path']);
            $session->set(self::KEY_RELEASE_URL, (string) ($result['release_url'] ?? ''));
            $session->set(self::KEY_RELEASE_TITLE, (string) ($result['release_title'] ?? ''));
            $session->set(self::KEY_OBSOLETE_PREVIEW, json_encode($preview['obsolete_paths'] ?? []));
            $session->set(self::KEY_HTACCESS_PREVIEW, (string) ($preview['htaccess_status'] ?? ''));
            $session->remove(self::KEY_BACKUP_CONFIRMED);
            $session->remove(self::KEY_BACKUP_DOWNLOADED);

            if ($this->preflightOk($preflight)) {
                $session->set('lstr_flash_success', 'Update package prepared and pre-flight checks passed.');
            } else {
                $session->set('lstr_flash_error', 'Update package prepared, but one or more pre-flight checks need attention.');
            }
        } catch (\Throwable $e) {
            $this->logError('Prepare update failed: ' . $e->getMessage());
            $session->set('lstr_flash_error', 'Prepare update failed: ' . $e->getMessage());
        }

        return Response::redirect($baseUrl . '/admin/update');
    }

    /**
     * Generates and streams a full backup ZIP to the browser.
     *
     * Includes application files and all Luster + member database tables.
     * Sets a session flag so the apply step knows a backup was downloaded.
     * The temporary ZIP file is deleted from disk after it is sent.
     */
    public function backup(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $stagingDir = (string) $session->get(self::KEY_STAGING, '');
        if ($stagingDir === '' || !$this->applier()->stagingIsValid($stagingDir)) {
            $session->set('lstr_flash_error', 'Prepare an update before downloading a backup.');
            return Response::redirect($baseUrl . '/admin/update');
        }

        try {
            $backup = $this->applier()->createBackupZip();
            $body = file_get_contents($backup['path']);
            $this->applier()->deleteFile($backup['path']);

            if ($body === false) {
                throw new \RuntimeException('Could not read generated backup ZIP.');
            }

            $session->set(self::KEY_BACKUP_DOWNLOADED, '1');
            return Response::download($body, $backup['filename'], 'application/zip');
        } catch (\Throwable $e) {
            $this->logError('Update backup failed: ' . $e->getMessage());
            $session->set('lstr_flash_error', 'Backup failed: ' . $e->getMessage());
            return Response::redirect($baseUrl . '/admin/update');
        }
    }

    /**
     * Applies the staged update by copying files from the staging directory.
     *
     * Re-runs preflight before doing anything. Requires the admin to have
     * checked the backup-confirmed checkbox. On success, clears all update
     * session keys and redirects to the migration runner to apply any
     * database changes the new version requires.
     */
    public function apply(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();
        $applier = $this->applier();

        $stagingDir = (string) $session->get(self::KEY_STAGING, '');
        $downloadPath = (string) $session->get(self::KEY_DOWNLOAD, '');

        if ($stagingDir === '' || !$applier->stagingIsValid($stagingDir)) {
            $session->set('lstr_flash_error', 'No prepared update is available.');
            return Response::redirect($baseUrl . '/admin/update');
        }

        $zipSize = is_file($downloadPath) ? (int) filesize($downloadPath) : 0;
        $preflight = $applier->runPreflight($zipSize, $stagingDir);
        $session->set(self::KEY_PREFLIGHT, json_encode($preflight));

        if (!$this->preflightOk($preflight)) {
            $session->set('lstr_flash_error', 'Pre-flight checks must pass before the update can be applied.');
            return Response::redirect($baseUrl . '/admin/update');
        }

        if ($request->post('backup_confirmed') !== '1') {
            $session->set('lstr_flash_error', 'Confirm that you saved the backup file before applying the update.');
            return Response::redirect($baseUrl . '/admin/update');
        }

        $session->set(self::KEY_BACKUP_CONFIRMED, true);

        try {
            $result = $applier->apply($stagingDir, $downloadPath !== '' ? $downloadPath : null);
            $this->clearUpdateSession();

            $message = 'Update files copied (' . (int) $result['copied'] . ' files)';
            $deleted = (int) ($result['deleted'] ?? 0);
            if ($deleted > 0) {
                $message .= ', ' . $deleted . ' obsolete file' . ($deleted === 1 ? '' : 's') . ' removed';
            }
            $message .= '.';

            $htaccess = (string) ($result['htaccess'] ?? '');
            if ($htaccess === 'kept_customized' || $htaccess === 'kept_customized_reviewed') {
                $message .= ' Your customized public/.htaccess was left alone'
                    . ($htaccess === 'kept_customized' ? ' — review the copy saved for you before your next update.' : '.');
            } elseif ($htaccess === 'updated') {
                $message .= ' public/.htaccess was updated to match this release.';
            }

            $message .= ' Run migrations to finish.';
            $session->set('lstr_flash_success', $message);

            return Response::redirect($baseUrl . '/migrate/');
        } catch (\Throwable $e) {
            $this->logError('Apply update failed: ' . $e->getMessage());
            $session->set(
                'lstr_flash_error',
                'Apply failed and stopped immediately: ' . $e->getMessage()
                . ' Files already copied were left in place. Use your backup to restore if the install is unstable.',
            );
            return Response::redirect($baseUrl . '/admin/update');
        }
    }

    /**
     * Discards the prepared update and clears all update session state.
     *
     * Deletes the staged directory and downloaded ZIP from disk if they exist,
     * then removes all update-related session keys so the wizard resets to the
     * version-check screen.
     */
    public function discard(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $this->applier()->discard(
            (string) $session->get(self::KEY_STAGING, ''),
            (string) $session->get(self::KEY_DOWNLOAD, ''),
        );
        $this->clearUpdateSession();

        $session->set('lstr_flash_success', 'Prepared update discarded.');
        return Response::redirect($baseUrl . '/admin/update');
    }

    /**
     * Streams the public/.htaccess review copy for download.
     *
     * Prefers the review copy a real apply() run wrote to
     * storage/cache/public.htaccess.new; falls back to reading straight from
     * the currently staged package when no apply has run yet (prepare-time
     * preview). Redirects with a flash notice when neither is available.
     */
    public function htaccessNew(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $stagingDir = (string) $session->get(self::KEY_STAGING, '');
        $content = $this->applier()->htaccessReviewContent($stagingDir !== '' ? $stagingDir : null);

        if ($content === null) {
            $session->set('lstr_flash_error', 'No public/.htaccess review copy is available right now.');
            return Response::redirect($baseUrl . '/admin/update');
        }

        return Response::download($content, 'public.htaccess.new', 'text/plain');
    }

    /**
     * Marks the current live public/.htaccess as reviewed - silences the
     * "needs review" banner for this exact content without making it
     * eligible for a future automatic overwrite (see
     * UpdateApplier::acceptHtaccessCustomization()).
     */
    public function acceptHtaccess(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $stagingDir = (string) $session->get(self::KEY_STAGING, '');
        $this->applier()->acceptHtaccessCustomization($stagingDir !== '' ? $stagingDir : null);

        $session->set('lstr_flash_success', "Got it — we'll stop flagging your current public/.htaccess until it changes again.");
        return Response::redirect($baseUrl . '/admin/update');
    }

    /**
     * Returns a configured UpdateApplier instance wired to this install's base path and database.
     */
    private function applier(): UpdateApplier
    {
        return new UpdateApplier(
            $this->app->getBasePath(),
            $this->app->getDatabase(),
            null,
            (string) $this->app->getConfig()->get('enth_table_prefix', ''),
        );
    }

    /**
     * Reads the cached preflight result from session.
     *
     * Returns an empty array if no preflight has been run yet, which is
     * treated as a failing state by preflightOk().
     *
     * @return array<int,array<string,mixed>>
     */
    private function preflightFromSession(): array
    {
        $raw = (string) $this->app->getSession()->get(self::KEY_PREFLIGHT, '[]');
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Reads the cached obsolete-files preview from session (relative paths,
     * capped to 300 - the same cap applied when it was stored).
     *
     * @return array<int,string>
     */
    private function obsoletePreviewFromSession(): array
    {
        $raw = (string) $this->app->getSession()->get(self::KEY_OBSOLETE_PREVIEW, '[]');
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** Reads the cached public/.htaccess reconciliation status from session, or '' if never computed. */
    private function htaccessPreviewFromSession(): string
    {
        return (string) $this->app->getSession()->get(self::KEY_HTACCESS_PREVIEW, '');
    }

    /**
     * Returns true only if every check in the preflight result passed.
     *
     * An empty preflight array (no checks run yet) is treated as failing.
     *
     * @param array<int,array<string,mixed>> $preflight
     */
    private function preflightOk(array $preflight): bool
    {
        if ($preflight === []) {
            return false;
        }

        foreach ($preflight as $row) {
            if (($row['ok'] ?? false) !== true) {
                return false;
            }
        }

        return true;
    }

    /**
     * Removes all update wizard session keys (staging dir, version, preflight, download path, etc.).
     *
     * Also calls clearManualSession() to remove the manual-update keys.
     */
    private function clearUpdateSession(): void
    {
        $session = $this->app->getSession();
        foreach ([
            self::KEY_STAGING,
            self::KEY_VERSION,
            self::KEY_PREFLIGHT,
            self::KEY_BACKUP_CONFIRMED,
            self::KEY_DOWNLOAD,
            self::KEY_RELEASE_URL,
            self::KEY_RELEASE_TITLE,
            self::KEY_BACKUP_DOWNLOADED,
            self::KEY_OBSOLETE_PREVIEW,
            self::KEY_HTACCESS_PREVIEW,
        ] as $key) {
            $session->remove($key);
        }
        $this->clearManualSession();
    }

    /**
     * Removes the manual-update session keys (reason text, version, and download URL).
     */
    private function clearManualSession(): void
    {
        $session = $this->app->getSession();
        foreach ([self::KEY_MANUAL_REASON, self::KEY_MANUAL_VERSION, self::KEY_MANUAL_URL] as $key) {
            $session->remove($key);
        }
    }

    /**
     * Writes an error message to the Luster error log, swallowing any logging failures silently.
     */
    private function logError(string $message): void
    {
        try {
            (new ErrorLogRepo($this->app->getDatabase()))->log($message, 'error', 'AdminUpdateController');
        } catch (\Throwable) {
        }
    }
}
