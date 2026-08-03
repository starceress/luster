<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\SecurityHeaders;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests for SecurityHeaders: the News Feed composer's route-scoped img-src
 * CSP relaxation (imgSrcDirective()) and HSTS. Both mirror equivalent
 * behavior in public/.htaccess; keep both in sync when either one changes.
 *
 * The img-src tests exercise the PHP method directly (via reflection, feeding
 * it a fake $_SERVER['REQUEST_URI']) - they do not run Apache and cannot
 * catch a regression in the .htaccess side (e.g. its %{THE_REQUEST} regex
 * drifting out of sync with this method's $_SERVER['REQUEST_URI'] regex).
 * The .htaccess side has no automated coverage; it was verified manually via
 * live curl requests against a running Apache instance (checking the actual
 * response headers, not just that the config parses) and needs the same kind
 * of manual re-check after any future edit to that block.
 */
final class SecurityHeadersTest extends TestCase
{
    private ?string $originalRequestUri = null;

    protected function setUp(): void
    {
        $this->originalRequestUri = $_SERVER['REQUEST_URI'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->originalRequestUri === null) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $this->originalRequestUri;
        }
    }

    private function imgSrcFor(string $requestUri): string
    {
        $_SERVER['REQUEST_URI'] = $requestUri;
        $m = new ReflectionMethod(SecurityHeaders::class, 'imgSrcDirective');
        $m->setAccessible(true);
        return $m->invoke(null);
    }

    public function testRelaxedForAllThreeComposerRoutes(): void
    {
        $relaxed = "img-src 'self' data: http: https:";

        $this->assertSame($relaxed, $this->imgSrcFor('/luster/public/admin/newsfeed/1/add'));
        $this->assertSame($relaxed, $this->imgSrcFor('/luster/public/admin/newsfeed/entry/42/edit'));
        $this->assertSame($relaxed, $this->imgSrcFor('/luster/public/admin/newsfeed/collective/add'));
    }

    public function testUnrelaxedElsewhere(): void
    {
        $unrelaxed = "img-src 'self' data:";

        $this->assertSame($unrelaxed, $this->imgSrcFor('/luster/public/admin/newsfeed'));
        $this->assertSame($unrelaxed, $this->imgSrcFor('/luster/public/admin/settings'));
    }

    /**
     * Regression: without a boundary after the alternation, "\d+/add" and
     * "entry/\d+/edit" match as a *prefix* of a longer, unrelated path
     * segment - confirmed empirically these three near-miss paths were
     * incorrectly relaxed before the (?=[?\s]|$) lookahead was added.
     */
    public function testNearMissRoutesAreNotRelaxed(): void
    {
        $unrelaxed = "img-src 'self' data:";

        $this->assertSame($unrelaxed, $this->imgSrcFor('/luster/public/admin/newsfeed/1/additional'));
        $this->assertSame($unrelaxed, $this->imgSrcFor('/luster/public/admin/newsfeed/entry/1/edit-old'));
        $this->assertSame($unrelaxed, $this->imgSrcFor('/luster/public/admin/newsfeed/collective/additional'));
    }

    public function testRelaxedRouteWithQueryStringStillMatches(): void
    {
        $relaxed = "img-src 'self' data: http: https:";

        $this->assertSame($relaxed, $this->imgSrcFor('/luster/public/admin/newsfeed/1/add?foo=bar'));
    }

    // -----------------------------------------------------------------------
    // embed-codes-preview route (SEC-18) - X-Frame-Options: SAMEORIGIN +
    // relaxed frame-ancestors, mirroring the equivalent <If> block in
    // public/.htaccess. Like imgSrcDirective(), these are pure route-matching
    // and string-building methods with no header()/headers_sent() call of
    // their own, so - unlike apply() itself - they can be tested directly.
    // -----------------------------------------------------------------------

    private function isEmbedPreviewFor(string $requestUri): bool
    {
        $_SERVER['REQUEST_URI'] = $requestUri;
        $m = new ReflectionMethod(SecurityHeaders::class, 'isEmbedCodesPreviewRoute');
        $m->setAccessible(true);
        return $m->invoke(null);
    }

    public function testEmbedCodesPreviewRouteDetected(): void
    {
        $this->assertTrue($this->isEmbedPreviewFor('/luster/public/admin/embed-codes/preview'));
        $this->assertTrue($this->isEmbedPreviewFor('/luster/public/admin/embed-codes/preview?foo=bar'));
    }

    public function testEmbedCodesPreviewRouteNotDetectedElsewhere(): void
    {
        $this->assertFalse($this->isEmbedPreviewFor('/luster/public/admin/embed-codes'));
        $this->assertFalse($this->isEmbedPreviewFor('/luster/public/admin/settings'));
        // Prefix-collision guard, same reasoning as the News Feed boundary -
        // no such route currently exists, but must not be misidentified if
        // one is ever added.
        $this->assertFalse($this->isEmbedPreviewFor('/luster/public/admin/embed-codes/preview-legacy'));
    }

    public function testEmbedCodesPreviewCspMatchesHtaccessByteForByte(): void
    {
        $m = new ReflectionMethod(SecurityHeaders::class, 'embedCodesPreviewCsp');
        $m->setAccessible(true);

        $this->assertSame(
            "default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; "
            . "font-src 'self' data:; frame-ancestors 'self'; base-uri 'self'; form-action 'self'",
            $m->invoke(null),
        );
    }

    // -----------------------------------------------------------------------
    // HSTS (mirrors public/.htaccess's <If "%{HTTPS} == 'on'"> block, but this
    // class's whole purpose is covering non-Apache deployments where that
    // .htaccess block never applies at all)
    //
    // No test here for apply()'s actual header-sending behavior: PHP's CLI
    // SAPI (which PHPUnit runs under, even with @runInSeparateProcess - that
    // isolates the process, not the SAPI) reports headers_sent() === true
    // from the very start of every run, before any header() call, since CLI
    // has no real HTTP response to send headers on. apply()'s own first line
    // is `if (headers_sent()) { return; }`, so under CLI it always returns
    // immediately regardless of $_SERVER['HTTPS'] - confirmed empirically:
    // a headers_list()-based assertion "passes" for a not-sent-over-HTTP case
    // for the wrong reason (nothing ran at all, not because the HTTPS check
    // correctly excluded it) and fails for testHstsSentOverHttps for the
    // same underlying reason. The new logic here is a single `if
    // (Request::detectHttps()) { header(...) }` line - Request::detectHttps()
    // itself already has full, dedicated coverage in RequestTest.php (on,
    // off, port 443, X-Forwarded-Proto, case-insensitivity), and the actual
    // live behavior (no HSTS sent to a plain-HTTP request) was verified via
    // curl against a running instance the same way the .htaccess side was.
    // -----------------------------------------------------------------------
}
