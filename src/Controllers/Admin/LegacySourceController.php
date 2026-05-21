<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Import\EnthusiastImportSource;
use Luster\Import\ListingAdminImportSource;

/**
 * Read-only browser for connected Enthusiast or Listing Admin sources.
 *
 * Never writes to the legacy database. "Import this listing" links back to
 * the existing import wizard; this controller only reads.
 *
 * Routes:
 *   GET  /admin/legacy-source                  index()
 *   POST /admin/legacy-source/connect          connect()
 *   GET  /admin/legacy-source/listings         listings()
 *   GET  /admin/legacy-source/listing/{id}     listingDetail()
 */
final class LegacySourceController
{
    private const SESS_CREDS   = 'lstr_legacy_credentials';
    private const SESS_SOURCE  = 'lstr_legacy_source';

    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /admin/legacy-source
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $session  = $this->app->getSession();
        $error    = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_error');

        return $this->layout('admin/import/legacy-source/index.php', 'Legacy Source Browser', [
            'error' => $error,
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/legacy-source/connect
    // -----------------------------------------------------------------------

    public function connect(Request $request, array $params): Response
    {
        $this->app->getCSRF()->validate($request->post('_csrf', ''));

        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();
        $source  = (string) $request->post('source', '');

        if (!in_array($source, ['enthusiast', 'listing-admin'], true)) {
            $session->set('lstr_flash_error', 'Unknown source type.');
            return Response::redirect($baseUrl . '/admin/legacy-source');
        }

        $credentials = [
            'host'   => (string) $request->post('db_host', ''),
            'name'   => (string) $request->post('db_name', ''),
            'user'   => (string) $request->post('db_user', ''),
            'pass'   => (string) $request->post('db_pass', ''),
            'prefix' => (string) $request->post('db_prefix', ''),
        ];

        $adapter = $this->makeAdapter($source);
        $error   = $adapter->testConnection($credentials);

        if ($error !== null) {
            $session->set('lstr_flash_error', 'Connection failed: ' . $error);
            return Response::redirect($baseUrl . '/admin/legacy-source');
        }

        $session->set(self::SESS_CREDS,  $credentials);
        $session->set(self::SESS_SOURCE, $source);

        return Response::redirect($baseUrl . '/admin/legacy-source/listings');
    }

    // -----------------------------------------------------------------------
    // GET /admin/legacy-source/listings
    // -----------------------------------------------------------------------

    public function listings(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $source  = (string) $session->get(self::SESS_SOURCE, '');
        $creds   = (array)  $session->get(self::SESS_CREDS,  []);

        if ($source === '' || empty($creds)) {
            return Response::redirect($this->app->getBaseUrl() . '/admin/legacy-source');
        }

        $adapter = $this->makeAdapter($source);

        try {
            $pdo    = $adapter->openConnection($creds);
            $schema = $adapter->detectSchema($pdo);
            $rows   = $this->fetchListings($pdo, $source, $schema, $creds['prefix'] ?? '');
        } catch (\Throwable $e) {
            $session->set('lstr_flash_error', 'Error reading source: ' . $e->getMessage());
            return Response::redirect($this->app->getBaseUrl() . '/admin/legacy-source');
        }

        return $this->layout('admin/import/legacy-source/listings.php', 'Legacy Source — Listings', [
            'source'   => $source,
            'schema'   => $schema,
            'listings' => $rows,
        ]);
    }

    // -----------------------------------------------------------------------
    // GET /admin/legacy-source/listing/{id}
    // -----------------------------------------------------------------------

    public function listingDetail(Request $request, array $params): Response
    {
        $session   = $this->app->getSession();
        $source    = (string) $session->get(self::SESS_SOURCE, '');
        $creds     = (array)  $session->get(self::SESS_CREDS,  []);
        $listingId = (int) ($params['id'] ?? 0);

        if ($source === '' || empty($creds) || $listingId === 0) {
            return Response::redirect($this->app->getBaseUrl() . '/admin/legacy-source');
        }

        $adapter = $this->makeAdapter($source);

        try {
            $pdo     = $adapter->openConnection($creds);
            $schema  = $adapter->detectSchema($pdo);
            $detail  = $this->fetchListingDetail($pdo, $source, $schema, $creds['prefix'] ?? '', $listingId);
        } catch (\Throwable $e) {
            $session->set('lstr_flash_error', 'Error reading source: ' . $e->getMessage());
            return Response::redirect($this->app->getBaseUrl() . '/admin/legacy-source/listings');
        }

        if ($detail === null) {
            return Response::notFound('Listing not found in source.');
        }

        return $this->layout('admin/import/legacy-source/listing.php', 'Legacy Source — ' . ($detail['title'] ?? 'Listing'), [
            'source'  => $source,
            'detail'  => $detail,
        ]);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeAdapter(string $source): EnthusiastImportSource|ListingAdminImportSource
    {
        return match ($source) {
            'listing-admin' => new ListingAdminImportSource(),
            default         => new EnthusiastImportSource(),
        };
    }

    /**
     * Fetches the listing registry from the source database.
     *
     * @return array[]  Each row: id, title, subject, url, member_count, pending_count, dbtable
     */
    private function fetchListings(\PDO $pdo, string $source, string $schema, string $prefix): array
    {
        if ($source === 'listing-admin') {
            $tp    = $prefix !== '' ? $prefix : '';
            $table = $tp . 'main';
            $stmt  = $pdo->query("SELECT id, name AS title, subject, url FROM `{$table}` ORDER BY name");
            $rows  = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $membersTable = $tp . 'members';
            foreach ($rows as &$row) {
                try {
                    $cnt = (int) $pdo->query(
                        "SELECT COUNT(*) FROM `{$membersTable}` WHERE mListingID = " . (int) $row['id']
                    )->fetchColumn();
                    $pen = (int) $pdo->query(
                        "SELECT COUNT(*) FROM `{$membersTable}` WHERE mListingID = " . (int) $row['id'] . " AND mPending = 1"
                    )->fetchColumn();
                    $row['member_count']  = $cnt;
                    $row['pending_count'] = $pen;
                } catch (\PDOException) {
                    $row['member_count']  = 0;
                    $row['pending_count'] = 0;
                }
                $row['dbtable'] = '';
            }
            unset($row);
            return $rows;
        }

        // Enthusiast
        if (str_contains($schema, '_enth')) {
            $table  = $prefix . 'enth_fanlistings';
            $idCol  = 'id';
            $nameCol = 'name';
            $stmt   = $pdo->query("SELECT {$idCol} AS id, {$nameCol} AS title, subject, url, dbprefix AS dbtable FROM `{$table}` ORDER BY {$nameCol}");
        } else {
            $table  = $prefix . 'owned';
            $stmt   = $pdo->query("SELECT listingid AS id, title, subject, url, dbtable FROM `{$table}` ORDER BY title");
        }

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $dbt = (string) ($row['dbtable'] ?? '');
            if ($dbt === '') {
                $row['member_count']  = 0;
                $row['pending_count'] = 0;
                continue;
            }
            try {
                $cnt = (int) $pdo->query("SELECT COUNT(*) FROM `{$dbt}`")->fetchColumn();
                $pen = (int) $pdo->query("SELECT COUNT(*) FROM `{$dbt}` WHERE pending = 1")->fetchColumn();
                $row['member_count']  = $cnt;
                $row['pending_count'] = $pen;
            } catch (\PDOException) {
                $row['member_count']  = 0;
                $row['pending_count'] = 0;
            }
        }
        unset($row);
        return $rows;
    }

    /**
     * Fetches detail for a single listing: member count + 5 sample members.
     *
     * @return array|null  null if listing not found
     */
    private function fetchListingDetail(\PDO $pdo, string $source, string $schema, string $prefix, int $id): ?array
    {
        if ($source === 'listing-admin') {
            $tp    = $prefix !== '' ? $prefix : '';
            $stmt  = $pdo->prepare("SELECT id, name AS title, subject, url FROM `{$tp}main` WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $listing = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($listing === false) {
                return null;
            }

            $membersTable = $tp . 'members';
            try {
                $listing['member_count']  = (int) $pdo->query("SELECT COUNT(*) FROM `{$membersTable}` WHERE mListingID = {$id}")->fetchColumn();
                $listing['pending_count'] = (int) $pdo->query("SELECT COUNT(*) FROM `{$membersTable}` WHERE mListingID = {$id} AND mPending = 1")->fetchColumn();
                $sampleStmt = $pdo->query("SELECT mEmail, mName, mCountry, mUrl, mPending FROM `{$membersTable}` WHERE mListingID = {$id} AND mPending = 0 LIMIT 5");
                $listing['samples'] = $sampleStmt->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\PDOException) {
                $listing['member_count']  = 0;
                $listing['pending_count'] = 0;
                $listing['samples']       = [];
            }
            $listing['dbtable'] = '';
            return $listing;
        }

        // Enthusiast
        if (str_contains($schema, '_enth')) {
            $table  = $prefix . 'enth_fanlistings';
            $stmt   = $pdo->prepare("SELECT id, name AS title, subject, url, dbprefix AS dbtable FROM `{$table}` WHERE id = :id");
        } else {
            $table = $prefix . 'owned';
            $stmt  = $pdo->prepare("SELECT listingid AS id, title, subject, url, dbtable FROM `{$table}` WHERE listingid = :id");
        }
        $stmt->execute(['id' => $id]);
        $listing = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($listing === false) {
            return null;
        }

        $dbt = (string) ($listing['dbtable'] ?? '');
        if ($dbt !== '') {
            try {
                $listing['member_count']  = (int) $pdo->query("SELECT COUNT(*) FROM `{$dbt}`")->fetchColumn();
                $listing['pending_count'] = (int) $pdo->query("SELECT COUNT(*) FROM `{$dbt}` WHERE pending = 1")->fetchColumn();
                $sampleStmt = $pdo->query("SELECT email, name, country, url, pending FROM `{$dbt}` WHERE pending = 0 LIMIT 5");
                $listing['samples'] = $sampleStmt->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\PDOException) {
                $listing['member_count']  = 0;
                $listing['pending_count'] = 0;
                $listing['samples']       = [];
            }
        } else {
            $listing['member_count']  = 0;
            $listing['pending_count'] = 0;
            $listing['samples']       = [];
        }

        return $listing;
    }

    private function layout(string $template, string $title, array $data): Response
    {
        $config    = $this->app->getConfig();
        $session   = $this->app->getSession();
        $view      = $this->app->getView();
        $baseUrl   = $this->app->getBaseUrl();
        $csrfToken = $this->app->getCSRF()->token();

        $inner = $view->render($template, array_merge($data, [
            'base_url'   => $baseUrl,
            'csrf_token' => $csrfToken,
        ]));

        return Response::html($view->render('admin/layout.php', array_merge([
            'page_title'      => $title,
            'site_name'       => (string) $config->get('site_name', 'Luster'),
            'admin_username'  => (string) $session->get('lstr_admin_username', ''),
            'csrf_token'      => $csrfToken,
            'current_section' => 'legacy-source',
            'base_url'        => $baseUrl,
            'content'         => $inner,
        ], $this->app->getAdminNavData())));
    }
}
