<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Database;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Session;
use Luster\Middleware\AuthMiddleware;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Session is started in setUp() and destroyed in tearDown() so each test
 * gets a clean slate (mirrors CSRFTest — Session::start() is safe in CLI).
 */
final class AuthMiddlewareTest extends TestCase
{
    private const ADMIN_DDL = <<<SQL
CREATE TABLE lstr_admin_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL
)
SQL;

    private Session $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new Session();
        $this->session->start();
    }

    protected function tearDown(): void
    {
        if ($this->session->isStarted()) {
            $this->session->destroy();
        }
        parent::tearDown();
    }

    private function makeDb(): Database
    {
        $db = SqliteDatabase::make(self::ADMIN_DDL);
        $db->pdo()->exec("INSERT INTO lstr_admin_users (id, username) VALUES (1, 'star')");
        return $db;
    }

    private function makeRequest(string $path = '/admin/dashboard'): Request
    {
        return new Request([], [], ['REQUEST_URI' => $path, 'REQUEST_METHOD' => 'GET'], [], []);
    }

    public function testAllowsRequestWhenAdminStillExists(): void
    {
        $this->session->set('lstr_admin_id', 1);
        $middleware = new AuthMiddleware($this->session, '/admin/login', [], $this->makeDb());

        self::assertNull($middleware->process($this->makeRequest()));
    }

    public function testRedirectsAndDestroysSessionWhenAdminWasDeleted(): void
    {
        $this->session->set('lstr_admin_id', 999);
        $middleware = new AuthMiddleware($this->session, '/admin/login', [], $this->makeDb());

        $result = $middleware->process($this->makeRequest());

        self::assertInstanceOf(Response::class, $result);
        self::assertFalse($this->session->has('lstr_admin_id'));
    }

    public function testExemptPathBypassesTheDeletedAdminCheck(): void
    {
        $this->session->set('lstr_admin_id', 999);
        $middleware = new AuthMiddleware($this->session, '/admin/login', ['/admin/login'], $this->makeDb());

        self::assertNull($middleware->process($this->makeRequest('/admin/login')));
    }

    public function testSkipsExistenceCheckWhenNoDatabaseInjected(): void
    {
        $this->session->set('lstr_admin_id', 999);
        $middleware = new AuthMiddleware($this->session, '/admin/login', [], null);

        // No db to verify against — falls back to trusting the session, as before.
        self::assertNull($middleware->process($this->makeRequest()));
    }

    public function testRedirectsToLoginWhenNoSessionAndNoRememberCookie(): void
    {
        $middleware = new AuthMiddleware($this->session, '/admin/login', [], $this->makeDb());

        self::assertInstanceOf(Response::class, $middleware->process($this->makeRequest()));
    }
}
