<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    // -----------------------------------------------------------------------
    // detectHttps() — used by Session::start() (cookie_secure) and
    // AuthMiddleware (remember-me cookie) to avoid setting a Secure cookie
    // on plain HTTP, which browsers silently reject.
    // -----------------------------------------------------------------------

    public function testDetectsHttpsViaHttpsServerVar(): void
    {
        self::assertTrue(Request::detectHttps(['HTTPS' => 'on']));
    }

    public function testHttpsOffIsNotHttps(): void
    {
        self::assertFalse(Request::detectHttps(['HTTPS' => 'off']));
    }

    public function testDetectsHttpsViaPort443(): void
    {
        self::assertTrue(Request::detectHttps(['SERVER_PORT' => '443']));
    }

    public function testDetectsHttpsViaForwardedProtoHeader(): void
    {
        self::assertTrue(Request::detectHttps(['HTTP_X_FORWARDED_PROTO' => 'https']));
    }

    public function testForwardedProtoHeaderIsCaseInsensitive(): void
    {
        self::assertTrue(Request::detectHttps(['HTTP_X_FORWARDED_PROTO' => 'HTTPS']));
    }

    public function testPlainHttpIsNotHttps(): void
    {
        self::assertFalse(Request::detectHttps(['SERVER_PORT' => '80']));
        self::assertFalse(Request::detectHttps([]));
    }

    public function testInstanceIsHttpsDelegatesToServerArray(): void
    {
        $request = new Request([], [], ['HTTPS' => 'on'], [], []);
        self::assertTrue($request->isHttps());

        $request = new Request([], [], ['SERVER_PORT' => '80'], [], []);
        self::assertFalse($request->isHttps());
    }
}
