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
