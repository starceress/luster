<?php

declare(strict_types=1);

namespace Luster\Tests\Unit\Support;

use Luster\Support\PreviousOwnersRenderer;
use PHPUnit\Framework\TestCase;

final class PreviousOwnersRendererTest extends TestCase
{
    /**
     * Simulates the normal stats-token render that runs between shield() and
     * resolve() in real usage (public/embed/stats.php, ListingController::
     * renderStatsTemplate()) — a plain strtr() flat-token pass, standing in for
     * TemplateEngine::tokens()/the hand-rolled str_replace() pipeline.
     *
     * @param array<string, string> $values
     */
    private function simulateStatsPass(string $tpl, array $values): string
    {
        $map = [];
        foreach ($values as $key => $value) {
            $map['{' . $key . '}'] = $value;
        }
        return strtr($tpl, $map);
    }

    private function renderThrough(string $tpl, array $owners, array $statsValues = []): string
    {
        $shielded = PreviousOwnersRenderer::shield($tpl);
        $rendered = $this->simulateStatsPass($shielded, $statsValues);
        return PreviousOwnersRenderer::resolve($rendered, $owners);
    }

    public function testFlatListLinksOwnersWithUrls(): void
    {
        $out = $this->renderThrough('{previous_owners}', [
            ['owner_name' => 'Alice', 'owner_url' => 'https://alice.example.com'],
        ]);
        self::assertSame('<a href="https://alice.example.com">Alice</a>', $out);
    }

    public function testFlatListNameOnlyRowIsPlainTextNotEmptyLink(): void
    {
        $out = $this->renderThrough('{previous_owners}', [
            ['owner_name' => 'Bob', 'owner_url' => ''],
        ]);
        self::assertSame('Bob', $out);
        self::assertStringNotContainsString('<a href=""', $out);
        self::assertStringNotContainsString('href=""', $out);
    }

    public function testFlatListUrlOnlyRowUsesUrlAsLabel(): void
    {
        $out = $this->renderThrough('{previous_owners}', [
            ['owner_name' => '', 'owner_url' => 'https://example.com'],
        ]);
        self::assertSame('<a href="https://example.com">https://example.com</a>', $out);
    }

    public function testFlatListJoinsMultipleOwnersWithComma(): void
    {
        $out = $this->renderThrough('{previous_owners}', [
            ['owner_name' => 'Alice', 'owner_url' => ''],
            ['owner_name' => 'Bob', 'owner_url' => ''],
        ]);
        self::assertSame('Alice, Bob', $out);
    }

    public function testCountToken(): void
    {
        self::assertSame('0', $this->renderThrough('{previous_owners_count}', []));
        self::assertSame('2', $this->renderThrough('{previous_owners_count}', [
            ['owner_name' => 'A', 'owner_url' => ''],
            ['owner_name' => 'B', 'owner_url' => ''],
        ]));
    }

    public function testIfPreviousOwnersShowsOnlyWhenOwnersExist(): void
    {
        $tpl = '{if_previous_owners}shown{/if_previous_owners}';
        self::assertSame('', $this->renderThrough($tpl, []));
        self::assertSame('shown', $this->renderThrough($tpl, [['owner_name' => 'A', 'owner_url' => '']]));
    }

    public function testEachPreviousOwnerLoopRendersOneRowPerOwner(): void
    {
        $tpl = '{each_previous_owner}<li>{previous_owner_name}</li>{/each_previous_owner}';
        $out = $this->renderThrough($tpl, [
            ['owner_name' => 'Alice', 'owner_url' => ''],
            ['owner_name' => 'Bob', 'owner_url' => ''],
        ]);
        self::assertSame('<li>Alice</li><li>Bob</li>', $out);
    }

    public function testEachPreviousOwnerRowUrlAndConditional(): void
    {
        $tpl = '{each_previous_owner}{previous_owner_name}{if_previous_owner_url} ({previous_owner_url}){/if_previous_owner_url}{/each_previous_owner}';
        $out = $this->renderThrough($tpl, [
            ['owner_name' => 'Alice', 'owner_url' => 'https://alice.example.com'],
            ['owner_name' => 'Bob', 'owner_url' => ''],
        ]);
        self::assertSame('Alice (https://alice.example.com)Bob', $out);
    }

