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
 * Verifies the consolidated {aff_image_url} token:
 *  - When the affiliate has an image: renders a self-contained
 *    <img src="..." alt="{aff_name}"> element.
 *  - When the affiliate has no image: renders a text link
 *    <a href="{aff_url}">{aff_name}</a> (always-show-something empty state).
 *  - {aff_name} and {aff_url} are HTML-escaped.
 *
 * {aff_image} and {aff_image_tag} have been retired and merged into
 * {aff_image_url}.
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
            status:              'approved',
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
    // {aff_name} / {aff_url} — escaped
    // -----------------------------------------------------------------------

    public function testAffNameAndUrlAreEscaped(): void
    {
        $aff    = $this->makeAffiliate('My <Site> & "Co"', 'https://example.com/?a=1&b=2', null);
        $result = $this->invoke('{aff_name}|{aff_url}', [$aff]);

        self::assertSame(
            'My &lt;Site&gt; &amp; &quot;Co&quot;|https://example.com/?a=1&amp;b=2',
            $result,
        );
    }

    // -----------------------------------------------------------------------
    // {aff_image_url} — image set: self-contained <img> element
    // -----------------------------------------------------------------------

    public function testAffImageUrlRendersImgElementWhenImageSet(): void
    {
        $aff    = $this->makeAffiliate('My Site', 'https://example.com', 'banner.png');
        $result = $this->invoke('{aff_image_url}', [$aff], 'https://example.com/uploads/affiliates');

        self::assertSame(
            '<img src="https://example.com/uploads/affiliates/banner.png" alt="My Site">',
            $result,
        );
    }

    public function testAffImageUrlEscapesNameInAltAndUrlInSrc(): void
    {
        $aff    = $this->makeAffiliate('My <Site> & "Co"', 'https://example.com', 'b.png');
        $result = $this->invoke('{aff_image_url}', [$aff], 'https://example.com/uploads/affiliates?x=1&y=2');

        self::assertSame(
            '<img src="https://example.com/uploads/affiliates?x=1&amp;y=2/b.png" alt="My &lt;Site&gt; &amp; &quot;Co&quot;">',
            $result,
        );
    }

    // -----------------------------------------------------------------------
    // {aff_image_url} — no image: text link to the affiliate (always shows something)
    // -----------------------------------------------------------------------

    public function testAffImageUrlRendersTextLinkWhenImageIsNull(): void
    {
        $aff    = $this->makeAffiliate('My Site', 'https://example.com', null);
        $result = $this->invoke('{aff_image_url}', [$aff]);

        self::assertSame('<a href="https://example.com">My Site</a>', $result);
    }

    public function testAffImageUrlRendersTextLinkWhenImageIsBlankString(): void
    {
        $aff    = $this->makeAffiliate('My Site', 'https://example.com', '');
        $result = $this->invoke('{aff_image_url}', [$aff]);

        self::assertSame('<a href="https://example.com">My Site</a>', $result);
    }

    public function testAffImageUrlTextLinkEscapesNameAndUrl(): void
    {
        $aff    = $this->makeAffiliate('My <Site> & "Co"', 'https://example.com/?a=1&b=2', null);
        $result = $this->invoke('{aff_image_url}', [$aff]);

        self::assertSame(
            '<a href="https://example.com/?a=1&amp;b=2">My &lt;Site&gt; &amp; &quot;Co&quot;</a>',
            $result,
        );
    }

    // -----------------------------------------------------------------------
    // Combined tokens (canonical row template usage)
    // -----------------------------------------------------------------------

    public function testRowTemplateWithImage(): void
    {
        $aff    = $this->makeAffiliate('Cool Site', 'https://cool.example', 'logo.gif');
        $tpl    = '<li>{aff_image_url}</li>';
        $result = $this->invoke($tpl, [$aff], 'https://example.com/uploads/affiliates');

        self::assertSame(
            '<li><img src="https://example.com/uploads/affiliates/logo.gif" alt="Cool Site"></li>',
            $result,
        );
    }

    public function testRowTemplateWithoutImage(): void
    {
        $aff    = $this->makeAffiliate('Cool Site', 'https://cool.example', null);
        $tpl    = '<li>{aff_image_url}</li>';
        $result = $this->invoke($tpl, [$aff], 'https://example.com/uploads/affiliates');

        self::assertSame(
            '<li><a href="https://cool.example">Cool Site</a></li>',
            $result,
        );
    }

    public function testMultipleAffiliatesProduceConcatenatedRows(): void
    {
        $a1 = $this->makeAffiliate('Site A', 'https://a.example', 'a.png');
        $a2 = $this->makeAffiliate('Site B', 'https://b.example', null);

        $result = $this->invoke('{aff_name}: {aff_image_url}', [$a1, $a2], 'https://example.com/uploads/affiliates');

        self::assertSame(
            "Site A: <img src=\"https://example.com/uploads/affiliates/a.png\" alt=\"Site A\">\n"
            . 'Site B: <a href="https://b.example">Site B</a>',
            $result,
        );
    }
}
