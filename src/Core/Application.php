<?php

declare(strict_types=1);

namespace Luster\Core;

use Luster\Middleware\AuthMiddleware;
use Luster\Middleware\CsrfMiddleware;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\ErrorLogRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\MemberRepo;
use Luster\Repositories\SiteSettingsRepo;
use Throwable;

/**
 * Application bootstrap and service container.
 *
 * Responsibilities:
 *  - Instantiate and wire core services (Config, Database, Session, Router,
 *    View, CSRF, RateLimit)
 *  - Start the session
 *  - Load route definitions from config/routes.php
 *  - Run the middleware pipeline (auth + CSRF) before controller dispatch
 *  - Dispatch the request and send the response
 *  - Catch unhandled exceptions and render an appropriate error page
 *
 * Dependency injection for controllers:
 *   The Router resolver closure captures $this (Application), so any controller
 *   instantiated via the router receives the Application in its constructor.
 *   Controllers accept Application $app as their only constructor argument and
 *   call $app->getDatabase(), $app->getCSRF(), etc. as needed.
 *
 * Middleware pipeline (Phase 2):
 *   run()      → CsrfMiddleware → Router
 *   runAdmin() → AuthMiddleware → CsrfMiddleware → Router
 *
 *   Each middleware's process() returns null to continue or a Response to
 *   short-circuit. CsrfMiddleware throws CsrfException on token failure;
 *   handleException() converts that to a 403 response.
 */
final class Application
{
    private Config    $config;
    private Database  $database;
    private Session   $session;
    private Router    $router;
    private View      $view;
    private CSRF      $csrf;
    private RateLimit $rateLimit;

    /** Addon keys that are currently installed (populated at boot or on first use). */
    private array $installedAddonKeys = [];

    /** Whether installedAddonKeys has been loaded from the DB yet. */
    private bool $addonsLoaded = false;

    /** Discovered addon instances keyed by addon key (populated on demand). */
    private ?array $discoveredAddons = null;

    public function __construct(
        private readonly string $basePath,
        string $configPath,
    ) {
        $this->config    = new Config($configPath);
        $this->database  = new Database($this->config);
        $this->session   = new Session((string) $this->config->get('session_cookie_domain', ''));
        $this->router    = new Router();
        $this->view      = new View($this->basePath . '/templates');
        $this->csrf      = new CSRF($this->session);
        $this->rateLimit = new RateLimit($this->database);

        // Give the router a resolver so controllers receive this Application
        $this->router->setResolver(function (string $class): object {
            return new $class($this);
        });
    }

    // -----------------------------------------------------------------------
    // Service accessors
    // -----------------------------------------------------------------------

    public function getConfig(): Config
    {
        return $this->config;
    }

    public function getDatabase(): Database
    {
        return $this->database;
    }

    public function getSession(): Session
    {
        return $this->session;
    }

    public function getRouter(): Router
    {
        return $this->router;
    }

    public function getView(): View
    {
        return $this->view;
    }

    public function getCSRF(): CSRF
    {
        return $this->csrf;
    }

