<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Luster\Core\Application;
use Luster\Core\SiteClock;
use Luster\Tests\Unit\Support\RunsMemberSaveHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The time as the site's Timezone setting says, for a date someone reads. An embed never
 * applies that setting, because it shares PHP's zone with the page it's on, so a join
 * through an embed used to be dated in whatever php.ini says.
 */
final class SiteClockTest extends TestCase
{
    use RunsMemberSaveHarness;

    private string $zoneBefore;

    protected function setUp(): void
    {
        $this->zoneBefore = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zoneBefore);
    }

    /** What PHP's own setter says about a value, without its notice. */
    private static function setterAccepts(string $zone): bool
    {
        set_error_handler(static fn(): bool => true);
        try {
            return date_default_timezone_set($zone);
        } finally {
            restore_error_handler();
        }
    }

    /** @return array<string, array{0: string}> */
    public static function values(): array
    {
        $values = [];
        foreach (['UTC', 'utc', 'America/New_York', 'america/new_york', 'AMERICA/NEW_YORK', 'US/Eastern', 'Asia/Calcutta', 'Etc/GMT+1', 'GMT', 'GMT0', 'EST', 'EST5EDT', 'CET', 'Z',
            '+02:00', '-05:00', '+0200', 'GMT+1', 'UTC+1', 'Nope/Nope', 'America/New_York ', ' UTC', 'America', '', ' ', '0', 'a', 'localtime', 'posixrules', 'Factory'] as $value) {
            $values["'{$value}'"] = [$value];
        }

        return $values;
    }

    #[DataProvider('values')]
    public function testItAgreesWithPhpsOwnSetterAboutEveryValue(string $value): void
    {
        $accepted = self::setterAccepts($value);

        self::assertSame($accepted ? $value : 'UTC', SiteClock::zoneName($value), 'admin pages use the setter, so this has to match it exactly');
    }

    public function testItAgreesWithPhpsOwnSetterAboutEveryZonePhpKnows(): void
    {
        foreach (DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC) as $zone) {
            self::assertTrue(self::setterAccepts($zone), $zone);
            self::assertSame($zone, SiteClock::zoneName($zone));
        }
    }

    public function testNowIsInTheConfiguredZoneAndLeavesPhpsZoneAlone(): void
    {
        date_default_timezone_set('Pacific/Pago_Pago');

        $now = SiteClock::now($this->config(['timezone' => 'Pacific/Kiritimati']));

        self::assertSame('Pacific/Pago_Pago', date_default_timezone_get());
        self::assertEqualsWithDelta(time(), (new DateTimeImmutable($now, new DateTimeZone('Pacific/Kiritimati')))->getTimestamp(), 5, $now);
    }

    public function testNowFallsBackToUtcOnABlankOrBadSetting(): void
    {
        date_default_timezone_set('Pacific/Kiritimati');

        foreach ([['timezone' => ''], ['timezone' => 'Nope/Nope'], ['timezone' => '+02:00'], []] as $settings) {
            $now = SiteClock::now($this->config($settings));
            self::assertEqualsWithDelta(time(), (new DateTimeImmutable($now, new DateTimeZone('UTC')))->getTimestamp(), 5, json_encode($settings) . ' ' . $now);
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function settings(): array
    {
        return [
            'a real zone'     => ['Asia/Tokyo', 'Asia/Tokyo'],
            'other capitals'  => ['asia/tokyo', 'Asia/Tokyo'],
            'blank'           => ['', 'UTC'],
            'bad'             => ['Nope/Nope', 'UTC'],
            'an offset'       => ['+02:00', 'UTC'],
        ];
    }

    #[DataProvider('settings')]
    public function testAdminPagesAndEmbedsUseTheSameZone(string $setting, string $zone): void
    {
        date_default_timezone_set('Pacific/Pago_Pago');
        $cfg = $this->config(['timezone' => $setting]);

        $app = $this->appWith($setting);
        (new ReflectionMethod(Application::class, 'applyTimezone'))->invoke($app);

        self::assertSame(strtolower($zone), strtolower(date_default_timezone_get()));
        date_default_timezone_set('Pacific/Pago_Pago');
        self::assertEqualsWithDelta(time(), (new DateTimeImmutable(SiteClock::now($cfg), new DateTimeZone($zone)))->getTimestamp(), 5);
    }

    private function config(array $settings): \Luster\Core\Config
    {
        return $this->appWith($settings['timezone'] ?? null)->getConfig();
    }

    private function appWith(?string $timezone): Application
    {
        if (!defined('LUSTER_BASE')) {
            define('LUSTER_BASE', dirname(__DIR__, 2));
        }
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_site_clock_' . uniqid('', true) . '.php';
        file_put_contents($path, '<?php return ' . var_export($timezone !== null ? ['timezone' => $timezone] : [], true) . ';');
        try {
            return new Application(dirname(__DIR__, 2), $path);
        } finally {
            unlink($path);
        }
    }

    // ------------------------------------------------------------------
    // A join through an embed
    // ------------------------------------------------------------------

    public function testAJoinIsDatedByTheTimezoneSettingNotPhpsZone(): void
    {
        foreach (['owned', 'enth'] as $layout) {
            $r = $this->runOne(['layout' => $layout, 'action' => 'join', 'member' => null, 'timezone' => 'Pacific/Kiritimati', 'php_zone' => 'Pacific/Pago_Pago']);

            self::assertNull($r['error'], $layout);
            self::assertIsArray($r['new_row'], $layout);
            self::assertSame('Pacific/Pago_Pago', $r['php_zone_after'], $layout . ": the page's zone is left alone");
            self::assertEqualsWithDelta(time(), (new DateTimeImmutable((string) $r['new_row']['added'], new DateTimeZone('Pacific/Kiritimati')))->getTimestamp(), 60, $layout . ': added');
            if ($layout === 'enth') {
                self::assertEqualsWithDelta(time(), (new DateTimeImmutable((string) $r['new_row']['updated'], new DateTimeZone('Pacific/Kiritimati')))->getTimestamp(), 60, 'updated');
            }
        }
    }

    public function testAJoinFallsBackToUtcLikeEveryOtherPageOnABadSetting(): void
    {
        foreach (['', 'Nope/Nope', '+02:00'] as $setting) {
            $r = $this->runOne(['layout' => 'owned', 'action' => 'join', 'member' => null, 'timezone' => $setting, 'php_zone' => 'Pacific/Kiritimati']);

            self::assertEqualsWithDelta(time(), (new DateTimeImmutable((string) $r['new_row']['added'], new DateTimeZone('UTC')))->getTimestamp(), 60, "'{$setting}'");
        }
    }

    public function testAddingAnApprovedMemberUsesTheSameClockForUpdated(): void
    {
        $r = $this->runOne(['layout' => 'enth', 'action' => 'add', 'member' => null, 'timezone' => 'Pacific/Kiritimati', 'php_zone' => 'Pacific/Pago_Pago']);

        self::assertEqualsWithDelta(time(), (new DateTimeImmutable((string) $r['new_row']['updated'], new DateTimeZone('Pacific/Kiritimati')))->getTimestamp(), 60);
    }
}
