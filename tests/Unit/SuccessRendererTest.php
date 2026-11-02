<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Support\SuccessRenderer;
use PHPUnit\Framework\TestCase;

final class SuccessRendererTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Default (empty template) path
    // -----------------------------------------------------------------------

    /** @return list<string> */
    private static function knownActions(): array
    {
        return [
            'joined-pending', 'updated', 'updated-pending',
            'removed', 'reset-sent', 'reset-done', 'code-submitted',
            'kim-signed-up', 'kim-updated', 'kim-reset-done',
            'adopt-submitted', 'contact-sent', 'affiliate-apply-sent',
        ];
    }

    public function testDefaultContainsFlSuccessWrapperForAllActions(): void
    {
        foreach (self::knownActions() as $action) {
            $html = SuccessRenderer::render('', $action);
            self::assertStringContainsString('class="fl-success"', $html, "Action '$action' missing fl-success wrapper");
            self::assertStringContainsString('<p>', $html, "Action '$action' missing <p> tag");
        }
    }

    public function testUnknownActionReturnsGenericFallback(): void
    {
        $html = SuccessRenderer::render('', 'nonexistent-action');
        self::assertStringContainsString('fl-success', $html);
        self::assertStringContainsString('Done', $html);
    }

    public function testEachKnownActionHasDistinctDefault(): void
    {
        $seen = [];
        foreach (self::knownActions() as $action) {
            $html = SuccessRenderer::render('', $action);
            self::assertFalse(
                in_array($html, $seen, true),
                "Action '$action' shares its default HTML with another action",
            );
            $seen[] = $html;
        }
    }

    // -----------------------------------------------------------------------
    // Template processing path — action conditionals
    // -----------------------------------------------------------------------

    // Auto Approve Joins was removed, so 'joined' (the "now live" action) is retired.

    public function testRetiredJoinedBlockIsHiddenForPendingJoins(): void
    {
        // An owner's template saved back when auto-approve existed.
        $tpl  = 'Thanks! {if_joined}You are live.{/if_joined}{if_joined_pending}Awaiting approval.{/if_joined_pending}';
        $html = SuccessRenderer::render($tpl, 'joined-pending');
        self::assertSame('Thanks! Awaiting approval.', $html);
    }

    public function testRetiredJoinedBlockNeverPrintsRawTags(): void
    {
        $html = SuccessRenderer::render('Hi{if_joined} live{/if_joined}', 'joined-pending');
        self::assertSame('Hi', $html);
        self::assertStringNotContainsString('{if_joined}', $html);
    }

    public function testLegacyJoinedActionRendersAsPending(): void
    {
        $tpl  = '{if_joined}live{/if_joined}{if_joined_pending}pending{/if_joined_pending}';
        self::assertSame('pending', SuccessRenderer::render($tpl, 'joined'));
    }

    public function testLegacyJoinedActionDefaultIsThePendingMessage(): void
    {
        $html = SuccessRenderer::render('', 'joined');
        self::assertSame(SuccessRenderer::render('', 'joined-pending'), $html);
        self::assertStringNotContainsString('now live', $html);
        self::assertStringContainsString('awaiting approval', $html);
        self::assertSame(SuccessRenderer::defaultTemplate('joined-pending'), SuccessRenderer::defaultTemplate('joined'));
    }

    public function testJoinedPendingConditionTrueForJoinedPendingAction(): void
    {
        $tpl  = '{if_joined}no{/if_joined}{if_joined_pending}yes{/if_joined_pending}';
        $html = SuccessRenderer::render($tpl, 'joined-pending');
        self::assertSame('yes', $html);
    }

    public function testUpdatedPendingConditionTrueForUpdatedPendingAction(): void
    {
        $tpl  = '{if_updated}no{/if_updated}{if_updated_pending}yes{/if_updated_pending}';
        $html = SuccessRenderer::render($tpl, 'updated-pending');
        self::assertSame('yes', $html);
    }

    public function testContactSentConditionTrueForContactSentAction(): void
    {
        $tpl  = '{if_contact_sent}yes{/if_contact_sent}{if_joined}no{/if_joined}';
        $html = SuccessRenderer::render($tpl, 'contact-sent');
        self::assertSame('yes', $html);
    }

    public function testAffiliateApplySentConditionTrueForCorrectAction(): void
    {
        $tpl  = '{if_affiliate_apply_sent}yes{/if_affiliate_apply_sent}';
        $html = SuccessRenderer::render($tpl, 'affiliate-apply-sent');
        self::assertSame('yes', $html);
    }

    public function testActionConditionFalseForDifferentAction(): void
    {
        $tpl  = '{if_joined}joined{/if_joined}{if_removed}removed{/if_removed}';
        $html = SuccessRenderer::render($tpl, 'removed');
        self::assertSame('removed', $html);
    }

    // -----------------------------------------------------------------------
    // Template processing path — context tokens
    // -----------------------------------------------------------------------

    public function testFanNameTokenReplacedFromContext(): void
    {
        $html = SuccessRenderer::render('Hello {fan_name}!', 'joined-pending', ['fan_name' => 'Alice']);
        self::assertSame('Hello Alice!', $html);
    }

    public function testListingTitleTokenReplacedFromContext(): void
    {
        $html = SuccessRenderer::render('You joined {listing_title}.', 'joined-pending', ['listing_title' => 'Best Fanlisting']);
        self::assertSame('You joined Best Fanlisting.', $html);
    }

    public function testSenderNameTokenReplacedFromContext(): void
    {
        $html = SuccessRenderer::render('From: {sender_name}', 'contact-sent', ['sender_name' => 'Bob']);
        self::assertSame('From: Bob', $html);
    }

    public function testAffNameTokenReplacedFromContext(): void
    {
        $html = SuccessRenderer::render('Site: {aff_name}', 'affiliate-apply-sent', ['aff_name' => 'Cool Site']);
        self::assertSame('Site: Cool Site', $html);
    }

    public function testUnknownContextTokenProducesEmptyOutput(): void
    {
        $html = SuccessRenderer::render('X:{fan_name}Y', 'joined-pending');
        self::assertSame('X:Y', $html);
    }

    public function testContextTokensAreXssEscaped(): void
    {
        $html = SuccessRenderer::render('{fan_name}', 'joined-pending', ['fan_name' => '<script>alert(1)</script>']);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    // -----------------------------------------------------------------------
    // Template processing path — aff_email conditions
    // -----------------------------------------------------------------------

    public function testAffEmailConditionTrueWhenEmailProvided(): void
    {
        $tpl  = '{if_aff_email}has email{/if_aff_email}{if_no_aff_email}no email{/if_no_aff_email}';
        $html = SuccessRenderer::render($tpl, 'affiliate-apply-sent', ['aff_email' => 'test@example.com']);
        self::assertSame('has email', $html);
    }

    public function testAffEmailConditionFalseWhenEmailMissing(): void
    {
        $tpl  = '{if_aff_email}has email{/if_aff_email}{if_no_aff_email}no email{/if_no_aff_email}';
        $html = SuccessRenderer::render($tpl, 'affiliate-apply-sent');
        self::assertSame('no email', $html);
    }

    public function testAffEmailConditionFalseWhenEmailEmptyString(): void
    {
        $tpl  = '{if_aff_email}has email{/if_aff_email}{if_no_aff_email}no email{/if_no_aff_email}';
        $html = SuccessRenderer::render($tpl, 'affiliate-apply-sent', ['aff_email' => '']);
        self::assertSame('no email', $html);
    }

    // -----------------------------------------------------------------------
    // Template processing path — action token
    // -----------------------------------------------------------------------

    public function testActionTokenAvailableInTemplate(): void
    {
        $html = SuccessRenderer::render('action={action}', 'reset-done');
        self::assertSame('action=reset-done', $html);
    }

    // -----------------------------------------------------------------------
    // Edge cases
    // -----------------------------------------------------------------------

    public function testEmptyTemplateUsesDefaultRegardlessOfContext(): void
    {
        $html = SuccessRenderer::render('', 'joined-pending', ['fan_name' => 'Ignored']);
        self::assertStringContainsString('fl-success', $html);
        self::assertStringNotContainsString('{fan_name}', $html);
        self::assertStringNotContainsString('Ignored', $html);
    }

    public function testTemplateWithNoTokensReturnedVerbatim(): void
    {
        $tpl  = '<div class="fl-success"><p>Custom message with no tokens.</p></div>';
        $html = SuccessRenderer::render($tpl, 'joined-pending');
        self::assertSame($tpl, $html);
    }

    public function testKimResetDoneConditionKey(): void
    {
        $tpl  = '{if_kim_reset_done}yes{/if_kim_reset_done}';
        $html = SuccessRenderer::render($tpl, 'kim-reset-done');
        self::assertSame('yes', $html);
    }

    public function testCodeSubmittedConditionKey(): void
    {
        $tpl  = '{if_code_submitted}yes{/if_code_submitted}';
        $html = SuccessRenderer::render($tpl, 'code-submitted');
        self::assertSame('yes', $html);
    }
}
