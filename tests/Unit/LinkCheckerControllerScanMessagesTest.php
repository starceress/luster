<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Controllers\Admin\LinkCheckerController;
use PHPUnit\Framework\TestCase;

/**
 * What a member scan reports: the Scan form's flash and skipped-members list, the
 * activity log line, and the JSON for the per-row Scan and Scan All buttons.
 */
final class LinkCheckerControllerScanMessagesTest extends TestCase
{
    public function testSkipSummaryNamesTheFirstFiveAndCountsTheRest(): void
    {
        $result = ['synced' => 3, 'skipped' => $this->skipped(7)];

        $summary = $this->call('scanSkipSummary', $result);

        self::assertSame(7, $summary['count']);
        self::assertSame(array_slice($result['skipped'], 0, 5), $summary['members']);
        self::assertSame(2, $summary['more']);
    }

    public function testSkipSummaryIsEmptyWhenNothingWasSkipped(): void
    {
        self::assertSame(['count' => 0, 'members' => [], 'more' => 0], $this->call('scanSkipSummary', ['synced' => 4, 'skipped' => []]));
        self::assertSame(['count' => 2, 'members' => $this->skipped(2), 'more' => 0], $this->call('scanSkipSummary', ['synced' => 4, 'skipped' => $this->skipped(2)]));
    }

    public function testAjaxPayload(): void
    {
        $stats   = ['ok' => 2, 'dead' => 1];
        $payload = $this->call('scanAjaxPayload', ['synced' => 3, 'skipped' => $this->skipped(6)], 12, 'Moonlight', $stats);

        self::assertSame(3, $payload['synced']);
        self::assertSame(12, $payload['listing_id']);
        self::assertSame('Moonlight', $payload['listing_name']);
        self::assertSame($stats, $payload['stats']);
        self::assertSame(6, $payload['skipped']);
        self::assertSame($this->skipped(5), $payload['skipped_members']);

        $clean = $this->call('scanAjaxPayload', ['synced' => 0, 'skipped' => []], 4, 'Starlight', []);
        self::assertSame([0, 0, []], [$clean['synced'], $clean['skipped'], $clean['skipped_members']]);
    }

    public function testFlashMessageMentionsTheListOnlyWhenSomethingWasSkipped(): void
    {
        self::assertSame(
            'Scan complete: 1 member URL imported for checking.',
            $this->call('scanFlashMessage', ['synced' => 1, 'skipped' => []])
        );
        self::assertSame(
            'Scan complete: 3 member URLs imported for checking. 1 member skipped — see the list below.',
            $this->call('scanFlashMessage', ['synced' => 3, 'skipped' => $this->skipped(1)])
        );
        self::assertSame(
            'Scan complete: 0 member URLs imported for checking. 2 members skipped — see the list below.',
            $this->call('scanFlashMessage', ['synced' => 0, 'skipped' => $this->skipped(2)])
        );
    }

    public function testActivityMessageCountsSkippedMembersOnlyWhenThereAreAny(): void
    {
        self::assertSame('Scanned 4 member URLs for listing #7', $this->call('scanActivityMessage', ['synced' => 4, 'skipped' => []], 7));
        self::assertSame('Scanned 4 member URLs for listing #7 (1 skipped)', $this->call('scanActivityMessage', ['synced' => 4, 'skipped' => $this->skipped(1)], 7));
    }

    /** @return list<array{email: string, reason: string}> */
    private function skipped(int $count): array
    {
        $list = [];
        for ($i = 1; $i <= $count; $i++) {
            $list[] = ['email' => "member{$i}@test.com", 'reason' => 'Same email as another member'];
        }
        return $list;
    }

    private function call(string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod(LinkCheckerController::class, $method))->invokeArgs(null, $args);
    }
}
