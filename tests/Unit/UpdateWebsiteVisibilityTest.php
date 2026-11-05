<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Tests\Unit\Support\RunsMemberAuthHarness;
use PHPUnit\Framework\TestCase;

/**
 * Public Update only changes whether a website shows when the website itself changes.
 * It used to set showurl from whether there was a website, every time, so a fan changing
 * their name turned a website the owner had hidden back on.
 */
final class UpdateWebsiteVisibilityTest extends TestCase
{
    use RunsMemberAuthHarness;

    private const PASSWORD = 'A-Long-Enough-Password-1';
    private const WEBSITE  = 'https://a.test/';
    private const OTHER    = 'https://other.test/';

    private const SHOWING = ['listing' => 1, 'email' => 'a@x.test', 'name' => 'Fan A', 'url' => self::WEBSITE, 'added' => '2020-01-01', 'showurl' => 1];
    private const HIDDEN  = ['showurl' => 0] + self::SHOWING;

    private const NAME     = ['new_name' => 'New Name'];
    private const PASSWORD_ONLY = ['new_password' => self::PASSWORD, 'new_password_confirm' => self::PASSWORD];
    private const SAME     = ['url' => self::WEBSITE];
    private const DIFFERENT = ['url' => self::OTHER];
    private const REMOVE   = ['delete_url' => '1'];

    /** @param array<string,mixed> $member @param array<string,string> $post */
    private static function scenario(string $layout, array $member, array $post, bool $queued = false): array
    {
        return ['layout' => $layout, 'members' => [$member], 'queue_updates' => $queued, 'steps' => [
            ['do' => 'signed_in'],
            ['do' => 'submit', 'post' => $post],
        ]];
    }

    private static function showurl(array $r): string
    {
        return (string) $r['members'][1][0]['showurl'];
    }

    public function testAHiddenWebsiteStaysHiddenUnlessTheFanGivesADifferentOne(): void
    {
        foreach (['owned', 'enth'] as $layout) {
            $results = $this->runScenarios([
                'name'      => self::scenario($layout, self::HIDDEN, self::NAME),
                'password'  => self::scenario($layout, self::HIDDEN, self::PASSWORD_ONLY),
                'same'      => self::scenario($layout, self::HIDDEN, self::SAME),
                'different' => self::scenario($layout, self::HIDDEN, self::DIFFERENT),
            ]);

            foreach ($results as $name => $r) {
                self::assertNull($r['error'], "{$layout}, {$name}");
            }
            self::assertSame('New Name', $results['name']['members'][1][0]['name'], $layout);
            self::assertSame('0', self::showurl($results['name']), "{$layout}: a name change doesn't bring it back");
            self::assertSame('0', self::showurl($results['password']), $layout);
            self::assertSame('0', self::showurl($results['same']), "{$layout}: the same website again");

            self::assertSame(self::OTHER, $results['different']['members'][1][0]['url'], $layout);
            self::assertSame('1', self::showurl($results['different']), "{$layout}: a different website is shown, as Star decided");
        }
    }

    public function testAShowingWebsiteKeepsShowing(): void
    {
        foreach (['owned', 'enth'] as $layout) {
            $r = $this->runOne(self::scenario($layout, self::SHOWING, self::NAME));

            self::assertSame('1', self::showurl($r), $layout);
        }
    }

    public function testRemovingAWebsiteHidesIt(): void
    {
        foreach (['owned', 'enth'] as $layout) {
            foreach ([self::SHOWING, self::HIDDEN] as $member) {
                $r = $this->runOne(self::scenario($layout, $member, self::REMOVE));

                self::assertSame('', (string) $r['members'][1][0]['url'], $layout);
                self::assertSame('0', self::showurl($r), $layout);
            }
        }
    }

    public function testAMemberWithNoWebsiteIsLeftAloneUntilTheyAskForSomething(): void
    {
        $noWebsite = ['url' => ''] + self::SHOWING;
        foreach (['owned', 'enth'] as $layout) {
            $results = $this->runScenarios([
                'password' => self::scenario($layout, $noWebsite, self::PASSWORD_ONLY),
                'removed'  => self::scenario($layout, $noWebsite, self::REMOVE),
                'added'    => self::scenario($layout, ['showurl' => 0] + $noWebsite, self::DIFFERENT),
            ]);

            self::assertSame('1', self::showurl($results['password']), "{$layout}: nothing it needs to change");
            self::assertSame('0', self::showurl($results['removed']), "{$layout}: they ticked the box");
            self::assertSame('1', self::showurl($results['added']), "{$layout}: a first website is shown");
        }
    }

    public function testStraySpacesInASavedWebsiteDontCountAsADifferentOne(): void
    {
        $spaced = ['url' => '  ' . self::WEBSITE . ' '] + self::HIDDEN;
        foreach (['owned', 'enth'] as $layout) {
            $r = $this->runOne(self::scenario($layout, $spaced, self::SAME));

            self::assertSame('0', self::showurl($r), $layout);
        }
    }

    public function testAQueuedListingOnlyAsksForWhatReallyChanges(): void
    {
        foreach (['owned', 'enth'] as $layout) {
            $results = $this->runScenarios([
                'name'      => self::scenario($layout, self::HIDDEN, self::NAME, true),
                'password'  => self::scenario($layout, self::HIDDEN, self::PASSWORD_ONLY, true),
                'same'      => self::scenario($layout, self::HIDDEN, self::SAME, true),
                'different' => self::scenario($layout, self::HIDDEN, self::DIFFERENT, true),
                'showing'   => self::scenario($layout, self::SHOWING, self::DIFFERENT, true),
                'removed'   => self::scenario($layout, self::SHOWING, self::REMOVE, true),
            ]);

            $asks = static fn(array $r): ?array => isset($r['queue'][0]) ? json_decode((string) $r['queue'][0]['changes'], true) : null;

            self::assertEquals(['name' => 'New Name'], $asks($results['name']), $layout);
            self::assertNull($asks($results['password']), "{$layout}: nothing to ask for");
            self::assertNull($asks($results['same']), $layout);
            self::assertEquals(['url' => self::OTHER, 'showurl' => 1], $asks($results['different']), $layout);
            self::assertEquals(['url' => self::OTHER], $asks($results['showing']), "{$layout}: it's already showing");
            self::assertEquals(['url' => '', 'showurl' => 0], $asks($results['removed']), $layout);

            $startedAs = ['name' => '0', 'password' => '0', 'same' => '0', 'different' => '0', 'showing' => '1', 'removed' => '1'];
            foreach ($results as $name => $r) {
                self::assertSame($startedAs[$name], self::showurl($r), "{$layout}, {$name}: nothing changes until it's approved");
            }
        }
    }
}
