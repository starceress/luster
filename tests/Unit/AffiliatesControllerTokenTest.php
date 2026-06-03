<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Controllers\Public\AffiliatesController;
use Luster\Models\Affiliate;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Regression tests for AffiliatesController::buildAffiliateRows() token semantics.
 *
 * Verifies:
 *  - {aff_image}     = filename only (HTML-escaped)
 *  - {aff_image_url} = full src-ready URL (HTML-escaped)
 *  - {aff_image_tag} = rendered <img> tag, or empty string when no image
 */
final class AffiliatesControllerTokenTest extends TestCase
{
    private AffiliatesController $controller;
    private ReflectionMethod $buildRows;

    protected function setUp(): void
    {
        // Application is final; use newInstanceWithoutConstructor() since
        // buildAffiliateRows() never accesses $this->app.
        $rc = new ReflectionClass(AffiliatesController::class);
        $this->controller = $rc->newInstanceWithoutConstructor();

        $this->buildRows = new ReflectionMethod(AffiliatesController::class, 'buildAffiliateRows');
        $this->buildRows->setAccessible(true);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeAffiliate(string $name, string $url, ?string $image): Affiliate
    {
        return new Affiliate(
            id:                  1,
            listingId:           null,
            name:                $name,
            url:                 $url,
            email:               null,
            image:               $image,
            sortOrder:           0,
            active:              true,
            createdAt:           '',
            updatedAt:           '',
            linkStatus:          'unchecked',
            linkCheckedAt:       null,
            linkRedirectUrl:     null,
            reciprocalStatus:    'unchecked',
            reciprocalCheckedAt: null,
            pending:             false,
            applicationMessage:  null,
        );
    }

    private function invoke(string $template, array $affiliates, string $uploadsUrl = 'https://example.com/uploads/affiliates'): string
    {
        return (string) $this->buildRows->invoke($this->controller, $template, $affiliates, $uploadsUrl);
    }

    // -----------------------------------------------------------------------
    // {aff_image} — filename only
    // -----------------------------------------------------------------------

    public function testAffImageIsFilenameOnly(): void
    {
        $aff    = $this->makeAffiliate('My Site', 'https://example.com', 'banner.png');
        $result = $this->invoke('{aff_image}', [$aff]);

        self::assertSame('banner.png', $result);
    }

    public function testAffImageIsEmptyWhenNoImage(): void
    {
        $aff    = $this->makeAffiliate('My Site', 'https://example.com', null);
        $result = $this->invoke('{aff_image}', [$aff]);

        self::assertSame('', $result);
    }

    public function testAffImageEscapesSpecialChars(): void
    {
        $aff    = $this->makeAffiliate('My Site', 'https://example.com', 'bad<>&.png');
        $result = $this->invoke('{aff_image}', [$aff]);

        self::assertSame('bad&lt;&gt;&amp;.png', $result);
    }

    // -----------------------------------------------------------------------
    // {aff_image_url} — full URL
    // -----------------------------------------------------------------------

    public function testAffImageUrlIsFullUrl(): void
    {
        $aff    = $this->makeAffiliate('My Site', 'https://example.com', 'banner.png');
        $result = $this->invoke('{aff_image_url}', [$aff], 'https://example.com/uploads/affiliates');

        self::assertSame('https://example.com/uploads/affiliates/banner.png', $result);
    }

    public function testAffImageUrlIsEmptyWhenNoImage(): void
    {
        $aff    = $this->makeAffiliate('My Site', 'https://example.com', null);
        $result = $this->invoke('{aff_image_url}', [$aff]);

        self::assertSame('', $result);
    }

    public function testAffImageUrlIsEmptyWhenImageIsBlankString(): void
    {
        $aff    = $this->makeAffiliate('My Site', 'https://example.com', '');
        $result = $this->invoke('{aff_image_url}', [$aff]);

        self::assertSame('', $result);
    }

    // -----------------------------------------------------------------------
    // {aff_image_tag} — rendered <img> tag
    // -----------------------------------------------------------------------

    public function testAffImageTagRendersImgElement(): void
    {
        $aff    = $this->makeAffiliate('My Site', 'https://example.com', 'banner.png');
        $result = $this->invoke('{aff_image_tag}', [$aff], 'https://example.com/uploads/affiliates');

        self::assertSame(
            '<img src="https://example.com/uploads/affiliates/banner.png" alt="My Site">',
            $result,
        );
    }

    public function testAffImageTagIsEmptyWhenNoImage(): void
    {
        $aff    = $this->makeAffiliate('My Site', 'https://example.com', null);
        $result = $this->invoke('{aff_image_tag}', [$aff]);

        self::assertSame('', $result);
    }

    public function testAffImageTagEscapesNameInAlt(): void
    {
        $aff    = $this->makeAffiliate('My <Site> & "Co"', 'https://example.com', 'b.png');
        $result = $this->invoke('{aff_image_tag}', [$aff]);

        self::assertStringContainsString('alt="My &lt;Site&gt; &amp; &quot;Co&quot;"', $result);
    }

    // -----------------------------------------------------------------------
    // All three tokens together (canonical default template)
    // -----------------------------------------------------------------------

    public function testDefaultTemplateRendersAllTokens(): void
    {
        $aff    = $this->makeAffiliate('Cool Site', 'https://cool.example', 'logo.gif');
        $tpl    = '<a href="{aff_url}" title="{aff_name}">{aff_image_tag}</a>';
        $result = $this->invoke($tpl, [$aff], 'https://example.com/uploads/affiliates');

        self::assertSame(
            '<a href="https://cool.example" title="Cool Site">'
            . '<img src="https://example.com/uploads/affiliates/logo.gif" alt="Cool Site">'
            . '</a>',
            $result,
        );
    }

    public function testAllThreeTokensDistinctValues(): void
    {
        $aff    = $this->makeAffiliate('My Site', 'https://example.com', 'banner.png');
        $tpl    = '{aff_image}|{aff_image_url}|{aff_image_tag}';
        $result = $this->invoke($tpl, [$aff], 'https://cdn.example/aff');

        [$filename, $url, $tag] = explode('|', $result, 3);

        self::assertSame('banner.png',                                       $filename);
        self::assertSame('https://cdn.example/aff/banner.png',               $url);
        self::assertSame('<img src="https://cdn.example/aff/banner.png" alt="My Site">', $tag);
    }
}
