<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Minimal request router that matches GET and POST requests to controller
 actions. Supports path parameters via {braces} syntax and delegates
 controller instantiation to a resolver closure set by the Application.
 No external routing library is used -- this is the only routing code in
 the project.
*/

declare(strict_types=1);

namespace Luster\Core;

use RuntimeException;

/**
 * Minimal router
 *
 * Routes are registered as GET/POST pairs of (path, controller class, action).
 * Path segments wrapped in {braces} become named route parameters passed
 * to the controller action alongside the Request.
 *
 * Controllers receive (Request $request, array $params) as their action args.
 * The Application injects itself into controllers via a resolver closure set
 * with setResolver(). Without a resolver, controllers are instantiated with
 * new $class() — fine for unit tests with no-dependency controllers.
 *
 * Example:
 *   $router->get('/members/{slug}', MembersController::class, 'index');
 *   // Controller action: public function index(Request $req, array $params): Response
 *   //   $params['slug'] is available
 */
final class Router
{
    private array    $routes   = [];
    private ?\Closure $resolver = null;

    // -----------------------------------------------------------------------
    // Route registration
    // -----------------------------------------------------------------------

    /** Registers a GET route. Path segments in {braces} become named parameters. */
    public function get(string $path, string $controller, string $action): void
    {
        $this->add('GET', $path, $controller, $action);
    }

    /** Registers a POST route. Path segments in {braces} become named parameters. */
    public function post(string $path, string $controller, string $action): void
    {
        $this->add('POST', $path, $controller, $action);
    }

    /** Registers a route for any HTTP method. */
    public function add(string $method, string $path, string $controller, string $action): void
    {
        $this->routes[] = [
            'method'     => strtoupper($method),
            'path'       => $path,
            'controller' => $controller,
            'action'     => $action,
            'pattern'    => $this->buildPattern($path),
        ];
    }

    // -----------------------------------------------------------------------
    // Dependency resolver
    // -----------------------------------------------------------------------

    /**
     * Provide a closure that resolves and instantiates controller classes.
     * The closure receives (string $class, Application $app) and must return
     * a controller instance.
     *
     * Set by Application::run() so controllers can receive the Application.
     */
    public function setResolver(\Closure $resolver): void
    {
        $this->resolver = $resolver;
    }

    // -----------------------------------------------------------------------
    // Dispatch
    // -----------------------------------------------------------------------

    /**
     * Matches the request against registered routes and calls the corresponding action.
     *
     * Normalises trailing slashes (e.g. '/admin/listings/' → '/admin/listings') before
     * matching. Returns a 404 response when no route matches.
     */
    public function dispatch(Request $request): Response
    {
        $method = $request->method();
        $path   = $request->path();

        // Normalise trailing slash (except root)
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (!preg_match($route['pattern'], $path, $matches)) {
                continue;
            }

            // Extract only the named captures
            $params = array_filter(
                $matches,
                static fn (string|int $k): bool => is_string($k),
                ARRAY_FILTER_USE_KEY
            );

            return $this->callAction(
                $route['controller'],
                $route['action'],
                $request,
                $params
            );
        }

        return Response::notFound("No route matched {$method} {$path}");
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /** Converts a route path with {placeholder} syntax into a named-capture regex. */
    private function buildPattern(string $path): string
    {
        // Escape everything except the placeholder syntax {name}
        $escaped = preg_quote($path, '#');

        // Replace escaped placeholder \{name\} with a named capture group
        $pattern = preg_replace(
            '/\\\{([a-zA-Z_][a-zA-Z0-9_]*)\\\}/',
            '(?P<$1>[^/]+)',
            $escaped
        );

        return '#^' . $pattern . '$#';
    }

    /** Instantiates $class via the resolver (or directly) and calls $action($request, $params). */
    private function callAction(
        string  $class,
        string  $action,
        Request $request,
        array   $params
    ): Response {
        if (!class_exists($class)) {
            throw new RuntimeException("Controller class not found: {$class}");
        }

        $controller = $this->resolver !== null
            ? ($this->resolver)($class)
            : new $class();

        if (!method_exists($controller, $action)) {
            throw new RuntimeException("Action not found: {$class}::{$action}()");
        }

        return $controller->{$action}($request, $params);
    }
}
