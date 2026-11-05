<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Support\QueuedUpdateApplier;
use Luster\Tests\Unit\Support\RunsMemberSaveHarness;
use PHPUnit\Framework\TestCase;

/**
 * How a stopped request looks on both queue tables: the real page, rendered through
 * the real controller. It has to say why, and offer no way to approve it.
 */
final class QueuedUpdateScreenTest extends TestCase
{
    use RunsMemberSaveHarness;

    /** @return array<string, array{0: string}> */
    private static function holds(): array
    {
        return [
            'address_moved'        => ['address_moved'],
            'may_be_other_member'  => ['may_be_other_member'],
            'joined_after_request' => ['joined_after_request'],
            'write_unconfirmed'    => ['write_unconfirmed'],
        ];
    }

    /** The page as a browser would read it. */
    private static function text(string $html): string
    {
        return html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function testEveryHoldShowsItsLabelReasonAndExplanationOnBothTables(): void
    {
        foreach (self::holds() as [$hold]) {
            foreach (['queue_page', 'queue_overview'] as $action) {
                $r = $this->runOne(['layout' => 'owned', 'action' => $action, 'queued' => [['changes' => ['name' => 'Held Name'], 'review_state' => $hold]]]);
                $why = "{$hold}, {$action}";

                self::assertNull($r['error'], $why);
                $page = self::text((string) $r['body']);
                self::assertStringContainsString(QueuedUpdateApplier::holdLabel($hold), $page, $why);
                self::assertStringContainsString(QueuedUpdateApplier::holdText($hold), $page, $why);
                self::assertStringContainsString(QueuedUpdateApplier::HOLD_EXPLANATION, $page, $why);
            }
        }
    }

    public function testAHeldRowHasNoApproveButtonButKeepsRejectAndItsCheckbox(): void
    {
        foreach (['queue_page' => 'approve-update-', 'queue_overview' => 'approve-update-overview-'] as $action => $approveForm) {
            $r = $this->runOne(['layout' => 'owned', 'action' => $action, 'queued' => [
                ['changes' => ['name' => 'Held Name'], 'review_state' => 'address_moved'],
                ['changes' => ['name' => 'Fine Name']],
            ]]);

            $body = (string) $r['body'];
            self::assertStringNotContainsString('form="' . $approveForm . '1"', $body, $action);
            self::assertStringContainsString('form="' . $approveForm . '2"', $body, "{$action}: the ordinary row still has one");
            self::assertStringContainsString('name="ids[]" class="row-cb-pu" value="1"', $body, $action);
            self::assertMatchesRegularExpression('~form="reject-update-(overview-)?1"~', $body, $action);
        }
    }

    public function testARowThatWouldBeHeldShowsAsHeldBeforeAnyoneClicks(): void
    {
        // Nothing is stored yet: the join date is two days after the request.
        $r = $this->runOne(['layout' => 'owned', 'action' => 'queue_page', 'member' => ['added' => '2026-06-17'], 'queued' => [
            ['changes' => ['name' => 'Fan Name'], 'submitted_at' => '2026-06-15 12:00:00'],
        ]]);

        self::assertStringContainsString(QueuedUpdateApplier::holdText('joined_after_request'), self::text((string) $r['body']));
        self::assertStringNotContainsString('form="approve-update-1"', (string) $r['body']);
    }

    public function testARowWithNoMemberAskingForATakenAddressShowsAsHeld(): void
    {
        foreach (['queue_page', 'queue_overview'] as $action) {
            $r = $this->runOne(['layout' => 'owned', 'action' => $action, 'queued' => [
                ['email' => 'gone@example.com', 'changes' => ['email' => 'ann@example.com']],
            ]]);

            self::assertStringContainsString(QueuedUpdateApplier::holdText('address_moved'), self::text((string) $r['body']), $action);
        }
    }

    public function testAnOrdinaryRowShowsNoHold(): void
    {
        foreach (['queue_page', 'queue_overview'] as $action) {
            $r = $this->runOne(['layout' => 'owned', 'action' => $action, 'queued' => [['changes' => ['name' => 'Fine Name']]]]);

            self::assertStringNotContainsString('Needs review', self::text((string) $r['body']), $action);
        }
    }

    public function testAStoredHoldShowsEvenWhenItsListingHasGone(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'action' => 'queue_overview', 'queued' => [
            ['listing' => 99, 'changes' => ['name' => 'Held Name'], 'review_state' => 'write_unconfirmed'],
        ]]);

        self::assertNull($r['error']);
        self::assertStringContainsString(QueuedUpdateApplier::holdText('write_unconfirmed'), self::text((string) $r['body']));
        self::assertStringNotContainsString('form="approve-update-overview-1"', (string) $r['body']);
    }

    public function testAnUnheldRowWhoseListingHasGoneStillShowsNoHold(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'action' => 'queue_overview', 'queued' => [
            ['listing' => 99, 'changes' => ['name' => 'Fine Name']],
        ]]);

        self::assertNull($r['error']);
        self::assertStringNotContainsString('Needs review', self::text((string) $r['body']));
    }

    public function testARequestsValuesCantGetPastTheEscaping(): void
    {
        foreach (['queue_page', 'queue_overview'] as $action) {
            $r = $this->runOne(['layout' => 'owned', 'action' => $action, 'queued' => [
                ['changes' => ['name' => '<script>alert(1)</script>', '<img src=x>' => 'x'], 'review_state' => 'address_moved'],
            ]]);

            self::assertStringNotContainsString('<script>alert(1)', (string) $r['body'], $action);
            self::assertStringNotContainsString('<img src=x>', (string) $r['body'], $action);
        }
    }

    public function testARequestThatCantBeReadSaysSo(): void
    {
        foreach (['queue_page', 'queue_overview'] as $action) {
            $r = $this->runOne(['layout' => 'owned', 'action' => $action, 'queued' => [['changes_raw' => '{not json']]]);

            self::assertStringContainsString('Couldn&rsquo;t read this request.', (string) $r['body'], $action);
        }
    }

    public function testBeforeTheMigrationAMemberWhoJoinedAroundTheRequestShowsAsHeld(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'action' => 'queue_page', 'member' => ['added' => '2026-06-15'], 'queued' => [
            ['changes' => ['name' => 'Fan Name'], 'submitted_at' => '2026-06-15 12:00:00', 'review_state' => 'queued_before_checks'],
        ]]);

        self::assertStringContainsString(QueuedUpdateApplier::holdText('may_be_other_member'), self::text((string) $r['body']));
    }
}
