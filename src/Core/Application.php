<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 The main application bootstrap and service container. This class wires up
 all core services (database, session, router, views, CSRF, rate limiting),
 loads route definitions, and runs the middleware pipeline before dispatching
 each request to the appropriate controller. It also catches unhandled
 exceptions and renders error pages. Think of it as the central hub that
 coordinates everything when a request comes in.
*/

declare(strict_types=1);

namespace Luster\Core;

use Luster\Middleware\AuthMiddleware;
use Luster\Middleware\CsrfMiddleware;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\ErrorLogRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\MemberRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\FormFieldBuilder;
use Luster\Support\MemberAddressLock;
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
 * Middleware pipeline:
 *   run()      → CsrfMiddleware → Router
 *   runAdmin() → AuthMiddleware → CsrfMiddleware → Router
 *
 *   Each middleware's process() returns null to continue or a Response to
 *   short-circuit. CsrfMiddleware throws CsrfException on token failure;
 *   handleException() converts that to a 403 response.
 */
final class Application
{
    private Config          $config;
    private Database        $database;
    private Session         $session;
    private Router          $router;
    private View            $view;
    private CSRF            $csrf;
    private RateLimit       $rateLimit;
    private FormFieldBuilder $formFieldBuilder;
    private ?MemberAddressLock $memberAddressLock = null;

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
        $this->config          = new Config($configPath);
        $this->database        = new Database($this->config);
        $this->session         = new Session((string) $this->config->get('session_cookie_domain', ''), $this->basePath);
        $this->router          = new Router();
        $this->view            = new View($this->basePath . '/templates');
        $this->csrf            = new CSRF($this->session);
        $this->rateLimit       = new RateLimit($this->database);
        $fieldRenderer         = new FieldRenderer();
        $fieldWrapper          = new FieldWrapper();
        $this->formFieldBuilder = new FormFieldBuilder($fieldRenderer, $fieldWrapper);

        \Luster\Repositories\EmailTemplateRepo::setGlobalSiteUrl(
            (string) $this->config->get('site_url', '')
        );

