<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Pagination;
use PHPUnit\Framework\TestCase;

final class PaginationTest extends TestCase
{
    public function testReturnsEmptyStringWhenTotalIsOneOrLess(): void
    {
        self::assertSame('', Pagination::render(1, 1, '/members'));
        self::assertSame('', Pagination::render(1, 0, '/members'));
    }

    public function testDefaultTemplateRendersDivWithPrevAndNextLinks(): void
    {
        $html = Pagination::render(3, 5, '/members');

        self::assertStringContainsString('<div class="fl-pagination-nav fl-pagination"', $html);
        self::assertStringNotContainsString('<nav', $html);
        self::assertStringContainsString('fl-page-prev', $html);
        self::assertStringContainsString('fl-page-next', $html);
        self::assertStringContainsString('href="/members?page=2"', $html);
        self::assertStringContainsString('href="/members?page=4"', $html);
    }

    public function testFirstPageOmitsPrevLinkAndLastPageOmitsNextLink(): void
    {
        $first = Pagination::render(1, 5, '/members');
        $last  = Pagination::render(5, 5, '/members');

        self::assertStringNotContainsString('fl-page-prev', $first);
        self::assertStringContainsString('fl-page-next', $first);

        self::assertStringContainsString('fl-page-prev', $last);
        self::assertStringNotContainsString('fl-page-next', $last);
    }

    public function testCurrentPageIsRenderedAsNonLinkedSpan(): void
    {
        $html = Pagination::render(3, 5, '/members');

        self::assertStringContainsString('<span class="fl-page-current">3</span>', $html);
        self::assertStringNotContainsString('href="/members?page=3"', $html);
    }

    public function testWindowAlwaysIncludesFirstAndLastPageWithGapMarkers(): void
    {
        $html = Pagination::render(10, 20, '/members');

        self::assertStringContainsString('href="/members?page=1"', $html);
        self::assertStringContainsString('href="/members?page=20"', $html);
        self::assertStringContainsString('fl-page-gap', $html);
        self::assertStringContainsString('…', $html);
    }

    public function testSmallPageCountHasNoGapMarkers(): void
    {
        $html = Pagination::render(2, 4, '/members');

        self::assertStringNotContainsString('fl-page-gap', $html);
        self::assertStringContainsString('href="/members?page=1"', $html);
        self::assertStringContainsString('href="/members?page=4"', $html);
    }

    public function testExtraParamsAreMergedIntoPageUrls(): void
    {
        $html = Pagination::render(2, 3, '/members', '', ['sort' => 'name', 'dir' => 'asc']);

        self::assertStringContainsString('sort=name', $html);
        self::assertStringContainsString('dir=asc', $html);
        self::assertStringContainsString('page=1', $html);
    }

    public function testCustomTemplateReceivesEachPageLoopAndOuterTokens(): void
    {
        $tpl = '{current_page}/{total_pages}|{each_page}{if_current}*{page_label}*{/if_current}{if_linked}[{page_label}:{page_url}]{/if_linked}{if_gap}({page_label}){/if_gap}{/each_page}';

        $html = Pagination::render(2, 3, '/members', $tpl);

        self::assertSame('2/3|[1:/members?page=1]*2*[3:/members?page=3]', $html);
    }

    public function testUrlsAreHtmlEscapedForAttributeContext(): void
    {
        $html = Pagination::render(1, 2, '/members', '', ['q' => 'rock & roll']);

        self::assertStringContainsString('q=rock+%26+roll', $html);
        self::assertStringContainsString('href="/members?q=rock+%26+roll&amp;page=2"', $html);
    }

    public function testBaseUrlWithExistingQueryStringIsMergedNotAppended(): void
    {
        $tpl = '{each_page}{if_linked}[{page_url}]{/if_linked}{/each_page}';

        $html = Pagination::render(2, 3, '/members?sort=name', $tpl);

        // Must merge into one query string ("?sort=name&page=N"), never produce
        // a second "?" like "/members?sort=name?page=1". The "&" is escaped to
        // "&amp;" by the template engine's attribute-context escaping.
        self::assertStringNotContainsString('?sort=name?', $html);
        self::assertStringContainsString('[/members?sort=name&amp;page=1]', $html);
        self::assertStringContainsString('[/members?sort=name&amp;page=3]', $html);
    }

    public function testBaseUrlQueryParamsAndExtraParamsBothAppearWithExtraParamsTakingPrecedence(): void
    {
        $tpl = '{each_page}{if_linked}[{page_url}]{/if_linked}{/each_page}';

        $html = Pagination::render(2, 3, '/members?sort=name&dir=asc', $tpl, ['dir' => 'desc', 'filter' => 'us']);

        self::assertStringContainsString('[/members?sort=name&amp;dir=desc&amp;filter=us&amp;page=1]', $html);
        self::assertStringContainsString('[/members?sort=name&amp;dir=desc&amp;filter=us&amp;page=3]', $html);
    }

    public function testBaseUrlWithoutQueryStringStillProducesCleanUrls(): void
    {
        $tpl = '{each_page}{if_linked}[{page_url}]{/if_linked}{/each_page}';

        $html = Pagination::render(2, 3, '/members', $tpl);

        self::assertStringContainsString('[/members?page=1]', $html);
        self::assertStringNotContainsString('??', $html);
    }
}