    public function getRateLimit(): RateLimit
    {
        return $this->rateLimit;
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    /**
     * Returns addon keys that are currently installed.
     * Available after boot() has been called.
     */
    public function getInstalledAddonKeys(): array
    {
        return $this->installedAddonKeys;
    }

    public function isAddonInstalled(string $key): bool
    {
        if (!$this->addonsLoaded) {
            $this->loadInstalledAddons();
        }
        return in_array($key, $this->installedAddonKeys, true);
    }

    /**
     * Scans src/Addons/ and returns one instance per discovered add-on.
     * Results are cached for the lifetime of the request.
     *
     * @return AddonInterface[]  keyed by addon key, sorted alphabetically
     */
    public function discoverAddons(): array
    {
        if ($this->discoveredAddons !== null) {
            return $this->discoveredAddons;
        }

        $this->discoveredAddons = [];
        $addonsDir = $this->basePath . '/src/Addons';

        if (!is_dir($addonsDir)) {
            return $this->discoveredAddons;
        }

        foreach (new \DirectoryIterator($addonsDir) as $entry) {
            if (!$entry->isDir() || $entry->isDot()) {
                continue;
            }

            $dirName = $entry->getFilename();
            $class   = "Luster\\Addons\\{$dirName}\\{$dirName}Addon";

            if (!class_exists($class)) {
                continue;
            }

            $instance = new $class();

            if (!($instance instanceof AddonInterface)) {
                continue;
            }

            $this->discoveredAddons[$instance->getKey()] = $instance;
        }

        ksort($this->discoveredAddons);
        return $this->discoveredAddons;
    }

    /**
     * Returns global nav data for admin layout templates.
     * Lazily computed once per request and cached on the instance.
     *
     * @return array{nav_pending_members: int, nav_pending_kim: int, nav_pending_adoptions: int, nav_site_url: string, nav_installed_addons: array, selected_skin: string}
     */
    public function getAdminNavData(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $pending     = 0;
        $listingRepo = new ListingRepo($this->database, $this->config);
        $memberRepo  = new MemberRepo($this->database, $this->config);

        foreach ($listingRepo->getAll() as $fl) {
            if ($fl->dbprefix === '') {
                continue;
            }
            $pending += $memberRepo->countPending($fl->dbprefix);
        }

        $pendingKim = 0;
        if (in_array('Kim', $this->installedAddonKeys, true)) {
            $kimRepo    = new \Luster\Addons\Kim\KimMemberRepo($this->database);
            $pendingKim = array_sum($kimRepo->getPendingCounts());
        }

        $pendingAdoptions = 0;
        if (in_array('Adoption', $this->installedAddonKeys, true)) {
            $pendingAdoptions = (new \Luster\Addons\Adoption\AdoptionRepo($this->database))->countNew();
        }

        $selectedSkin = '';
        try {
            $siteRepo     = new \Luster\Repositories\SiteSettingsRepo($this->database);
            $skinSettings = $siteRepo->getMany(['selected_skin', 'selected_skin_hash']);
            $selectedSkin = $skinSettings['selected_skin'] ?? '';
            $storedHash   = $skinSettings['selected_skin_hash'] ?? null;

            // Verify community skin file integrity on every request.
            if ($selectedSkin !== '' && !\Luster\Core\SkinCatalog::isBundled($selectedSkin)) {
                $skinFile    = $this->basePath . '/public/assets/css/skins/' . $selectedSkin . '.css';
                $currentHash = is_file($skinFile) ? hash_file('sha256', $skinFile) : false;

                if ($currentHash === false) {
                    // File is gone — clear before anything else so a logging failure
                    // cannot leave the tampered skin active for this request.
                    $tamperedName = $selectedSkin;
                    $selectedSkin = '';
                    $siteRepo->set('selected_skin', null);
                    $siteRepo->set('selected_skin_hash', null);
                    try {
                        (new \Luster\Repositories\ActivityRepo($this->database))->log(
                            null,
                            'security.skin_tampered',
                            'Community skin "' . $tamperedName . '" deactivated: skin file missing.',
                        );
                    } catch (\Throwable) {}
                } elseif ($storedHash === null) {
                    // Skin was saved before hash tracking existed — store the hash now
                    // so subsequent loads can detect future tampering.
                    $siteRepo->set('selected_skin_hash', $currentHash);
                } elseif ($currentHash !== $storedHash) {
                    // Hash changed since activation — clear before anything else.
                    $tamperedName = $selectedSkin;
                    $selectedSkin = '';
                    $siteRepo->set('selected_skin', null);
                    $siteRepo->set('selected_skin_hash', null);
                    try {
                        (new \Luster\Repositories\ActivityRepo($this->database))->log(
                            null,
                            'security.skin_tampered',
                            'Community skin "' . $tamperedName . '" deactivated: file hash mismatch (possible tampering).',
                        );
                    } catch (\Throwable) {}
                }
            }
        } catch (\Throwable) {}

        $cache = [
            'nav_pending_members'   => $pending,
            'nav_pending_kim'       => $pendingKim,
            'nav_pending_adoptions' => $pendingAdoptions,
            'nav_site_url'          => rtrim((string) $this->config->get('site_url', ''), '/'),
            'nav_installed_addons'  => $this->installedAddonKeys,
            'selected_skin'         => $selectedSkin,
        ];
        return $cache;
    }

    /**
     * Returns the application base URL with no trailing slash.
     * Used by controllers to build full redirect URLs (e.g. for Location headers).
     */
    public function getBaseUrl(): string
    {
        return rtrim((string) $this->config->get('app_url', ''), '/');
    }

    // -----------------------------------------------------------------------
    // Boot
    // -----------------------------------------------------------------------

    /**
     * Runs the full request lifecycle for the public front controller.
     * Middleware: CsrfMiddleware (POST token validation).
     */
    public function run(): void
    {
        $this->boot('public', [
            new CsrfMiddleware($this->csrf),
        ]);
    }

    /**
     * Runs the full request lifecycle for the admin front controller.
     * Middleware: AuthMiddleware (session check) → CsrfMiddleware (token check).
     */
    public function runAdmin(): void
    {
        // Share admin-wide template variables before route dispatch so every
        // inner template receives them without each controller needing to pass them.
        try {
            $siteRepo = new SiteSettingsRepo($this->database);
            $dateFmt  = $siteRepo->get('date_format', 'j M Y') ?? 'j M Y';
            $timeFmt  = $siteRepo->get('time_format', 'H:i')   ?? 'H:i';
        } catch (\Throwable) {
            $dateFmt = 'j M Y';
            $timeFmt = 'H:i';
        }
        $this->view->share(['admin_date_format' => $dateFmt, 'admin_time_format' => $timeFmt]);

        // Fire overdue listing notification for authenticated admins (at most once per 24h).
        // Start the session early — Session::start() is idempotent.
        $this->session->start();
        if ($this->session->has('lstr_admin_id')) {
            $this->runOverdueCheck();
        }

        $loginUrl = $this->getBaseUrl() . '/admin/login';

        $this->boot('admin', [
            new AuthMiddleware($this->session, $loginUrl, ['/admin/login', '/admin/forgot', '/admin/reset/'], $this->getDatabase()),
            new CsrfMiddleware($this->csrf),
        ]);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Sends the overdue listing notification email if enabled and the 24-hour
     * throttle has elapsed. Fires silently — any exception is swallowed so
     * a mail failure never breaks admin page loads.
     */
    private function runOverdueCheck(): void
    {
        try {
            $siteRepo = new SiteSettingsRepo($this->database);

            if ($siteRepo->get('overdue_notify_enabled', '0') !== '1') {
                return;
            }

            $lastSent = (int) ($siteRepo->get('overdue_notify_last_sent', '0') ?? '0');
            if ((time() - $lastSent) < 86400) {
                return;
            }

            $days         = max(1, (int) ($siteRepo->get('overdue_days', '55') ?? '55'));
            $listingRepo  = new ListingRepo($this->database, $this->config);
            $stale        = $listingRepo->getStaleListings($days);

            // Update the timestamp first — even if stale is empty we reset the 24h window.
            $siteRepo->set('overdue_notify_last_sent', (string) time());

            if (empty($stale)) {
                return;
            }

            $ownerEmail = (string) ($this->config->get('owner_email', '') ?? '');
            if ($ownerEmail === '') {
                return;
            }

            $siteName = (string) ($this->config->get('site_name', 'your collective') ?? '');
            $adminUrl = rtrim((string) ($this->config->get('app_url', '') ?? ''), '/') . '/admin';

            $lines = array_map(
                static fn($l) => '- ' . $l->name . ' (' . $l->url . ')',
                $stale,
            );

            $tplPath = LUSTER_BASE . '/templates/email/admin-overdue-listings.txt';
            if (is_file($tplPath)) {
                $body = str_replace(
                    ['{listings}', '{collective_name}', '{admin_url}', '{days}'],
                    [implode("\n", $lines), $siteName, $adminUrl, (string) $days],
                    (string) file_get_contents($tplPath),
                );
            } else {
                $body = "The following fanlistings on {$siteName} haven't been updated in {$days}+ days:\n\n"
                      . implode("\n", $lines)
                      . "\n\nLog in to mark them updated: {$adminUrl}";
            }

            $mailer = new Mailer($this->config);
            $mailer->send($ownerEmail, '', '[' . $siteName . '] Overdue fanlistings', $body);

            $adminId = (int) $this->session->get('lstr_admin_id', 0);
            (new ActivityRepo($this->database))->log(
                $adminId > 0 ? $adminId : null,
                'overdue_notification',
                'Overdue notification sent for ' . count($stale) . ' listing(s)',
            );
        } catch (\Throwable $e) {
            try {
                (new ErrorLogRepo($this->database))->log(
                    'Overdue notification failed: ' . $e->getMessage(),
                    'error',
                    'Application::runOverdueCheck',
                );
            } catch (\Throwable) {
                // DB unavailable too — truly silent fallback
            }
        }
    }

    /**
     * @param list<CsrfMiddleware|AuthMiddleware> $middlewares
     */
    private function boot(string $context, array $middlewares = []): void
    {
        try {
            $this->applyTimezone();
            $this->session->start();
            $this->loadInstalledAddons();
            $this->loadRoutes($context);
            $this->loadAddonRoutes();

            $request = new Request();

            foreach ($middlewares as $middleware) {
                $result = $middleware->process($request);

                if ($result instanceof Response) {
                    $result->send();
                    return;
                }
            }

            $response = $this->router->dispatch($request);
            $response->send();
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * Loads the list of installed addon keys from lstr_addons.
     * Silently skips if the table does not exist yet (pre-migration 27).
     */
    private function loadInstalledAddons(): void
    {
        $this->addonsLoaded = true;
        try {
            $rows = $this->database
                ->pdo()
                ->query('SELECT `addon_key` FROM `lstr_addons`')
                ->fetchAll(\PDO::FETCH_COLUMN);

            $this->installedAddonKeys = is_array($rows) ? $rows : [];
        } catch (\Throwable) {
            // Table doesn't exist yet — treat as no addons installed.
            $this->installedAddonKeys = [];
        }
    }

    /**
     * Calls registerRoutes() on every installed addon that has been discovered.
     */
    private function loadAddonRoutes(): void
    {
        if (empty($this->installedAddonKeys)) {
            return;
        }

        $discovered = $this->discoverAddons();

        foreach ($this->installedAddonKeys as $key) {
            if (isset($discovered[$key])) {
                $discovered[$key]->registerRoutes($this->router);
            }
        }
    }

    private function applyTimezone(): void
    {
        $tz = (string) $this->config->get('timezone', 'UTC');

        if (!date_default_timezone_set($tz)) {
            date_default_timezone_set('UTC');
        }
    }

    private function loadRoutes(string $context): void
    {
        $routesFile = $this->basePath . '/config/routes.php';

        if (!is_file($routesFile)) {
            return; // No routes defined yet — Router will return 404 for all paths
        }

        $routes = require $routesFile;

        if (is_array($routes) && isset($routes[$context]) && is_callable($routes[$context])) {
            ($routes[$context])($this->router);
        }
    }

    private function handleException(Throwable $e): void
    {
        // CSRF token failure → 403 Forbidden
        if ($e instanceof CsrfException) {
            http_response_code(403);
            echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
                . '<title>403 Forbidden</title></head><body>'
                . '<h1>403 Forbidden</h1>'
                . '<p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
                . '</body></html>';
            return;
        }

        $debug = (bool) $this->config->get('app_debug', false);

        error_log('[Luster] ' . $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());

        // Best-effort write to the DB error log — suppress any secondary failure
        // (e.g. the exception itself was a DB error) so we don't recurse.
        try {
            $repo = new ErrorLogRepo($this->database);
            $repo->log(
                $e::class . ': ' . $e->getMessage(),
                'error',
                $e->getFile() . ':' . $e->getLine(),
            );
        } catch (Throwable) {
            // Intentionally suppressed — already in error handler
        }

        if ($debug) {
            $msg   = htmlspecialchars($e->getMessage(),       ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $trace = htmlspecialchars($e->getTraceAsString(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            http_response_code(500);
            echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
                . '<title>Error</title></head><body>'
                . '<h1>' . htmlspecialchars($e::class, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1>'
                . '<p>' . $msg . '</p>'
                . '<pre style="background:#f3f3f3;padding:1rem;overflow:auto">' . $trace . '</pre>'
                . '</body></html>';
        } else {
            Response::serverError()->send();
        }
    }
}
