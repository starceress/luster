<?php

declare(strict_types=1);

namespace Luster\Controllers\Public;

use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\FanlistingRepo;
use Luster\Repositories\FanlistingSettingsRepo;
use Luster\Repositories\MemberRepo;

/**
 * Public stats endpoint.
 *
 * GET /stats/{fanlistingId}
 *
 * Returns the stats_template stored in lstr_fanlisting_settings, with $token$
 * placeholders replaced with live data. If no custom template is configured,
 * DEFAULT_TEMPLATE is used.
 *
 * Returns 404 for unknown fanlistings and for upcoming/trouble fanlistings
 * (i.e. anything that is not actively open).
 *
 * Tokens:
 *   $stat_opened$     — date fanlisting opened, formatted "Month Dth Year"
 *   $stat_updated$    — date of most recently added approved member (same format)
 *   $stat_approved$   — approved member count
 *   $stat_pending$    — pending member count
 *   $stat_newmembers$ — comma-separated list of newest 5 approved member names
 *   $stat_growth$     — members per day since opened, formatted to 2 decimal places
 *   $fanlisting_name$ — fanlisting name (HTML-escaped)
 *   $fanlisting_url$  — fanlisting URL (HTML-escaped)
 */
final class StatsController
{
    private const DEFAULT_TEMPLATE =
        '<p>Opened: $stat_opened$<br>Last updated: $stat_updated$<br>'
        . 'Members: $stat_approved$ ($stat_pending$ pending)<br>'
        . 'Newest: $stat_newmembers$</p>';

    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /stats/{fanlistingId}
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $fanlistingId = (int) ($params['fanlistingId'] ?? 0);

        $fanlistingRepo = new FanlistingRepo($this->app->getDatabase(), $this->app->getConfig());
        $fanlisting     = $fanlistingRepo->getById($fanlistingId);

        if ($fanlisting === null || $fanlisting->upcoming || $fanlisting->trouble) {
            return Response::notFound('Stats not available for this fanlisting.');
        }

        // ── Load stats ──────────────────────────────────────────────────────

        $prefix     = $fanlisting->dbprefix;
        $memberRepo = new MemberRepo($this->app->getDatabase(), $this->app->getConfig());

        $approved    = $memberRepo->countApproved($prefix);
        $pending     = $memberRepo->countPending($prefix);
        $newest      = $memberRepo->getNewestMembers($prefix, 5);
        $lastUpdated = $memberRepo->getMostRecentApprovedDate($prefix);

        // ── Load template ───────────────────────────────────────────────────

        $settingsRepo = new FanlistingSettingsRepo($this->app->getDatabase());
        $templates    = $settingsRepo->getTemplates($fanlistingId);
        $template     = ($templates['stats_template'] !== null && trim($templates['stats_template']) !== '')
            ? $templates['stats_template']
            : self::DEFAULT_TEMPLATE;

        // ── Build token values ──────────────────────────────────────────────

        $opened  = $fanlisting->joined !== null ? $this->formatDate($fanlisting->joined)  : 'Unknown';
        $updated = $lastUpdated        !== null ? $this->formatDate($lastUpdated)          : 'Never';

        $newMembersStr = implode(', ', array_map(
            static fn(string $name) => htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $newest,
        ));

        // Growth: members per day since opened; guard against zero days
        $growth = '0.00';
        if ($fanlisting->joined !== null) {
            $openedTs = strtotime($fanlisting->joined);
            if ($openedTs !== false) {
                $days   = max(1, (int) round((time() - $openedTs) / 86400));
                $growth = number_format($approved / $days, 2);
            }
        }

        // ── Replace tokens ──────────────────────────────────────────────────

        $output = str_replace(
            [
                '$stat_opened$',
                '$stat_updated$',
                '$stat_approved$',
                '$stat_pending$',
                '$stat_newmembers$',
                '$stat_growth$',
                '$fanlisting_name$',
                '$fanlisting_url$',
            ],
            [
                $opened,
                $updated,
                (string) $approved,
                (string) $pending,
                $newMembersStr,
                $growth,
                htmlspecialchars($fanlisting->name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($fanlisting->url,  ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            ],
            $template,
        );

        return Response::html($output);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Formats a date or datetime string as "Month Dth Year" (e.g. "March 1st 2026").
     * Returns the original string unchanged if it cannot be parsed.
     */
    private function formatDate(string $dateStr): string
    {
        $ts = strtotime($dateStr);

        if ($ts === false) {
            return $dateStr;
        }

        $day    = (int) date('j', $ts);
        $suffix = match (true) {
            $day >= 11 && $day <= 13 => 'th',   // 11th, 12th, 13th
            $day % 10 === 1          => 'st',
            $day % 10 === 2          => 'nd',
            $day % 10 === 3          => 'rd',
            default                  => 'th',
        };

        return date('F', $ts) . ' ' . $day . $suffix . ' ' . date('Y', $ts);
    }
}
