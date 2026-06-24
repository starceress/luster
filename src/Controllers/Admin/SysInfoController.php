<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Legacy redirect controller for the system information page. The system details
 content now lives on the Settings page under the System tab, so this controller
 simply redirects GET /admin/sysinfo to the appropriate Settings panel anchor.
*/

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;

/**
 * Legacy redirect shim for the system information page.
 *
 * The System Details panel now lives under Settings. This controller permanently
 * redirects GET /admin/sysinfo to /admin/settings#tab-sysinfo so that any
 * bookmarked or externally linked URLs keep working.
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
}
