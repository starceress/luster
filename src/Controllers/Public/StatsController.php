<?php

declare(strict_types=1);

namespace Luster\Controllers\Public;

use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\MemberRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\DateFormatter;
use Luster\Support\ListingTemplateResolver;

/**
 * Public stats endpoint.
 *
 * GET /stats/{listingId}
 *
 * Returns the stats_template stored in lstr_fanlisting_settings, with {token}
 * placeholders replaced with live data. If no custom template is configured,
 * ListingSettingsRepo::defaultStatsTemplate() is used.
 *
 * Returns 404 for unknown listings and for upcoming/trouble listings
 * (i.e. anything that is not actively open).
 *
 * Tokens: see ListingSettingsRepo::defaultStatsTemplate() for the full reference.
 */
final class StatsController
{

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

        $approved   = $memberRepo->countApproved($prefix);
        $pending    = $memberRepo->countPending($prefix);
        $countries  = $memberRepo->countUniqueCountries($prefix);
        $newest     = $memberRepo->getNewestMembers($prefix, 5);
        $memberDate = $memberRepo->getMostRecentApprovedDate($prefix);

        // ── Load template ───────────────────────────────────────────────────

        $settingsRepo   = new ListingSettingsRepo($this->app->getDatabase());
        $templates      = $settingsRepo->getTemplates($listingId);
        $globalTemplates = (new SiteSettingsRepo($this->app->getDatabase()))->getGlobalTemplates();
        $resolved       = ListingTemplateResolver::resolve(
            $templates['stats_template'] ?? null,
            $globalTemplates['listing_default_stats_template'] ?? null,
            ListingSettingsRepo::defaultStatsTemplate(),
        );
        $template = $resolved['value'];

        // Approval status
        $approvedBy  = $settingsRepo->getApprovedBy($listingId);
        $network     = strtoupper($approvedBy);
        $networkName = match ($approvedBy) {
            'tfl'   => 'The Fanlistings Network',
            'tafl'  => 'The Anime Fanlistings Network',
            default => '',
        };

        // ── Build token values ──────────────────────────────────────────────

        $opened = $listing->joined !== null ? DateFormatter::format($listing->joined) : 'Unknown';

        $adminDate   = $settingsRepo->getLastUpdated($listingId);
        $updatedDate = DateFormatter::mostRecent($memberDate, $adminDate);
        $updated     = $updatedDate !== null ? DateFormatter::format($updatedDate) : 'N/A';

        $newestEscaped = array_map(
            static fn(string $name) => htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $newest,
        );
        $newMembers = $this->oxfordComma($newestEscaped);

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

        // Conditional blocks — must run before flat str_replace
        $template = (string) preg_replace(
            '/\{if_approved_by\}(.*?)\{\/if_approved_by\}/s',
            $approvedBy !== '' ? '$1' : '',
            $template,
        );
        $template = (string) preg_replace(
            '/\{if_tfl\}(.*?)\{\/if_tfl\}/s',
            $approvedBy === 'tfl' ? '$1' : '',
            $template,
        );
        $template = (string) preg_replace(
            '/\{if_tafl\}(.*?)\{\/if_tafl\}/s',
            $approvedBy === 'tafl' ? '$1' : '',
            $template,
        );
        $template = (string) preg_replace(
            '/\{if_stat_pending\}(.*?)\{\/if_stat_pending\}/s',
            $pending > 0 ? '$1' : '',
            $template,
        );

        $output = str_replace(
            [
                '{stat_opened}',
                '{stat_updated}',
                '{stat_approved}',
                '{stat_pending}',
                '{stat_countries}',
                '{stat_newmembers}',
                '{stat_growth}',
                '{listing_title}',
                '{listing_url}',
                '{approved_by}',
                '{network}',
                '{network_status}',
                '{network_name}',
                '{tfl_url}',
                '{tafl_url}',
            ],
            [
                $opened,
                $updated,
                (string) $approved,
                (string) $pending,
                (string) $countries,
                $newMembers,
                $growth . ' members/day',
                htmlspecialchars($listing->title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($listing->url,  ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $network,
                $network,
                $network,
                $networkName,
                'https://thefanlistings.org/',
                'https://www.animefanlistings.com/',
            ],
            $template,
        );

        return Response::html($output);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /** Formats names with Oxford comma: "", "A", "A and B", "A, B, and C". */
    private function oxfordComma(array $names): string
    {
        return match (count($names)) {
            0       => '',
            1       => $names[0],
            2       => $names[0] . ' and ' . $names[1],
            default => implode(', ', array_slice($names, 0, -1)) . ', and ' . end($names),
        };
    }
}
