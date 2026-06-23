<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Drives the five-step database import wizard that migrates data from legacy
 fanlisting scripts (Enthusiast, Listing Admin, FanUpdate, CodeSort, BellaBuffs,
 Fanatically) into Luster. Each step has its own GET/POST route pair: source
 selection, credential entry and connection testing, scope/options configuration,
 dry-run preview with counts and warnings, and the final execution run with a
 downloadable report. Credentials live in the session for the wizard's duration
 and are cleared after the import completes.
*/

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Import\BellaBuffsImportSource;
use Luster\Import\CodeSortImportSource;
use Luster\Import\EnthusiastImportSource;
use Luster\Import\FanaticallyImportSource;
use Luster\Import\FanUpdateImportSource;
use Luster\Import\ImageMigrator;
use Luster\Import\ListingAdminImportSource;
use Luster\Import\ImportOptions;
use Luster\Import\ImportSourceInterface;
use Luster\Import\LegacyConfigReader;
use Luster\Import\SqlDumpLoader;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\SiteSettingsRepo;
use ZipArchive;

final class ImportController extends AdminController
{
    private const SESSION_CREDS       = 'lstr_import_credentials';
    private const SESSION_SCOPE       = 'lstr_import_scope';
    private const SESSION_RESULT      = 'lstr_import_result';
    private const SESSION_ZIP_PATH    = 'lstr_import_zip_path';
    private const SESSION_DUMP_PREFIX = 'lstr_import_dump_prefix';
    private const SESSION_DUMP_TABLES = 'lstr_import_dump_tables';
    private const SESSION_FN_PREFIX   = 'lstr_import_fn_prefix';
    // BellaBuffs flat-file upload session keys
    private const SESSION_BB_APPROVED = 'lstr_import_bb_approved';
    private const SESSION_BB_PENDING  = 'lstr_import_bb_pending';

    private const SUPPORTED_SOURCES = ['enthusiast', 'listing-admin', 'fanupdate', 'codesort', 'bellabuffs', 'fanatically'];

    // -----------------------------------------------------------------------
    // Import Wizard Settings
    // GET  /admin/import/settings
    // POST /admin/import/settings
    // -----------------------------------------------------------------------

    public function settings(Request $request, array $params): Response
    {
        $db           = $this->app->getDatabase();
        $session      = $this->app->getSession();
        $baseUrl      = $this->app->getBaseUrl();
        $siteSettings = new SiteSettingsRepo($db);

        $success = '';

        if ($request->isPost()) {
            $this->app->getCSRF()->validate((string) $request->post('_csrf', ''));

            $siteSettings->set('import_show_enthusiast',    $request->post('import_show_enthusiast')    === '1' ? '1' : '0');
            $siteSettings->set('import_show_listing_admin', $request->post('import_show_listing_admin') === '1' ? '1' : '0');
            $siteSettings->set('import_show_fanupdate',     $request->post('import_show_fanupdate')     === '1' ? '1' : '0');
            $siteSettings->set('import_show_codesort',      $request->post('import_show_codesort')      === '1' ? '1' : '0');
            $siteSettings->set('import_show_bellabuffs',    $request->post('import_show_bellabuffs')    === '1' ? '1' : '0');
            $siteSettings->set('import_show_fanatically',   $request->post('import_show_fanatically')   === '1' ? '1' : '0');
            $siteSettings->set('import_allow_config_reader', $request->post('import_allow_config_reader') === '1' ? '1' : '0');

            (new ActivityRepo($db))->log(
                (int) $session->get('lstr_admin_id'),
                'settings.save',
                'Import wizard settings updated',
                $request->ip(),
            );

            $session->set('lstr_flash_success', 'Import wizard settings saved.');
            return Response::redirect($baseUrl . '/admin/import/settings');
        }

        $success = (string) ($session->get('lstr_flash_success') ?? '');
        $session->remove('lstr_flash_success');

        $storedSettings = $siteSettings->getMany([
            'import_show_enthusiast',
            'import_show_listing_admin',
            'import_show_fanupdate',
            'import_show_codesort',
            'import_show_bellabuffs',
            'import_show_fanatically',
            'import_allow_config_reader',
        ]);

        return $this->adminLayout('admin/import/import-wizard/settings.php', 'Import Wizard Settings', 'import', [
            'settings'   => $storedSettings,
            'success'    => $success,
            'base_url'   => $baseUrl,
            'csrf_token' => $this->app->getCSRF()->token(),
        ], '', [
            $this->crumb('Import Data', $this->adminUrl('/admin/import')),
            $this->crumb('Settings'),
        ]);
    }

    // -----------------------------------------------------------------------
    // Step 1 — Source selection
    // GET /admin/import
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $this->clearImportSession();
        $this->cleanupStaleZips();

        $visibility = (new SiteSettingsRepo($this->app->getDatabase()))->getMany([
            'import_show_enthusiast',
            'import_show_listing_admin',
            'import_show_fanupdate',
            'import_show_codesort',
            'import_show_bellabuffs',
            'import_show_fanatically',
        ]);

