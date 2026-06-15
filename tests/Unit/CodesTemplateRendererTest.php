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
    // Fixture helpers — shapes match CodesRepo / CodeCategoriesRepo / CodeDonorsRepo
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

    private function makeCategory(int $id, string $name): object
    {
        return (object) [
            'id'        => $id,
            'listingId' => 1,
            'name'      => $name,
            'parentId'  => null,
            'sortOrder' => 0,
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
            [],
            [],
            '/uploads/codes',
            'My Listing',
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
            [],
            [],
            '/uploads/codes',
            'My Listing',
        );

        self::assertNull($result);
    }

    // -----------------------------------------------------------------------
    // Flat mode — per-code tokens
    // -----------------------------------------------------------------------

    public function testFlatModeCodeUrlAndAltWithSize(): void
    {
        $code = $this->makeCode(1, 50, 50, null, null, null, null, 'abc123.gif');
        $tpl  = '{each_code}<img src="{code_url}" alt="{code_alt}">{/each_code}';

        $html = CodesTemplateRenderer::render($tpl, [$code], [], [], [], [], '/uploads/codes', 'My Listing');

        self::assertSame('<img src="/uploads/codes/abc123.gif" alt="50×50">', $html);
    }

    public function testFlatModeSizeTokensWhenDimensionsPresent(): void
    {
        $code = $this->makeCode(1, 88, 31);
        $tpl  = '{each_code}{if_size}{code_width}x{code_height} ({code_size}){/if_size}{/each_code}';

        $html = CodesTemplateRenderer::render($tpl, [$code], [], [], [], [], '/uploads/codes', 'My Listing');

        self::assertSame('88x31 (88×31)', $html);
    }

    public function testFlatModeNoSizeWhenDimensionsZero(): void
    {
        $code = $this->makeCode(1, 0, 0);
        $tpl  = '{each_code}[{if_size}HAS SIZE{/if_size}{code_width}|{code_height}|{code_size}]{/each_code}';

        $html = CodesTemplateRenderer::render($tpl, [$code], [], [], [], [], '/uploads/codes', 'My Listing');

        self::assertSame('[||]', $html);
    }

    public function testFlatModeAltFallsBackToListingNameWhenNoSizeOrDonor(): void
    {
        $code = $this->makeCode(1, 0, 0);
        $tpl  = '{each_code}{code_alt}{/each_code}';

        $html = CodesTemplateRenderer::render($tpl, [$code], [], [], [], [], '/uploads/codes', 'Sparkle Fanlisting');

        self::assertSame('Sparkle Fanlisting code', $html);
    }

    public function testFlatModeAltPrefersSizeOverListingNameWhenNoDonor(): void
    {
        $code = $this->makeCode(1, 80, 15);
        $tpl  = '{each_code}{code_alt}{/each_code}';

        $html = CodesTemplateRenderer::render($tpl, [$code], [], [], [], [], '/uploads/codes', 'Sparkle Fanlisting');

        self::assertSame('80×15', $html);
    }

    public function testFlatModeAltPrefersDonorOverSize(): void
    {
        $code = $this->makeCode(1, 80, 15, null, null, 'Jamie');
        $tpl  = '{each_code}{code_alt}{/each_code}';

        $html = CodesTemplateRenderer::render($tpl, [$code], [], [], [], [], '/uploads/codes', 'Sparkle Fanlisting');

        self::assertSame('Donated by Jamie', $html);
    }

    public function testFlatModeCodeNameConditional(): void
    {
        $named   = $this->makeCode(1, 50, 50, 'Spring Set');
        $unnamed = $this->makeCode(2, 50, 50, null);
        $tpl     = '{each_code}[{if_code_name}{code_name}{/if_code_name}]{/each_code}';

        $html = CodesTemplateRenderer::render($tpl, [$named, $unnamed], [], [], [], [], '/uploads/codes', 'My Listing');

        self::assertSame('[Spring Set][]', $html);
    }

    public function testFlatModeSizeChangedTracksConsecutiveCodes(): void
    {
        $codes = [
            $this->makeCode(1, 50, 50),
            $this->makeCode(2, 50, 50),
            $this->makeCode(3, 100, 100),
            $this->makeCode(4, 50, 50),
        ];
        $tpl = '{each_code}{if_size_changed}NEW:{/if_size_changed}{code_size}|{/each_code}';

        $html = CodesTemplateRenderer::render($tpl, $codes, [], [], [], [], '/uploads/codes', 'My Listing');

        self::assertSame('NEW:50×50|50×50|NEW:100×100|NEW:50×50|', $html);
    }

    public function testFlatModeSizeChangedGroupsZeroDimensionCodesAsUnknown(): void
    {
        $codes = [
            $this->makeCode(1, 0, 0),
            $this->makeCode(2, 0, 0),
            $this->makeCode(3, 50, 50),
        ];
        $tpl = '{each_code}{if_size_changed}NEW {/if_size_changed}#{/each_code}';

        $html = CodesTemplateRenderer::render($tpl, $codes, [], [], [], [], '/uploads/codes', 'My Listing');

        self::assertSame('NEW ##NEW #', $html);
    }

    // -----------------------------------------------------------------------
    // Donor resolution
    // -----------------------------------------------------------------------

    public function testDonorFromCodeSubmitterFieldsTakesPrecedenceOverDonorMap(): void
    {
        $code     = $this->makeCode(1, 50, 50, null, 5, 'Alice', 'https://alice.example/');
        $donorMap = [5 => $this->makeDonor(5, 'Bob', 'https://bob.example/')];
        $tpl      = '{each_code}{code_donor_name}|{code_donor_url}{/each_code}';

        $html = CodesTemplateRenderer::render($tpl, [$code], [], [], [], $donorMap, '/uploads/codes', 'My Listing');

        self::assertSame('Alice|https://alice.example/', $html);
    }

    public function testDonorFallsBackToDonorMapWhenCodeHasNoSubmitterInfo(): void
    {
        $code     = $this->makeCode(1, 50, 50, null, 5, null, null);
        $donorMap = [5 => $this->makeDonor(5, 'Bob', 'https://bob.example/')];
        $tpl      = '{each_code}{code_donor_name}|{code_donor_url}{/each_code}';

        $html = CodesTemplateRenderer::render($tpl, [$code], [], [], [], $donorMap, '/uploads/codes', 'My Listing');

        self::assertSame('Bob|https://bob.example/', $html);
    }

    public function testNoDonorWhenNeitherSubmitterNorDonorMapPresent(): void
    {
        $code = $this->makeCode(1, 50, 50);
        $tpl  = '{each_code}[{if_donor}DONOR{/if_donor}{if_no_donor}NO DONOR{/if_no_donor}]{/each_code}';

        $html = CodesTemplateRenderer::render($tpl, [$code], [], [], [], [], '/uploads/codes', 'My Listing');

        self::assertSame('[NO DONOR]', $html);
    }

    public function testDonorWithoutUrlUsesIfNoDonorUrl(): void
    {
        $code = $this->makeCode(1, 50, 50, null, null, 'Carol', null);
        $tpl  = '{each_code}[{if_donor}{code_donor_name}{/if_donor}{if_donor_url}<a href="{code_donor_url}">link</a>{/if_donor_url}{if_no_donor_url}no-link{/if_no_donor_url}]{/each_code}';

        $html = CodesTemplateRenderer::render($tpl, [$code], [], [], [], [], '/uploads/codes', 'My Listing');

        self::assertSame('[Carolno-link]', $html);
    }

    public function testDonorMapEntryWithoutUrlAndNoSubmitterUrl(): void
    {
        $code     = $this->makeCode(1, 50, 50, null, 7, null, null);
        $donorMap = [7 => $this->makeDonor(7, 'Dana', null)];
        $tpl      = '{each_code}[{code_donor_name}{if_donor_url}HAS-URL{/if_donor_url}{if_no_donor_url}NO-URL{/if_no_donor_url}]{/each_code}';

        $html = CodesTemplateRenderer::render($tpl, [$code], [], [], [], $donorMap, '/uploads/codes', 'My Listing');

        self::assertSame('[DanaNO-URL]', $html);
    }

    // -----------------------------------------------------------------------
    // Categories
    // -----------------------------------------------------------------------

    public function testCategoriesRenderedFromCatMapAndCategoryMap(): void
    {
        $code        = $this->makeCode(1, 50, 50);
        $catMap      = [1 => [10, 20]];
        $categoryMap = [
            10 => $this->makeCategory(10, 'Anime'),
            20 => $this->makeCategory(20, 'Games'),
        ];
        $tpl = '{each_code}[{if_categories}{code_categories}{/if_categories}]{/each_code}';

        $html = CodesTemplateRenderer::render($tpl, [$code], [], $catMap, $categoryMap, [], '/uploads/codes', 'My Listing');

        self::assertSame('[Anime, Games]', $html);
    }

    public function testNoCategoriesWhenCatMapEmpty(): void
    {
        $code = $this->makeCode(1, 50, 50);
        $tpl  = '{each_code}[{if_categories}HAS{/if_categories}{code_categories}]{/each_code}';

        $html = CodesTemplateRenderer::render($tpl, [$code], [], [], [], [], '/uploads/codes', 'My Listing');

        self::assertSame('[]', $html);
    }

    public function testCategoryIdsNotInCategoryMapAreSkipped(): void
    {
        $code        = $this->makeCode(1, 50, 50);
        $catMap      = [1 => [10, 99]];
        $categoryMap = [10 => $this->makeCategory(10, 'Anime')];
        $tpl         = '{each_code}{code_categories}{/each_code}';

        $html = CodesTemplateRenderer::render($tpl, [$code], [], $catMap, $categoryMap, [], '/uploads/codes', 'My Listing');

        self::assertSame('Anime', $html);
    }

    // -----------------------------------------------------------------------
    // Escaping
    // -----------------------------------------------------------------------

    public function testSpecialCharactersAreEscapedInOutput(): void
    {
        $code = $this->makeCode(1, 50, 50, '<b>Bold</b> & "quoted"', null, 'A & B <Co>');
        $tpl  = '{each_code}{code_name}|{code_donor_name}{/each_code}';

        $html = CodesTemplateRenderer::render($tpl, [$code], [], [], [], [], '/uploads/codes', 'My Listing');

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

        $html = CodesTemplateRenderer::render($tpl, [$code1, $code2, $code3], $grouped, [], [], [], '/uploads/codes', 'My Listing');

        self::assertSame(
            '<h2>50×50 (2)</h2><span>/uploads/codes/a.gif</span><span>/uploads/codes/b.gif</span>'
            . '<h2>100×100 (1)</h2><span>/uploads/codes/c.gif</span>',
            $html,
        );
    }

    public function testGroupedModeNeverSetsSizeChanged(): void
    {
        $code1 = $this->makeCode(1, 50, 50);
        $code2 = $this->makeCode(2, 50, 50);

        $grouped = ['50x50' => ['label' => '50×50', 'codes' => [$code1, $code2]]];

        $tpl = '{each_size_group}{each_code}[{if_size_changed}CHANGED{/if_size_changed}]{/each_code}{/each_size_group}';

        $html = CodesTemplateRenderer::render($tpl, [$code1, $code2], $grouped, [], [], [], '/uploads/codes', 'My Listing');

        self::assertSame('[][]', $html);
    }

    // -----------------------------------------------------------------------
    // Page-level conditionals
    // -----------------------------------------------------------------------

    public function testIfNoCodesWhenEmpty(): void
    {
        $tpl = '{if_codes}HAS{/if_codes}{if_no_codes}NONE{/if_no_codes}{each_code}{/each_code}';

        $html = CodesTemplateRenderer::render($tpl, [], [], [], [], [], '/uploads/codes', 'My Listing');

        self::assertSame('NONE', $html);
    }

    public function testIfCodesWhenNonEmpty(): void
    {
        $code = $this->makeCode(1, 50, 50);
        $tpl  = '{if_codes}HAS{/if_codes}{if_no_codes}NONE{/if_no_codes}{each_code}{/each_code}';

        $html = CodesTemplateRenderer::render($tpl, [$code], [], [], [], [], '/uploads/codes', 'My Listing');

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
            [],
            [],
            '/uploads/codes',
            'My Fanlisting',
        );

        self::assertNotNull($html);
        self::assertStringContainsString('No codes available yet.', $html);
        self::assertStringNotContainsString('fl-code-group', $html);
    }

    public function testDefaultTemplateRendersGroupedOutputWithDonorAndCategory(): void
    {
        $code1 = $this->makeCode(1, 88, 31, 'Classic', 5, null, null, 'classic.gif');
        $code2 = $this->makeCode(2, 88, 31, null, null, 'Jamie', 'https://jamie.example/', 'jamie.gif');

        $grouped = [
            '88x31' => ['label' => '88×31', 'codes' => [$code1, $code2]],
        ];
        $catMap      = [1 => [10]];
        $categoryMap = [10 => $this->makeCategory(10, 'Anime')];
        $donorMap    = [5 => $this->makeDonor(5, 'Riley', 'https://riley.example/')];

        $html = CodesTemplateRenderer::render(
            ListingSettingsRepo::defaultCodesTemplate(),
            [$code1, $code2],
            $grouped,
            $catMap,
            $categoryMap,
            $donorMap,
            '/uploads/codes',
            'My Fanlisting',
        );

        self::assertNotNull($html);
        self::assertStringContainsString('<h2 class="fl-code-group-label">88×31</h2>', $html);
        self::assertStringContainsString('src="/uploads/codes/classic.gif"', $html);
        self::assertStringContainsString('src="/uploads/codes/jamie.gif"', $html);
        self::assertStringContainsString('alt="Donated by Riley"', $html);
        self::assertStringContainsString('<span class="fl-code-caption">Classic</span>', $html);
        self::assertStringContainsString('<span class="fl-code-caption">Anime</span>', $html);
        self::assertStringContainsString('by <a href="https://riley.example/" target="_blank" rel="noopener noreferrer">Riley</a>', $html);
        self::assertStringContainsString('by <a href="https://jamie.example/" target="_blank" rel="noopener noreferrer">Jamie</a>', $html);
        self::assertStringNotContainsString('No codes available yet.', $html);
    }
}
