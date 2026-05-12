<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Import\CodeSortImportSource;
use Luster\Import\EnthusiastImportSource;
use Luster\Import\FanUpdateImportSource;
use Luster\Import\ImageMigrator;
use Luster\Import\ListingAdminImportSource;
use Luster\Import\ImportOptions;
use Luster\Import\ImportSourceInterface;
use Luster\Import\SqlDumpLoader;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\SiteSettingsRepo;
use ZipArchive;

/**
 * Drives the import wizard UI.
 *
 * Routes:
 *   GET  /admin/import                            — step 1: source selection
 *   GET  /admin/import/{source}/connect           — step 2: connect form
 *   POST /admin/import/{source}/connect           — validate + save credentials, redirect to scope
 *   POST /admin/import/{source}/test-connection   — AJAX; JSON response
 *   GET  /admin/import/{source}/scope             — step 3: scope form
 *   POST /admin/import/{source}/scope             — save scope options, redirect to preview
 *   POST /admin/import/{source}/zip-upload        — AJAX/form: upload pre-import ZIP
 *   POST /admin/import/{source}/preview           — step 4: dry-run summary
 *   POST /admin/import/{source}/run               — step 5: execute import
 *   GET  /admin/import/{source}/result            — step 5 result page
 *   GET  /admin/import/{source}/report            — download text report
 *
 * Credentials are stored in $_SESSION['lstr_import_credentials'] for the
 * duration of the wizard and cleared after run() completes.
 */
final class ImportController extends AdminController
{
    private const SESSION_CREDS       = 'lstr_import_credentials';
    private const SESSION_SCOPE       = 'lstr_import_scope';
    private const SESSION_RESULT      = 'lstr_import_result';
    private const SESSION_ZIP_PATH    = 'lstr_import_zip_path';
    private const SESSION_DUMP_PREFIX = 'lstr_import_dump_prefix';
    private const SESSION_DUMP_TABLES = 'lstr_import_dump_tables';

