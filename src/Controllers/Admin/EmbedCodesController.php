<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\ListingRepo;

/**
 * Read-only reference page showing copyable PHP embed snippets, and the
 * compat wrapper smoke-test renderer.
 *
 * Routes:
 *   GET  /admin/embed-codes
 *   GET  /admin/compat-test
 *   POST /admin/compat-test
 */
final class EmbedCodesController
{
    public function __construct(private readonly Application $app) {}

    public function index(Request $request, array $params): Response
    {
        $db        = $this->app->getDatabase();
        $config    = $this->app->getConfig();
        $view      = $this->app->getView();
        $session   = $this->app->getSession();
        $baseUrl   = $this->app->getBaseUrl();
        $csrfToken = $this->app->getCSRF()->token();

        $listingRepo = new ListingRepo($db, $config);
        $listings    = $listingRepo->getAll();

        // Pick a real listing ID for snippet examples.
        // Honour ?listing=ID so callers can deep-link to a specific listing.
        $requestedId = (int) $request->query('listing', 0);
        $exampleId   = 1;
        foreach ($listings as $fl) {
            if ($requestedId > 0 && $fl->id === $requestedId) {
                $exampleId = $fl->id;
                break;
            }
            if ($requestedId === 0 && !$fl->upcoming && !$fl->trouble) {
                $exampleId = $fl->id;
                break;
            }
        }

        // Absolute server path to the embed directory
        $lusterBase = defined('LUSTER_BASE') ? \LUSTER_BASE : dirname(__DIR__, 3);
        $embedPath  = rtrim(str_replace('\\', '/', (string) realpath($lusterBase . '/public/embed')), '/');

        $inner = $view->render('admin/embed-codes/index.php', [
            'embed_path'  => $embedPath,
            'example_id'  => $exampleId,
            'listings'    => $listings,
            'base_url'    => $baseUrl,
            'csrf_token'  => $csrfToken,
        ]);

        return Response::html($view->render('admin/layout.php', array_merge([
            'page_title'      => 'Embed Codes',
            'site_name'       => (string) $config->get('site_name', 'Luster'),
            'admin_username'  => (string) $session->get('lstr_admin_username', ''),
            'csrf_token'      => $csrfToken,
            'current_section' => 'embed-codes',
            'base_url'        => $baseUrl,
            'content'         => $inner,
        ], $this->app->getAdminNavData())));
    }

    // -----------------------------------------------------------------------
    // GET /admin/embed-codes/preview
    // -----------------------------------------------------------------------

    public function preview(Request $request, array $params): Response
    {
        $config     = $this->app->getConfig();
        $lusterBase = defined('LUSTER_BASE') ? \LUSTER_BASE : dirname(__DIR__, 3);
        $appUrl     = rtrim((string) $config->get('app_url', ''), '/');

        $fileMap = [
            'join'         => 'join.php',
            'members'      => 'members.php',
            'stats'        => 'stats.php',
            'affiliates'   => 'affiliates.php',
            'aff-apply'    => 'affiliate-apply.php',
            'update'       => 'update.php',
            'remove'       => 'remove.php',
            'reset'        => 'reset.php',
            'codes'        => 'codes.php',
            'codes-submit' => 'codes-submit.php',
            'contact'      => 'contact.php',
            'updates'      => 'updates.php',
            'adopt'        => 'adopt.php',
            'owned'        => 'owned.php',
            'joined'       => 'joined.php',
            'cstats'       => 'collective-stats.php',
            'aff-col'      => 'affiliates.php',
            'col-aff-apply'=> 'affiliate-apply.php',
            'random'       => 'random-listing.php',
            'latest'       => 'latest-listing.php',
            'kim'          => 'kim-signup.php',
            'col-updates'  => 'collective-updates.php',
            'col-contact'  => 'collective-contact.php',
            'wishlist'     => 'wishlist.php',
        ];

        $key = (string) $request->query('key', '');

        if (!isset($fileMap[$key])) {
            return Response::html($this->previewWrap('<p>Unknown embed type.</p>', $appUrl));
        }

        $embedFile = $lusterBase . '/public/embed/' . $fileMap[$key];

        if (!is_file($embedFile)) {
            return Response::html($this->previewWrap('<p>Embed file not found.</p>', $appUrl));
        }

        // Variables the embed files read (set only those present in query)
        $luster_return = true;

        if ($key === 'aff-col' || $key === 'col-aff-apply') {
            $luster_fanlisting_id = 0;
        } elseif ($request->query('lid') !== null) {
            $luster_fanlisting_id = max(0, (int) $request->query('lid', 1));
        }

        $q = static fn(string $k): ?string => $request->query($k);

        if ($q('per_page') !== null && $q('per_page') !== '')     { $luster_per_page            = max(1, (int) $q('per_page')); }
        if ($q('sort_by') !== null && $q('sort_by') !== '')       { $luster_sort_by             = (string) $q('sort_by'); }
        if ($q('show_search') !== null)                           { $luster_show_search         = $q('show_search') !== 'false'; }
        if ($q('reset_step') !== null && $q('reset_step') !== '') { $luster_reset_step          = (string) $q('reset_step'); }
        if ($q('browse_by_category') !== null)                    { $luster_browse_by_category  = $q('browse_by_category') === 'true'; }
        if ($q('category') !== null && $q('category') !== '')     { $luster_category            = (string) $q('category'); }
        if ($q('status') !== null && $q('status') !== '')         { $luster_status              = (string) $q('status'); }
        if ($q('hide_dropdown') !== null)                         { $luster_hide_dropdown       = $q('hide_dropdown') === 'true'; }
        if ($q('show_list') !== null)                             { $luster_show_list           = $q('show_list') === 'true'; }
        if ($q('show_cats') !== null)                             { $luster_show_cats           = $q('show_cats') === 'true'; }
        if ($q('wishlist_type') !== null && $q('wishlist_type') !== '') { $luster_wishlist_type = (string) $q('wishlist_type'); }

        try {
            ob_start();
            $result   = require $embedFile;
            $buffered = ob_get_clean() ?: '';
            $html     = is_string($result) ? $result : $buffered;
        } catch (\Throwable $e) {
            ob_end_clean();
            $html = '<p style="color:#c00;font-family:monospace">Error: '
                . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }

        return Response::html($this->previewWrap($html, $appUrl));
    }

