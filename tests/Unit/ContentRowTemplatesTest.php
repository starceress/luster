<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\TemplateEngine;
use PHPUnit\Framework\TestCase;

/**
 * Tests the Phase 4 content-row template rendering contracts:
 *   - Codes: flat {each_code} with {if_size_changed} tracking
 *   - Codes: grouped {each_size_group} with nested {each_code}
 *   - Updates: {update_body_html} raw insertion + author/category conditionals
 *   - Wishlist: {type_top}/{type_wanted}/{type_granted} conditionals
 *   - KIM member: {if_kim_url}/{if_no_kim_url} conditionals
 */
final class ContentRowTemplatesTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Codes — flat mode
    // -------------------------------------------------------------------------

    public function testCodesFlatModeTracksIfSizeChanged(): void
    {
        $codes = [
            ['size_label' => '75×50',  'name' => 'a.gif'],
            ['size_label' => '75×50',  'name' => 'b.gif'],
            ['size_label' => '100×50', 'name' => 'c.gif'],
        ];
        $tpl = '{each_code}{if_size_changed}[{code_size}]{/if_size_changed}{code_name};{/each_code}';

        $prevSizeKey = '';
        $result      = TemplateEngine::loop(
            $tpl,
            'code',
            $codes,
            static function (string $inner, array $code) use (&$prevSizeKey): string {
                $sizeChanged = ($prevSizeKey !== $code['size_label']);
                $prevSizeKey = $code['size_label'];
                return TemplateEngine::render(
                    $inner,
                    ['code_size' => $code['size_label'], 'code_name' => $code['name']],
                    [],
                    ['size_changed' => $sizeChanged],
                );
            },
        );

        // First code of each size group gets the header; same-size codes do not.
        self::assertSame('[75×50]a.gif;b.gif;[100×50]c.gif;', $result);
    }

    public function testCodesFlatModeFirstCodeAlwaysGetsHeader(): void
    {
        $codes = [['size_label' => '50×50', 'name' => 'x.png']];
        $tpl   = '{each_code}{if_size_changed}[{code_size}]{/if_size_changed}{code_name}{/each_code}';

        $prevSizeKey = '';
        $result      = TemplateEngine::loop(
            $tpl,
            'code',
            $codes,
            static function (string $inner, array $code) use (&$prevSizeKey): string {
                $sizeChanged = ($prevSizeKey !== $code['size_label']);
                $prevSizeKey = $code['size_label'];
                return TemplateEngine::render(
                    $inner,
                    ['code_size' => $code['size_label'], 'code_name' => $code['name']],
                    [],
                    ['size_changed' => $sizeChanged],
                );
            },
        );

        self::assertSame('[50×50]x.png', $result);
    }

    public function testCodesFlatModeSizeSwitchResetsHeader(): void
    {
        // Three distinct sizes; header must appear exactly three times.
        $codes = [
            ['size_label' => 'A', 'name' => '1'],
            ['size_label' => 'B', 'name' => '2'],
            ['size_label' => 'A', 'name' => '3'],
        ];
        $tpl = '{each_code}{if_size_changed}[{code_size}]{/if_size_changed}{code_name}{/each_code}';

        $prevSizeKey = '';
        $result      = TemplateEngine::loop(
            $tpl,
            'code',
            $codes,
            static function (string $inner, array $code) use (&$prevSizeKey): string {
                $sizeChanged = ($prevSizeKey !== $code['size_label']);
                $prevSizeKey = $code['size_label'];
                return TemplateEngine::render(
                    $inner,
                    ['code_size' => $code['size_label'], 'code_name' => $code['name']],
                    [],
                    ['size_changed' => $sizeChanged],
                );
            },
        );

        self::assertSame('[A]1[B]2[A]3', $result);
    }

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
    // Updates template
    // -------------------------------------------------------------------------

    public function testUpdatesTemplateBodyIsEscapedBeforeRawInsertion(): void
    {
        // The embed pre-escapes the body via nl2br(htmlspecialchars(...)) and
        // passes it as a "raw" value.  Verify the XSS payload is neutralised.
        $body    = '<script>alert("xss")</script>';
        $escaped = nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

        $tpl = '{each_update}{update_body_html}{/each_update}';

        $result = TemplateEngine::loop(
            $tpl,
            'update',
            [['body' => $body]],
            static function (string $inner, array $update) use ($escaped): string {
                return TemplateEngine::render(
                    $inner,
                    [],
                    ['update_body_html' => nl2br(htmlspecialchars(
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
        $tpl = '{each_update}{if_author}by {update_author}{/if_author};{/each_update}';

        $updates = [
            ['author' => 'Alice', 'has_author' => true],
            ['author' => '',      'has_author' => false],
        ];

        $result = TemplateEngine::loop(
            $tpl,
            'update',
            $updates,
            static fn(string $inner, array $u): string => TemplateEngine::render(
                $inner,
                ['update_author' => $u['author']],
                [],
                ['author' => $u['has_author']],
            ),
        );

        self::assertSame('by Alice;;', $result);
    }

    public function testUpdatesTemplateCategoryConditional(): void
    {
        $tpl = '{each_update}{if_categories}({update_categories}){/if_categories}{update_title}{/each_update}';

        $updates = [
            ['title' => 'A', 'cats' => 'Music, Rock', 'has_cats' => true],
            ['title' => 'B', 'cats' => '',             'has_cats' => false],
        ];

        $result = TemplateEngine::loop(
            $tpl,
            'update',
            $updates,
            static fn(string $inner, array $u): string => TemplateEngine::render(
                $inner,
                ['update_title' => $u['title'], 'update_categories' => $u['cats']],
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
