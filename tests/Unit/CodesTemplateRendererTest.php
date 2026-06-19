<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\ListingSettingsRepo;
use Luster\Support\CodesTemplateRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Luster\Support\CodesTemplateRenderer::render(), the shared renderer
 * for the per-listing codes_template and the collective collective_codes_template
 * (used by public/embed/codes.php, ListingController's per-listing preview, and
 * SettingsController's collective templates preview).
 */
final class CodesTemplateRendererTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Fixture helpers — shapes match CodesRepo / CodeDonorsRepo
    // -----------------------------------------------------------------------

    private function makeCode(
        int $id,
        int $width = 0,
        int $height = 0,
        ?string $name = null,
        ?int $donorId = null,
        ?string $submitterName = null,
        ?string $submitterUrl = null,
        string $filename = 'code.gif',
    ): object {
        return (object) [
            'id'             => $id,
            'listingId'      => 1,
            'name'           => $name,
            'filename'       => $filename,
            'width'          => $width,
            'height'         => $height,
            'sizeId'         => null,
            'donorId'        => $donorId,
            'sortOrder'      => 0,
            'status'         => 'approved',
            'submitterName'  => $submitterName,
            'submitterEmail' => null,
            'submitterUrl'   => $submitterUrl,
            'createdAt'      => '2026-01-01 00:00:00',
        ];
    }

    private function makeDonor(int $id, string $name, ?string $url = null): object
    {
        return (object) [
            'id'        => $id,
            'listingId' => 1,
            'name'      => $name,
            'email'     => null,
            'url'       => $url,
        ];
    }

    // -----------------------------------------------------------------------
    // render() returns null for templates that aren't a recognised codes template
    // -----------------------------------------------------------------------

    public function testReturnsNullWhenTemplateLacksLoopTokens(): void
    {
        $result = CodesTemplateRenderer::render(
            '<p>Static markup with no codes loop.</p>',
            [],
            [],
            [],
            '/uploads/codes',
        );

        self::assertNull($result);
    }

    public function testReturnsNullWhenTemplateHasConditionalsButNoLoop(): void
    {
        $tpl = '{if_codes}<p>Has codes</p>{/if_codes}{if_no_codes}<p>None</p>{/if_no_codes}';

        $result = CodesTemplateRenderer::render(
            $tpl,
            [$this->makeCode(1)],
            [],
            [],
            '/uploads/codes',
        );

        self::assertNull($result);
    }

    // -----------------------------------------------------------------------
    // Flat mode — per-code tokens
    // -----------------------------------------------------------------------

    public function testFlatModeCodeUrlRendered(): void
    {
        $code = $this->makeCode(1, 50, 50, null, null, null, null, 'abc123.gif');
        $tpl  = '{each_code}<img src="{code_url}">{/each_code}';

        $html = CodesTemplateRenderer::render($tpl, [$code], [], [], '/uploads/codes');

        self::assertSame('<img src="/uploads/codes/abc123.gif">', $html);
    }

    public function testFlatModeSizeTokensWhenDimensionsPresent(): void
    {
        $code = $this->makeCode(1, 88, 31);
        $tpl  = '{each_code}{code_width}x{code_height} ({code_size}){/each_code}';

        $html = CodesTemplateRenderer::render($tpl, [$code], [], [], '/uploads/codes');

        self::assertSame('88x31 (88×31)', $html);
    }

    public function testFlatModeNoSizeWhenDimensionsZero(): void
    {
        $code = $this->makeCode(1, 0, 0);
        $tpl  = '{each_code}[{code_width}|{code_height}|{code_size}]{/each_code}';

        $html = CodesTemplateRenderer::render($tpl, [$code], [], [], '/uploads/codes');

        self::assertSame('[||]', $html);
    }

    public function testFlatModeCodeNameConditional(): void
    {
        $named   = $this->makeCode(1, 50, 50, 'Spring Set');
        $unnamed = $this->makeCode(2, 50, 50, null);
        $tpl     = '{each_code}[{if_code_name}{code_name}{/if_code_name}]{/each_code}';

        $html = CodesTemplateRenderer::render($tpl, [$named, $unnamed], [], [], '/uploads/codes');

        self::assertSame('[Spring Set][]', $html);
    }

    // -----------------------------------------------------------------------
    // Donor resolution
    // -----------------------------------------------------------------------

    public function testDonorFromCodeSubmitterFieldsTakesPrecedenceOverDonorMap(): void
    {
        $code     = $this->makeCode(1, 50, 50, null, 5, 'Alice', 'https://alice.example/');
        $donorMap = [5 => $this->makeDonor(5, 'Bob', 'https://bob.example/')];
        $tpl      = '{each_code}{code_donor_name}|{code_donor_url}{/each_code}';

        $html = CodesTemplateRenderer::render($tpl, [$code], [], $donorMap, '/uploads/codes');

        self::assertSame('Alice|https://alice.example/', $html);
    }

    public function testDonorFallsBackToDonorMapWhenCodeHasNoSubmitterInfo(): void
    {
        $code     = $this->makeCode(1, 50, 50, null, 5, null, null);
        $donorMap = [5 => $this->makeDonor(5, 'Bob', 'https://bob.example/')];
        $tpl      = '{each_code}{code_donor_name}|{code_donor_url}{/each_code}';

        $html = CodesTemplateRenderer::render($tpl, [$code], [], $donorMap, '/uploads/codes');

        self::assertSame('Bob|https://bob.example/', $html);
    }

    public function testNoDonorWhenNeitherSubmitterNorDonorMapPresent(): void
    {
        $code = $this->makeCode(1, 50, 50);
        $tpl  = '{each_code}[{if_donor}DONOR{/if_donor}{if_no_donor}NO DONOR{/if_no_donor}]{/each_code}';

        $html = CodesTemplateRenderer::render($tpl, [$code], [], [], '/uploads/codes');

        self::assertSame('[NO DONOR]', $html);
    }

    public function testDonorWithoutUrlUsesIfNoDonorUrl(): void
    {
        $code = $this->makeCode(1, 50, 50, null, null, 'Carol', null);
        $tpl  = '{each_code}[{if_donor}{code_donor_name}{/if_donor}{if_donor_url}<a href="{code_donor_url}">link</a>{/if_donor_url}{if_no_donor_url}no-link{/if_no_donor_url}]{/each_code}';

        $html = CodesTemplateRenderer::render($tpl, [$code], [], [], '/uploads/codes');

        self::assertSame('[Carolno-link]', $html);
    }

    public function testDonorMapEntryWithoutUrlAndNoSubmitterUrl(): void
    {
        $code     = $this->makeCode(1, 50, 50, null, 7, null, null);
        $donorMap = [7 => $this->makeDonor(7, 'Dana', null)];
        $tpl      = '{each_code}[{code_donor_name}{if_donor_url}HAS-URL{/if_donor_url}{if_no_donor_url}NO-URL{/if_no_donor_url}]{/each_code}';

        $html = CodesTemplateRenderer::render($tpl, [$code], [], $donorMap, '/uploads/codes');

        self::assertSame('[DanaNO-URL]', $html);
    }

    // -----------------------------------------------------------------------
    // Escaping
    // -----------------------------------------------------------------------

    public function testSpecialCharactersAreEscapedInOutput(): void
    {
        $code = $this->makeCode(1, 50, 50, '<b>Bold</b> & "quoted"', null, 'A & B <Co>');
        $tpl  = '{each_code}{code_name}|{code_donor_name}{/each_code}';

        $html = CodesTemplateRenderer::render($tpl, [$code], [], [], '/uploads/codes');

        self::assertSame('&lt;b&gt;Bold&lt;/b&gt; &amp; &quot;quoted&quot;|A &amp; B &lt;Co&gt;', $html);
    }

    // -----------------------------------------------------------------------
    // Grouped mode
    // -----------------------------------------------------------------------

    public function testGroupedModeRendersGroupsAndCounts(): void
    {
        $code1 = $this->makeCode(1, 50, 50, null, null, null, null, 'a.gif');
        $code2 = $this->makeCode(2, 50, 50, null, null, null, null, 'b.gif');
        $code3 = $this->makeCode(3, 100, 100, null, null, null, null, 'c.gif');

        $grouped = [
            '50x50'   => ['label' => '50×50', 'codes' => [$code1, $code2]],
            '100x100' => ['label' => '100×100', 'codes' => [$code3]],
        ];

        $tpl = '{each_size_group}<h2>{group_size} ({group_code_count})</h2>{each_code}<span>{code_url}</span>{/each_code}{/each_size_group}';

        $html = CodesTemplateRenderer::render($tpl, [$code1, $code2, $code3], $grouped, [], '/uploads/codes');

        self::assertSame(
            '<h2>50×50 (2)</h2><span>/uploads/codes/a.gif</span><span>/uploads/codes/b.gif</span>'
            . '<h2>100×100 (1)</h2><span>/uploads/codes/c.gif</span>',
            $html,
        );
    }

    public function testGroupedModeNeverFiresSizeChanged(): void
    {
        $code1 = $this->makeCode(1, 50, 50, null, null, null, null, 'a.gif');
        $code2 = $this->makeCode(2, 100, 100, null, null, null, null, 'b.gif');

        $grouped = [
            '50x50'   => ['label' => '50×50', 'codes' => [$code1]],
            '100x100' => ['label' => '100×100', 'codes' => [$code2]],
        ];

        $tpl = '{each_size_group}{each_code}{if_size_changed}CHANGED{/if_size_changed}[{code_url}]{/each_code}{/each_size_group}';

        $html = CodesTemplateRenderer::render($tpl, [$code1, $code2], $grouped, [], '/uploads/codes');

        self::assertStringNotContainsString('CHANGED', $html,
            '{if_size_changed} must never fire in grouped mode');
        self::assertStringContainsString('[/uploads/codes/a.gif]', $html);
        self::assertStringContainsString('[/uploads/codes/b.gif]', $html);
    }

    public function testFlatModeSizeChangedFiresOnFirstOfEachGroup(): void
    {
        $code1 = $this->makeCode(1, 50, 50, null, null, null, null, 'a.gif');
        $code2 = $this->makeCode(2, 50, 50, null, null, null, null, 'b.gif');
        $code3 = $this->makeCode(3, 100, 100, null, null, null, null, 'c.gif');

        $tpl = '{each_code}{if_size_changed}[NEW]{/if_size_changed}{code_url};{/each_code}';

        $html = CodesTemplateRenderer::render($tpl, [$code1, $code2, $code3], [], [], '/uploads/codes');

        self::assertSame(
            '[NEW]/uploads/codes/a.gif;/uploads/codes/b.gif;[NEW]/uploads/codes/c.gif;',
            $html,
        );
    }

    // -----------------------------------------------------------------------
    // Page-level conditionals
    // -----------------------------------------------------------------------

    public function testIfNoCodesWhenEmpty(): void
    {
        $tpl = '{if_codes}HAS{/if_codes}{if_no_codes}NONE{/if_no_codes}{each_code}{/each_code}';

        $html = CodesTemplateRenderer::render($tpl, [], [], [], '/uploads/codes');

        self::assertSame('NONE', $html);
    }

    public function testIfCodesWhenNonEmpty(): void
    {
        $code = $this->makeCode(1, 50, 50);
        $tpl  = '{if_codes}HAS{/if_codes}{if_no_codes}NONE{/if_no_codes}{each_code}{/each_code}';

        $html = CodesTemplateRenderer::render($tpl, [$code], [], [], '/uploads/codes');

        self::assertSame('HAS', $html);
    }

    // -----------------------------------------------------------------------
    // Built-in default template (ListingSettingsRepo::defaultCodesTemplate())
    // -----------------------------------------------------------------------

    public function testDefaultTemplateShowsEmptyStateWhenNoCodes(): void
    {
        $html = CodesTemplateRenderer::render(
            ListingSettingsRepo::defaultCodesTemplate(),
            [],
            [],
            [],
            '/uploads/codes',
        );

        self::assertNotNull($html);
        self::assertStringContainsString('No codes available yet.', $html);
        self::assertStringNotContainsString('fl-code-group', $html);
    }

    public function testDefaultTemplateRendersGroupedOutputWithDonor(): void
    {
        $code1 = $this->makeCode(1, 88, 31, 'Classic', 5, null, null, 'classic.gif');
        $code2 = $this->makeCode(2, 88, 31, null, null, 'Jamie', 'https://jamie.example/', 'jamie.gif');

        $grouped = [
            '88x31' => ['label' => '88×31', 'codes' => [$code1, $code2]],
        ];
        $donorMap = [5 => $this->makeDonor(5, 'Riley', 'https://riley.example/')];

        $html = CodesTemplateRenderer::render(
            ListingSettingsRepo::defaultCodesTemplate(),
            [$code1, $code2],
            $grouped,
            $donorMap,
            '/uploads/codes',
        );

        self::assertNotNull($html);
        self::assertStringContainsString('<h2>88×31</h2>', $html);
        self::assertStringContainsString('src="/uploads/codes/classic.gif"', $html);
        self::assertStringContainsString('src="/uploads/codes/jamie.gif"', $html);
        self::assertStringContainsString('<span class="fl-code-caption">Classic</span>', $html);
        self::assertStringContainsString('by <a href="https://riley.example/" target="_blank">Riley</a>', $html);
        self::assertStringContainsString('by <a href="https://jamie.example/" target="_blank">Jamie</a>', $html);
        self::assertStringNotContainsString('No codes available yet.', $html);
    }
}