    private function previewWrap(string $content, string $appUrl): string
    {
        $cssUrl = htmlspecialchars(
            rtrim($appUrl, '/') . '/assets/css/default/style.css',
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        return '<!DOCTYPE html><html lang="en"><head>'
            . '<meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<link rel="stylesheet" href="' . $cssUrl . '">'
            . '<style>body{font-family:system-ui,sans-serif;font-size:15px;line-height:1.6;'
            . 'padding:1.5rem;margin:0;background:#fff;color:#333}'
            . 'input,select,textarea,button{font-family:inherit}'
            . 'p{margin:.5rem 0}</style>'
            . '</head><body>'
            . $content
            . '</body></html>';
    }

    // -----------------------------------------------------------------------
    // GET/POST /admin/compat-test
    // -----------------------------------------------------------------------

    public function compatTest(Request $request, array $params): Response
    {
        $db        = $this->app->getDatabase();
        $config    = $this->app->getConfig();
        $view      = $this->app->getView();
        $session   = $this->app->getSession();
        $baseUrl   = $this->app->getBaseUrl();
        $csrfToken = $this->app->getCSRF()->token();

        $listingRepo = new ListingRepo($db, $config);
        $listings    = $listingRepo->getAll();

        $embedTypes = ['join', 'members', 'update', 'remove', 'reset', 'stats', 'affiliates'];

        $selectedListingId = (int) $request->post('listing_id', $request->query('listing_id', 0));
        $selectedType      = (string) $request->post('embed_type', $request->query('embed_type', ''));
        $output            = null;
        $error             = null;

        if ($request->method() === 'POST') {
            $this->app->getCSRF()->validate($request->post('_csrf', ''));

            if ($selectedListingId > 0 && in_array($selectedType, $embedTypes, true)) {
                $lusterBase  = defined('LUSTER_BASE') ? \LUSTER_BASE : dirname(__DIR__, 3);
                $embedFile   = $lusterBase . '/public/embed/' . $selectedType . '.php';

                if (!is_file($embedFile)) {
                    $error = 'Embed file not found: ' . htmlspecialchars($embedFile, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                } else {
                    ob_start();
                    $luster_fanlisting_id = $selectedListingId;
                    $luster_return        = true;
                    try {
                        $result   = require $embedFile;
                        $buffered = ob_get_clean();
                        $output   = is_string($result) ? $result : (is_string($buffered) ? $buffered : '');
                    } catch (\Throwable $e) {
                        ob_end_clean();
                        $error = 'Embed error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    }
                }
            }
        }

        $inner = $view->render('admin/compat-test.php', [
            'listings'            => $listings,
            'embed_types'         => $embedTypes,
            'selected_listing_id' => $selectedListingId,
            'selected_type'       => $selectedType,
            'output'              => $output,
            'error'               => $error,
            'base_url'            => $baseUrl,
            'csrf_token'          => $csrfToken,
        ]);

        return Response::html($view->render('admin/layout.php', array_merge([
            'page_title'      => 'Compat Test',
            'site_name'       => (string) $config->get('site_name', 'Luster'),
            'admin_username'  => (string) $session->get('lstr_admin_username', ''),
            'csrf_token'      => $csrfToken,
            'current_section' => 'compat-test',
            'base_url'        => $baseUrl,
            'content'         => $inner,
        ], $this->app->getAdminNavData())));
    }
}
