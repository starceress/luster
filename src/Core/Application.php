<?php

declare(strict_types=1);

namespace Luster\Core;

use Throwable;

/**
 * Application bootstrap and service container.
 *
 * Responsibilities:
 *  - Instantiate and wire the core services (Config, Database, Session, Router, View)
 *  - Start the session
 *  - Load route definitions from config/routes.php
 *  - Dispatch the request and send the response
 *  - Catch unhandled exceptions and render an appropriate error page
 *
 * Dependency injection for controllers:
 *   The Router resolver closure captures $this (Application), so any controller
 *   instantiated via the router receives the Application in its constructor.
 *   Phase 4/5 controllers should accept Application $app as their only
 *   constructor argument and call $app->getDatabase() etc. as needed.
 */
final class Application
{
    private Config   $config;
    private Database $database;
    private Session  $session;
    private Router   $router;
    private View     $view;

    public function __construct(
        private readonly string $basePath,
        string $configPath,
    ) {
        $this->config   = new Config($configPath);
        $this->database = new Database($this->config);
        $this->session  = new Session();
        $this->router   = new Router();
        $this->view     = new View($this->basePath . '/templates');

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

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    // -----------------------------------------------------------------------
    // Boot
    // -----------------------------------------------------------------------

    /**
     * Runs the full request lifecycle for the public front controller.
     *
     * Loads public routes from config/routes.php['public'], dispatches
     * the request, and sends the response.
     */
    public function run(): void
    {
        $this->boot('public');
    }

    /**
     * Runs the full request lifecycle for the admin front controller.
     *
     * Loads admin routes from config/routes.php['admin'] and dispatches.
     */
    public function runAdmin(): void
    {
        $this->boot('admin');
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    private function boot(string $context): void
    {
        try {
            $this->applyTimezone();
            $this->session->start();
            $this->loadRoutes($context);

            $request  = new Request();
            $response = $this->router->dispatch($request);
            $response->send();
        } catch (Throwable $e) {
            $this->handleException($e);
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
        $debug = (bool) $this->config->get('app_debug', false);

        error_log('[Luster] ' . $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());

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
