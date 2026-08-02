<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\SecurityHeaders;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests for SecurityHeaders::imgSrcDirective() - the News Feed composer's
 * route-scoped img-src CSP relaxation. Mirrors the equivalent <If> block in
 * public/.htaccess; keep both in sync when either one changes.
 *
 * This file only exercises the PHP method directly (via reflection, feeding
 * it a fake $_SERVER['REQUEST_URI']) - it does not run Apache and cannot
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
}
