<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Targeted member-only import wizard. Accepts an uploaded SQL dump containing a
 single member table (Enthusiast or Listing Admin format), loads it into a
 temporary table, lets the admin select the target listing, previews the row
 counts and column mapping, then merges the members into the listing's member
 table. Separate from the main import wizard, which handles full-database
 migrations.
*/

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Import\SqlDumpLoader;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\ListingRepo;

/**
 * Standalone member-only import wizard.
 *
 * Accepts an uploaded SQL dump containing a single member table (Enthusiast or
 * Listing Admin format), loads it into temporary prefixed tables, auto-detects
 * the column mapping, shows a preview with row counts and a sample, then merges
 * members into the selected listing's member table using INSERT IGNORE to skip
 * duplicates. Separate from the full import wizard; does not touch listings,
 * categories, or any other data type.
 */
final class ImportMembersController
{
    private const SESS_LISTING_ID  = 'lstr_imembers_listing_id';
    private const SESS_TEMP_TABLES = 'lstr_imembers_temp_tables';
    private const SESS_TEMP_PREFIX = 'lstr_imembers_temp_prefix';
    private const SESS_FORMAT      = 'lstr_imembers_format';
    private const SESS_MAPPING     = 'lstr_imembers_mapping';
    private const SESS_COUNTS      = 'lstr_imembers_counts';
    private const SESS_SAMPLES     = 'lstr_imembers_samples';
    private const SESS_RESULT      = 'lstr_imembers_result';

    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /admin/import-members
    // -----------------------------------------------------------------------