        return $this->adminLayout('admin/import/import-wizard/index.php', 'Import Data', 'import', [
            'base_url'                  => $this->app->getBaseUrl(),
            'import_show_enthusiast'    => ($visibility['import_show_enthusiast']    ?? '1') === '1',
            'import_show_listing_admin' => ($visibility['import_show_listing_admin'] ?? '1') === '1',
            'import_show_fanupdate'     => ($visibility['import_show_fanupdate']     ?? '1') === '1',
            'import_show_codesort'      => ($visibility['import_show_codesort']      ?? '1') === '1',
            'import_show_bellabuffs'    => ($visibility['import_show_bellabuffs']    ?? '1') === '1',
            'import_show_fanatically'   => ($visibility['import_show_fanatically']   ?? '1') === '1',
        ]);
    }

    // -----------------------------------------------------------------------
    // Step 2 — Connect  (backward-compat alias: /configure → /connect)
    // GET  /admin/import/{source}/connect
    // POST /admin/import/{source}/connect
    // -----------------------------------------------------------------------

    public function connect(Request $request, array $params): Response
    {
        $source = $this->resolveSource($params);
        if ($source === null) {
            return Response::redirect($this->app->getBaseUrl() . '/admin/import');
        }

        if ($source === 'bellabuffs') {
            return $this->connectBellaBuffs($request);
        }

        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        // Consume any flash error set by run() on import failure.
        $flashError = (string) ($session->get('lstr_flash_error') ?? '');
        $session->remove('lstr_flash_error');
        $error = $flashError;

        if ($request->isPost()) {
            $error    = ''; // form validation takes over; flash was for GET redirect
            $connMode = (string) $request->post('_conn_mode', 'live');

            // Was a SQL dump uploaded?
            $fileInfo = $request->file('sql_dump') ?? [];
            if (!empty($fileInfo['tmp_name']) && ($fileInfo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                // SQL dump path — process the file and redirect to scope
                return $this->handleDumpUpload($request, $source);
            }

            // Dump mode but no file submitted this request.
            if ($connMode === 'dump') {
                if ($session->get(self::SESSION_DUMP_PREFIX) !== null) {
                    // A dump was already uploaded this session — proceed to options.
                    return Response::redirect($baseUrl . "/admin/import/{$source}/options");
                }
                $error = 'Please select a .sql file to upload before continuing.';
            }

            if ($error === '') {
                // Live-DB / same-DB credential path
                $submitted = [
                    'host' => trim((string) $request->post('db_host', '')),
                    'name' => trim((string) $request->post('db_name', '')),
                    'user' => trim((string) $request->post('db_user', '')),
                    'pass' => (string) $request->post('db_pass', ''),
                ];

                // Dev bypass: empty creds + debug mode + fanatically skips connection test
                $isDebug = (bool) $this->app->getConfig()->get('app_debug', false);
                if ($isDebug && $source === 'fanatically'
                    && $submitted['host'] === '' && $submitted['name'] === '' && $submitted['user'] === '') {
                    $session->set(self::SESSION_CREDS, [
                        'fn_prefix' => trim((string) $request->post('fn_table_prefix', 'fanatic_')),
                    ]);
                    $session->remove(self::SESSION_DUMP_PREFIX);
                    $session->remove(self::SESSION_DUMP_TABLES);
                    $session->remove(self::SESSION_FN_PREFIX);
                    return Response::redirect($baseUrl . "/admin/import/{$source}/options");
                }

                [$credentials, $guardError] = $this->resolveImportCredentials($submitted);

                if ($guardError !== null) {
                    $error = $guardError;
                } else {
                    if ($source === 'fanatically') {
                        $credentials['fn_prefix'] = trim((string) $request->post('fn_table_prefix', ''));
                    }
                    $adapter   = $this->getAdapter($source);
                    $connError = $adapter->testConnection($credentials);
                    if ($connError !== null) {
                        $error = 'Connection failed: ' . $connError;
                    } else {
                        $session->set(self::SESSION_CREDS, $credentials);
                        $session->remove(self::SESSION_DUMP_PREFIX);
                        $session->remove(self::SESSION_DUMP_TABLES);
                        $session->remove(self::SESSION_FN_PREFIX);
                        return Response::redirect($baseUrl . "/admin/import/{$source}/options");
                    }
                }
            }
        }

        $savedCreds   = (array) ($session->get(self::SESSION_CREDS) ?? []);
        $savedFnPrefix = ($source === 'fanatically')
            ? (string) ($savedCreds['fn_prefix'] ?? 'fanatic_')
            : 'fanatic_';

        $enthNetworks = [];
        if ($source === 'enthusiast') {
            try {
                $stmt = $this->app->getDatabase()->pdo()->query(
                    'SELECT id, name FROM lstr_categories ORDER BY name ASC'
                );
                $enthNetworks = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
            } catch (\Throwable) {}
        }

        $siteSettings       = new SiteSettingsRepo($this->app->getDatabase());
        $configReaderEnabled = ($siteSettings->get('import_allow_config_reader') ?? '0') === '1';

        return $this->adminLayout('admin/import/import-wizard/connect.php', 'Connect to Source', 'import', [
            'source'                 => $source,
            'source_name'            => $this->sourceName($source),
            'error'                  => $error,
            'saved_creds'            => $savedCreds,
            'saved_fn_prefix'        => $savedFnPrefix,
            'enth_networks'          => $enthNetworks,
            'dump_active'            => $session->get(self::SESSION_DUMP_PREFIX) !== null,
            'config_reader_enabled'  => $configReaderEnabled,
        ], '', [
            $this->crumb('Import Data', $this->adminUrl('/admin/import')),
            $this->crumb($this->sourceName($source)),
        ]);
    }

    // -----------------------------------------------------------------------
    // configure() — backward-compatibility redirect to connect()
    // -----------------------------------------------------------------------

    public function configure(Request $request, array $params): Response
    {
        $source = $this->resolveSource($params);
        if ($source === null) {
            return Response::redirect($this->app->getBaseUrl() . '/admin/import');
        }
        return Response::redirect($this->app->getBaseUrl() . "/admin/import/{$source}/connect");
    }

    // -----------------------------------------------------------------------
    // AJAX — Test connection
    // POST /admin/import/{source}/test-connection
    // -----------------------------------------------------------------------

    public function testConnection(Request $request, array $params): Response
    {
        $source = $this->resolveSource($params);
        if ($source === null || !$request->isPost()) {
            return Response::json(['ok' => false, 'message' => 'Invalid request.']);
        }

        $submittedCredentials = [
            'host' => trim((string) $request->post('db_host', '')),
            'name' => trim((string) $request->post('db_name', '')),
            'user' => trim((string) $request->post('db_user', '')),
            'pass' => (string) $request->post('db_pass', ''),
        ];

        [$credentials, $guardError] = $this->resolveImportCredentials($submittedCredentials);
        if ($guardError !== null) {
            return Response::json(['ok' => false, 'message' => $guardError]);
        }

        if ($source === 'fanatically') {
            $credentials['fn_prefix'] = trim((string) $request->post('fn_table_prefix', ''));
        }

        $adapter = $this->getAdapter($source);
        $error   = $adapter->testConnection($credentials);

        if ($error !== null) {
            return Response::json(['ok' => false, 'message' => $error]);
        }

        try {
            $pdo    = $adapter->openConnection($credentials);
            $schema = $adapter->detectSchema($pdo);
        } catch (\Throwable $e) {
            return Response::json(['ok' => false, 'message' => $e->getMessage()]);
        }

        $fnPrefix    = $credentials['fn_prefix'] ?? 'fanatic_';
        $schemaLabel = match ($schema) {
            'enthusiast_unprefixed' => 'Enthusiast (unprefixed schema)',
            'enthusiast_enth'       => 'Enthusiast (enth_ prefix schema)',
            'fanatically_1x'        => "Fanatically 1.x (prefix: {$fnPrefix})",
            default                 => 'Unknown schema — import may not work correctly',
        };

        $registryCount = null;
        $registryTable = match ($schema) {
            'enthusiast_unprefixed' => 'owned',
            'enthusiast_enth'       => 'enth_fanlistings',
            'fanatically_1x'        => $fnPrefix . 'listings',
            default                 => null,
        };
        if ($registryTable !== null) {
            try {
                $stmt          = $pdo->query("SELECT COUNT(*) FROM `{$registryTable}`");
                $registryCount = (int) $stmt->fetchColumn();
            } catch (\Throwable) {}
        }

        $countLabel = $registryCount !== null
            ? " ({$registryCount} listing(s) found in `{$registryTable}`)"
            : '';

        return Response::json(['ok' => true, 'message' => 'Connected.', 'schema' => $schemaLabel . $countLabel]);
    }

    // -----------------------------------------------------------------------
    // AJAX — Auto-detect credentials from a legacy config.php
    // POST /admin/import/{source}/detect-config
    // -----------------------------------------------------------------------

    public function detectConfig(Request $request, array $params): Response
    {
        if (!$request->isPost()) {
            return Response::json(['ok' => false, 'message' => 'Invalid request.']);
        }

        // Gate: feature must be explicitly enabled by the admin in Settings → System.
        $siteSettings = new SiteSettingsRepo($this->app->getDatabase());
        if (($siteSettings->get('import_allow_config_reader') ?? '0') !== '1') {
            return Response::json(['ok' => false, 'message' => 'Config auto-detect is not enabled.']);
        }

        $source = $this->resolveSource($params);
        if ($source === null || $source === 'bellabuffs') {
            return Response::json(['ok' => false, 'message' => 'Not available for this source.']);
        }

        $path = trim((string) $request->post('config_path', ''));
        if ($path === '') {
            return Response::json(['ok' => false, 'message' => 'No path provided.']);
        }

        try {
            $parsed = LegacyConfigReader::parse($path);
        } catch (\RuntimeException $e) {
            return Response::json(['ok' => false, 'message' => $e->getMessage()]);
        }

        $found = count(array_filter(array_values($parsed), fn($v) => $v !== null));

        return Response::json([
            'ok'     => true,
            'found'  => $found,
            'host'   => $parsed['host'],
            'name'   => $parsed['name'],
            'user'   => $parsed['user'],
            'pass'   => $parsed['pass'],
            'prefix' => $parsed['prefix'],
        ]);
    }

    // -----------------------------------------------------------------------
    // Step 3 — Scope
    // GET  /admin/import/{source}/options
    // POST /admin/import/{source}/options
    // -----------------------------------------------------------------------

    public function scope(Request $request, array $params): Response
    {
        $source  = $this->resolveSource($params);
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        if ($source === null) {
            return Response::redirect($baseUrl . '/admin/import');
        }

        if ($source === 'bellabuffs') {
            return $this->scopeBellaBuffs($request);
        }

        // Must have gone through connect step first
        $hasCreds = is_array($session->get(self::SESSION_CREDS));
        $hasDump  = $session->get(self::SESSION_DUMP_PREFIX) !== null;
        if (!$hasCreds && !$hasDump) {
            return Response::redirect($baseUrl . "/admin/import/{$source}/connect");
        }

        if ($request->isPost()) {
            $scopePost             = $request->postAll();
            $scopePost['source']   = $source;

            // Carry over dump metadata so ImportOptions can find it
            if ($hasDump) {
                $scopePost['_dump_table_prefix']   = (string) $session->get(self::SESSION_DUMP_PREFIX, '');
                $scopePost['_dump_schema_variant'] = (string) ($session->get(self::SESSION_DUMP_TABLES)['_schema'] ?? '');
            }

            // Fanatically: carry the prefix from session into scope.
            // Live-connection mode: read from SESSION_CREDS['fn_prefix'].
            // SQL-dump mode: SESSION_CREDS is cleared; use SESSION_FN_PREFIX instead.
            if ($source === 'fanatically') {
                if ($hasDump) {
                    $scopePost['fn_table_prefix'] ??= (string) ($session->get(self::SESSION_FN_PREFIX) ?? '');
                } else {
                    $fnCreds = (array) ($session->get(self::SESSION_CREDS) ?? []);
                    $scopePost['fn_table_prefix'] ??= (string) ($fnCreds['fn_prefix'] ?? '');
                }
            }

            // Carry over pre-uploaded ZIP path if present
            $zipPath = (string) ($session->get(self::SESSION_ZIP_PATH) ?? '');
            if ($zipPath !== '' && is_file($zipPath)) {
                $scopePost['_zip_path'] = $zipPath;
            }

            $session->set(self::SESSION_SCOPE, $scopePost);
            return Response::redirect($baseUrl . "/admin/import/{$source}/preview");
        }

        $savedScope = (array) ($session->get(self::SESSION_SCOPE) ?? []);
        $zipPath    = (string) ($session->get(self::SESSION_ZIP_PATH) ?? '');
        $zipReady   = $zipPath !== '' && is_file($zipPath);

        $enthNetworks = [];
        if ($source === 'enthusiast') {
            try {
                $stmt = $this->app->getDatabase()->pdo()->query(
                    'SELECT id, name FROM lstr_categories ORDER BY name ASC'
                );
                $enthNetworks = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
            } catch (\Throwable) {}
        }

        return $this->adminLayout('admin/import/import-wizard/options.php', 'Import Options', 'import', [
            'source'        => $source,
            'source_name'   => $this->sourceName($source),
            'old'           => $savedScope,
            'zip_ready'     => $zipReady,
            'zip_url'       => $baseUrl . "/admin/import/{$source}/zip-upload",
            'enth_networks' => $enthNetworks,
        ], '', [
            $this->crumb('Import Data', $this->adminUrl('/admin/import')),
            $this->crumb($this->sourceName($source), $this->adminUrl("/admin/import/{$source}/connect")),
        ]);
    }

    // -----------------------------------------------------------------------
    // ZIP upload (called from Scope step when image_mode=zip is selected)
    // POST /admin/import/{source}/zip-upload
    // -----------------------------------------------------------------------

    public function uploadZip(Request $request, array $params): Response
    {
        $source = $this->resolveSource($params);
        if ($source === null || !$request->isPost()) {
            return Response::json(['ok' => false, 'error' => 'Invalid request.'], 400);
        }

        $session  = $this->app->getSession();
        $fileInfo = $request->file('import_zip') ?? [];

        if (empty($fileInfo['tmp_name']) || ($fileInfo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return Response::json(['ok' => false, 'error' => 'No ZIP file was uploaded.'], 422);
        }

        // 50 MB limit
        if (($fileInfo['size'] ?? 0) > 52428800) {
            return Response::json(['ok' => false, 'error' => 'ZIP file exceeds the 50 MB limit.'], 422);
        }

        $ext = strtolower((string) pathinfo((string) ($fileInfo['name'] ?? ''), PATHINFO_EXTENSION));
        if ($ext !== 'zip') {
            return Response::json(['ok' => false, 'error' => 'Only .zip files are accepted.'], 422);
        }

        $destPath = LUSTER_BASE . '/storage/cache/import_zip_' . bin2hex(random_bytes(8)) . '.zip';

        if (!move_uploaded_file($fileInfo['tmp_name'], $destPath)) {
            return Response::json(['ok' => false, 'error' => 'Could not save uploaded file.'], 500);
        }

        $zip = new ZipArchive();
        if ($zip->open($destPath) !== true) {
            @unlink($destPath);
            return Response::json(['ok' => false, 'error' => 'Could not open uploaded ZIP — file may be corrupt.'], 422);
        }
        $entryCount = $zip->count();
        $zip->close();

        // Replace any existing stale ZIP
        $oldPath = (string) ($session->get(self::SESSION_ZIP_PATH) ?? '');
        if ($oldPath !== '' && is_file($oldPath)) {
            @unlink($oldPath);
        }

        $session->set(self::SESSION_ZIP_PATH, $destPath);

        return Response::json(['ok' => true, 'files' => $entryCount]);
    }

    // -----------------------------------------------------------------------
    // Step 4 — Preview
    // POST /admin/import/{source}/preview
    // -----------------------------------------------------------------------

    public function preview(Request $request, array $params): Response
    {
        $source  = $this->resolveSource($params);
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        if ($source === null) {
            return Response::redirect($baseUrl . '/admin/import');
        }

        $dumpPrefix  = $session->get(self::SESSION_DUMP_PREFIX);
        $credentials = $session->get(self::SESSION_CREDS);
        $scopePost   = (array) ($session->get(self::SESSION_SCOPE) ?? []);

        if ($dumpPrefix === null && !is_array($credentials)) {
            return Response::redirect($baseUrl . "/admin/import/{$source}/connect");
        }

        $adapter     = $this->getAdapter($source);
        $previewData = null;
        $error       = '';
        $targetPdo   = $this->app->getDatabase()->pdo();

        // BellaBuffs: no SQL source — use target PDO, inject session file paths
        if ($source === 'bellabuffs') {
            $approvedPath = (string) ($session->get(self::SESSION_BB_APPROVED) ?? '');
            $pendingPath  = (string) ($session->get(self::SESSION_BB_PENDING)  ?? '');
            if ($approvedPath !== '') {
                $scopePost['_bb_approved_path'] = $approvedPath;
            }
            if ($pendingPath !== '') {
                $scopePost['_bb_pending_path'] = $pendingPath;
            }
        }

        $options = ImportOptions::fromPost($scopePost);

        try {
            if ($source === 'bellabuffs') {
                $previewData = $adapter->preview($targetPdo, $targetPdo, $options);
            } elseif ($dumpPrefix !== null) {
                $pdo         = $targetPdo;
                $previewData = $adapter->preview($pdo, $targetPdo, $options);
            } else {
                $guardError = $this->validateStoredCredentials($credentials);
                if ($guardError !== null) {
                    throw new \RuntimeException($guardError);
                }
                $pdo         = $adapter->openConnection($credentials);
                $previewData = $adapter->preview($pdo, $targetPdo, $options);
            }
        } catch (\Throwable $e) {
            $error = 'Could not run preview: ' . $e->getMessage();
        }

        // Build summary data for the summary card
        $isSameDb = $source !== 'bellabuffs' && $this->shouldUsePrimaryDatabase($credentials ?? []);

        $connType = 'bellabuffs';
        $connHost = '';
        $connDb   = '';

        if ($source === 'bellabuffs') {
            $bbCreds  = (array) ($session->get(self::SESSION_CREDS) ?? []);
            $connType = ($bbCreds['bb_mode'] ?? '') === 'upload' ? 'File upload' : 'Server path';
            $connHost = ($bbCreds['bb_mode'] ?? '') === 'upload'
                ? '(uploaded files)'
                : ($bbCreds['bb_path'] ?? '');
        } elseif ($dumpPrefix !== null) {
            $connType = 'SQL dump';
            $connHost = 'SQL dump';
        } elseif ($isSameDb) {
            $connType = 'Same database';
            $connHost = (string) $this->app->getConfig()->get('db_host', 'localhost');
            $connDb   = (string) $this->app->getConfig()->get('db_name', '');
        } elseif (is_array($credentials)) {
            $connType = 'Live connection';
            $connHost = (string) ($credentials['host'] ?? '');
            $connDb   = (string) ($credentials['name'] ?? '');
        }

        return $this->adminLayout('admin/import/import-wizard/preview.php', 'Preview Import', 'import', [
            'source'      => $source,
            'source_name' => $this->sourceName($source),
            'preview'     => $previewData,
            'options'     => $options,
            'error'       => $error,
            'conn_type'   => $connType,
            'conn_host'   => $connHost,
            'conn_db'     => $connDb,
        ], '', [
            $this->crumb('Import Data', $this->adminUrl('/admin/import')),
            $this->crumb($this->sourceName($source), $this->adminUrl("/admin/import/{$source}/connect")),
        ]);
    }

    // -----------------------------------------------------------------------
    // Step 5 — Run
    // POST /admin/import/{source}/run
    // -----------------------------------------------------------------------

    public function run(Request $request, array $params): Response
    {
        $source  = $this->resolveSource($params);
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        if ($source === null || !$request->isPost()) {
            return Response::redirect($baseUrl . '/admin/import');
        }

        $dumpPrefix  = $session->get(self::SESSION_DUMP_PREFIX);
        $credentials = $session->get(self::SESSION_CREDS);
        $scopePost   = (array) ($session->get(self::SESSION_SCOPE) ?? []);

        if ($dumpPrefix === null && !is_array($credentials)) {
            return Response::redirect($baseUrl . "/admin/import/{$source}/connect");
        }

        $adapter = $this->getAdapter($source);

        // BellaBuffs: inject session file paths before building ImportOptions
        if ($source === 'bellabuffs') {
            $approvedPath = (string) ($session->get(self::SESSION_BB_APPROVED) ?? '');
            $pendingPath  = (string) ($session->get(self::SESSION_BB_PENDING)  ?? '');
            if ($approvedPath !== '') {
                $scopePost['_bb_approved_path'] = $approvedPath;
            }
            if ($pendingPath !== '') {
                $scopePost['_bb_pending_path'] = $pendingPath;
            }
        }

        $options = ImportOptions::fromPost($scopePost);

        $result = null;
        $error  = '';

        try {
            if ($source === 'bellabuffs') {
                $targetPdo = $this->app->getDatabase()->pdo();
                $result    = $adapter->run($targetPdo, $targetPdo, $options);
            } elseif ($dumpPrefix !== null) {
                $targetPdo = $this->app->getDatabase()->pdo();
                $result    = $adapter->run($targetPdo, $targetPdo, $options);
            } else {
                $guardError = $this->validateStoredCredentials($credentials);
                if ($guardError !== null) {
                    throw new \RuntimeException($guardError);
                }
                $sourcePdo = $adapter->openConnection($credentials);
                $targetPdo = $this->app->getDatabase()->pdo();
                $result    = $adapter->run($sourcePdo, $targetPdo, $options);
            }

            $session->set(self::SESSION_RESULT, $result);

            try {
                $activityRepo = new ActivityRepo($this->app->getDatabase());
                $adminId      = (int) $session->get('lstr_admin_id', 0);
                $ip           = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
                $summary      = sprintf(
                    'Import from %s (%s): %d records imported, %d skipped, %d images copied.',
                    $this->sourceName($source),
                    $result->schemaVariant,
                    $result->totalImported(),
                    $result->totalSkipped(),
                    $result->totalImagesCopied(),
                );
                $activityRepo->log($adminId ?: null, 'import.run', $summary, $ip);
            } catch (\Throwable) {
                // Non-fatal: a logging failure must not undo a completed import.
            }
            $session->remove(self::SESSION_CREDS);
            $session->remove(self::SESSION_SCOPE);
            $session->remove(self::SESSION_ZIP_PATH);
            $this->cleanupBbTempFiles();
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        } finally {
            if ($dumpPrefix !== null) {
                $this->cleanupDumpTables();
                $session->remove(self::SESSION_DUMP_PREFIX);
                $session->remove(self::SESSION_DUMP_TABLES);
            }
        }

        if ($error !== '') {
            $session->set('lstr_flash_error', 'Import failed: ' . $error);
            return Response::redirect($baseUrl . "/admin/import/{$source}/connect");
        }

        return Response::redirect($baseUrl . "/admin/import/{$source}/result");
    }

    // -----------------------------------------------------------------------
    // Step 5 result page
    // GET /admin/import/{source}/result
    // -----------------------------------------------------------------------

    public function result(Request $request, array $params): Response
    {
        $source  = $this->resolveSource($params);
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        if ($source === null) {
            return Response::redirect($baseUrl . '/admin/import');
        }

        $result = $session->get(self::SESSION_RESULT);
        if ($result === null) {
            // No result data: /run either failed (and redirected to /connect) or was
            // never executed. Send the user to /connect so a flash error from a failed
            // run is visible on the connect form rather than silently dropping them on
            // the import index with no clear path to retry.
            return Response::redirect($baseUrl . "/admin/import/{$source}/connect");
        }

        return $this->adminLayout('admin/import/import-wizard/result.php', 'Import Complete', 'import', [
            'source'      => $source,
            'source_name' => $this->sourceName($source),
            'result'      => $result,
        ], '', [
            $this->crumb('Import Data', $this->adminUrl('/admin/import')),
            $this->crumb($this->sourceName($source)),
        ]);
    }

    // -----------------------------------------------------------------------
    // Report download
    // GET /admin/import/{source}/report
    // -----------------------------------------------------------------------

    public function downloadReport(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $result  = $session->get(self::SESSION_RESULT);

        if ($result === null) {
            return Response::redirect($this->app->getBaseUrl() . '/admin/import');
        }

        $text = $result->toText();
        header('Content-Type: text/plain; charset=UTF-8');
        header('Content-Disposition: attachment; filename="luster-import-report.txt"');
        header('Content-Length: ' . strlen($text));
        echo $text;
        exit();
    }

    // -----------------------------------------------------------------------
    // SQL dump upload (called from Connect step)
    // POST /admin/import/{source}/upload-dump
    // -----------------------------------------------------------------------

    public function uploadDump(Request $request, array $params): Response
    {
        $source  = $this->resolveSource($params);
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        if ($source === null || !$request->isPost()) {
            return Response::redirect($baseUrl . '/admin/import');
        }

        $result = $this->handleDumpUpload($request, $source);
        return $result;
    }

    // -----------------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------------

    /**
     * Processes a SQL dump upload and redirects to the scope step on success.
     * Used by both connect() (inline) and uploadDump() (dedicated route).
     */
    private function handleDumpUpload(Request $request, string $source): Response
    {
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $fileInfo = $request->file('sql_dump') ?? [];
        if (empty($fileInfo['tmp_name']) || ($fileInfo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $session->set('lstr_flash_error', 'No valid SQL file was uploaded.');
            return Response::redirect($baseUrl . "/admin/import/{$source}/connect");
        }

        // Clean up any leftover state from a previous run before starting fresh.
        $this->cleanupDumpTables();
        $session->remove(self::SESSION_SCOPE);
        $session->remove(self::SESSION_ZIP_PATH);
        $session->remove(self::SESSION_RESULT);

        set_time_limit(0); // dump parsing + bulk inserts can be slow on large files

        $tempPrefix = 'lstr_tmp_' . bin2hex(random_bytes(8)) . '_';
        $pdo        = $this->app->getDatabase()->pdo();
        $loader     = new SqlDumpLoader();

        try {
            $tables = $loader->load($fileInfo['tmp_name'], $pdo, $tempPrefix);
        } catch (\RuntimeException $e) {
            $session->set('lstr_flash_error', 'SQL dump error: ' . $e->getMessage());
            return Response::redirect($baseUrl . "/admin/import/{$source}/connect");
        }

        if (empty($tables)) {
            $session->set('lstr_flash_error', 'No tables could be loaded from the SQL file.');
            return Response::redirect($baseUrl . "/admin/import/{$source}/connect");
        }

        $schemaVariant = SqlDumpLoader::inferSchemaVariant($tables);

        $session->set(self::SESSION_DUMP_PREFIX, $tempPrefix);
        $tables['_schema'] = $schemaVariant;
        $session->set(self::SESSION_DUMP_TABLES, $tables);
        $session->remove(self::SESSION_CREDS);

        // For Fanatically dump uploads, save the user-supplied prefix so the scope
        // step can carry it forward (SESSION_CREDS is cleared above for dump mode).
        if ($source === 'fanatically') {
            $session->set(self::SESSION_FN_PREFIX, trim((string) $request->post('fn_table_prefix', '')));
        }

        return Response::redirect($baseUrl . "/admin/import/{$source}/options");
    }

    private function resolveSource(array $params): ?string
    {
        $s = $params['source'] ?? '';
        return in_array($s, self::SUPPORTED_SOURCES, true) ? $s : null;
    }

    private function getAdapter(string $source): ImportSourceInterface
    {
        return match ($source) {
            'enthusiast'    => new EnthusiastImportSource(),
            'listing-admin' => new ListingAdminImportSource(),
            'fanupdate'     => new FanUpdateImportSource(),
            'codesort'      => new CodeSortImportSource(),
            'bellabuffs'    => new BellaBuffsImportSource(),
            'fanatically'   => new FanaticallyImportSource(),
            default         => throw new \RuntimeException("Unknown import source: {$source}"),
        };
    }

    private function sourceName(string $source): string
    {
        return match ($source) {
            'enthusiast'    => 'Enthusiast',
            'listing-admin' => 'Listing Admin',
            'fanupdate'     => 'FanUpdate',
            'codesort'      => 'CodeSort',
            'bellabuffs'    => 'BellaBuffs',
            'fanatically'   => 'Fanatically',
            default         => $source,
        };
    }

    // -----------------------------------------------------------------------
    // BellaBuffs-specific flow
    // -----------------------------------------------------------------------

    private function connectBellaBuffs(Request $request): Response
    {
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        // Consume any flash error set by run() on import failure (same as shared connect()).
        $flashError = (string) ($session->get('lstr_flash_error') ?? '');
        $session->remove('lstr_flash_error');
        $error = $flashError;

        if ($request->isPost()) {
            // Start fresh — discard any previous BB temp files / state
            $this->cleanupBbTempFiles();
            $session->remove(self::SESSION_CREDS);
            $session->remove(self::SESSION_SCOPE);

            $connMode = (string) $request->post('bb_conn_mode', 'path');

            if ($connMode === 'upload') {
                // ── File upload mode ─────────────────────────────────────
                $membersFile = $request->file('members_file') ?? [];
                if (empty($membersFile['tmp_name']) || ($membersFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    $error = 'Please upload a members.txt file.';
                } elseif (strtolower(pathinfo((string) ($membersFile['name'] ?? ''), PATHINFO_EXTENSION)) !== 'txt') {
                    $error = 'The approved members file must be a .txt file.';
                } else {
                    $approvedDest = LUSTER_BASE . '/storage/cache/import_bb_approved_' . bin2hex(random_bytes(8)) . '.txt';
                    if (!move_uploaded_file($membersFile['tmp_name'], $approvedDest)) {
                        $error = 'Could not save uploaded members.txt.';
                    } else {
                        $session->set(self::SESSION_BB_APPROVED, $approvedDest);

                        // Optional newbies.txt upload
                        $newbiesFile = $request->file('newbies_file') ?? [];
                        if (!empty($newbiesFile['tmp_name']) && ($newbiesFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                            $ext2 = strtolower(pathinfo((string) ($newbiesFile['name'] ?? ''), PATHINFO_EXTENSION));
                            if ($ext2 === 'txt') {
                                $pendingDest = LUSTER_BASE . '/storage/cache/import_bb_pending_' . bin2hex(random_bytes(8)) . '.txt';
                                if (move_uploaded_file($newbiesFile['tmp_name'], $pendingDest)) {
                                    $session->set(self::SESSION_BB_PENDING, $pendingDest);
                                }
                            }
                        } else {
                            $session->remove(self::SESSION_BB_PENDING);
                        }

                        $session->set(self::SESSION_CREDS, ['bb_mode' => 'upload']);
                        return Response::redirect($baseUrl . '/admin/import/bellabuffs/options');
                    }
                }
            } else {
                // ── Server path mode ─────────────────────────────────────
                $folderPath = rtrim(trim((string) $request->post('bb_folder_path', '')), '/\\');
                $adapter    = new BellaBuffsImportSource();
                $connError  = $adapter->testConnection(['bb_path' => $folderPath, 'bb_mode' => 'path']);
                if ($connError !== null) {
                    $error = $connError;
                } else {
                    $session->set(self::SESSION_CREDS, [
                        'bb_mode'    => 'path',
                        'bb_path'    => $folderPath,
                    ]);
                    return Response::redirect($baseUrl . '/admin/import/bellabuffs/options');
                }
            }
        }

        return $this->adminLayout(
            'admin/import/import-wizard/connect-bellabuffs.php',
            'Connect to BellaBuffs',
            'import',
            [
                'source'      => 'bellabuffs',
                'source_name' => 'BellaBuffs',
                'error'       => $error,
                'conn_mode'   => $connMode ?? 'path',
            ],
            '',
            [
                $this->crumb('Import Data', $this->adminUrl('/admin/import')),
                $this->crumb('BellaBuffs'),
            ]
        );
    }

    private function scopeBellaBuffs(Request $request): Response
    {
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $bbCreds = $session->get(self::SESSION_CREDS);
        if (!is_array($bbCreds)) {
            return Response::redirect($baseUrl . '/admin/import/bellabuffs/connect');
        }

        $bbMode = (string) ($bbCreds['bb_mode'] ?? 'path');

        if ($request->isPost()) {
            $scopePost = $request->postAll();
            $scopePost['source']          = 'bellabuffs';
            $scopePost['bb_mode']         = $bbMode;
            $scopePost['bb_folder_path']  = (string) ($bbCreds['bb_path'] ?? '');
            $session->set(self::SESSION_SCOPE, $scopePost);
            return Response::redirect($baseUrl . '/admin/import/bellabuffs/preview');
        }

        // Fetch listings for the selector
        $listings = [];
        try {
            $stmt = $this->app->getDatabase()->pdo()->query(
                "SELECT listingid, dbtable, COALESCE(NULLIF(subject,''), title, dbtable) AS label
                 FROM owned ORDER BY label ASC"
            );
            $listings = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        } catch (\Throwable) {}

        // What optional files are present (server-path mode)
        $hasNewbies    = false;
        $hasAffiliates = false;
        $hasButtons    = false;
        if ($bbMode === 'path') {
            $folder        = rtrim((string) ($bbCreds['bb_path'] ?? ''), '/\\');
            $hasNewbies    = $folder !== '' && is_readable($folder . '/newbies.txt');
            $hasAffiliates = $folder !== '' && is_readable($folder . '/affiliates.txt');
            $hasButtons    = $folder !== '' && is_readable($folder . '/buttons.txt');
        } elseif ($bbMode === 'upload') {
            $hasNewbies = $session->get(self::SESSION_BB_PENDING) !== null;
        }

        $old = (array) ($session->get(self::SESSION_SCOPE) ?? []);

        return $this->adminLayout(
            'admin/import/import-wizard/options-bellabuffs.php',
            'Import Options',
            'import',
            [
                'source'         => 'bellabuffs',
                'source_name'    => 'BellaBuffs',
                'listings'       => $listings,
                'bb_mode'        => $bbMode,
                'has_newbies'    => $hasNewbies,
                'has_affiliates' => $hasAffiliates,
                'has_buttons'    => $hasButtons,
                'old'            => $old,
            ],
            '',
            [
                $this->crumb('Import Data', $this->adminUrl('/admin/import')),
                $this->crumb('BellaBuffs', $this->adminUrl('/admin/import/bellabuffs/connect')),
            ]
        );
    }

    private function cleanupBbTempFiles(): void
    {
        $session = $this->app->getSession();
        foreach ([self::SESSION_BB_APPROVED, self::SESSION_BB_PENDING] as $key) {
            $path = (string) ($session->get($key) ?? '');
            if ($path !== '' && is_file($path)) {
                @unlink($path);
            }
            $session->remove($key);
        }
    }

    private function clearImportSession(): void
    {
        $session = $this->app->getSession();
        $session->remove(self::SESSION_CREDS);
        $session->remove(self::SESSION_SCOPE);
        $session->remove(self::SESSION_RESULT);
        $session->remove(self::SESSION_ZIP_PATH);
        $this->cleanupDumpTables();
        $session->remove(self::SESSION_DUMP_PREFIX);
        $session->remove(self::SESSION_DUMP_TABLES);
        $this->cleanupBbTempFiles();
    }

    private function cleanupDumpTables(): void
    {
        $session = $this->app->getSession();
        $prefix  = $session->get(self::SESSION_DUMP_PREFIX);
        $tables  = (array) ($session->get(self::SESSION_DUMP_TABLES) ?? []);
        // Remove internal metadata key before passing to cleanup
        unset($tables['_schema']);

        if (is_string($prefix) && $prefix !== '' && !empty($tables)) {
            (new SqlDumpLoader())->cleanup($tables, $prefix, $this->app->getDatabase()->pdo());
        }
    }

    /** Removes stale pre-import ZIP and BellaBuffs temp files from storage/cache/ (older than 24 h). */
    private function cleanupStaleZips(): void
    {
        $cacheDir = LUSTER_BASE . '/storage/cache';
        if (!is_dir($cacheDir)) {
            return;
        }
        $cutoff  = time() - 86400;
        $patterns = [
            $cacheDir . '/import_zip_*.zip',
            $cacheDir . '/import_bb_approved_*.txt',
            $cacheDir . '/import_bb_pending_*.txt',
        ];
        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (filemtime($file) < $cutoff) {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * @param array{host:string,name:string,user:string,pass:string} $submitted
     * @return array{array{host:string,name:string,user:string,pass:string}, ?string}
     */
    private function resolveImportCredentials(array $submitted): array
    {
        if ($this->shouldUsePrimaryDatabase($submitted)) {
            return [$this->getPrimaryDatabaseCredentials(), null];
        }

        $guardError = $this->validateStoredCredentials($submitted);
        return [$submitted, $guardError];
    }

    private function shouldUsePrimaryDatabase(array $credentials): bool
    {
        return ($credentials['host'] ?? '') === ''
            && ($credentials['name'] ?? '') === ''
            && ($credentials['user'] ?? '') === ''
            && ($credentials['pass'] ?? '') === '';
    }

    private function getPrimaryDatabaseCredentials(): array
    {
        $config = $this->app->getConfig();
        return [
            'host' => (string) $config->get('db_host', 'localhost'),
            'name' => (string) $config->get('db_name', ''),
            'user' => (string) $config->get('db_user', ''),
            'pass' => (string) $config->get('db_pass', ''),
        ];
    }

    private function validateStoredCredentials(array $credentials): ?string
    {
        $host = trim((string) ($credentials['host'] ?? ''));
        if ($host === '') {
            return 'A database host is required.';
        }

        // Only reject characters that would break the DSN or indicate injection.
        // localhost and private IPs are valid and common for self-hosted installs.
        if (preg_match('/[\s;\'"]/', $host)) {
            return 'Database host contains invalid characters.';
        }

        return null;
    }
}