        // Give the router a resolver so controllers receive this Application
        $this->router->setResolver(function (string $class): object {
            return new $class($this);
        });
    }

    // -----------------------------------------------------------------------
    // Service accessors
    // -----------------------------------------------------------------------

    /** Returns the application configuration (loaded from config/app.php). */
    public function getConfig(): Config
    {
        return $this->config;
    }

    /** Returns the lazy PDO wrapper used by all repositories. */
    public function getDatabase(): Database
    {
        return $this->database;
    }

    /** Returns the session wrapper (may not be started yet; call start() first). */
    public function getSession(): Session
    {
        return $this->session;
    }

    /** Returns the request router. Normally used only by the boot internals. */
    public function getRouter(): Router
    {
        return $this->router;
    }

    /** Returns the template renderer shared across all controllers. */
    public function getView(): View
    {
        return $this->view;
    }

    /** Returns the CSRF token manager. */
    public function getCSRF(): CSRF
    {
        return $this->csrf;
    }

    /** Returns the database-backed rate limiter. */
    public function getRateLimit(): RateLimit
    {
        return $this->rateLimit;
    }

    /** Returns the address lock, one per request so it can track what this request holds. */
    public function getMemberAddressLock(): MemberAddressLock
    {
        return $this->memberAddressLock ??= new MemberAddressLock($this->database, (string) $this->config->get('db_name', ''));
    }

    /** Returns the public form field builder (FieldRenderer + FieldWrapper). */
    public function getFormFieldBuilder(): FormFieldBuilder
    {
        return $this->formFieldBuilder;
    }

    /** Returns the absolute filesystem path to the project root (no trailing slash). */
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

    /** Returns true when the addon with the given key is in the installed registry. */
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
     * @return array{nav_pending_members: int, nav_pending_kim: int, nav_pending_adoptions: int, nav_pending_codes: int, nav_pending_affiliates: int, nav_site_url: string, nav_installed_addons: array, selected_skin: string}
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

        $pendingCodes = 0;
        try {
            $pendingCodes = (new \Luster\Repositories\CodesRepo($this->database))->countPendingAll();
        } catch (\Throwable) {}

        $pendingAffiliates = 0;
        try {
            $pendingAffiliates = (new \Luster\Repositories\AffiliateRepo($this->database))->getPendingCount();
        } catch (\Throwable) {}

        $navLcDead = 0;
        try {
            $affStats    = (new \Luster\Repositories\AffiliateRepo($this->database))->getLinkStats();
            $joinedStats = (new \Luster\Repositories\JoinedListingRepo($this->database))->getLinkStats();
            $memStats    = (new \Luster\Repositories\MemberLinkCheckRepo($this->database, $this->config))->getLinkStatsAll();
            $navLcDead   = ($affStats['dead'] ?? 0) + ($joinedStats['dead'] ?? 0) + ($memStats['dead'] ?? 0);
        } catch (\Throwable) {}

        $selectedSkin       = '';
        $adminDefaultTheme  = 'light';
        try {
            $siteRepo     = new \Luster\Repositories\SiteSettingsRepo($this->database);
            $skinSettings = $siteRepo->getMany(['selected_skin', 'selected_skin_hash', 'admin_default_theme']);
            $selectedSkin      = $skinSettings['selected_skin'] ?? '';
            $adminDefaultTheme = in_array($skinSettings['admin_default_theme'] ?? '', ['dark', 'light'], true)
                ? $skinSettings['admin_default_theme']
                : 'dark';
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
            'nav_pending_members'    => $pending,
            'nav_pending_kim'        => $pendingKim,
            'nav_pending_adoptions'  => $pendingAdoptions,
            'nav_pending_codes'      => $pendingCodes,
            'nav_pending_affiliates' => $pendingAffiliates,
            'nav_lc_dead'           => $navLcDead,
            'nav_site_url'          => rtrim((string) $this->config->get('site_url', ''), '/'),
            'nav_installed_addons'  => $this->installedAddonKeys,
            'selected_skin'         => $selectedSkin,
            'admin_default_theme'   => $adminDefaultTheme,
            'site_name'             => (string) ($this->config->get('site_name', '') ?? ''),
            'owner_name'            => (string) ($this->config->get('owner_name', '') ?? ''),
            'nav_display_name'      => (string) ($this->session->get('lstr_admin_display_name', '') ?? ''),
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

        // Fire periodic admin notifications for authenticated admins.
        // Start the session early — Session::start() is idempotent.
        $this->session->start();
        if ($this->session->has('lstr_admin_id')) {
            $this->runScheduledNotifications();
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

            if ($siteRepo->get('overdue_notify_enabled', '1') !== '1') {
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
                static fn($l) => '- ' . $l->title . ' (' . $l->url . ')',
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
     * Runs all three periodic notification checks (overdue listings, pending
     * items digest, update availability). Safe to call without an active admin
     * session — activity log entries use null for the admin ID in that case.
     * Designed to be called from both runAdmin() and the CLI cron entry point.
     *
     * @param bool $forceUpdateCheck When true, forces a live GitHub fetch for
     *   the update check even on a never-checked install. Pass true from the
     *   cron path only — admin page loads keep this false to avoid an outbound
     *   request on every page.
     */
    public function runScheduledNotifications(bool $forceUpdateCheck = false): void
    {
        $this->runOverdueCheck();
        $this->runPendingDigestCheck();
        $this->runUpdateNotificationCheck($forceUpdateCheck);
    }

    /**
     * Sends the weekly pending-items digest email if enabled and the 7-day
     * throttle has elapsed. Fires silently — any exception is swallowed so
     * a mail failure never breaks admin page loads.
     */
    private function runPendingDigestCheck(): void
    {
        try {
            $siteRepo = new SiteSettingsRepo($this->database);

            if ($siteRepo->get('pending_digest_enabled', '1') !== '1') {
                return;
            }

            $lastSent = (int) ($siteRepo->get('pending_digest_last_sent', '0') ?? '0');
            if ((time() - $lastSent) < 604800) {
                return;
            }

            $digest = new PendingItemsDigest(
                $this->database,
                $this->config,
                $this->isAddonInstalled('Kim'),
                $this->isAddonInstalled('Adoption'),
            );
            $data = $digest->gather();

            if ($data['grand_total'] === 0) {
                return;
            }

            // Only advance the 7-day window when there are items to report —
            // an empty queue leaves the clock untouched so the next cron tick
            // can check again immediately rather than waiting a full week.
            $siteRepo->set('pending_digest_last_sent', (string) time());

            $ownerEmail = (string) ($this->config->get('owner_email', '') ?? '');
            if ($ownerEmail === '') {
                return;
            }

            $siteName = (string) ($this->config->get('site_name', 'your collective') ?? '');
            $adminUrl = rtrim((string) ($this->config->get('app_url', '') ?? ''), '/') . '/admin';

            $body = $digest->renderEmailBody($data, $siteName, $adminUrl);

            $mailer = new Mailer($this->config);
            $mailer->send($ownerEmail, '', '[' . $siteName . '] Pending items digest', $body);

            $adminId = (int) $this->session->get('lstr_admin_id', 0);
            (new ActivityRepo($this->database))->log(
                $adminId > 0 ? $adminId : null,
                'pending_digest_notification',
                'Pending items digest sent (' . $data['grand_total'] . ' item(s))',
                (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            );
        } catch (\Throwable $e) {
            try {
                (new ErrorLogRepo($this->database))->log(
                    'Pending items digest failed: ' . $e->getMessage(),
                    'error',
                    'Application::runPendingDigestCheck',
                );
            } catch (\Throwable) {
                // DB unavailable too — truly silent fallback
            }
        }
    }

    /**
     * Sends a one-per-version email when a newer Luster release is detected.
     * Gated by update_email_notify setting and whether this version was already
     * notified. A failed send waits 24 hours before trying again, so a broken mail
     * server can't slow down every admin page. Fires silently — exceptions are
     * swallowed and logged.
     */
    private function runUpdateNotificationCheck(bool $force = false): void
    {
        try {
            $siteRepo = new SiteSettingsRepo($this->database);
            $checker  = new UpdateChecker($siteRepo);
            $status   = $checker->getStatus();

            // When called from the cron path with $force=true, do a live GitHub
            // fetch on a never-checked install so the cron can discover updates
            // without requiring a prior admin page load first.
            if ($force && $status->neverChecked) {
                $status = $checker->getStatus(force: true);
            }

            if (!$checker->shouldSendNotification($status)) {
                return;
            }

            $ownerEmail = (string) ($this->config->get('owner_email', '') ?? '');
            if ($ownerEmail === '') {
                $checker->markNotified((string) $status->latest);
                return;
            }

            $siteName = (string) ($this->config->get('site_name', 'Luster') ?? '');
            $adminUrl = rtrim((string) ($this->config->get('app_url', '') ?? ''), '/') . '/admin';

            $body = "A new version of Luster is available.\n\n"
                . "Installed: " . $status->installed . "\n"
                . "Available: " . $status->latest . "\n\n"
                . ($status->releaseUrl !== null ? "Release: " . $status->releaseUrl . "\n\n" : '')
                . "Log in to update: " . $adminUrl;

            $subject = '[' . $siteName . '] Luster ' . $status->latest . ' is available';

            $checker->sendNotification(
                (string) $status->latest,
                fn() => (new Mailer($this->config))->send($ownerEmail, '', $subject, $body),
            );

            $adminId = (int) $this->session->get('lstr_admin_id', 0);
            (new ActivityRepo($this->database))->log(
                $adminId > 0 ? $adminId : null,
                'update_notification',
                'Update notification sent for Luster ' . $status->latest,
                (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            );
        } catch (\Throwable $e) {
            try {
                (new ErrorLogRepo($this->database))->log(
                    'Update notification failed: ' . $e->getMessage(),
                    'error',
                    'Application::runUpdateNotificationCheck',
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
            // Apply security headers before any response path (middleware
            // short-circuits, normal dispatch, and the exception handler all
            // need them — applying early is idempotent via headers_sent()).
            SecurityHeaders::apply();
            $this->loadInstalledAddons();
            $this->loadRoutes($context);
            $this->loadAddonRoutes($context);

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
     * Calls registerRoutes() on every installed addon that has been discovered,
     * passing the current boot context ('public' or 'admin') so each addon only
     * registers the routes appropriate to that front controller.
     */
    private function loadAddonRoutes(string $context): void
    {
        if (empty($this->installedAddonKeys)) {
            return;
        }

        $discovered = $this->discoverAddons();

        foreach ($this->installedAddonKeys as $key) {
            if (isset($discovered[$key])) {
                $discovered[$key]->registerRoutes($this->router, $context);
            }
        }
    }

    /** Sets the PHP default timezone from config, falling back to UTC on an invalid value. */
    private function applyTimezone(): void
    {
        date_default_timezone_set(SiteClock::zoneName((string) $this->config->get('timezone', 'UTC')));
    }

    /**
     * Requires config/routes.php and invokes the closure for the given context
     * ('public' or 'admin') so route definitions are registered on the router.
     */
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

    /**
     * Last-resort exception handler for anything that escapes the boot pipeline.
     *
     * Sends a 403 for CSRF failures, a debug stack trace when app_debug is true,
     * and a generic 500 page otherwise. Always logs to error_log and attempts a
     * best-effort write to lstr_error_log.
     */
    private function handleException(Throwable $e): void
    {
        // Ensure security headers are sent even when an exception escaped before
        // the normal SecurityHeaders::apply() call in boot() (e.g. session start
        // failure). apply() is idempotent — it checks headers_sent() first.
        SecurityHeaders::apply();

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