    // --- Ordering/injection safety regressions caught in review ---

    public function testOwnerNameContainingStatTokenSyntaxSurvivesAsPlainText(): void
    {
        // The stats pass runs BEFORE resolve(); an owner name is only ever
        // inserted by resolve(), so it must never be scanned by the stats pass.
        $out = $this->renderThrough('{stat_approved} owners: {previous_owners}', [
            ['owner_name' => '{stat_approved}', 'owner_url' => ''],
        ], ['stat_approved' => '42']);

        self::assertSame('42 owners: {stat_approved}', $out);
    }

    public function testListingTitleContainingPreviousOwnersTokenSyntaxSurvives(): void
    {
        // The reverse direction: text substituted BY the stats pass (a listing
        // title, here) must never be re-scanned by resolve()'s sentinel swap.
        $out = $this->renderThrough('{listing_title} / {previous_owners}', [
            ['owner_name' => 'Real Owner', 'owner_url' => ''],
        ], ['listing_title' => '{previous_owners} and {if_previous_owners}fake{/if_previous_owners}']);

        self::assertSame('{previous_owners} and {if_previous_owners}fake{/if_previous_owners} / Real Owner', $out);
    }

    public function testNormalStatsTokenNestedInsideIfPreviousOwnersResolvesNormally(): void
    {
        $tpl = '{if_previous_owners}<b>{listing_title}</b> was previously owned by {previous_owners}{/if_previous_owners}';
        $out = $this->renderThrough($tpl, [
            ['owner_name' => 'Alice', 'owner_url' => ''],
        ], ['listing_title' => 'Silver Crystal']);

        self::assertSame('<b>Silver Crystal</b> was previously owned by Alice', $out);
    }

    public function testKnownStatsTokenInsideEachRowStillResolvesNormally(): void
    {
        // shield() only sentinels the four previous-owner delimiter/flat-token
        // strings — a row's body (everything between {each_previous_owner} and
        // {/each_previous_owner}) is otherwise left completely live for the
        // stats pass, same as content inside {if_previous_owners}. A real stats
        // token nested in a row template resolves normally (repeated per row).
        $tpl = '{each_previous_owner}{previous_owner_name}:{stat_approved}{/each_previous_owner}';
        $out = $this->renderThrough($tpl, [
            ['owner_name' => 'Alice', 'owner_url' => ''],
        ], ['stat_approved' => '42']);

        self::assertSame('Alice:42', $out);
    }

    public function testUnknownTokenInsideEachRowStaysLiteral(): void
    {
        // A token name that is neither a real row token
        // ({previous_owner_name}/{previous_owner_url}/{if_previous_owner_url})
        // nor present in the simulated stats pass's own value map — proving
        // renderRow()'s strtr() only ever touches its own three known tokens,
        // not the row body's other unrelated text.
        $tpl = '{each_previous_owner}{previous_owner_name}:{some_unrelated_token}{/each_previous_owner}';
        $out = $this->renderThrough($tpl, [
            ['owner_name' => 'Alice', 'owner_url' => ''],
        ], ['stat_approved' => '42']);

        self::assertSame('Alice:{some_unrelated_token}', $out);
    }

    public function testOwnerNameLiterallyPreviousOwnerUrlIsNotRewrittenToUrl(): void
    {
        $tpl = '{each_previous_owner}{previous_owner_name}{/each_previous_owner}';
        $out = $this->renderThrough($tpl, [
            ['owner_name' => '{previous_owner_url}', 'owner_url' => 'https://example.com'],
        ]);
        self::assertSame('{previous_owner_url}', $out);
    }

    public function testOwnerLabelShapedLikeConditionalDoesNotCorruptSurroundingConditional(): void
    {
        $tpl = '{if_previous_owners}before-{previous_owners}-after{/if_previous_owners}';
        $out = $this->renderThrough($tpl, [
            ['owner_name' => '{if_previous_owners}injected{/if_previous_owners}', 'owner_url' => ''],
        ]);
        self::assertSame('before-{if_previous_owners}injected{/if_previous_owners}-after', $out);
    }
}
