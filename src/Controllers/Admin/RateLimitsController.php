<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Manages public-form rate limit blocks from the Settings panel. Every public
 form (join, contact, affiliate apply, etc.) tracks attempts per IP in
 lstr_rate_limits and blocks further attempts once the threshold is hit — this
 controller lets an admin clear a block early instead of waiting out the
 window, which matters most while the admin is testing their own forms.
*/

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\RateLimit;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\ActivityRepo;

/**
 * Manages rate limit blocks accessible from the Settings panel.
 *
 * The index simply redirects to the Spam Filtering panel on the Settings
 * page, which is where the active-blocks list and clear buttons live.
 */
final class RateLimitsController
{
    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /admin/rate-limits
    // -----------------------------------------------------------------------

    /**
     * Redirects to the Spam Filtering panel on the Settings page.
     */
    public function index(Request $request, array $params): Response
    {
        return Response::redirect($this->app->getBaseUrl() . '/admin/settings#panel-blacklist');
    }

    // -----------------------------------------------------------------------
    // POST /admin/rate-limits/{id}/clear
    // -----------------------------------------------------------------------

    /**
     * Clears a single rate limit block by its row ID.
     */
    public function clear(Request $request, array $params): Response
    {
        $baseUrl = $this->app->getBaseUrl();
        $session = $this->app->getSession();
        $id      = (int) ($params['id'] ?? 0);

        $rateLimit = new RateLimit($this->app->getDatabase());
        $blocks    = $rateLimit->getActiveBlocks();

        $target = null;
        foreach ($blocks as $block) {
            if ($block['id'] === $id) {
                $target = $block;
                break;
            }
        }

        if ($target === null) {
            $session->set('lstr_flash_error', 'That rate limit block was not found (it may have already expired).');
            return Response::redirect($baseUrl . '/admin/settings#panel-blacklist');
        }

        $rateLimit->clearById($id);

        $adminId = (int) $session->get('lstr_admin_id');
        (new ActivityRepo($this->app->getDatabase()))->log(
            $adminId,
            'rate_limit.clear',
            "Cleared rate limit block: {$target['identifier']} / {$target['action_label']}",
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Rate limit block cleared.');
        return Response::redirect($baseUrl . '/admin/settings#panel-blacklist');
    }

    // -----------------------------------------------------------------------
    // POST /admin/rate-limits/clear-mine
    // -----------------------------------------------------------------------

    /**
     * Clears every rate limit block for the requesting admin's own IP address,
     * across all form actions at once. A quick escape hatch for whoever is
     * sitting at the keyboard testing forms and keeps tripping their own limits.
     */
    public function clearMine(Request $request, array $params): Response
    {
        $baseUrl = $this->app->getBaseUrl();
        $session = $this->app->getSession();
        $ip      = $request->ip();

        (new RateLimit($this->app->getDatabase()))->clearAllForIdentifier($ip);

        $adminId = (int) $session->get('lstr_admin_id');
        (new ActivityRepo($this->app->getDatabase()))->log(
            $adminId,
            'rate_limit.clear_mine',
            "Cleared all rate limit blocks for IP: {$ip}",
            $ip,
        );

        $session->set('lstr_flash_success', 'Rate limits cleared for your IP address.');
        return Response::redirect($baseUrl . '/admin/settings#panel-blacklist');
    }
}
