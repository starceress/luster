<?php

declare(strict_types=1);

namespace Luster\Core;

use Luster\Middleware\AuthMiddleware;
use Luster\Middleware\CsrfMiddleware;
use Luster\Repositories\ErrorLogRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\MemberRepo;
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

    /** Addon keys that are currently installed (populated at boot). */
    private array $installedAddonKeys = [];

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
     * @return array{nav_pending_members: int, nav_site_url: string}
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

        $cache = [
            'nav_pending_members'  => $pending,
            'nav_site_url'         => rtrim((string) $this->config->get('site_url', ''), '/'),
            'nav_installed_addons' => $this->installedAddonKeys,
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
            $siteRepo = new \Luster\Repositories\SiteSettingsRepo($this->database);
            $dateFmt  = $siteRepo->get('date_format', 'j M Y') ?? 'j M Y';
            $timeFmt  = $siteRepo->get('time_format', 'H:i')   ?? 'H:i';
        } catch (\Throwable) {
            $dateFmt = 'j M Y';
            $timeFmt = 'H:i';
        }
        $this->view->share(['admin_date_format' => $dateFmt, 'admin_time_format' => $timeFmt]);

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
