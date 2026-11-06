<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 The date and time as the site's Timezone setting says. Admin pages and
 public/index.php set PHP's own zone from that setting, but an embed can't,
 because it shares PHP's zone with the site owner's page. So a date someone
 reads, saved from somewhere an embed can reach, comes from here.
*/

declare(strict_types=1);

namespace Luster\Core;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The time for dates people read, like the day a fan joined.
 *
 * Anything that decides something, like a token's expiry or a rate limit, stays on
 * Database::now(), which is the clock those get checked against. See "Clocks" in the
 * architecture notes.
 */
final class SiteClock
{
    private function __construct() {}

    /**
     * The zone a Timezone setting means: the setting if it's a real zone name, or UTC.
     * Application::applyTimezone() uses this too, so every way into Luster agrees.
     *
     * This is the same test date_default_timezone_set() makes: a name from PHP's zone
     * list, ignoring capitals. new DateTimeZone() isn't, because it also takes offsets
     * like "+02:00", so trying that instead would disagree with the admin pages.
     *
     * A PHP that reads the server's own zone files, like Debian's and Ubuntu's, also lets
     * the setter take a few names it doesn't list, such as "posixrules". Those mean UTC
     * here, and so everywhere, because Luster only calls the setter with this answer.
     */
    public static function zoneName(string $configured): string
    {
        $known = array_map('strtolower', DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC));

        return in_array(strtolower($configured), $known, true) ? $configured : 'UTC';
    }

    /** Now in the configured zone, as Y-m-d H:i:s. PHP's own zone is left alone. */
    public static function now(Config $config): string
    {
        $zone = self::zoneName((string) $config->get('timezone', 'UTC'));

        return (new DateTimeImmutable('now', new DateTimeZone($zone)))->format('Y-m-d H:i:s');
    }
}
