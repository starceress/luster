<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Controllers\Public\AffiliatesController;
use Luster\Models\Affiliate;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Tests for AffiliatesController::applySectionTemplate().
 *
 * Covers:
 *  - {affiliates}        receives the row-template output when row template is set
 *  - {affiliates}        falls back to simple <a> links when no row template
 *  - {affiliates_count}  receives the total count
 *  - {affiliates_list}   receives a comma-separated link list
 *  - {if_affiliates}     block shown / {if_no_affiliates} removed when count > 0
 *  - {if_no_affiliates}  block shown / {if_affiliates}    removed when count = 0
 *  - Both conditional blocks process correctly together
 */
final class AffiliateSectionTemplateTest extends TestCase
{
    private AffiliatesController $controller;
    private ReflectionMethod $applySection;

    protected function setUp(): void
    {
        $rc = new ReflectionClass(AffiliatesController::class);
        $this->controller = $rc->newInstanceWithoutConstructor();

        $this->applySection = new ReflectionMethod(AffiliatesController::class, 'applySectionTemplate');
        $this->applySection->setAccessible(true);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeAffiliate(string $name, string $url, ?string $image = null): Affiliate
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

    private function invoke(
        string  $template,
        array   $affiliates,
        int     $total,
        string  $uploadsUrl = 'https://example.com/uploads/affiliates',
        ?string $rowTemplate = null,
    ): string {
        return (string) $this->applySection->invoke(
            $this->controller,
            $template,
            $affiliates,
            $total,
            $uploadsUrl,
            $rowTemplate,
        );
    }

    // -----------------------------------------------------------------------
    // Token replacement
    // -----------------------------------------------------------------------

    public function testAffiliatesCountToken(): void
    {
        $aff = $this->makeAffiliate('Cool', 'https://cool.example');
        $result = $this->invoke('{affiliates_count}', [$aff], 1);

        self::assertSame('1', $result);
    }

    public function testAffiliatesCountTokenWithMultiple(): void
    {
        $affiliates = [
            $this->makeAffiliate('A', 'https://a.example'),
            $this->makeAffiliate('B', 'https://b.example'),
            $this->makeAffiliate('C', 'https://c.example'),
        ];
        $result = $this->invoke('{affiliates_count}', $affiliates, 3);

        self::assertSame('3', $result);
    }

    public function testAffiliatesListTokenCommaJoined(): void
    {
        $affiliates = [
            $this->makeAffiliate('Site A', 'https://a.example'),
            $this->makeAffiliate('Site B', 'https://b.example'),
        ];
        $result = $this->invoke('{affiliates_list}', $affiliates, 2);

        self::assertSame(
            '<a href="https://a.example">Site A</a>, <a href="https://b.example">Site B</a>',
            $result,
        );
    }

    public function testAffiliatesListTokenIsEmptyWhenNone(): void
    {
        $result = $this->invoke('{affiliates_list}', [], 0);
        self::assertSame('', $result);
    }

    public function testAffiliatesTokenUsesRowTemplateWhenSet(): void
    {
        $aff = $this->makeAffiliate('Cool Site', 'https://cool.example', null);
        $result = $this->invoke(
            '{affiliates}',
            [$aff],
            1,
            'https://example.com/uploads/affiliates',
            '<span>{aff_name}</span>',
        );

        self::assertSame('<span>Cool Site</span>', $result);
    }

    public function testAffiliatesTokenFallbackSimpleLinkWhenNoRowTemplate(): void
    {
        $aff = $this->makeAffiliate('My Fanlisting', 'https://fl.example', null);
        $result = $this->invoke('{affiliates}', [$aff], 1);

        self::assertStringContainsString('href="https://fl.example"', $result);
        self::assertStringContainsString('My Fanlisting', $result);
    }

    public function testAffiliatesTokenFallbackRendersImageWhenPresent(): void
    {
        $aff = $this->makeAffiliate('Image Site', 'https://img.example', 'banner.png');
        $result = $this->invoke('{affiliates}', [$aff], 1, 'https://example.com/uploads/affiliates');

        self::assertStringContainsString('<img src="https://example.com/uploads/affiliates/banner.png"', $result);
    }

    public function testAffiliatesTokenEscapesNameInFallback(): void
    {
        $aff = $this->makeAffiliate('<Script> & "site"', 'https://xss.example', null);
        $result = $this->invoke('{affiliates}', [$aff], 1);

        self::assertStringNotContainsString('<Script>', $result);
        self::assertStringContainsString('&lt;Script&gt;', $result);
    }

    // -----------------------------------------------------------------------
    // Conditional blocks — affiliates present
    // -----------------------------------------------------------------------

    public function testIfAffiliatesBlockShownWhenCountPositive(): void
    {
        $aff = $this->makeAffiliate('A', 'https://a.example');
        $tpl = '{if_affiliates}HAS AFFILIATES{/if_affiliates}';
        $result = $this->invoke($tpl, [$aff], 1);

        self::assertSame('HAS AFFILIATES', $result);
    }

    public function testIfNoAffiliatesBlockRemovedWhenCountPositive(): void
    {
        $aff = $this->makeAffiliate('A', 'https://a.example');
        $tpl = '{if_no_affiliates}NO AFFILIATES{/if_no_affiliates}';
        $result = $this->invoke($tpl, [$aff], 1);

        self::assertSame('', $result);
    }

    public function testBothBlocksWhenCountPositive(): void
    {
        $aff = $this->makeAffiliate('A', 'https://a.example');
        $tpl = '{if_affiliates}YES{/if_affiliates}{if_no_affiliates}NO{/if_no_affiliates}';
        $result = $this->invoke($tpl, [$aff], 1);

        self::assertSame('YES', $result);
    }

    // -----------------------------------------------------------------------
    // Conditional blocks — no affiliates
    // -----------------------------------------------------------------------

    public function testIfAffiliatesBlockRemovedWhenCountZero(): void
    {
        $tpl = '{if_affiliates}HAS AFFILIATES{/if_affiliates}';
        $result = $this->invoke($tpl, [], 0);

        self::assertSame('', $result);
    }

    public function testIfNoAffiliatesBlockShownWhenCountZero(): void
    {
        $tpl = '{if_no_affiliates}NO AFFILIATES{/if_no_affiliates}';
        $result = $this->invoke($tpl, [], 0);

        self::assertSame('NO AFFILIATES', $result);
    }

    public function testBothBlocksWhenCountZero(): void
    {
        $tpl = '{if_affiliates}YES{/if_affiliates}{if_no_affiliates}NO{/if_no_affiliates}';
        $result = $this->invoke($tpl, [], 0);

        self::assertSame('NO', $result);
    }

    // -----------------------------------------------------------------------
    // Realistic full template
    // -----------------------------------------------------------------------

    public function testRealisticTemplateWithAffiliates(): void
    {
        $affiliates = [
            $this->makeAffiliate('Site A', 'https://a.example'),
            $this->makeAffiliate('Site B', 'https://b.example'),
        ];

        $tpl = '{if_affiliates}<p>{affiliates_count} affiliates</p>{affiliates}{/if_affiliates}'
             . '{if_no_affiliates}<p>No affiliates yet.</p>{/if_no_affiliates}';

        $result = $this->invoke(
            $tpl,
            $affiliates,
            2,
            'https://example.com/uploads',
            '<a href="{aff_url}">{aff_name}</a>',
        );

        self::assertStringContainsString('2 affiliates', $result);
        self::assertStringContainsString('href="https://a.example"', $result);
        self::assertStringNotContainsString('No affiliates yet.', $result);
    }

    public function testRealisticTemplateWithNoAffiliates(): void
    {
        $tpl = '{if_affiliates}<p>{affiliates_count} affiliates</p>{affiliates}{/if_affiliates}'
             . '{if_no_affiliates}<p>No affiliates yet.</p>{/if_no_affiliates}';

        $result = $this->invoke($tpl, [], 0);

        self::assertStringContainsString('No affiliates yet.', $result);
        self::assertStringNotContainsString('affiliates</p>', $result);
    }
}
