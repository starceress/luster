<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Router;
use PHPUnit\Framework\TestCase;

// ---------------------------------------------------------------------------
// Stub controllers — defined at namespace level so Router can do new $class().
// Zero-arg constructors are intentional; the router's default resolver is used.
// ---------------------------------------------------------------------------

final class StubSimpleController
{
    public function index(Request $request, array $params): Response
    {
        return Response::html('index-ok');
    }

    public function create(Request $request, array $params): Response
    {
        return Response::html('created');
    }
}

final class StubParamController
{
    public function show(Request $request, array $params): Response
    {
        return Response::html('id=' . ($params['id'] ?? 'MISSING'));
    }

    public function multi(Request $request, array $params): Response
    {
        $cat  = $params['cat']  ?? '';
        $slug = $params['slug'] ?? '';
        return Response::html("{$cat}:{$slug}");
    }
}

final class StubPriorityController
{
    public function literal(Request $request, array $params): Response
    {
        return Response::html('literal');
    }
}

// ---------------------------------------------------------------------------

final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
    }

    // -----------------------------------------------------------------------
    // Helper — build a Request with given method and path
    // -----------------------------------------------------------------------

    private function req(string $method, string $path): Request
    {
        return new Request(
            query:  [],
            post:   [],
            server: [
                'REQUEST_METHOD' => $method,
                'REQUEST_URI'    => $path,
                'SCRIPT_NAME'    => '/index.php',
            ],
        );
    }

    // -----------------------------------------------------------------------
    // Simple route matching
    // -----------------------------------------------------------------------

    public function testSimpleGetRouteReturns200(): void
    {
        $this->router->get('/members', StubSimpleController::class, 'index');

        $response = $this->router->dispatch($this->req('GET', '/members'));

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('index-ok', $response->getBody());
    }

    public function testSimplePostRouteReturns200(): void
    {
        $this->router->post('/join', StubSimpleController::class, 'create');

        $response = $this->router->dispatch($this->req('POST', '/join'));

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('created', $response->getBody());
    }

    // -----------------------------------------------------------------------
    // Named parameter extraction
    // -----------------------------------------------------------------------

    public function testSingleNamedParamExtracted(): void
    {
        $this->router->get('/members/{id}', StubParamController::class, 'show');

        $response = $this->router->dispatch($this->req('GET', '/members/42'));

        $this->assertSame('id=42', $response->getBody());
    }

    public function testNamedParamAcceptsAlphanumericValue(): void
    {
        $this->router->get('/members/{id}', StubParamController::class, 'show');

        $response = $this->router->dispatch($this->req('GET', '/members/abc123'));

        $this->assertSame('id=abc123', $response->getBody());
    }

    public function testMultipleNamedParamsExtracted(): void
    {
        $this->router->get('/cat/{cat}/item/{slug}', StubParamController::class, 'multi');

        $response = $this->router->dispatch($this->req('GET', '/cat/music/item/beatles'));

        $this->assertSame('music:beatles', $response->getBody());
    }

    // -----------------------------------------------------------------------
    // 404 cases
    // -----------------------------------------------------------------------

    public function testUnmatchedPathReturns404(): void
    {
        $this->router->get('/members', StubSimpleController::class, 'index');

        $response = $this->router->dispatch($this->req('GET', '/nonexistent'));

        $this->assertSame(404, $response->getStatus());
    }

    public function testMethodMismatchGetVsPostReturns404(): void
    {
        $this->router->get('/members', StubSimpleController::class, 'index');

        $response = $this->router->dispatch($this->req('POST', '/members'));

        $this->assertSame(404, $response->getStatus());
    }

    public function testMethodMismatchPostVsGetReturns404(): void
    {
        $this->router->post('/join', StubSimpleController::class, 'create');

        $response = $this->router->dispatch($this->req('GET', '/join'));

        $this->assertSame(404, $response->getStatus());
    }

    public function testEmptyRouterReturns404(): void
    {
        $response = $this->router->dispatch($this->req('GET', '/anything'));

        $this->assertSame(404, $response->getStatus());
    }

    // -----------------------------------------------------------------------
    // Path normalisation
    // -----------------------------------------------------------------------

    public function testTrailingSlashIsNormalisedBeforeMatch(): void
    {
        $this->router->get('/members', StubSimpleController::class, 'index');

        $response = $this->router->dispatch($this->req('GET', '/members/'));

        $this->assertSame(200, $response->getStatus());
    }

    // -----------------------------------------------------------------------
    // Param does NOT match a slash (captures [^/]+ only)
    // -----------------------------------------------------------------------

    public function testParamDoesNotMatchSlash(): void
    {
        $this->router->get('/members/{id}', StubParamController::class, 'show');

        // Two extra segments → should not match /members/{id}
        $response = $this->router->dispatch($this->req('GET', '/members/1/extra'));

        $this->assertSame(404, $response->getStatus());
    }

    // -----------------------------------------------------------------------
    // Route ordering — first registered match wins
    // -----------------------------------------------------------------------

    public function testLiteralRouteRegisteredBeforeParamRouteWins(): void
    {
        // Register the literal path first
        $this->router->get('/members/add', StubPriorityController::class, 'literal');
        $this->router->get('/members/{id}',  StubParamController::class,    'show');

        $response = $this->router->dispatch($this->req('GET', '/members/add'));

        $this->assertSame('literal', $response->getBody());
    }

    public function testParamRouteRegisteredFirstCapturesLiteralSegment(): void
    {
        // When param route is first, it will capture "add" as the id param
        $this->router->get('/members/{id}',  StubParamController::class,    'show');
        $this->router->get('/members/add', StubPriorityController::class, 'literal');

        $response = $this->router->dispatch($this->req('GET', '/members/add'));

        $this->assertSame('id=add', $response->getBody());
    }

    // -----------------------------------------------------------------------
    // Root path
    // -----------------------------------------------------------------------

    public function testRootPathMatches(): void
    {
        $this->router->get('/', StubSimpleController::class, 'index');

        $response = $this->router->dispatch($this->req('GET', '/'));

        $this->assertSame(200, $response->getStatus());
    }
}
