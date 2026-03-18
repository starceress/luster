<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;

/**
 * Displays system information for the current installation.
 *
 * Routes:
 *   GET /admin/sysinfo
 */
final class SysInfoController
{
    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /admin/sysinfo
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        return Response::redirect($this->app->getBaseUrl() . '/admin/settings#tab-sysinfo');
    }
}
