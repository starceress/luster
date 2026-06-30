<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Legacy redirect shim and on-demand backup handler for the system information area.
 The system details content lives on the Settings page under the System tab; GET
 /admin/sysinfo redirects there. POST /admin/sysinfo/backup creates and streams a
 complete backup ZIP (including public/uploads/) back to the browser.
*/

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\BackupService;
use Luster\Core\Request;
use Luster\Core\Response;

/**
 * Handles GET /admin/sysinfo (legacy redirect) and POST /admin/sysinfo/backup
 * (on-demand full site backup download).
 */
final class SysInfoController
{
    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /admin/sysinfo
    // -----------------------------------------------------------------------

    /**
     * Redirects to the System Details panel on the Settings page.
     */
    public function index(Request $request, array $params): Response
    {
        return Response::redirect($this->app->getBaseUrl() . '/admin/settings#tab-sysinfo');
    }

    // -----------------------------------------------------------------------
    // POST /admin/sysinfo/backup
    // -----------------------------------------------------------------------

    /**
     * Creates a complete backup ZIP (all files + database) and streams it to the
     * browser. The ZIP includes public/uploads/ so it serves as a full site backup,
     * not just an update-rollback snapshot. The temporary file is deleted after streaming.
     */
    public function backup(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        try {
            $enthPrefix = (string) $this->app->getConfig()->get('enth_table_prefix', '');
            $backup     = (new BackupService($this->app->getDatabase(), LUSTER_BASE, $enthPrefix))->createZip('complete');
            return Response::streamFile($backup['path'], $backup['filename'], 'application/zip');
        } catch (\Throwable $e) {
            $session->set('lstr_flash_error', 'Backup failed: ' . $e->getMessage());
            return Response::redirect($baseUrl . '/admin/settings#tab-sysinfo');
        }
    }
}
