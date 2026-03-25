<?php

declare(strict_types=1);

namespace Luster\Controllers\Public;

use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\MemberRepo;

/**
 * Public stats endpoint.
 *
 * GET /stats/{listingId}
 *
 * Returns the stats_template stored in lstr_fanlisting_settings, with {token}
 * placeholders replaced with live data. If no custom template is configured,
 * DEFAULT_TEMPLATE is used.
 *
 * Returns 404 for unknown listings and for upcoming/trouble listings
 * (i.e. anything that is not actively open).
 *
 * Tokens:
 *   {stat_opened}       — date listing opened, formatted "Month Dth Year"
 *   {stat_updated}      — date of most recently added approved member (same format)
 *   {stat_last_updated} — admin-controlled "last updated" date from settings
 *   {stat_approved}     — approved member count
 *   {stat_pending}      — pending member count
 *   {stat_newmembers}   — comma-separated list of newest 5 approved member names
 *   {stat_growth}       — average members per day since opened, e.g. "1.23 members/day"
 *   {listing_name}      — listing name (HTML-escaped)
 *   {listing_url}       — listing URL (HTML-escaped)
 */
final class StatsController
{
    private const DEFAULT_TEMPLATE =
        '<p>Opened: {stat_opened}<br>Last updated: {stat_updated}<br>'
        . 'Members: {stat_approved} ({stat_pending} pending)<br>'
        . 'Newest: {stat_newmembers}</p>';

    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /stats/{listingId}
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $listingId = (int) ($params['listingId'] ?? 0);

        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $listing     = $listingRepo->getById($listingId);

        if ($listing === null || $listing->upcoming || $listing->trouble) {
            return Response::notFound('Stats not available for this listing.');
        }

        // ── Load stats ──────────────────────────────────────────────────────

        $prefix     = $listing->dbprefix;
        $memberRepo = new MemberRepo($this->app->getDatabase(), $this->app->getConfig());

        $approved    = $memberRepo->countApproved($prefix);
        $pending     = $memberRepo->countPending($prefix);
        $newest      = $memberRepo->getNewestMembers($prefix, 5);
        $lastUpdated = $memberRepo->getMostRecentApprovedDate($prefix);

        // ── Load template ───────────────────────────────────────────────────

        $settingsRepo = new ListingSettingsRepo($this->app->getDatabase());
        $templates    = $settingsRepo->getTemplates($listingId);
        $template     = ($templates['stats_template'] !== null && trim($templates['stats_template']) !== '')
            ? $templates['stats_template']
            : self::DEFAULT_TEMPLATE;

        // ── Build token values ──────────────────────────────────────────────

        $opened  = $listing->joined !== null ? $this->formatDate($listing->joined)  : 'Unknown';
        $updated = $lastUpdated        !== null ? $this->formatDate($lastUpdated)          : 'Never';

        $newMembersStr = implode(', ', array_map(
            static fn(string $name) => htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $newest,
        ));

        // Growth: members per day since opened; guard against zero days
        $growth = '0.00';
        if ($listing->joined !== null) {
            $openedTs = strtotime($listing->joined);
            if ($openedTs !== false) {
                $days   = max(1, (int) round((time() - $openedTs) / 86400));
                $growth = number_format($approved / $days, 2);
            }
        }

        // ── Replace tokens ──────────────────────────────────────────────────

        $output = str_replace(
            [
                '{stat_opened}',
                '{stat_updated}',
                '{stat_approved}',
                '{stat_pending}',
                '{stat_newmembers}',
                '{stat_growth}',
                '{listing_name}',
                '{listing_url}',
            ],
            [
                $opened,
                $updated,
                (string) $approved,
                (string) $pending,
                $newMembersStr,
                $growth . ' members/day',
                htmlspecialchars($listing->name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($listing->url,  ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
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