    private const SUPPORTED_SOURCES = ['enthusiast', 'listing-admin', 'fanupdate', 'codesort'];

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
        ]);

        return $this->adminLayout('admin/import/index.php', 'Import Data', 'import', [
            'base_url'                  => $this->app->getBaseUrl(),
            'import_show_enthusiast'    => ($visibility['import_show_enthusiast']    ?? '1') === '1',
            'import_show_listing_admin' => ($visibility['import_show_listing_admin'] ?? '1') === '1',
            'import_show_fanupdate'     => ($visibility['import_show_fanupdate']     ?? '1') === '1',
            'import_show_codesort'      => ($visibility['import_show_codesort']      ?? '1') === '1',
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

        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();
        $error   = '';

        if ($request->isPost()) {
            // Was a SQL dump uploaded?
            $fileInfo = $request->file('sql_dump') ?? [];
            if (!empty($fileInfo['tmp_name']) && ($fileInfo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                // SQL dump path — process the file and redirect to scope
                return $this->handleDumpUpload($request, $source);
            }

            // Live-DB / same-DB credential path
            $submitted = [
                'host' => trim((string) $request->post('db_host', '')),
                'name' => trim((string) $request->post('db_name', '')),
                'user' => trim((string) $request->post('db_user', '')),
                'pass' => (string) $request->post('db_pass', ''),
            ];

            [$credentials, $guardError] = $this->resolveImportCredentials($submitted);

            if ($guardError !== null) {
                $error = $guardError;
            } else {
                $adapter   = $this->getAdapter($source);
                $connError = $adapter->testConnection($credentials);
                if ($connError !== null) {
                    $error = 'Connection failed: ' . $connError;
                } else {
                    $session->set(self::SESSION_CREDS, $credentials);
                    $session->remove(self::SESSION_DUMP_PREFIX);
                    $session->remove(self::SESSION_DUMP_TABLES);
                    return Response::redirect($baseUrl . "/admin/import/{$source}/scope");
                }
            }
        }

        $savedCreds = (array) ($session->get(self::SESSION_CREDS) ?? []);

        $enthNetworks = [];
        if ($source === 'enthusiast') {
            try {
                $stmt = $this->app->getDatabase()->pdo()->query(
                    'SELECT id, name FROM lstr_categories WHERE parent_id IS NULL ORDER BY name ASC'
                );
                $enthNetworks = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
            } catch (\Throwable) {}
        }

        return $this->adminLayout('admin/import/connect.php', 'Connect to Source', 'import', [
            'source'        => $source,
            'source_name'   => $this->sourceName($source),
            'error'         => $error,
            'saved_creds'   => $savedCreds,
            'enth_networks' => $enthNetworks,
            'dump_active'   => $session->get(self::SESSION_DUMP_PREFIX) !== null,
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

        $schemaLabel = match ($schema) {
            'enthusiast_unprefixed' => 'Enthusiast (unprefixed schema)',
            'enthusiast_enth'       => 'Enthusiast (enth_ prefix schema)',
            default                 => 'Unknown schema — import may not work correctly',
        };

        $registryCount = null;
        $registryTable = match ($schema) {
            'enthusiast_unprefixed' => 'owned',
            'enthusiast_enth'       => 'enth_fanlistings',
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
    // Step 3 — Scope
    // GET  /admin/import/{source}/scope
    // POST /admin/import/{source}/scope
    // -----------------------------------------------------------------------

    public function scope(Request $request, array $params): Response
    {
        $source  = $this->resolveSource($params);
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        if ($source === null) {
            return Response::redirect($baseUrl . '/admin/import');
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
                    'SELECT id, name FROM lstr_categories WHERE parent_id IS NULL ORDER BY name ASC'
                );
                $enthNetworks = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
            } catch (\Throwable) {}
        }

        return $this->adminLayout('admin/import/scope.php', 'Import Options', 'import', [
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

        $options = ImportOptions::fromPost($scopePost);
        $adapter = $this->getAdapter($source);

        $previewData = null;
        $error       = '';

        try {
            if ($dumpPrefix !== null) {
                $pdo         = $this->app->getDatabase()->pdo();
                $previewData = $adapter->preview($pdo, $options);
            } else {
                $guardError = $this->validateStoredCredentials($credentials);
                if ($guardError !== null) {
                    throw new \RuntimeException($guardError);
                }
                $pdo         = $adapter->openConnection($credentials);
                $previewData = $adapter->preview($pdo, $options);
            }
        } catch (\Throwable $e) {
            $error = 'Could not run preview: ' . $e->getMessage();
        }

        // Build summary data for the summary card
        $isSameDb = $this->shouldUsePrimaryDatabase($credentials ?? []);
        $connType = $dumpPrefix !== null ? 'SQL dump'
            : ($isSameDb ? 'Same database' : 'Live connection');

        $connHost = '';
        $connDb   = '';
        if ($dumpPrefix !== null) {
            $connHost = 'SQL dump';
        } elseif ($isSameDb) {
            $connHost = (string) $this->app->getConfig()->get('db_host', 'localhost');
            $connDb   = (string) $this->app->getConfig()->get('db_name', '');
        } elseif (is_array($credentials)) {
            $connHost = (string) ($credentials['host'] ?? '');
            $connDb   = (string) ($credentials['name'] ?? '');
        }

        return $this->adminLayout('admin/import/preview.php', 'Preview Import', 'import', [
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

        $options = ImportOptions::fromPost($scopePost);
        $adapter = $this->getAdapter($source);

        $result = null;
        $error  = '';

        try {
            if ($dumpPrefix !== null) {
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

            $session->set(self::SESSION_RESULT, $result);
            $session->remove(self::SESSION_CREDS);
            $session->remove(self::SESSION_SCOPE);
            $session->remove(self::SESSION_ZIP_PATH);
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
            return Response::redirect($baseUrl . '/admin/import');
        }

        return $this->adminLayout('admin/import/result.php', 'Import Complete', 'import', [
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

        return Response::redirect($baseUrl . "/admin/import/{$source}/scope");
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
            default         => $source,
        };
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

    /** Removes stale pre-import ZIP files from storage/cache/ (older than 24 h). */
    private function cleanupStaleZips(): void
    {
        $cacheDir = LUSTER_BASE . '/storage/cache';
        if (!is_dir($cacheDir)) {
            return;
        }
        $cutoff = time() - 86400;
        foreach (glob($cacheDir . '/import_zip_*.zip') ?: [] as $file) {
            if (filemtime($file) < $cutoff) {
                @unlink($file);
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