    /**
     * Renders the landing page where the admin selects a target listing and uploads the SQL file.
     *
     * Only listings with a configured dbprefix (i.e., an actual member table) appear
     * in the selector. Flash errors from a failed upload step are consumed here.
     */
    public function landing(Request $request, array $params): Response
    {
        $db          = $this->app->getDatabase();
        $config      = $this->app->getConfig();
        $session     = $this->app->getSession();
        $listingRepo = new ListingRepo($db, $config);
        $listings    = array_filter($listingRepo->getAll(), static fn($l) => $l->dbprefix !== '');

        $error = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_error');

        return $this->layout('admin/import/import-members/index.php', 'Import Members', [
            'listings' => array_values($listings),
            'error'    => $error,
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/import-members/upload
    // -----------------------------------------------------------------------

    /**
     * Processes the uploaded SQL file and stores metadata for the preview step.
     *
     * Validates the listing selection and the uploaded .sql file (max 10 MB).
     * Loads the SQL into temporary tables via SqlDumpLoader, then auto-detects
     * which table is the member table and what column-name format it uses
     * (Enthusiast: email/name/country/...; Listing Admin: mEmail/mName/mCountry/...).
     * Stores counts, sample rows, and the column mapping in session for the preview
     * page, then redirects to /admin/import-members/preview.
     */
    public function upload(Request $request, array $params): Response
    {
        $this->app->getCSRF()->validate($request->post('_csrf', ''));

        $session     = $this->app->getSession();
        $db          = $this->app->getDatabase();
        $config      = $this->app->getConfig();
        $listingRepo = new ListingRepo($db, $config);
        $baseUrl     = $this->app->getBaseUrl();

        $listingId = (int) $request->post('listing_id', 0);
        $listing   = $listingId > 0 ? $listingRepo->getById($listingId) : null;

        if ($listing === null || $listing->dbprefix === '') {
            $session->set('lstr_flash_error', 'Please select a valid listing with a member table.');
            return Response::redirect($baseUrl . '/admin/import-members');
        }

        $file = $_FILES['sql_file'] ?? null;
        if (
            $file === null
            || !isset($file['tmp_name'], $file['error'])
            || $file['error'] !== UPLOAD_ERR_OK
            || !is_uploaded_file($file['tmp_name'])
        ) {
            $session->set('lstr_flash_error', 'No file was uploaded or upload failed.');
            return Response::redirect($baseUrl . '/admin/import-members');
        }

        $name = (string) ($file['name'] ?? '');
        if (!str_ends_with(strtolower($name), '.sql')) {
            $session->set('lstr_flash_error', 'Only .sql files are accepted.');
            return Response::redirect($baseUrl . '/admin/import-members');
        }

        if (($file['size'] ?? 0) > 10 * 1024 * 1024) {
            $session->set('lstr_flash_error', 'File must be 10 MB or smaller.');
            return Response::redirect($baseUrl . '/admin/import-members');
        }

        $pdo = $db->pdo();

        // Clean up any previous temp tables from this session
        $this->cleanupSession($session, $pdo);

        $tempPrefix = 'lstr_im_' . bin2hex(random_bytes(4)) . '_';
        $loader     = new SqlDumpLoader();

        try {
            $createdTables = $loader->load($file['tmp_name'], $pdo, $tempPrefix);
        } catch (\RuntimeException $e) {
            $session->set('lstr_flash_error', 'SQL load error: ' . $e->getMessage());
            return Response::redirect($baseUrl . '/admin/import-members');
        }

        if (empty($createdTables)) {
            $session->set('lstr_flash_error', 'No tables were found in the SQL dump.');
            return Response::redirect($baseUrl . '/admin/import-members');
        }

        [$memberTable, $format, $mapping] = $this->detectMembersTable($pdo, $tempPrefix, $createdTables);

        if ($memberTable === null) {
            $loader->cleanup($createdTables, $tempPrefix, $pdo);
            $session->set('lstr_flash_error', 'Could not find a recognisable member table. Expected columns: email + name (Enthusiast) or mEmail + mName (Listing Admin).');
            return Response::redirect($baseUrl . '/admin/import-members');
        }

        $fullTable = $tempPrefix . $memberTable;
        try {
            $total   = (int) $pdo->query("SELECT COUNT(*) FROM `{$fullTable}`")->fetchColumn();
            $pending = (int) $pdo->query("SELECT COUNT(*) FROM `{$fullTable}` WHERE `{$mapping['pending']}` = 1")->fetchColumn();
        } catch (\PDOException) {
            $total   = 0;
            $pending = 0;
        }

        $samples = [];
        try {
            $stmt    = $pdo->query("SELECT * FROM `{$fullTable}` LIMIT 5");
            $samples = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException) {
        }

        $session->set(self::SESS_LISTING_ID,  $listingId);
        $session->set(self::SESS_TEMP_TABLES, $createdTables);
        $session->set(self::SESS_TEMP_PREFIX, $tempPrefix);
        $session->set(self::SESS_FORMAT,      $format);
        $session->set(self::SESS_MAPPING,     array_merge($mapping, ['table' => $memberTable]));
        $session->set(self::SESS_COUNTS,      ['total' => $total, 'pending' => $pending, 'approved' => $total - $pending]);
        $session->set(self::SESS_SAMPLES,     $samples);

        return Response::redirect($baseUrl . '/admin/import-members/preview');
    }

    // -----------------------------------------------------------------------
    // GET /admin/import-members/preview
    // -----------------------------------------------------------------------

    /**
     * Renders the preview page showing detected format, column mapping, row counts, and sample rows.
     *
     * All data is read from session (stored by the upload step). If no session state
     * exists (e.g. the admin navigated here directly), redirects to the landing page.
     */
    public function preview(Request $request, array $params): Response
    {
        $session   = $this->app->getSession();
        $listingId = (int) $session->get(self::SESS_LISTING_ID, 0);

        if ($listingId === 0) {
            return Response::redirect($this->app->getBaseUrl() . '/admin/import-members');
        }

        $db          = $this->app->getDatabase();
        $config      = $this->app->getConfig();
        $listingRepo = new ListingRepo($db, $config);
        $listing     = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::redirect($this->app->getBaseUrl() . '/admin/import-members');
        }

        return $this->layout('admin/import/import-members/preview.php', 'Import Members — Preview', [
            'listing' => $listing,
            'format'  => (string) $session->get(self::SESS_FORMAT, 'unknown'),
            'mapping' => (array)  $session->get(self::SESS_MAPPING, []),
            'counts'  => (array)  $session->get(self::SESS_COUNTS,  []),
            'samples' => (array)  $session->get(self::SESS_SAMPLES, []),
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/import-members/run
    // -----------------------------------------------------------------------

    /**
     * Executes the member merge and redirects to the result page.
     *
     * Reads all rows from the detected temp table, maps columns using the stored
     * mapping, and inserts each row into the target listing's member table with
     * INSERT IGNORE (duplicates by email are skipped, not updated). Validates all
     * three table-name components against validatePrefix() before interpolating
     * into SQL. Temp tables are dropped in a finally block. Logs the outcome to
     * lstr_activity_log and stores a result summary in session for the result page.
     */
    public function run(Request $request, array $params): Response
    {
        $this->app->getCSRF()->validate($request->post('_csrf', ''));

        $session   = $this->app->getSession();
        $baseUrl   = $this->app->getBaseUrl();
        $listingId = (int) $session->get(self::SESS_LISTING_ID, 0);

        if ($listingId === 0) {
            return Response::redirect($baseUrl . '/admin/import-members');
        }

        $db          = $this->app->getDatabase();
        $config      = $this->app->getConfig();
        $listingRepo = new ListingRepo($db, $config);
        $listing     = $listingRepo->getById($listingId);

        if ($listing === null || $listing->dbprefix === '') {
            $session->set('lstr_flash_error', 'Listing not found or has no member table.');
            return Response::redirect($baseUrl . '/admin/import-members');
        }

        $pdo        = $db->pdo();
        $tempPrefix = (string) $session->get(self::SESS_TEMP_PREFIX, '');
        $mapping    = (array)  $session->get(self::SESS_MAPPING, []);
        $tempTables = (array)  $session->get(self::SESS_TEMP_TABLES, []);

        if ($tempPrefix === '' || empty($mapping)) {
            $session->set('lstr_flash_error', 'Session expired. Please restart the import.');
            return Response::redirect($baseUrl . '/admin/import-members');
        }

        $memberTable = (string) ($mapping['table'] ?? '');
        $db->validatePrefix($memberTable);
        $db->validatePrefix($listing->dbprefix);
        $db->validatePrefix($tempPrefix);

        $sourceTable = $tempPrefix . $memberTable;
        $targetTable = $listing->dbprefix;

        try {
            $rows = $pdo->query("SELECT * FROM `{$sourceTable}`")->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            (new SqlDumpLoader())->cleanup($tempTables, $tempPrefix, $pdo);
            $this->clearImportSession($session);
            $session->set('lstr_flash_error', 'Could not read source table: ' . $e->getMessage());
            return Response::redirect($baseUrl . '/admin/import-members');
        }

        $imported = 0;
        $skipped  = 0;

        try {
            $insertStmt = $pdo->prepare(
                "INSERT IGNORE INTO `{$targetTable}`
                    (email, name, country, url, pending, password, showemail, showurl, added)
                 VALUES
                    (:email, :name, :country, :url, :pending, :password, :showemail, :showurl, :added)"
            );

            foreach ($rows as $row) {
                $email = strtolower(trim((string) ($row[$mapping['email']] ?? '')));
                if ($email === '') {
                    continue;
                }

                try {
                    $insertStmt->execute([
                        'email'     => $email,
                        'name'      => trim((string) ($row[$mapping['name']] ?? '')),
                        'country'   => isset($mapping['country'])   ? trim((string) ($row[$mapping['country']]   ?? '')) : '',
                        'url'       => isset($mapping['url'])        ? trim((string) ($row[$mapping['url']]        ?? '')) : '',
                        'pending'   => isset($mapping['pending'])    ? (int) ($row[$mapping['pending']]            ?? 0)  : 0,
                        'password'  => isset($mapping['password'])   ? (string) ($row[$mapping['password']]        ?? '') : '',
                        'showemail' => isset($mapping['showemail'])  ? (int) ($row[$mapping['showemail']]           ?? 0)  : 0,
                        'showurl'   => isset($mapping['showurl'])    ? (int) ($row[$mapping['showurl']]             ?? 0)  : 0,
                        'added'     => isset($mapping['added'])      ? (string) ($row[$mapping['added']]            ?? date('Y-m-d H:i:s')) : date('Y-m-d H:i:s'),
                    ]);
                    if ($insertStmt->rowCount() > 0) {
                        $imported++;
                    } else {
                        $skipped++;
                    }
                } catch (\PDOException) {
                    $skipped++;
                }
            }
        } finally {
            (new SqlDumpLoader())->cleanup($tempTables, $tempPrefix, $pdo);
        }

        $activityRepo = new ActivityRepo($db);
        $adminUserId  = (int) $session->get('lstr_admin_id', 0) ?: null;
        $activityRepo->log(
            $adminUserId,
            'import_members',
            "Imported {$imported} members (skipped {$skipped}) into listing #{$listingId}: {$listing->title}",
            $request->ip()
        );

        $this->clearImportSession($session);

        $session->set(self::SESS_RESULT, [
            'listing_id'   => $listingId,
            'listing_title' => $listing->title,
            'imported'     => $imported,
            'skipped'      => $skipped,
        ]);

        return Response::redirect($baseUrl . '/admin/import-members/result');
    }

    // -----------------------------------------------------------------------
    // GET /admin/import-members/result
    // -----------------------------------------------------------------------

    /**
     * Renders the result page with imported and skipped member counts.
     *
     * Reads the result array from session (stored by run()) and consumes it.
     * Redirects to the landing page if no result is present.
     */
    public function result(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $result  = (array) $session->get(self::SESS_RESULT, []);
        $session->remove(self::SESS_RESULT);

        if (empty($result)) {
            return Response::redirect($this->app->getBaseUrl() . '/admin/import-members');
        }

        return $this->layout('admin/import/import-members/result.php', 'Import Members — Done', [
            'result' => $result,
        ]);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Scans temp tables to find one with recognisable member columns and returns the mapping.
     *
     * Checks for Listing Admin columns (mEmail + mName) first, then Enthusiast columns
     * (email + name). Returns the table name, the detected format string, and a mapping
     * from Luster column names to source column names. Returns null as the table name
     * if no recognisable member table is found.
     *
     * @param  string[] $tables Original table names (without prefix)
     * @return array{0: string|null, 1: string, 2: array<string,string>}
     */
    private function detectMembersTable(\PDO $pdo, string $tempPrefix, array $tables): array
    {
        foreach ($tables as $tbl) {
            $full = $tempPrefix . $tbl;
            try {
                $cols = array_column(
                    $pdo->query("DESCRIBE `{$full}`")->fetchAll(\PDO::FETCH_ASSOC),
                    'Field'
                );
            } catch (\PDOException) {
                continue;
            }

            if (in_array('mEmail', $cols, true) && in_array('mName', $cols, true)) {
                return [$tbl, 'listing_admin', [
                    'email'     => 'mEmail',
                    'name'      => 'mName',
                    'country'   => in_array('mCountry',  $cols, true) ? 'mCountry'  : null,
                    'url'       => in_array('mUrl',       $cols, true) ? 'mUrl'      : null,
                    'pending'   => in_array('mPending',   $cols, true) ? 'mPending'  : null,
                    'password'  => in_array('mPassword',  $cols, true) ? 'mPassword' : null,
                    'showemail' => in_array('mShowEmail', $cols, true) ? 'mShowEmail': null,
                    'showurl'   => in_array('mShowUrl',   $cols, true) ? 'mShowUrl'  : null,
                    'added'     => in_array('mAdded',     $cols, true) ? 'mAdded'    : null,
                ]];
            }

            if (in_array('email', $cols, true) && in_array('name', $cols, true)) {
                return [$tbl, 'enthusiast', [
                    'email'     => 'email',
                    'name'      => 'name',
                    'country'   => in_array('country',   $cols, true) ? 'country'  : null,
                    'url'       => in_array('url',        $cols, true) ? 'url'      : null,
                    'pending'   => in_array('pending',    $cols, true) ? 'pending'  : null,
                    'password'  => in_array('password',   $cols, true) ? 'password' : null,
                    'showemail' => in_array('showemail',  $cols, true) ? 'showemail': null,
                    'showurl'   => in_array('showurl',    $cols, true) ? 'showurl'  : null,
                    'added'     => in_array('added',      $cols, true) ? 'added'    : null,
                ]];
            }
        }

        return [null, 'unknown', []];
    }

    /**
     * Clears all member-import session keys (everything except the result key).
     */
    private function clearImportSession(\Luster\Core\Session $session): void
    {
        foreach ([self::SESS_LISTING_ID, self::SESS_TEMP_TABLES, self::SESS_TEMP_PREFIX,
                  self::SESS_FORMAT, self::SESS_MAPPING, self::SESS_COUNTS, self::SESS_SAMPLES] as $key) {
            $session->remove($key);
        }
    }

    /**
     * Drops any leftover temp tables from a previous session run before starting a new one.
     */
    private function cleanupSession(\Luster\Core\Session $session, \PDO $pdo): void
    {
        $tables = (array)  $session->get(self::SESS_TEMP_TABLES, []);
        $prefix = (string) $session->get(self::SESS_TEMP_PREFIX, '');
        if (!empty($tables) && $prefix !== '') {
            (new SqlDumpLoader())->cleanup($tables, $prefix, $pdo);
        }
    }

    /**
     * Renders a page inside the admin layout shell, injecting base_url and csrf_token.
     *
     * Used instead of adminLayout() because this controller does not extend AdminController.
     */
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
            'current_section' => 'import-members',
            'base_url'        => $baseUrl,
            'content'         => $inner,
        ], $this->app->getAdminNavData())));
    }
}
