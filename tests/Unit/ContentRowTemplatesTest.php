<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\TemplateEngine;
use PHPUnit\Framework\TestCase;

/**
 * Tests content-row template rendering contracts:
 *   - Codes: grouped {each_size_group} with nested {each_code}
 *   - Newsfeed: {newsfeed_body_html} raw insertion + author/category conditionals
 *   - Wishlist: {type_top}/{type_wanted}/{type_granted} conditionals
 *   - KIM member: {if_kim_url}/{if_no_kim_url} conditionals
 */
final class ContentRowTemplatesTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Codes — grouped mode
    // -------------------------------------------------------------------------

    public function testCodesGroupedModeRendersNestedLoop(): void
    {
        $groups = [
            ['size_label' => '75×50', 'codes' => [
                ['name' => 'a.gif'],
                ['name' => 'b.gif'],
            ]],
            ['size_label' => '100×50', 'codes' => [
                ['name' => 'c.gif'],
            ]],
        ];
        $tpl = '{each_size_group}({group_size}:{group_code_count}){each_code}[{code_name}]{/each_code}{/each_size_group}';

        $result = TemplateEngine::loop(
            $tpl,
            'size_group',
            $groups,
            static function (string $inner, array $group): string {
                $inner = TemplateEngine::tokens($inner, [
                    'group_size'       => $group['size_label'],
                    'group_code_count' => (string) count($group['codes']),
                ]);
                return TemplateEngine::loop(
                    $inner,
                    'code',
                    $group['codes'],
                    static fn(string $ci, array $code): string =>
                        TemplateEngine::tokens($ci, ['code_name' => $code['name']]),
                );
            },
        );

        self::assertSame('(75×50:2)[a.gif][b.gif](100×50:1)[c.gif]', $result);
    }

    public function testCodesGroupedModeDoesNotCascadeTokenLookingUrl(): void
    {
        // A code whose URL value looks like another token (e.g. "{code_size}") must
        // appear literally — strtr's single-pass semantics prevent cascading.
        $groups = [['size_label' => '50×50', 'codes' => [['url' => '{code_size}']]]];
        $tpl    = '{each_size_group}{each_code}{code_url}{/each_code}{/each_size_group}';

        $result = TemplateEngine::loop(
            $tpl,
            'size_group',
            $groups,
            static function (string $inner, array $group): string {
                $inner = TemplateEngine::tokens($inner, ['group_size' => $group['size_label']]);
                return TemplateEngine::loop(
                    $inner,
                    'code',
                    $group['codes'],
                    static fn(string $ci, array $code): string =>
                        TemplateEngine::tokens($ci, ['code_url' => $code['url']]),
                );
            },
        );

        self::assertSame('{code_size}', $result);
    }

    public function testCodesGroupedModeEmptyGroupRendersWrapperWithoutRows(): void
    {
        $groups = [
            ['size_label' => '50×50', 'codes' => []],
            ['size_label' => '75×50', 'codes' => [['name' => 'x.gif']]],
        ];
        $tpl = '{each_size_group}[{group_size}]{each_code}{code_name}{/each_code}{/each_size_group}';

        $result = TemplateEngine::loop(
            $tpl,
            'size_group',
            $groups,
            static function (string $inner, array $group): string {
                $inner = TemplateEngine::tokens($inner, ['group_size' => $group['size_label']]);
                return TemplateEngine::loop(
                    $inner,
                    'code',
                    $group['codes'],
                    static fn(string $ci, array $code): string =>
                        TemplateEngine::tokens($ci, ['code_name' => $code['name']]),
                );
            },
        );

        // Both groups render their header; the empty group produces no code rows.
        self::assertSame('[50×50][75×50]x.gif', $result);
    }

    // -------------------------------------------------------------------------
    // Newsfeed template
    // -------------------------------------------------------------------------

    public function testUpdatesTemplateBodyIsEscapedBeforeRawInsertion(): void
    {
        // The embed pre-escapes the body via nl2br(htmlspecialchars(...)) and
        // passes it as a "raw" value.  Verify the XSS payload is neutralised.
        $body    = '<script>alert("xss")</script>';
        $escaped = nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

        $tpl = '{each_newsfeed_entry}{newsfeed_body_html}{/each_newsfeed_entry}';

        $result = TemplateEngine::loop(
            $tpl,
            'newsfeed_entry',
            [['body' => $body]],
            static function (string $inner, array $update) use ($escaped): string {
                return TemplateEngine::render(
                    $inner,
                    [],
                    ['newsfeed_body_html' => nl2br(htmlspecialchars(
                        $update['body'],
                        ENT_QUOTES | ENT_SUBSTITUTE,
                        'UTF-8',
                    ))],
                );
            },
        );

        self::assertSame($escaped, $result);
        self::assertStringNotContainsString('<script>', $result);
    }

    public function testUpdatesTemplateAuthorConditional(): void
    {
        $tpl = '{each_newsfeed_entry}{if_author}by {newsfeed_author}{/if_author};{/each_newsfeed_entry}';

        $updates = [
            ['author' => 'Alice', 'has_author' => true],
            ['author' => '',      'has_author' => false],
        ];

        $result = TemplateEngine::loop(
            $tpl,
            'newsfeed_entry',
            $updates,
            static fn(string $inner, array $u): string => TemplateEngine::render(
                $inner,
                ['newsfeed_author' => $u['author']],
                [],
                ['author' => $u['has_author']],
            ),
        );

        self::assertSame('by Alice;;', $result);
    }

    public function testUpdatesTemplateCategoryConditional(): void
    {
        $tpl = '{each_newsfeed_entry}{if_categories}({newsfeed_categories}){/if_categories}{newsfeed_title}{/each_newsfeed_entry}';

        $updates = [
            ['title' => 'A', 'cats' => 'Music, Rock', 'has_cats' => true],
            ['title' => 'B', 'cats' => '',             'has_cats' => false],
        ];

        $result = TemplateEngine::loop(
            $tpl,
            'newsfeed_entry',
            $updates,
            static fn(string $inner, array $u): string => TemplateEngine::render(
                $inner,
                ['newsfeed_title' => $u['title'], 'newsfeed_categories' => $u['cats']],
                [],
                ['categories' => $u['has_cats']],
            ),
        );

        self::assertSame('(Music, Rock)AB', $result);
    }

    // -------------------------------------------------------------------------
    // Wishlist type conditionals
    // -------------------------------------------------------------------------

    public function testWishlistTypeConditionalsAreExclusive(): void
    {
        $tpl = '{each_wishlist_item}'
             . '{if_type_top}[TOP]{/if_type_top}'
             . '{if_type_wanted}[WANTED]{/if_type_wanted}'
             . '{if_type_granted}[GRANTED]{/if_type_granted}'
             . '{item_subject},'
             . '{/each_wishlist_item}';

        $entries = [
            ['subject' => 'A', 'type' => 'top'],
            ['subject' => 'B', 'type' => 'wanted'],
            ['subject' => 'C', 'type' => 'granted'],
        ];

        $result = TemplateEngine::loop(
            $tpl,
            'wishlist_item',
            $entries,
            static fn(string $inner, array $e): string => TemplateEngine::render(
                $inner,
                ['item_subject' => $e['subject']],
                [],
                [
                    'type_top'     => $e['type'] === 'top',
                    'type_wanted'  => $e['type'] === 'wanted',
                    'type_granted' => $e['type'] === 'granted',
                ],
            ),
        );

        self::assertSame('[TOP]A,[WANTED]B,[GRANTED]C,', $result);
    }

    public function testWishlistItemUrlConditional(): void
    {
        $tpl = '{each_wishlist_item}'
             . '{if_item_url}<a href="{item_url}">{item_subject}</a>{/if_item_url}'
             . '{if_no_item_url}{item_subject}{/if_no_item_url}'
             . '{/each_wishlist_item}';

        $entries = [
            ['subject' => 'Alpha', 'url' => 'https://alpha.test'],
            ['subject' => 'Beta',  'url' => ''],
        ];

        $result = TemplateEngine::loop(
            $tpl,
            'wishlist_item',
            $entries,
            static fn(string $inner, array $e): string => TemplateEngine::render(
                $inner,
                ['item_subject' => $e['subject'], 'item_url' => $e['url']],
                [],
                ['item_url' => $e['url'] !== '', 'no_item_url' => $e['url'] === ''],
            ),
        );

        self::assertSame('<a href="https://alpha.test">Alpha</a>Beta', $result);
    }

    public function testWishlistCategoryAndDescriptionConditionals(): void
    {
        $tpl = '{each_wishlist_item}'
             . '{if_item_category}({item_category}){/if_item_category}'
             . '{if_item_description}: {item_description}{/if_item_description}'
             . '{item_subject}'
             . '{/each_wishlist_item}';

        $entries = [
            ['subject' => 'A', 'category' => 'Anime', 'description' => 'Fave'],
            ['subject' => 'B', 'category' => '',       'description' => ''],
        ];

        $result = TemplateEngine::loop(
            $tpl,
            'wishlist_item',
            $entries,
            static fn(string $inner, array $e): string => TemplateEngine::render(
                $inner,
                ['item_subject' => $e['subject'], 'item_category' => $e['category'], 'item_description' => $e['description']],
                [],
                ['item_category' => $e['category'] !== '', 'item_description' => $e['description'] !== ''],
            ),
        );

        self::assertSame('(Anime): FaveAB', $result);
    }

    public function testWishlistTypeScopedLoopsOnlyIncludeMatchingType(): void
    {
        $tpl = '{each_wishlist_item_top}[T:{item_subject}]{/each_wishlist_item_top}'
             . '{each_wishlist_item_wanted}[W:{item_subject}]{/each_wishlist_item_wanted}'
             . '{each_wishlist_item_granted}[G:{item_subject}]{/each_wishlist_item_granted}';

        $entries = [
            ['subject' => 'A', 'type' => 'top'],
            ['subject' => 'B', 'type' => 'wanted'],
            ['subject' => 'C', 'type' => 'granted'],
            ['subject' => 'D', 'type' => 'top'],
        ];

        $rowFn = static fn(string $inner, array $e): string => TemplateEngine::render(
            $inner,
            ['item_subject' => $e['subject']],
        );

        $top     = array_values(array_filter($entries, static fn(array $e): bool => $e['type'] === 'top'));
        $wanted  = array_values(array_filter($entries, static fn(array $e): bool => $e['type'] === 'wanted'));
        $granted = array_values(array_filter($entries, static fn(array $e): bool => $e['type'] === 'granted'));

        $result = TemplateEngine::loop($tpl, 'wishlist_item_top', $top, $rowFn);
        $result = TemplateEngine::loop($result, 'wishlist_item_wanted', $wanted, $rowFn);
        $result = TemplateEngine::loop($result, 'wishlist_item_granted', $granted, $rowFn);

        self::assertSame('[T:A][T:D][W:B][G:C]', $result);
    }

    public function testWishlistHasTypeConditionalsHideEmptyGroups(): void
    {
        $tpl = '{if_has_top}<h2>Top Wanted</h2>{each_wishlist_item_top}{item_subject}{/each_wishlist_item_top}{/if_has_top}'
             . '{if_has_wanted}<h2>Wanted</h2>{each_wishlist_item_wanted}{item_subject}{/each_wishlist_item_wanted}{/if_has_wanted}'
             . '{if_has_granted}<h2>Granted</h2>{each_wishlist_item_granted}{item_subject}{/each_wishlist_item_granted}{/if_has_granted}';

        $entries = [
            ['subject' => 'A', 'type' => 'wanted'],
            ['subject' => 'B', 'type' => 'wanted'],
        ];

        $rowFn = static fn(string $inner, array $e): string => TemplateEngine::render(
            $inner,
            ['item_subject' => $e['subject']],
        );

        $top     = array_values(array_filter($entries, static fn(array $e): bool => $e['type'] === 'top'));
        $wanted  = array_values(array_filter($entries, static fn(array $e): bool => $e['type'] === 'wanted'));
        $granted = array_values(array_filter($entries, static fn(array $e): bool => $e['type'] === 'granted'));

        // Shell-first ordering, matching public/embed/wishlist.php.
        $result = TemplateEngine::conditionals($tpl, [
            'has_top'     => $top     !== [],
            'has_wanted'  => $wanted  !== [],
            'has_granted' => $granted !== [],
        ]);
        $result = TemplateEngine::loop($result, 'wishlist_item_top', $top, $rowFn);
        $result = TemplateEngine::loop($result, 'wishlist_item_wanted', $wanted, $rowFn);
        $result = TemplateEngine::loop($result, 'wishlist_item_granted', $granted, $rowFn);

        self::assertSame('<h2>Wanted</h2>AB', $result);
    }

    // -------------------------------------------------------------------------
    // KIM member URL conditionals
    // -------------------------------------------------------------------------

    public function testKimMemberWithUrlRendersLink(): void
    {
        $tpl = '{each_kim_member}'
             . '{if_kim_url}<a href="{kim_url}">{kim_name}</a>{/if_kim_url}'
             . '{if_no_kim_url}{kim_name}{/if_no_kim_url}'
             . ';{/each_kim_member}';

        $members = [
            ['name' => 'Alice', 'url' => 'https://alice.example'],
            ['name' => 'Bob',   'url' => ''],
        ];

        $result = TemplateEngine::loop(
            $tpl,
            'kim_member',
            $members,
            static fn(string $inner, array $m): string => TemplateEngine::render(
                $inner,
                ['kim_name' => $m['name'], 'kim_url' => $m['url']],
                [],
                ['kim_url' => $m['url'] !== '', 'no_kim_url' => $m['url'] === ''],
            ),
        );

        self::assertSame('<a href="https://alice.example">Alice</a>;Bob;', $result);
    }

    public function testKimMemberListingTokensAreAvailable(): void
    {
        $tpl = '{each_kim_member}{kim_name} — {listing_title} ({listing_subject}){/each_kim_member}';

        $members = [['name' => 'Carol']];

        $result = TemplateEngine::loop(
            $tpl,
            'kim_member',
            $members,
            static fn(string $inner, array $m): string => TemplateEngine::render(
                $inner,
                [
                    'kim_name'        => $m['name'],
                    'listing_title'   => 'Taylor Swift Fanlisting',
                    'listing_subject' => 'Taylor Swift',
                ],
            ),
        );

        self::assertSame('Carol — Taylor Swift Fanlisting (Taylor Swift)', $result);
    }

    public function testKimMemberNameWithSpecialCharsIsEscaped(): void
    {
        $tpl     = '{each_kim_member}{kim_name}{/each_kim_member}';
        $members = [['name' => '<script>alert(1)</script>']];

        $result = TemplateEngine::loop(
            $tpl,
            'kim_member',
            $members,
            static fn(string $inner, array $m): string =>
                TemplateEngine::render($inner, ['kim_name' => $m['name']]),
        );

        self::assertStringNotContainsString('<script>', $result);
        self::assertStringContainsString('&lt;script&gt;', $result);
    }
}
