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
 * Verifies the {aff_*} row tokens (via AffiliateRowRenderer):
 *  - {aff_name} / {aff_url} are HTML-escaped.
 *  - {aff_image_url} is JUST the raw image URL (escaped), usable inside
 *    <img src="{aff_image_url}">; empty string when the affiliate has no image.
 *  - {aff_image} is the WHOLE ready-made <img src="..." alt="{aff_name}"> tag;
 *    empty string when the affiliate has no image.
 *  - {if_aff_image}...{/if_aff_image} / {if_no_aff_image}...{/if_no_aff_image}
 *    let template authors build fallback markup for affiliates without images.
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
    // {aff_image_url} — just the raw image URL
    // -----------------------------------------------------------------------

    public function testAffImageUrlRendersRawUrlWhenImageSet(): void
    {
        $aff    = $this->makeAffiliate('My Site', 'https://example.com', 'banner.png');
        $result = $this->invoke('{aff_image_url}', [$aff], 'https://example.com/uploads/affiliates');

        self::assertSame('https://example.com/uploads/affiliates/banner.png', $result);
    }

    public function testAffImageUrlIsEscaped(): void
    {
        $aff    = $this->makeAffiliate('My Site', 'https://example.com', 'b.png');
        $result = $this->invoke('{aff_image_url}', [$aff], 'https://example.com/uploads/affiliates?x=1&y=2');

        self::assertSame('https://example.com/uploads/affiliates?x=1&amp;y=2/b.png', $result);
    }

    public function testAffImageUrlEmptyWhenImageIsNull(): void
    {
        $aff    = $this->makeAffiliate('My Site', 'https://example.com', null);
        $result = $this->invoke('{aff_image_url}', [$aff]);

        self::assertSame('', $result);
    }

    public function testAffImageUrlEmptyWhenImageIsBlankString(): void
    {
        $aff    = $this->makeAffiliate('My Site', 'https://example.com', '');
        $result = $this->invoke('{aff_image_url}', [$aff]);

        self::assertSame('', $result);
    }

    // -----------------------------------------------------------------------
    // {aff_image} — the whole <img> tag
    // -----------------------------------------------------------------------

    public function testAffImageRendersImgElementWhenImageSet(): void
    {
        $aff    = $this->makeAffiliate('My Site', 'https://example.com', 'banner.png');
        $result = $this->invoke('{aff_image}', [$aff], 'https://example.com/uploads/affiliates');

        self::assertSame(
            '<img src="https://example.com/uploads/affiliates/banner.png" alt="My Site">',
            $result,
        );
    }

    public function testAffImageEscapesNameInAltAndUrlInSrc(): void
    {
        $aff    = $this->makeAffiliate('My <Site> & "Co"', 'https://example.com', 'b.png');
        $result = $this->invoke('{aff_image}', [$aff], 'https://example.com/uploads/affiliates?x=1&y=2');

        self::assertSame(
            '<img src="https://example.com/uploads/affiliates?x=1&amp;y=2/b.png" alt="My &lt;Site&gt; &amp; &quot;Co&quot;">',
            $result,
        );
    }

    public function testAffImageEmptyWhenImageIsNull(): void
    {
        $aff    = $this->makeAffiliate('My Site', 'https://example.com', null);
        $result = $this->invoke('{aff_image}', [$aff]);

        self::assertSame('', $result);
    }

    public function testAffImageEmptyWhenImageIsBlankString(): void
    {
        $aff    = $this->makeAffiliate('My Site', 'https://example.com', '');
        $result = $this->invoke('{aff_image}', [$aff]);

        self::assertSame('', $result);
    }

    // -----------------------------------------------------------------------
    // {if_aff_image} / {if_no_aff_image}
    // -----------------------------------------------------------------------

    public function testIfAffImageShowsContentWhenImageSet(): void
    {
        $aff    = $this->makeAffiliate('My Site', 'https://example.com', 'banner.png');
        $result = $this->invoke('{if_aff_image}has image{/if_aff_image}', [$aff]);

        self::assertSame('has image', $result);
    }

    public function testIfAffImageHidesContentWhenNoImage(): void
    {
        $aff    = $this->makeAffiliate('My Site', 'https://example.com', null);
        $result = $this->invoke('{if_aff_image}has image{/if_aff_image}', [$aff]);

        self::assertSame('', $result);
    }

    public function testIfNoAffImageShowsContentWhenNoImage(): void
    {
        $aff    = $this->makeAffiliate('My Site', 'https://example.com', null);
        $result = $this->invoke('{if_no_aff_image}no image{/if_no_aff_image}', [$aff]);

        self::assertSame('no image', $result);
    }

    public function testIfNoAffImageHidesContentWhenImageSet(): void
    {
        $aff    = $this->makeAffiliate('My Site', 'https://example.com', 'banner.png');
        $result = $this->invoke('{if_no_aff_image}no image{/if_no_aff_image}', [$aff]);

        self::assertSame('', $result);
    }

    // -----------------------------------------------------------------------
    // Combined tokens (canonical row template usage)
    // -----------------------------------------------------------------------

    public function testRowTemplateWithImage(): void
    {
        $aff    = $this->makeAffiliate('Cool Site', 'https://cool.example', 'logo.gif');
        $tpl    = '<li>{if_aff_image}{aff_image}{/if_aff_image}{if_no_aff_image}<a href="{aff_url}">{aff_name}</a>{/if_no_aff_image}</li>';
        $result = $this->invoke($tpl, [$aff], 'https://example.com/uploads/affiliates');

        self::assertSame(
            '<li><img src="https://example.com/uploads/affiliates/logo.gif" alt="Cool Site"></li>',
            $result,
        );
    }

    public function testRowTemplateWithoutImage(): void
    {
        $aff    = $this->makeAffiliate('Cool Site', 'https://cool.example', null);
        $tpl    = '<li>{if_aff_image}{aff_image}{/if_aff_image}{if_no_aff_image}<a href="{aff_url}">{aff_name}</a>{/if_no_aff_image}</li>';
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

        $tpl    = '{aff_name}: {if_aff_image}{aff_image}{/if_aff_image}{if_no_aff_image}<a href="{aff_url}">{aff_name}</a>{/if_no_aff_image}';
        $result = $this->invoke($tpl, [$a1, $a2], 'https://example.com/uploads/affiliates');

        self::assertSame(
            "Site A: <img src=\"https://example.com/uploads/affiliates/a.png\" alt=\"Site A\">\n"
            . 'Site B: <a href="https://b.example">Site B</a>',
            $result,
        );
    }
}
