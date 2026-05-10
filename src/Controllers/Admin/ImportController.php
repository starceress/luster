<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\NetworkAddressGuard;
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
use ZipArchive;

/**
 * Drives the import wizard UI (Phase 1: Enthusiast; Phase 2: Listing Admin; Phase 3: FanUpdate + CodeSort).
 *
 * Routes:
 *   GET  /admin/import                            — step 1: source selection
 *   GET  /admin/import/{source}/configure         — step 2: configure form
 *   POST /admin/import/{source}/configure         — save credentials to session; redirect to preview
 *   POST /admin/import/{source}/test-connection   — AJAX; JSON response
 *   POST /admin/import/{source}/preview           — step 3: dry-run summary
 *   POST /admin/import/{source}/run               — step 4: execute import
 *   GET  /admin/import/{source}/result            — step 4 result page (redirect after run)
 *   POST /admin/import/{source}/upload-images     — step 5 (optional): ZIP image upload
 *
 * Credentials are stored in $_SESSION['lstr_import_credentials'] for the
 * duration of the wizard and cleared after run() completes.
 */
final class ImportController extends AdminController
{
    private const SESSION_CREDS      = 'lstr_import_credentials';
    private const SESSION_OPTIONS    = 'lstr_import_options';
    private const SESSION_RESULT     = 'lstr_import_result';
    private const SESSION_IMAGE_MAP  = 'lstr_import_image_map';
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

