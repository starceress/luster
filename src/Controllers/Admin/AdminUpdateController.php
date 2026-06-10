<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\UpdateApplier;
use Luster\Core\UpdateChecker;
use Luster\Repositories\ErrorLogRepo;
use Luster\Repositories\SiteSettingsRepo;

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

    public function index(Request $request, array $params): Response
    {
        $session = $this->app->getSession();

        $success = (string) $session->get('lstr_flash_success', '');
        $error = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        $applier = $this->applier();
        $stagingDir = (string) $session->get(self::KEY_STAGING, '');
        $downloadPath = (string) $session->get(self::KEY_DOWNLOAD, '');
        $stagingValid = $stagingDir !== '' && $applier->stagingIsValid($stagingDir);

        $preflight = $this->preflightFromSession();
        if ($stagingValid) {
            $zipSize = is_file($downloadPath) ? (int) filesize($downloadPath) : 0;
            $preflight = $applier->runPreflight($zipSize);
            $session->set(self::KEY_PREFLIGHT, json_encode($preflight));
        } elseif ($stagingDir !== '') {
            $error = $error !== ''
                ? $error
                : 'The staged update could not be found. Discard it and prepare the update again.';
        }

        $siteRepo = new SiteSettingsRepo($this->app->getDatabase());
        $updateStatus = (new UpdateChecker($siteRepo))->getStatus();

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
        ], '', [
            $this->crumb('Settings', $this->adminUrl('/admin/settings#panel-system')),
        ]);
    }

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

            $session->set(self::KEY_STAGING, (string) $result['staging_dir']);
            $session->set(self::KEY_VERSION, (string) $result['version']);
            $session->set(self::KEY_PREFLIGHT, json_encode($preflight));
            $session->set(self::KEY_DOWNLOAD, (string) $result['download_path']);
            $session->set(self::KEY_RELEASE_URL, (string) ($result['release_url'] ?? ''));
            $session->set(self::KEY_RELEASE_TITLE, (string) ($result['release_title'] ?? ''));
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
        $preflight = $applier->runPreflight($zipSize);
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
            $session->set(
                'lstr_flash_success',
                'Update files copied (' . (int) $result['copied'] . ' files). Run migrations to finish.',
            );

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

    private function applier(): UpdateApplier
    {
        return new UpdateApplier($this->app->getBasePath(), $this->app->getDatabase());
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function preflightFromSession(): array
    {
        $raw = (string) $this->app->getSession()->get(self::KEY_PREFLIGHT, '[]');
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
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
        ] as $key) {
            $session->remove($key);
        }
        $this->clearManualSession();
    }

    private function clearManualSession(): void
    {
        $session = $this->app->getSession();
        foreach ([self::KEY_MANUAL_REASON, self::KEY_MANUAL_VERSION, self::KEY_MANUAL_URL] as $key) {
            $session->remove($key);
        }
    }

    private function logError(string $message): void
    {
        try {
            (new ErrorLogRepo($this->app->getDatabase()))->log($message, 'error', 'AdminUpdateController');
        } catch (\Throwable) {
        }
    }
}