        return $this->adminLayout('admin/import/index.php', 'Import Data', 'import', [
            'base_url' => $this->app->getBaseUrl(),
        ]);
    }

    // -----------------------------------------------------------------------
    // Step 2 — Configure
    // GET  /admin/import/{source}/configure
    // POST /admin/import/{source}/configure
    // -----------------------------------------------------------------------

    public function configure(Request $request, array $params): Response
    {
        $source = $this->resolveSource($params);
        if ($source === null) {
            return Response::redirect($this->app->getBaseUrl() . '/admin/import');
        }

        $session  = $this->app->getSession();
        $baseUrl  = $this->app->getBaseUrl();
        $error    = '';

        if ($request->isPost()) {
            // CSRF validated globally by CsrfMiddleware

            // Persist credentials in session (never written to DB)
            $submittedCredentials = [
                'host' => trim((string) $request->post('db_host', '')),
                'name' => trim((string) $request->post('db_name', '')),
                'user' => trim((string) $request->post('db_user', '')),
                'pass' => (string) $request->post('db_pass', ''),
            ];

            [$credentials, $guardError] = $this->resolveImportCredentials($submittedCredentials);

            if ($guardError !== null) {
                $error = $guardError;
            } else {
                // Quick connection test
                $adapter = $this->getAdapter($source);
                $connError = $adapter->testConnection($credentials);
                if ($connError !== null) {
                    $error = 'Connection failed: ' . $connError;
                } else {
                    $session->set(self::SESSION_CREDS, $credentials);

                    // Stash the raw POST for options (reconstructed in preview/run)
                    $postData             = $request->postAll();
                    $postData['source']   = $source;
                    $session->set(self::SESSION_OPTIONS, $postData);

                    return Response::redirect($baseUrl . "/admin/import/{$source}/preview");
                }
            }
        }

        // Repopulate from session if returning
        $savedPost = (array) ($session->get(self::SESSION_OPTIONS) ?? []);

        // For Enthusiast: load Luster's top-level categories so the user can
        // optionally place imported categories under an existing network parent.
        $enthNetworks = [];
        if ($source === 'enthusiast') {
            try {
                $stmt = $this->app->getDatabase()->pdo()->query(
                    'SELECT id, name FROM lstr_categories WHERE parent_id IS NULL ORDER BY name ASC'
                );
                $enthNetworks = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
            } catch (\Throwable) {}
        }

        return $this->adminLayout('admin/import/configure.php', 'Configure Import', 'import', [
            'source'        => $source,
            'source_name'   => $this->sourceName($source),
            'error'         => $error,
            'old'           => $savedPost,
            'enth_networks' => $enthNetworks,
        ], '', [
            $this->crumb('Import Data', $this->adminUrl('/admin/import')),
            $this->crumb($this->sourceName($source)),
        ]);
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

        // CSRF validated globally by CsrfMiddleware

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

        // Detect schema variant
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

        // Count rows in the main registry table so the user can confirm they are
        // connected to the correct database (not e.g. Luster's own DB by mistake).
        $registryCount = null;
        $registryTable = match ($schema) {
            'enthusiast_unprefixed' => 'owned',
            'enthusiast_enth'       => 'enth_fanlistings',
            default                 => null,
        };
        if ($registryTable !== null) {
            try {
                $stmt = $pdo->query("SELECT COUNT(*) FROM `{$registryTable}`");
                $registryCount = (int) $stmt->fetchColumn();
            } catch (\Throwable) {
                // Non-fatal — just omit the count
            }
        }

        $countLabel = $registryCount !== null
            ? " ({$registryCount} listing(s) found in `{$registryTable}`)"
            : '';

        return Response::json(['ok' => true, 'message' => 'Connected.', 'schema' => $schemaLabel . $countLabel]);
    }

    // -----------------------------------------------------------------------
    // Step 3 — Preview
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
        $optionsPost = (array) ($session->get(self::SESSION_OPTIONS) ?? []);

        if ($dumpPrefix === null && !is_array($credentials)) {
            return Response::redirect($baseUrl . "/admin/import/{$source}/configure");
        }

        $options = ImportOptions::fromPost($optionsPost);
        $adapter = $this->getAdapter($source);

        $previewData = null;
        $error       = '';

        try {
            if ($dumpPrefix !== null) {
                // Dump mode: source is Luster's own DB; temp tables already loaded
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

        return $this->adminLayout('admin/import/preview.php', 'Preview Import', 'import', [
            'source'      => $source,
            'source_name' => $this->sourceName($source),
            'preview'     => $previewData,
            'options'     => $options,
            'error'       => $error,
        ], '', [
            $this->crumb('Import Data', $this->adminUrl('/admin/import')),
            $this->crumb($this->sourceName($source), $this->adminUrl('/admin/import/' . $source . '/configure')),
        ]);
    }

    // -----------------------------------------------------------------------
    // Step 4 — Run
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

        // CSRF validated globally by CsrfMiddleware

        $dumpPrefix  = $session->get(self::SESSION_DUMP_PREFIX);
        $credentials = $session->get(self::SESSION_CREDS);
        $optionsPost = (array) ($session->get(self::SESSION_OPTIONS) ?? []);

        if ($dumpPrefix === null && !is_array($credentials)) {
            return Response::redirect($baseUrl . "/admin/import/{$source}/configure");
        }

        $options = ImportOptions::fromPost($optionsPost);
        $adapter = $this->getAdapter($source);

        $result = null;
        $error  = '';

        try {
            if ($dumpPrefix !== null) {
                // Dump mode: source and target are the same Luster DB
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

            // Log to activity log
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

            // Store result in session for the result page; clear credentials
            $session->set(self::SESSION_RESULT, $result);
            $session->remove(self::SESSION_CREDS);

            // Keep options if ZIP upload still needed
            if ($options->imageMode !== 'zip') {
                $session->remove(self::SESSION_OPTIONS);
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        } finally {
            // Always drop temp tables after run (success or failure)
            if ($dumpPrefix !== null) {
                $this->cleanupDumpTables();
                $session->remove(self::SESSION_DUMP_PREFIX);
                $session->remove(self::SESSION_DUMP_TABLES);
            }
        }

        if ($error !== '') {
            $session->set('lstr_flash_error', 'Import failed: ' . $error);
            return Response::redirect($baseUrl . "/admin/import/{$source}/configure");
        }

        return Response::redirect($baseUrl . "/admin/import/{$source}/result");
    }

    // -----------------------------------------------------------------------
    // Step 4 result page
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

        $optionsPost = (array) ($session->get(self::SESSION_OPTIONS) ?? []);
        $options     = ImportOptions::fromPost($optionsPost);
        $hasImageMap = !empty($session->get(self::SESSION_IMAGE_MAP));

        return $this->adminLayout('admin/import/result.php', 'Import Result', 'import', [
            'source'        => $source,
            'source_name'   => $this->sourceName($source),
            'result'        => $result,
            'show_zip_form' => $options->imageMode === 'zip' && $hasImageMap,
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
    // Step 5 (optional) — ZIP image upload
    // POST /admin/import/{source}/upload-images
    // -----------------------------------------------------------------------

    public function uploadImages(Request $request, array $params): Response
    {
        $source  = $this->resolveSource($params);
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        if ($source === null || !$request->isPost()) {
            return Response::redirect($baseUrl . '/admin/import');
        }

        // CSRF validated globally by CsrfMiddleware

        $imageMap = $session->get(self::SESSION_IMAGE_MAP);
        if (!is_array($imageMap) || empty($imageMap)) {
            $session->set('lstr_flash_error', 'Image map has expired. Please re-run the import to regenerate it.');
            return Response::redirect($baseUrl . "/admin/import/{$source}/result");
        }

        $fileInfo = $request->file('zip_file') ?? [];
        if (empty($fileInfo['tmp_name']) || $fileInfo['error'] !== UPLOAD_ERR_OK) {
            $session->set('lstr_flash_error', 'No valid ZIP file was uploaded.');
            return Response::redirect($baseUrl . "/admin/import/{$source}/result");
        }

        // Max 50 MB
        if ($fileInfo['size'] > 52428800) {
            $session->set('lstr_flash_error', 'ZIP file exceeds the 50 MB limit.');
            return Response::redirect($baseUrl . "/admin/import/{$source}/result");
        }

        $zip = new ZipArchive();
        if ($zip->open($fileInfo['tmp_name']) !== true) {
            $session->set('lstr_flash_error', 'Could not open the uploaded ZIP file.');
            return Response::redirect($baseUrl . "/admin/import/{$source}/result");
        }

        $migrator = new ImageMigrator(dirname(__DIR__, 3) . '/public');
        $targetPdo = $this->app->getDatabase()->pdo();

        $matched   = 0;
        $unmatched = 0;
        $errors    = [];

        for ($i = 0, $count = $zip->count(); $i < $count; $i++) {
            $entryName = $zip->getNameIndex($i);
            $basename  = basename($entryName);

            if (!isset($imageMap[$basename])) {
                $unmatched++;
                continue;
            }

            $entry      = $imageMap[$basename];
            $subDir     = (string) $entry['type'];
            $prefix     = (string) $entry['prefix'];
            $newFilename = $migrator->matchFromZipEntry($zip, $i, $subDir, $prefix);

            if ($newFilename === null) {
                $errors[]  = "Could not extract or validate image: {$basename}";
                $unmatched++;
                continue;
            }

            // Update the DB record with the new filename
            try {
                if (isset($entry['table'])) {
                    // Generic: table + key column + value column
                    $table  = (string) $entry['table'];
                    $keyCol = (string) $entry['key'];
                    $valCol = (string) $entry['column'];
                    $keyVal = $entry['fanlisting_id'] ?? $entry['record_id'];

                    // Whitelist identifiers before interpolation — defence-in-depth against
                    // session-manipulation attacks (values are hardcoded by import adapters,
                    // but we validate here regardless).
                    static $allowedTables  = ['lstr_fanlisting_settings'];
                    static $allowedColumns = ['image', 'fanlisting_id', 'id'];
                    if (!in_array($table, $allowedTables, true)
                        || !in_array($keyCol, $allowedColumns, true)
                        || !in_array($valCol, $allowedColumns, true)
                    ) {
                        $errors[] = "Skipped {$basename}: image map contains an unexpected table or column reference.";
                        $unmatched++;
                        continue;
                    }
                    $stmt   = $targetPdo->prepare(
                        "UPDATE `{$table}` SET `{$valCol}` = :v WHERE `{$keyCol}` = :k"
                    );
                    $stmt->execute(['v' => $newFilename, 'k' => $keyVal]);
                } else {
                    $recordId = (int) $entry['record_id'];
                    $tblMap   = ['affiliates' => 'lstr_affiliates', 'joined' => 'lstr_joined'];
                    $tbl      = $tblMap[$subDir] ?? null;
                    if ($tbl !== null) {
                        $stmt = $targetPdo->prepare("UPDATE `{$tbl}` SET image = :img WHERE id = :id");
                        $stmt->execute(['img' => $newFilename, 'id' => $recordId]);
                    }
                }
                $matched++;
                unset($imageMap[$basename]);
            } catch (\Throwable $e) {
                $errors[] = "DB update failed for {$basename}: " . $e->getMessage();
            }
        }

        $zip->close();

        // Update the image map in session (remove matched entries)
        if (empty($imageMap)) {
            $session->remove(self::SESSION_IMAGE_MAP);
        } else {
            $session->set(self::SESSION_IMAGE_MAP, $imageMap);
        }

        $msg = "ZIP upload complete: {$matched} image(s) matched and applied, {$unmatched} unmatched.";
        if (!empty($errors)) {
            $msg .= ' Errors: ' . implode('; ', array_slice($errors, 0, 5));
        }
        $session->set('lstr_flash_success', $msg);

        return Response::redirect($baseUrl . "/admin/import/{$source}/result");
    }

    // -----------------------------------------------------------------------
    // SQL dump upload
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

        // CSRF validated globally by CsrfMiddleware

        $fileInfo = $request->file('sql_dump') ?? [];
        if (empty($fileInfo['tmp_name']) || ($fileInfo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $session->set('lstr_flash_error', 'No valid SQL file was uploaded.');
            return Response::redirect($baseUrl . "/admin/import/{$source}/configure");
        }

        $tempPrefix = 'lstr_tmp_' . bin2hex(random_bytes(8)) . '_';
        $pdo        = $this->app->getDatabase()->pdo();
        $loader     = new SqlDumpLoader();

        try {
            $tables = $loader->load($fileInfo['tmp_name'], $pdo, $tempPrefix);
        } catch (\RuntimeException $e) {
            $session->set('lstr_flash_error', 'SQL dump error: ' . $e->getMessage());
            return Response::redirect($baseUrl . "/admin/import/{$source}/configure");
        }

        if (empty($tables)) {
            $session->set('lstr_flash_error', 'No tables could be loaded from the SQL file.');
            return Response::redirect($baseUrl . "/admin/import/{$source}/configure");
        }

        $schemaVariant = SqlDumpLoader::inferSchemaVariant($tables);

        // Store temp prefix + table list for cleanup after run()
        $session->set(self::SESSION_DUMP_PREFIX, $tempPrefix);
        $session->set(self::SESSION_DUMP_TABLES, $tables);

        // Inject dump context into the stored POST data so ImportOptions picks it up
        $postData                      = $request->postAll();
        $postData['source']            = $source;
        $postData['_dump_table_prefix']   = $tempPrefix;
        $postData['_dump_schema_variant'] = $schemaVariant;
        $session->set(self::SESSION_OPTIONS, $postData);
        $session->remove(self::SESSION_CREDS); // dump mode never uses live credentials

        return Response::redirect($baseUrl . "/admin/import/{$source}/preview");
    }

    // -----------------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------------

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
        $session->remove(self::SESSION_OPTIONS);
        $session->remove(self::SESSION_RESULT);
        $session->remove(self::SESSION_IMAGE_MAP);
        $this->cleanupDumpTables();
        $session->remove(self::SESSION_DUMP_PREFIX);
        $session->remove(self::SESSION_DUMP_TABLES);
    }

    private function cleanupDumpTables(): void
    {
        $session = $this->app->getSession();
        $prefix  = $session->get(self::SESSION_DUMP_PREFIX);
        $tables  = (array) ($session->get(self::SESSION_DUMP_TABLES) ?? []);

        if (is_string($prefix) && $prefix !== '' && !empty($tables)) {
            (new SqlDumpLoader())->cleanup($tables, $prefix, $this->app->getDatabase()->pdo());
        }
    }

    /**
     * @param array{host:string,name:string,user:string,pass:string} $submittedCredentials
     * @return array{array{host:string,name:string,user:string,pass:string}, ?string}
     */
    private function resolveImportCredentials(array $submittedCredentials): array
    {
        if ($this->shouldUsePrimaryDatabase($submittedCredentials)) {
            return [$this->getPrimaryDatabaseCredentials(), null];
        }

        $guardError = $this->validateStoredCredentials($submittedCredentials);

        return [$submittedCredentials, $guardError];
    }

    /**
     * @param array{host:string,name:string,user:string,pass:string} $credentials
     */
    private function shouldUsePrimaryDatabase(array $credentials): bool
    {
        return $credentials['host'] === ''
            && $credentials['name'] === ''
            && $credentials['user'] === ''
            && $credentials['pass'] === '';
    }

    /**
     * @return array{host:string,name:string,user:string,pass:string}
     */
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

    /**
     * @param array{host:string,name:string,user:string,pass:string} $credentials
     */
    private function validateStoredCredentials(array $credentials): ?string
    {
        $host = trim((string) ($credentials['host'] ?? ''));
        if ($host === '') {
            return 'A database host is required.';
        }

        return NetworkAddressGuard::validatePublicHost($host);
    }
}
