<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 The central settings controller. Renders the multi-panel Settings page (admin
 profile, admin accounts, appearance/skin selection, general/SMTP config,
 embed URL/path settings, blacklist, and system details) and handles all the
 POST save actions for each panel. Also manages collective-level templates
 (owned rows, joined rows, stats, affiliates, codes, updates), skin
 selection, stale-listing threshold, and import source visibility toggles.
 Config values are written to config/app.php via a safe temp-file-then-rename
 pattern, and only keys on the ALLOWED_KEYS whitelist are accepted.
*/

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Addons\Newsfeed\NewsfeedRepo;
use Luster\Addons\Wishlist\WishlistRepo;
use Luster\Core\Captcha;
use Luster\Core\Config;
use Luster\Core\Countries;
use Luster\Support\DateFormatter;
use Luster\Core\Mailer;
use Luster\Core\Database;
use Luster\Core\RateLimit;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\SkinCatalog;
use Luster\Core\TemplateEngine;
use Luster\Core\UpdateChecker;
use Luster\Core\Validator;
use Luster\Core\Version;
use Luster\Core\View;
use Luster\Support\NewsfeedTemplateRenderer as NewsfeedPreviewHelper;
use Luster\Support\AffiliateRowRenderer;
use Luster\Support\SampleTemplateData;
use Luster\Support\CollectiveFormUrlResolver;
use Luster\Support\FormTemplateRenderer;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\AdminUserRepo;
use Luster\Repositories\AffiliateRepo;
use Luster\Repositories\BlacklistRepo;
use Luster\Repositories\CollectiveStatsRepo;
use Luster\Repositories\CodeCategoriesRepo;
use Luster\Repositories\CodeDonorsRepo;
use Luster\Repositories\CodesRepo;
use Luster\Support\CodesTemplateRenderer;
use Luster\Support\SuccessRenderer;
use Luster\Repositories\ErrorLogRepo;
use Luster\Repositories\JoinedListingRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\MemberRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Repositories\TemplateRevisionRepo;
use Luster\Support\JoinedListingRowRenderer;
use Luster\Support\ListingRowRenderer;
use Luster\Support\MemberStatsService;
use RuntimeException;

/**
 * Manages application settings stored in config/app.php.
 *
 * Saves values using var_export() into config/app.php via a safe write
 * pattern (write temp file → rename). Only keys enumerated in the
 * ALLOWED_KEYS list are accepted; all others are ignored.
 *
 * Routes:
 *   GET  /admin/settings — show settings form
 *   POST /admin/settings — save settings
 */
final class SettingsController extends AdminController
{
    /**
     * Keys that are safe to update via the settings form.
     * DB credentials and debug flag are intentionally excluded from
     * in-panel editing (they are set once at install time).
     */
    private const ALLOWED_KEYS = [
        'site_name',
        'site_url',
        'timezone',
        'joined_display_mode',
        'joined_per_page',
        'install_path',
        'embed_base_url',
        'owner_name',
        'owner_email',
        'smtp_enabled',
        'smtp_host',
        'smtp_port',
        'smtp_user',
        'smtp_pass',
        'smtp_secure',
        'smtp_from',
        'smtp_from_name',
        'reciprocal_url',
    ];

    /**
     * Valid keys for the Collective "Quick Actions" tile grid, in their
     * default display order. Used to validate/normalise the saved
     * collective_quick_actions_order site setting.
     */
    private const QUICK_ACTION_KEYS = [
        'add_owned',
        'add_joined',
        'add_code',
        'add_affiliate',
        'add_update',
        'add_wishlist',
        'add_kim',
    ];

    // -----------------------------------------------------------------------
    // GET /admin/collective
    // -----------------------------------------------------------------------

    /**
     * Renders the Collective overview dashboard: listing/member/joined counts,
     * newest and most-active listing, newest member/affiliate/joined entry,
     * collective codes count, and per-add-on record counts.
     */
    public function collectiveIndex(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();

        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error',   '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        $listingRepo = new ListingRepo($db, $config);
        $memberRepo  = new MemberRepo($db, $config);
        $joinedRepo  = new JoinedListingRepo($db);

        $fanStats    = $listingRepo->getStats();
        $listings    = $listingRepo->getAll();
        $fanPending  = (int) $fanStats['trouble'];
        $fanUpcoming = (int) $fanStats['upcoming'];
        $fanCurrent  = max(0, (int) $fanStats['total'] - $fanPending - $fanUpcoming);

        $newestOwned = null;
        foreach ($listings as $fl) {
            $stamp = $fl->opened !== null && $fl->opened !== '' ? strtotime((string) $fl->opened) : false;
            $rank  = $stamp !== false ? $stamp : $fl->id;
            if ($newestOwned === null || $rank > $newestOwned['rank']) {
                $newestOwned = [
                    'rank' => $rank,
                    'id'   => $fl->id,
                    'name' => $fl->subject !== '' ? $fl->subject : $fl->title,
                ];
            }
        }

        $totalMembers    = 0;
        $allCountries    = [];
        $recentMembers   = [];
        $mostActive      = null;
        $mostActiveCount = 0;

        foreach ($listings as $fl) {
            if ($fl->dbprefix === '') {
                continue;
            }

            try {
                $approved = $memberRepo->countApproved($fl->dbprefix);
                $totalMembers += $approved;

                if ($approved > $mostActiveCount) {
                    $mostActiveCount = $approved;
                    $mostActive = [
                        'id'    => $fl->id,
                        'name'  => $fl->subject !== '' ? $fl->subject : $fl->title,
                        'count' => $approved,
                    ];
                }

                foreach ($memberRepo->getDistinctCountryNames($fl->dbprefix) as $country) {
                    $allCountries[(string) $country] = true;
                }

                foreach ($memberRepo->getRecent($fl->dbprefix, 1) as $member) {
                    $recentMembers[] = [
                        'member'      => $member,
                        'listing_id'  => $fl->id,
                        'listing_title'=> $fl->subject !== '' ? $fl->subject : $fl->title,
                    ];
                }
            } catch (\Throwable) {
                continue;
            }
        }

        usort($recentMembers, static fn(array $a, array $b): int => strcmp(
            (string) $b['member']->added,
            (string) $a['member']->added,
        ));
        $newestMember = $recentMembers[0] ?? null;

        $joinedCounts = $joinedRepo->countAllByStatusTab();
        $totalJoined  = (int) $joinedCounts['all'];
        $totalJoinedInactive = (int) $joinedCounts['hidden'];
        $totalJoinedCategories = count($joinedRepo->getCategories());

        $newestJoined = null;
        try {
            $stmt = $db->prepare('SELECT id, name, url, date_joined FROM lstr_joined ORDER BY id DESC LIMIT 1');
            $stmt->execute();
            $row = $stmt->fetch();
            if ($row !== false) {
                $newestJoined = [
                    'id'   => (int) $row['id'],
                    'name' => (string) $row['name'],
                    'url'  => (string) ($row['url'] ?? ''),
                    'date' => (string) ($row['date_joined'] ?? ''),
                ];
            }
        } catch (\Throwable) {}

        $totalAffiliatesCollective = 0;
        $newestAffiliate = null;
        try {
            $affiliatePage = (new AffiliateRepo($db))->getCollective(1, 1, 'created_at DESC, id DESC');
            $totalAffiliatesCollective = (int) $affiliatePage['total'];
            $newestAffiliate = $affiliatePage['items'][0] ?? null;
        } catch (\Throwable) {}

        $collectiveCodes = ['total' => 0, 'pending' => 0];
        try {
            $codesRepo = new CodesRepo($db);
            $collectiveCodes = [
                'total'   => $codesRepo->countByListing(null),
                'pending' => $codesRepo->countPendingByListing(null),
            ];
        } catch (\Throwable) {}

        $addonCounts = [
            'wishlist'  => null,
            'newsfeed'  => null,
            'kim'       => null,
            'adoptions' => null,
        ];
        if ($this->tableExists($db, 'lstr_wishlist')) {
            try {
                $stmt = $db->prepare('SELECT COUNT(*) FROM lstr_wishlist');
                $stmt->execute();
                $addonCounts['wishlist'] = (int) $stmt->fetchColumn();
            } catch (\Throwable) {}
        }
        if ($this->tableExists($db, 'lstr_newsfeed')) {
            try {
                $stmt = $db->prepare('SELECT COUNT(*) FROM lstr_newsfeed WHERE fanlisting_id IS NULL');
                $stmt->execute();
                $addonCounts['newsfeed'] = (int) $stmt->fetchColumn();
            } catch (\Throwable) {}
        }
        if ($this->tableExists($db, 'lstr_kim_members')) {
            try {
                $stmt = $db->prepare('SELECT COUNT(*) FROM lstr_kim_members');
                $stmt->execute();
                $addonCounts['kim'] = (int) $stmt->fetchColumn();
            } catch (\Throwable) {}
        }
        if ($this->tableExists($db, 'lstr_adoption_applications')) {
            try {
                $stmt = $db->prepare('SELECT COUNT(*) FROM lstr_adoption_applications');
                $stmt->execute();
                $addonCounts['adoptions'] = (int) $stmt->fetchColumn();
            } catch (\Throwable) {}
        }

        $quickActionsOrder = json_decode((string) (new SiteSettingsRepo($db))->get('collective_quick_actions_order', '[]'), true);

        return $this->adminLayout('admin/collective/index.php', 'Collective', 'collective', [
            'success'                     => $success,
            'error'                       => $error,
            'total_listings'              => (int) $fanStats['total'],
            'fan_current'                 => $fanCurrent,
            'fan_pending'                 => $fanPending,
            'fan_upcoming'                => $fanUpcoming,
            'newest_owned'                => $newestOwned,
            'most_active'                 => $mostActive,
            'total_joined'                => $totalJoined,
            'total_joined_inactive'       => $totalJoinedInactive,
            'total_joined_categories'     => $totalJoinedCategories,
            'newest_joined'               => $newestJoined,
            'total_members'               => $totalMembers,
            'member_countries'            => count($allCountries),
            'newest_member'               => $newestMember,
            'total_affiliates_collective' => $totalAffiliatesCollective,
            'newest_affiliate'            => $newestAffiliate,
            'collective_codes'            => $collectiveCodes,
            'addon_counts'                => $addonCounts,
            'quick_actions_order'         => is_array($quickActionsOrder) ? $quickActionsOrder : [],
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/collective/quick-actions/reorder
    // -----------------------------------------------------------------------

    /**
     * Persists the drag-drop quick-action order from the Collective overview.
     * Returns a JSON response suitable for the AJAX caller.
     */
    public function reorderQuickActions(Request $request, array $params): Response
    {
        $submitted = array_map('strval', (array) $request->post('order', []));
        $order     = array_values(array_unique(array_intersect($submitted, self::QUICK_ACTION_KEYS)));

        foreach (self::QUICK_ACTION_KEYS as $key) {
            if (!in_array($key, $order, true)) {
                $order[] = $key;
            }
        }

        (new SiteSettingsRepo($this->app->getDatabase()))->set('collective_quick_actions_order', json_encode($order));

        return Response::json(['ok' => true]);
    }

    // -----------------------------------------------------------------------
    // GET /admin/collective/templates
    // -----------------------------------------------------------------------

    /**
     * Renders the Collective Templates page where the admin can edit all
     * collective-level embed templates (owned rows, joined rows, stats,
     * affiliates section, codes, updates, KIM forms, adoption, wishlist,
     * contact, etc.) grouped by the ?group= query parameter.
     */
    public function collectiveTemplates(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error',   '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        $groupWhitelist = [
            'all',
            'owned', 'latest-owned', 'random-owned',
            'joined', 'latest-joined', 'random-joined',
            'stats',
            'codes', 'codes-form',
            'affiliates', 'affiliate-form',
            'contact',
            'kim-overview', 'kim-subject', 'kim-signup', 'kim-update', 'kim-reset',
            'adoption',
            'wishlist',
            'newsfeed',
            'collective-update-form',
        ];
        $activeGroup = (string) $request->query('group', 'all');
        if (!in_array($activeGroup, $groupWhitelist, true)) {
            $activeGroup = 'all';
        }

        $siteRepo = new SiteSettingsRepo($this->app->getDatabase());
        $collectiveTemplates = $siteRepo->getMany([
            'owned_template',
            'joined_template',
            'collective_stats_template',
            'collective_affiliates_section_template',
            'random_listing_template',
            'latest_listing_template',
            'random_joined_listing_template',
            'latest_joined_listing_template',
            'collective_affiliate_apply_form_template',
            'kim_signup_form_template',
            'wishlist_template',
            'collective_newsfeed_template',
            'collective_codes_template',
            'collective_codes_submit_form_template',
            'collective_kim_list_overview_template',
            'collective_kim_list_subject_template',
            'kim_update_form_template',
            'kim_reset_form_template',
            'collective_contact_form_template',
            'collective_adoption_form_template',
            'collective_update_form_template',
        ]);

        return $this->adminLayout('admin/collective/templates.php', 'Collective Templates', 'collective', [
            'collective_templates' => $collectiveTemplates,
            'activeGroup'          => $activeGroup,
            'success'              => $success,
            'error'                => $error,
            'collective_affiliate_apply_style'        => $siteRepo->get('collective_affiliate_apply_style') ?? 'button',
            'collective_codes_submit_style'           => $siteRepo->get('collective_codes_submit_style') ?? 'button',
            'collective_affiliate_apply_toggle_label' => $siteRepo->get('collective_affiliate_apply_toggle_label'),
            'collective_codes_submit_toggle_label'    => $siteRepo->get('collective_codes_submit_toggle_label'),
        ], '', [
            $this->crumb('Collective', $this->adminUrl('/admin/collective')),
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/collective/templates/preview
    // -----------------------------------------------------------------------

    /**
     * Renders a live preview of a collective embed template using real DB data.
     * Called via AJAX from the Collective Templates editor; returns a bare HTML
     * fragment wrapped in the public stylesheet.
     */
    public function previewCollectiveTemplate(Request $request, array $params): Response
    {
        $this->app->getCSRF()->validate($request->post('_csrf', ''));

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();
        $appUrl = rtrim((string) $config->get('app_url', ''), '/');

        $type = (string) $request->post('template_type', '');
        $tpl  = (string) $request->post('template_html', '');

        $allowed = [
            'owned', 'random', 'latest', 'joined',
            'random-joined', 'latest-joined',
            'collective_affiliates_section', 'collective_stats',
            'collective_affiliate_apply_form',
            'collective_adopt_form',
            'kim_signup_form',
            'collective_kim_list_overview', 'collective_kim_list_subject',
            'kim_update_form', 'kim_reset_form',
            'wishlist', 'collective_newsfeed',
            'collective_codes', 'collective_codes_submit_form',
            'collective_contact_form',
            'collective_update_form',
        ];

        if (!in_array($type, $allowed, true)) {
            return Response::html($this->coltplPreviewWrap(
                '<p style="color:#c00">Unknown template type.</p>',
                $appUrl,
            ));
        }

        if ($tpl === '') {
            return Response::html($this->coltplPreviewWrap(
                '<p style="color:#777;font-style:italic">The template is empty. Enter tokens and HTML in the textarea, then click Preview.</p>',
                $appUrl,
            ));
        }

        try {
            $uploadsBase = $appUrl . '/uploads';
            $html = match ($type) {
                'owned', 'random', 'latest'                => $this->coltplPreviewListings($type, $tpl, $db, $config, $uploadsBase . '/listings'),
                'joined', 'random-joined', 'latest-joined' => $this->coltplPreviewJoined($type, $tpl, $db, $uploadsBase . '/joined', $config),
                'collective_affiliates_section'      => $this->coltplPreviewAffiliates($tpl, $db, $uploadsBase . '/affiliates', $request),
                'collective_stats'                   => $this->coltplPreviewStats($tpl, $db, $config),
                'collective_affiliate_apply_form'    => $this->coltplPreviewAffiliateApplyForm($tpl, $config),
                'collective_adopt_form'              => $this->coltplPreviewAdoptForm($tpl, $config),
                'kim_signup_form'                    => $this->coltplPreviewKimSignupForm($tpl, $config),
                'collective_kim_list_overview'       => $this->coltplPreviewKimListOverview($tpl, $config),
                'collective_kim_list_subject'        => $this->coltplPreviewKimListSubject($tpl, $config),
                'kim_update_form'                    => $this->coltplPreviewKimUpdateForm($tpl, $config),
                'kim_reset_form'                     => $this->coltplPreviewKimResetForm($tpl, $config),
                'wishlist'                            => $this->coltplPreviewWishlist($tpl, $db, $uploadsBase . '/wishlist', $appUrl . '/assets/images'),
                'collective_newsfeed'                  => $this->coltplPreviewCollectiveUpdates($tpl, $db, $config),
                'collective_codes'                   => $this->coltplPreviewCodes($tpl, $db, $uploadsBase . '/codes', $request),
                'collective_codes_submit_form'        => $this->coltplPreviewCodesSubmitForm($tpl, $config),
                'collective_contact_form'             => $this->coltplPreviewContactForm($tpl, $config),
                'collective_update_form'              => $this->coltplPreviewCollectiveUpdateForm($tpl, $config),
            };
        } catch (\Throwable $e) {
            $html = '<p style="color:#c00;font-family:monospace">Preview error: '
                  . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }

        return Response::html($this->coltplPreviewWrap($html, $appUrl));
    }

    private function coltplPreviewWrap(string $content, string $appUrl): string
    {
        $cssUrl = htmlspecialchars(
            rtrim($appUrl, '/') . '/assets/css/default/style.css',
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
        $cssVer = @filemtime(rtrim(LUSTER_BASE, '/') . '/public/assets/css/default/style.css') ?: 0;
        $jsUrl = htmlspecialchars(
            rtrim($appUrl, '/') . '/assets/js/fl-embed.js',
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
        return '<!DOCTYPE html><html lang="en"><head>'
             . '<meta charset="utf-8">'
             . '<meta name="viewport" content="width=device-width,initial-scale=1">'
             . '<link rel="stylesheet" href="' . $cssUrl . '?v=' . $cssVer . '">'
             . '<style>body{font-family:system-ui,sans-serif;font-size:15px;line-height:1.6;'
             . 'padding:1.5rem;margin:0;background:#fff;color:#333}img{max-width:100%;height:auto}'
             . 'p{margin:.5rem 0}</style>'
             . '</head><body>'
             . $content
             . '<script src="' . $jsUrl . '"></script>'
             . '</body></html>';
    }

    private function coltplPreviewListings(
        string $type,
        string $tpl,
        Database $db,
        Config $config,
        string $uploadsUrl,
    ): string {
        $listingRepo = new ListingRepo($db, $config);
        $all         = $listingRepo->getAll();

        $current = array_values(array_filter($all, static fn($fl) => !$fl->trouble && !$fl->upcoming));

        if (empty($current)) {
            return '<p style="font-style:italic;color:#888">No active listings to preview with.</p>';
        }

        if ($type === 'random') {
            shuffle($current);
            $current = [$current[0]];
        } elseif ($type === 'latest') {
            usort($current, static function ($a, $b) {
                $ra = ($a->opened !== null && $a->opened !== '') ? strtotime((string) $a->opened) : $a->id;
                $rb = ($b->opened !== null && $b->opened !== '') ? strtotime((string) $b->opened) : $b->id;
                return $rb <=> $ra;
            });
            $current = [$current[0]];
        }

        $ids  = array_map(static fn($fl) => $fl->id, $current);
        $phs  = implode(',', array_fill(0, count($ids), '?'));

        $settingsMap = (new ListingSettingsRepo($db))->getRenderSettingsBatch($ids);

        $categoryMap = [];
        try {
            $stmt = $db->prepare(
                "SELECT lc.fanlisting_id, c.name, c.source
                 FROM lstr_listing_categories lc
                 JOIN lstr_categories c ON c.id = lc.category_id
                 WHERE lc.fanlisting_id IN ({$phs})
                 ORDER BY lc.fanlisting_id, c.name"
            );
            $stmt->execute(array_values($ids));
            $raw = [];
            foreach ($stmt->fetchAll() as $row) {
                $rowListingId = (int) $row['fanlisting_id'];
                $raw[$rowListingId][] = (string) $row['name'];
                // {if_tfl}/{if_tafl}/{network_name} are category-derived, independent
                // of "Approved By" — see ListingRowRenderer::render().
                if ($row['source'] === 'tfl')  { $settingsMap[$rowListingId]['has_tfl_category']  = true; }
                if ($row['source'] === 'tafl') { $settingsMap[$rowListingId]['has_tafl_category'] = true; }
            }
            foreach ($raw as $id => $names) {
                $categoryMap[$id] = implode(', ', $names);
            }
        } catch (\Throwable) {}

        $tpl = str_replace('{site_name}', htmlspecialchars((string) $config->get('site_name', 'Collective'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $tpl);

        $statsMap = [];
        $needsStats = str_contains($tpl, '{member_count}')
                   || str_contains($tpl, '{member_pending_count}')
                   || str_contains($tpl, '{pending_owned_count}')
                   || str_contains($tpl, '{country_count}')
                   || str_contains($tpl, '{countries_count}')
                   || str_contains($tpl, '{if_countries}')
                   || str_contains($tpl, '{if_pending}');
        if ($needsStats && !empty($current)) {
            $statsMap = (new MemberStatsService($db, $config))->forListings($current);
        }

        return ListingRowRenderer::renderTemplate($tpl, $current, $uploadsUrl, $settingsMap, $statsMap, $categoryMap);
    }

    private function coltplPreviewJoined(string $type, string $tpl, Database $db, string $uploadsUrl, Config $config): string
    {
        $tpl = str_replace('{site_name}', htmlspecialchars((string) $config->get('site_name', 'Collective'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $tpl);
        $joinedRepo = new JoinedListingRepo($db);

        if ($type === 'random-joined') {
            $items = $joinedRepo->getActiveApproved();
            if (empty($items)) {
                return '<p style="font-style:italic;color:#888">No active joined listings to preview with.</p>';
            }
            shuffle($items);
            $items = [$items[0]];
        } elseif ($type === 'latest-joined') {
            $items = $joinedRepo->getLatestJoined(1);
            if (empty($items)) {
                return '<p style="font-style:italic;color:#888">No active joined listings to preview with.</p>';
            }
        } else {
            $all   = $joinedRepo->getAllForLinkCheck();
            $items = array_values(array_filter($all, static fn($j) => $j->active));
            if (empty($items)) {
                return '<p style="font-style:italic;color:#888">No active joined listings to preview with.</p>';
            }
        }

        return JoinedListingRowRenderer::renderTemplate($tpl, $items, $uploadsUrl);
    }

    private function coltplPreviewAffiliates(string $tpl, Database $db, string $uploadsUrl, ?Request $request = null): string
    {
        $rawTpl = $tpl;

        $result     = (new AffiliateRepo($db))->getActiveCollective(200, 1);
        $affiliates = $result['items'];
        $total      = $result['total'];

        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $baseUrl    = rtrim((string) $this->app->getConfig()->get('app_url', ''), '/');
        $siteUrl    = (string) ($this->app->getConfig()->get('site_url', '') ?: $baseUrl);
        $siteRepo   = new SiteSettingsRepo($db);
        $affOverride = $siteRepo->get('collective_form_url_affiliate_apply');
        $affiliateApplyUrl = CollectiveFormUrlResolver::resolve($affOverride ?: 'affiliates.php', $siteUrl, $siteUrl);

        $applyEnabled = ($siteRepo->get('collective_affiliate_apply_enabled') ?? '1') === '1';

        // null = key absent from POST (not a live preview); '' = sent as empty ("Use saved default")
        $previewStyle = $request ? $request->post('toggle_style', null) : null;
        $applyStyle   = $previewStyle !== null
            ? (in_array(trim((string) $previewStyle), ['link', 'button'], true) ? trim((string) $previewStyle) : ($siteRepo->get('collective_affiliate_apply_style') ?? 'button'))
            : (string) ($siteRepo->get('collective_affiliate_apply_style') ?? 'button');
        if (!in_array($applyStyle, ['link', 'button'], true)) {
            $applyStyle = 'button';
        }
        $applyFormHtml = '';
        if ($applyEnabled && (str_contains($rawTpl, '{affiliate_apply_toggle}') || str_contains($rawTpl, '{affiliate_apply_form}'))) {
            $collectiveAffTpl = $siteRepo->get('collective_affiliate_apply_form_template') ?? '';
            if ($collectiveAffTpl === '') {
                $collectiveAffTpl = SiteSettingsRepo::defaultCollectiveAffiliateApplyFormTemplate();
            }
            $config    = $this->app->getConfig();
            $applyFormHtml = $this->coltplPreviewAffiliateApplyForm($collectiveAffTpl, $config);
        }
        // A blank posted label previews as the built-in default, not the still-saved
        // value — saving blank clears the setting to NULL, which renders as the
        // built-in default too (see updateTemplates()). Preview must match.
        $previewLabel = $request ? $request->post('toggle_label', null) : null;
        $applyLabel   = $previewLabel !== null
            ? (trim((string) $previewLabel) !== '' ? trim((string) $previewLabel) : 'Apply to become an affiliate?')
            : (string) ($siteRepo->get('collective_affiliate_apply_toggle_label') ?? 'Apply to become an affiliate?');

        $buildToggle = static function (string $idSuffix) use ($e, $applyFormHtml, $applyStyle, $applyLabel): array {
            if ($applyFormHtml === '') {
                return ['', ''];
            }
            $formId = 'fl-affiliate-apply-form-' . $e($idSuffix);
            $tag    = $applyStyle === 'link' ? 'a' : 'button';
            $attrs  = $applyStyle === 'link' ? ' role="button" tabindex="0"' : ' type="button"';
            $toggle = '<' . $tag . ' class="fl-affiliate-apply-toggle fl-affiliate-apply-toggle--' . $e($applyStyle) . '"'
                . $attrs
                . ' data-fl-toggle="' . $formId . '" aria-expanded="false" aria-controls="' . $formId . '">'
                . $e($applyLabel)
                . '</' . $tag . '>';
            $form = '<div class="fl-affiliate-apply-form-wrap" id="' . $formId . '" hidden>'
                . $applyFormHtml
                . '</div>';
            return [$toggle, $form];
        };
        [$toggleHtmlActive, $formHtmlActive] = $buildToggle('active');
        [$toggleHtmlEmpty, $formHtmlEmpty]   = $buildToggle('empty');

        $siteName = (string) $this->app->getConfig()->get('site_name', 'Collective');
        $flatTokens = ['{site_name}', '{affiliates_count}', '{affiliate_apply_url}'];

        // --- Active state
        $active = $rawTpl;
        if (preg_match('/\{each_affiliate\}(.*?)\{\/each_affiliate\}/s', $active, $m)) {
            $rendered = AffiliateRowRenderer::renderRows($m[1], $affiliates, $uploadsUrl);
            $active = (string) preg_replace('/\{each_affiliate\}.*?\{\/each_affiliate\}/s', $rendered, $active, 1);
        }
        $active = (string) preg_replace('/\{if_affiliates\}(.*?)\{\/if_affiliates\}/s',       $total > 0 ? '$1' : '',  $active);
        $active = (string) preg_replace('/\{if_no_affiliates\}(.*?)\{\/if_no_affiliates\}/s', $total > 0 ? ''  : '$1', $active);
        $active = str_replace(['{affiliate_apply_toggle}', '{affiliate_apply_form}'], [$toggleHtmlActive, $formHtmlActive], $active);
        $activeHtml = str_replace($flatTokens, [$e($siteName), (string) $total, $e($affiliateApplyUrl)], $active);

        // --- Empty state
        $empty = $rawTpl;
        $empty = (string) preg_replace('/\{each_affiliate\}.*?\{\/each_affiliate\}/s', '', $empty, 1);
        $empty = (string) preg_replace('/\{if_affiliates\}(.*?)\{\/if_affiliates\}/s',       '', $empty);
        $empty = (string) preg_replace('/\{if_no_affiliates\}(.*?)\{\/if_no_affiliates\}/s', '$1', $empty);
        $empty = str_replace(['{affiliate_apply_toggle}', '{affiliate_apply_form}'], [$toggleHtmlEmpty, $formHtmlEmpty], $empty);
        $emptyHtml = str_replace($flatTokens, [$e($siteName), '0', $e($affiliateApplyUrl)], $empty);

        return self::previewSectionLabel($total > 0 ? 'Active Data' : 'Sample Data')
            . $activeHtml
            . self::previewSectionLabel('Empty State')
            . $emptyHtml;
    }

    private function coltplPreviewStats(string $tpl, Database $db, Config $config): string
    {
        $repo           = new CollectiveStatsRepo($db, $config);
        $owned          = $repo->getOwnedCounts();
        $joined         = $repo->getJoinedCounts();
        $members        = $repo->getMemberTotals();
        $newest         = $repo->getNewestOwned(1);
        $random         = $repo->getRandomOwned();
        $countries      = $repo->getDistinctCountryCount();
        $ownedNetwork   = $repo->getOwnedNetworkCounts();
        $joinedNetwork  = $repo->getJoinedNetworkCounts();
        $lastOpened     = $repo->getLastOwnedOpenedDate();
        $joinedCats     = (new \Luster\Repositories\JoinedListingRepo($db))->getCategories(true);

        $collectiveLastUpdated = $lastOpened !== null
            ? DateFormatter::format($lastOpened)
            : 'Never';

        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $affiliatesSitewide = $repo->getSitewideAffiliateCount();
        $affiliatesTotal    = $repo->getTotalAffiliateCount();

        $tpl = (string) preg_replace(
            '/\{if_countries\}(.*?)\{\/if_countries\}/s',
            $countries > 0 ? '$1' : '',
            $tpl,
        );
        $tpl = (string) preg_replace(
            '/\{if_pending\}(.*?)\{\/if_pending\}/s',
            $members['pending'] > 0 ? '$1' : '',
            $tpl,
        );
        $tpl = (string) preg_replace(
            '/\{if_owned_upcoming\}(.*?)\{\/if_owned_upcoming\}/s',
            $owned['upcoming'] > 0 ? '$1' : '',
            $tpl,
        );
        $tpl = (string) preg_replace(
            '/\{if_owned_pending\}(.*?)\{\/if_owned_pending\}/s',
            $owned['trouble'] > 0 ? '$1' : '',
            $tpl,
        );
        $tpl = (string) preg_replace(
            '/\{if_affiliates_sitewide\}(.*?)\{\/if_affiliates_sitewide\}/s',
            $affiliatesSitewide > 0 ? '$1' : '',
            $tpl,
        );
        $tpl = (string) preg_replace(
            '/\{if_affiliates_total\}(.*?)\{\/if_affiliates_total\}/s',
            $affiliatesTotal > 0 ? '$1' : '',
            $tpl,
        );

        $siteName = (string) $config->get('site_name', 'Collective');

        return str_replace(
            [
                '{site_name}',
                '{owned_active}', '{owned_upcoming}', '{owned_pending}',
                '{owned_tfl_count}', '{owned_tafl_count}',
                '{joined_active}', '{joined_pending}', '{joined_upcoming}', '{joined_approved}',
                '{joined_tfl_count}', '{joined_tafl_count}',
                '{members_total}', '{members_approved}', '{members_pending}',
                '{affiliates}', '{affiliates_sitewide}', '{affiliates_total}',
                '{countries_count}',
                '{collective_last_updated}',
                '{joined_total_cats}',
                '{owned_newest_title}', '{owned_newest_subject}', '{owned_newest_url}',
                '{owned_random_title}', '{owned_random_subject}', '{owned_random_url}',
                '{tfl_url}', '{tafl_url}',
            ],
            [
                $e($siteName),
                (string) $owned['active'],
                (string) $owned['upcoming'],
                (string) $owned['trouble'],
                (string) $ownedNetwork['tfl'],
                (string) $ownedNetwork['tafl'],
                (string) $joined['active'],
                (string) $joined['pending'],
                (string) $joined['upcoming'],
                (string) $joined['approved'],
                (string) $joinedNetwork['tfl'],
                (string) $joinedNetwork['tafl'],
                (string) $members['total'],
                (string) $members['approved'],
                (string) $members['pending'],
                (string) $affiliatesSitewide,
                (string) $affiliatesSitewide,
                (string) $affiliatesTotal,
                (string) $countries,
                $e($collectiveLastUpdated),
                (string) count($joinedCats),
                $e(!empty($newest) ? $newest[0]->title   : ''),
                $e(!empty($newest) ? $newest[0]->subject : ''),
                $e(!empty($newest) ? $newest[0]->url     : ''),
                $e($random !== null ? $random->title   : ''),
                $e($random !== null ? $random->subject : ''),
                $e($random !== null ? $random->url     : ''),
                'https://thefanlistings.org/',
                'https://www.animefanlistings.com/',
            ],
            $tpl,
        );
    }

    private function coltplPreviewAffiliateApplyForm(string $tpl, Config $config): string
    {
        $captchaQuestion = Captcha::getQuestion('affiliate_apply');
        $fb = $this->app->getFormFieldBuilder();
        $siteName = (string) $config->get('site_name', 'Collective');
        $siteUrl  = (string) ($config->get('site_url', ''));

        $descriptors = [
            ['type' => 'text',     'name' => 'name',           'id' => 'aff-name',    'label' => 'Site Name',                      'required' => true,  'value' => '', 'attrs' => ['maxlength' => '200', 'autocomplete' => 'organization']],
            ['type' => 'url',      'name' => 'url',            'id' => 'aff-url',     'label' => 'Website URL',                       'required' => true,  'value' => '', 'attrs' => ['maxlength' => '500', 'placeholder' => 'https://', 'autocomplete' => 'url']],
            ['type' => 'email',    'name' => 'email',          'id' => 'aff-email',   'label' => 'Email',           'required' => true, 'value' => '', 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
            ['type' => 'file',     'name' => 'image',          'id' => 'aff-image',   'label' => 'Site Button / Image (optional)', 'required' => false, 'attrs' => ['accept' => 'image/jpeg,image/png,image/gif,image/webp'], 'hint' => 'JPEG, PNG, GIF, or WebP. Max 500 KB.'],
            ['type' => 'textarea', 'name' => 'message',        'id' => 'aff-message', 'label' => 'Message (optional)',             'required' => false, 'value' => '', 'attrs' => ['maxlength' => '2000', 'rows' => '4']],
            ['type' => 'text',     'name' => 'captcha_answer', 'id' => 'aff-captcha', 'label' => 'Captcha: ' . $captchaQuestion,   'required' => true,  'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
        ];
        $f = $fb->build($descriptors, '');

        $tokens = [
            '{site_name}'             => View::e($siteName),
            '{listing_title}'         => View::e($siteName),
            '{listing_url}'           => View::e($siteUrl),
            '{field_name}'            => $f['name'],
            '{field_name_input}'      => $f['name_input'],
            '{field_url}'             => $f['url'],
            '{field_url_input}'       => $f['url_input'],
            '{field_email}'           => $f['email'],
            '{field_email_input}'     => $f['email_input'],
            '{field_image}'           => $f['image'],
            '{field_image_input}'     => $f['image_input'],
            '{field_message}'         => $f['message'],
            '{field_message_input}'   => $f['message_input'],
            '{captcha_block}'         => $f['captcha_answer'],
            '{captcha_block_input}'   => $f['captcha_answer_input'],
            '{captcha_question}'      => View::e($captchaQuestion),
            '{submit_button}'         => '<button type="submit" class="fl-submit">Submit Application</button>',
            '{clear_button}'          => '<button type="reset" class="fl-btn-secondary">Clear</button>',
        ];

        $inner = FormTemplateRenderer::expandTokens($tpl, $tokens);
        return FormTemplateRenderer::wrapForm($inner, '#', $this->app->getCSRF()->token(), true, preview: true);
    }

    private function coltplPreviewAdoptForm(string $tpl, Config $config): string
    {
        $captchaQuestion = Captcha::getQuestion('adopt');
        $fb = $this->app->getFormFieldBuilder();
        $siteName = (string) $config->get('site_name', 'Collective');

        $listingSelectHtml = '<select class="fl-select" id="adopt-listing" name="listing_id" required>'
                           . '<option value="">&#8212; Select a Listing &#8212;</option>'
                           . '<option value="1">' . SampleTemplateData::LISTING_TITLE_A . '</option>'
                           . '<option value="2">' . SampleTemplateData::LISTING_TITLE_B . '</option>'
                           . '</select>';

        $descriptors = [
            ['type' => 'select',   'name' => 'listing_id',    'id' => 'adopt-listing', 'label' => 'Listing',     'required' => true,  'raw_input' => $listingSelectHtml],
            ['type' => 'text',     'name' => 'name',          'id' => 'adopt-name',    'label' => 'Name',        'required' => true,  'value' => '', 'attrs' => ['maxlength' => '100', 'autocomplete' => 'name']],
            ['type' => 'email',    'name' => 'email',         'id' => 'adopt-email',   'label' => 'Email',       'required' => true,  'value' => '', 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
            ['type' => 'url',      'name' => 'url',           'id' => 'adopt-url',     'label' => 'Website URL', 'required' => false, 'value' => '', 'attrs' => ['maxlength' => '500', 'placeholder' => 'https://', 'autocomplete' => 'url'], 'hint' => 'Optional'],
            ['type' => 'textarea', 'name' => 'message',       'id' => 'adopt-message', 'label' => 'Message',     'required' => false, 'value' => '', 'attrs' => ['maxlength' => '2000', 'rows' => '5'], 'hint' => 'Optional'],
            ['type' => 'text',     'name' => 'captcha_answer','id' => 'adopt-captcha', 'label' => 'Captcha: ' . $captchaQuestion, 'required' => true, 'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
        ];
        $f = $fb->build($descriptors, '');

        $tokens = [
            '{site_name}'              => View::e($siteName),
            '{field_listing_id}'       => $f['listing_id'],
            '{field_listing_id_input}' => $f['listing_id_input'],
            '{field_name}'             => $f['name'],
            '{field_name_input}'       => $f['name_input'],
            '{field_email}'            => $f['email'],
            '{field_email_input}'      => $f['email_input'],
            '{field_url}'              => $f['url'],
            '{field_url_input}'        => $f['url_input'],
            '{field_message}'          => $f['message'],
            '{field_message_input}'    => $f['message_input'],
            '{captcha_block}'          => $f['captcha_answer'],
            '{captcha_block_input}'    => $f['captcha_answer_input'],
            '{captcha_question}'       => View::e($captchaQuestion),
            '{submit_button}'          => '<button type="submit" class="fl-submit">Submit Application</button>',
            '{clear_button}'           => '<button type="reset" class="fl-btn-secondary">Clear</button>',
        ];

        $inner = FormTemplateRenderer::expandTokens($tpl, $tokens);
        return FormTemplateRenderer::wrapForm($inner, '#', $this->app->getCSRF()->token(), false, preview: true);
    }

    private function coltplPreviewKimSignupForm(string $tpl, Config $config): string
    {
        $fb       = $this->app->getFormFieldBuilder();
        $siteName = (string) $config->get('site_name', 'Collective');

        $flSelectHtml = '<select class="fl-select" id="kim-fanlisting" name="fanlisting_id" required>'
                      . '<option value="">&#8212; Select a Listing &#8212;</option>'
                      . '<option value="1">' . SampleTemplateData::LISTING_TITLE . '</option>'
                      . '</select>';

        $descriptors = [
            ['type' => 'text',     'name' => 'name',             'id' => 'kim-name',             'label' => 'Name',                    'required' => true,  'value' => '', 'attrs' => ['maxlength' => '100', 'autocomplete' => 'name']],
            ['type' => 'url',      'name' => 'url',              'id' => 'kim-url',              'label' => 'Website URL (optional)',   'required' => false, 'value' => '', 'attrs' => ['maxlength' => '500', 'placeholder' => 'https://', 'autocomplete' => 'url']],
            ['type' => 'email',    'name' => 'email',            'id' => 'kim-email',            'label' => 'Email',                   'required' => true,  'value' => '', 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
            ['type' => 'select',   'name' => 'fanlisting_id',    'id' => 'kim-fanlisting',       'label' => 'Fanlisting Interested In','required' => true,  'raw_input' => $flSelectHtml],
            ['type' => 'password', 'name' => 'password',         'id' => 'kim-password',         'label' => 'Password',                'required' => true,  'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password'], 'hint' => 'Minimum 12 characters.'],
            ['type' => 'password', 'name' => 'password_confirm', 'id' => 'kim-password-confirm', 'label' => 'Confirm Password',        'required' => true,  'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password']],
            ['type' => 'text',     'name' => 'captcha_answer',   'id' => 'kim-captcha',          'label' => 'Captcha: 2 + 3',          'required' => true,  'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
        ];
        $f = $fb->build($descriptors, '');

        $tokens = [
            '{site_name}'                        => View::e($siteName),
            '{field_name}'                        => $f['name'],
            '{field_name_input}'                  => $f['name_input'],
            '{field_url}'                         => $f['url'],
            '{field_url_input}'                   => $f['url_input'],
            '{field_email}'                       => $f['email'],
            '{field_email_input}'                 => $f['email_input'],
            '{field_fanlisting_id}'               => $f['fanlisting_id'],
            '{field_fanlisting_id_input}'         => $f['fanlisting_id_input'],
            '{field_password}'                    => $f['password'],
            '{field_password_input}'              => $f['password_input'],
            '{field_password_confirm}'            => $f['password_confirm'],
            '{field_password_confirm_input}'      => $f['password_confirm_input'],
            '{captcha_block}'                     => $f['captcha_answer'],
            '{captcha_block_input}'               => $f['captcha_answer_input'] ?? '',
            '{captcha_question}'                  => '2 + 3',
            '{submit_button}'                     => '<button type="submit" class="fl-submit">Sign Up</button>',
            '{clear_button}'                      => '<button type="reset" class="fl-btn-secondary">Clear</button>',
        ];

        $inner = FormTemplateRenderer::expandTokens($tpl, $tokens);
        return FormTemplateRenderer::wrapForm($inner, '#', $this->app->getCSRF()->token(), false, preview: true);
    }

    private function coltplPreviewKimListOverview(string $tpl, Config $config): string
    {
        $tpl = str_replace('{site_name}', htmlspecialchars((string) $config->get('site_name', 'Collective'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $tpl);
        $sampleListings = [
            ['title' => 'Sample Listing A', 'subject' => 'Archeology', 'url' => '#', 'kim_url' => '#', 'count' => 4],
            ['title' => 'Sample Listing B', 'subject' => 'Marilyn Monroe', 'url' => '#', 'kim_url' => '#', 'count' => 2],
            ['title' => 'Sample Listing C', 'subject' => 'Vintage Tea Sets', 'url' => '#', 'kim_url' => '#', 'count' => 7],
        ];

        $hasListings = count($sampleListings) > 0;
        $tpl = TemplateEngine::conditionals($tpl, [
            'has_listings' => $hasListings,
            'no_listings'  => !$hasListings,
        ]);

        return TemplateEngine::loop(
            $tpl,
            'kim_listing',
            $sampleListings,
            static function (string $inner, array $entry): string {
                return TemplateEngine::tokens($inner, [
                    'listing_title'     => $entry['title'],
                    'listing_subject'   => $entry['subject'],
                    'listing_url'       => $entry['url'],
                    'listing_kim_url'   => $entry['kim_url'],
                    'listing_kim_count' => (string) $entry['count'],
                ]);
            },
        );
    }

    private function coltplPreviewKimListSubject(string $tpl, Config $config): string
    {
        $tpl = str_replace('{site_name}', htmlspecialchars((string) $config->get('site_name', 'Collective'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $tpl);
        $sampleMembers = [
            (object) ['name' => 'Alice', 'url' => 'https://alice.example.com'],
            (object) ['name' => 'Bob', 'url' => ''],
            (object) ['name' => 'Carol', 'url' => 'https://carol.example.com'],
        ];

        $hasMembers = count($sampleMembers) > 0;
        $tpl = TemplateEngine::conditionals($tpl, [
            'has_kim_members' => $hasMembers,
            'no_kim_members'  => !$hasMembers,
        ]);
        $tpl = TemplateEngine::loop(
            $tpl,
            'kim_member',
            $sampleMembers,
            static function (string $inner, object $member): string {
                return TemplateEngine::render(
                    $inner,
                    ['member_name' => $member->name, 'member_url' => $member->url],
                    [],
                    ['member_url' => $member->url !== '', 'no_member_url' => $member->url === ''],
                );
            },
        );

        $tpl = TemplateEngine::conditionals($tpl, [
            'kim_overview_url' => true,
            'kim_signup_url'   => true,
        ]);

        return TemplateEngine::tokens(
            $tpl,
            [
                'listing_title'    => SampleTemplateData::LISTING_TITLE,
                'listing_subject'  => SampleTemplateData::LISTING_SUBJECT,
                'listing_url'      => '#',
                'kim_overview_url' => '#',
                'kim_signup_url'   => '#',
            ],
            ['pagination' => ''],
        );
    }

    private function coltplPreviewKimUpdateForm(string $tpl, Config $config): string
    {
        $fb = $this->app->getFormFieldBuilder();

        // --- Step 1: sign-in form (custom template)
        $f1 = $fb->build([
            ['type' => 'email',    'name' => 'email',    'id' => 'kim-email',    'label' => 'Email',    'required' => true, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
            ['type' => 'password', 'name' => 'password', 'id' => 'kim-password', 'label' => 'Password', 'required' => true, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'current-password']],
        ], '');

        $siteName = (string) $config->get('site_name', 'Collective');
        $tplOut = TemplateEngine::conditionals($tpl, ['lost_password_url' => true]);
        $tokens = [
            '{site_name}'                        => View::e($siteName),
            '{field_email}'                      => $f1['email'],
            '{field_email_input}'                => $f1['email_input'],
            '{field_password}'                   => $f1['password'],
            '{field_password_input}'             => $f1['password_input'],
            '{submit_button}'                    => '<button type="submit" class="fl-submit">Sign In</button>',
            '{clear_button}'                     => '<button type="reset" class="fl-btn-secondary">Clear</button>',
            '{lost_password_url}'                => View::e('#'),
        ];

        $step1Inner = FormTemplateRenderer::expandTokens($tplOut, $tokens);
        $step1Html  = FormTemplateRenderer::wrapForm($step1Inner, '#', $this->app->getCSRF()->token(), false, preview: true);

        // --- Step 2: dashboard (hardcoded — not customizable via template)
        $f2 = $fb->build([
            ['type' => 'text',     'name' => 'new_name',             'id' => 'kim-new-name',             'label' => 'New Name',             'required' => false, 'attrs' => ['maxlength' => '100'], 'hint' => 'Leave blank to keep current'],
            ['type' => 'email',    'name' => 'new_email',            'id' => 'kim-new-email',            'label' => 'New Email',            'required' => false, 'attrs' => ['maxlength' => '255'], 'hint' => 'Leave blank to keep current'],
            ['type' => 'url',      'name' => 'new_url',              'id' => 'kim-new-url',              'label' => 'New Website URL',      'required' => false, 'attrs' => ['maxlength' => '500', 'placeholder' => 'https://'], 'hint' => 'Leave blank to keep current'],
            ['type' => 'password', 'name' => 'new_password',         'id' => 'kim-new-password',         'label' => 'New Password',         'required' => false, 'attrs' => ['maxlength' => '255'], 'hint' => 'Minimum 12 characters. Leave blank to keep current.'],
            ['type' => 'password', 'name' => 'new_password_confirm', 'id' => 'kim-new-password-confirm', 'label' => 'Confirm New Password', 'required' => false, 'attrs' => ['maxlength' => '255']],
        ], '');

        $_e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $th  = static fn(string $s, string $style = ''): string =>
            '<th style="padding:.4em .65em;border-bottom:2px solid #c0c0c0;white-space:nowrap' . ($style !== '' ? ';' . $style : '') . '">' . $_e($s) . '</th>';
        $td  = static fn(string $s, string $style = ''): string =>
            '<td style="padding:.4em .65em;border-bottom:1px solid #e0e0e0' . ($style !== '' ? ';' . $style : '') . '">' . $s . '</td>';
        $row = static function (bool $entryChecked, string $fl, string $name, string $email, string $url) use ($_e, $td): string {
            return '<tr>'
                . '<td style="width:2em;text-align:center;padding:.4em .35em;border-bottom:1px solid #e0e0e0"><input type="checkbox"' . ($entryChecked ? ' checked' : '') . ' disabled></td>'
                . $td($_e($fl)) . $td($_e($name)) . $td($_e($email))
                . $td($url !== '' ? '<a href="#">' . $_e($url) . '</a>' : '')
                . '<td style="text-align:center;padding:.4em .35em;border-bottom:1px solid #e0e0e0"><input type="checkbox" disabled></td>'
                . '</tr>';
        };
        $tableHtml = '<table style="width:100%;border-collapse:collapse;font-size:.9em"><thead><tr>'
            . '<th style="width:2em;padding:.4em .35em;border-bottom:2px solid #c0c0c0"></th>'
            . $th('Listing') . $th('Name') . $th('Email') . $th('Website URL')
            . $th('Remove?', 'text-align:center')
            . '</tr></thead><tbody>'
            . $row(true,  SampleTemplateData::LISTING_TITLE_A, 'Usagi', 'usagi@example.com', 'https://example.com/usagi')
            . $row(true,  SampleTemplateData::LISTING_TITLE_B, 'Misty', 'misty@example.com', '')
            . '</tbody></table>';

        $step2Html = '<div class="fl-kim">'
            . '<p class="fl-hint">Signed in as <strong>' . $_e('fan@example.com') . '</strong>.'
            . ' <a href="#">Not you?</a></p>'
            . '<h2 class="fl-kim-heading">Update Your Entries</h2>'
            . '<p class="fl-kim-intro">Select entries to update and fill in the fields below, or check <strong>Remove?</strong> on any row to delete that entry. Click <strong>Save Changes</strong> when done.</p>'
            . '<div class="fl-kim-table-wrap">' . $tableHtml . '</div>'
            . '<fieldset class="fl-fieldset fl-kim-changes"><legend class="fl-label">Changes to apply to selected entries</legend>'
            . $f2['new_name'] . $f2['new_email'] . $f2['new_url'] . $f2['new_password'] . $f2['new_password_confirm']
            . '</fieldset>'
            . '<div class="fl-form-actions"><button class="fl-submit fl-btn" disabled>Save Changes</button> <button class="fl-btn" disabled>Clear</button></div>'
            . '</div>';

        return self::previewSectionLabel('Step 1 — Sign In') . $step1Html
             . self::previewSectionLabel('Step 2 — Dashboard (Signed In)') . $step2Html;
    }

    /**
     * Previews only the request step — the only step this template controls.
     * The actual "set new password" step always uses the built-in hardcoded
     * markup (see public/embed/kim-reset.php), so there is nothing else to preview.
     */
    private function coltplPreviewKimResetForm(string $tpl, Config $config): string
    {
        $fb = $this->app->getFormFieldBuilder();

        $fEmail = $fb->build([
            ['type' => 'email', 'name' => 'email', 'id' => 'kim-email', 'label' => 'Email', 'required' => true, 'attrs' => ['maxlength' => '255']],
        ], '');

        $siteName = (string) $config->get('site_name', 'Collective');
        $tokens = [
            '{site_name}'          => View::e($siteName),
            '{reset_request_url}'  => '#',
            '{clear_button}'       => '<button type="reset" class="fl-btn-secondary">Clear</button>',
            '{field_email}'        => $fEmail['email'],
            '{field_email_input}'  => $fEmail['email_input'],
            '{submit_button}'      => '<button type="submit" class="fl-submit">Send Reset Link</button>',
        ];

        // Templates saved before Step 2 was locked down may still physically
        // contain an {if_step_confirm}...{/if_step_confirm} block; strip it
        // here so the preview matches what actually renders now.
        $processed = TemplateEngine::conditionals($tpl, [
            'step_email'    => true,
            'step_confirm'  => false,
            'token_valid'   => false,
            'token_invalid' => false,
        ]);
        $inner = FormTemplateRenderer::expandTokens($processed, $tokens);
        return FormTemplateRenderer::wrapForm($inner, '#', $this->app->getCSRF()->token(), false, preview: true);
    }

    private function coltplPreviewWishlist(string $rawTpl, Database $db, string $uploadsUrl, string $sampleImagesUrl): string
    {
        if (!$this->tableExists($db, 'lstr_wishlist')) {
            return '<p style="font-style:italic;color:#888">The Wishlist add-on is not installed.</p>';
        }

        $liveEntries   = (new WishlistRepo($db))->getAll(null, true);
        $usingSample   = empty($liveEntries);
        $sampleEntries = $usingSample ? SampleTemplateData::wishlist() : $liveEntries;
        $imageBaseUrl  = $usingSample ? $sampleImagesUrl : $uploadsUrl;

        $rowFn = static function (string $inner, $entry) use ($imageBaseUrl): string {
            $imageUrl = ($entry->image !== null && $entry->image !== '') ? $imageBaseUrl . '/' . $entry->image : '';

            return TemplateEngine::render(
                $inner,
                escaped: [
                    'item_subject'     => $entry->subject,
                    'item_url'         => $entry->url,
                    'item_type'        => $entry->typeLabel(),
                    'item_category'    => $entry->category,
                    'item_description' => $entry->description ?? '',
                    'item_image_url'   => $imageUrl,
                ],
                conditions: [
                    'item_url'         => $entry->url !== '',
                    'no_item_url'      => $entry->url === '',
                    'item_category'    => $entry->category !== '',
                    'item_description' => $entry->description !== null && $entry->description !== '',
                    'item_image'       => $imageUrl !== '',
                    'type_top'         => $entry->type === 'top',
                    'type_wanted'      => $entry->type === 'wanted',
                    'type_granted'     => $entry->type === 'granted',
                ],
            );
        };

        $siteName    = (string) $this->app->getConfig()->get('site_name', 'Collective');
        $siteNameEsc = htmlspecialchars($siteName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $renderWishlist = static function (string $tpl, array $items) use ($rowFn, $siteNameEsc): string {
            $top     = array_values(array_filter($items, static fn(object $e): bool => $e->type === 'top'));
            $wanted  = array_values(array_filter($items, static fn(object $e): bool => $e->type === 'wanted'));
            $granted = array_values(array_filter($items, static fn(object $e): bool => $e->type === 'granted'));

            $tpl = TemplateEngine::conditionals($tpl, [
                'has_top'     => $top     !== [],
                'has_wanted'  => $wanted  !== [],
                'has_granted' => $granted !== [],
            ]);
            $tpl = str_replace('{site_name}', $siteNameEsc, $tpl);
            $tpl = TemplateEngine::loop($tpl, 'wishlist_item',         $items,   $rowFn);
            $tpl = TemplateEngine::loop($tpl, 'wishlist_item_top',     $top,     $rowFn);
            $tpl = TemplateEngine::loop($tpl, 'wishlist_item_wanted',  $wanted,  $rowFn);
            $tpl = TemplateEngine::loop($tpl, 'wishlist_item_granted', $granted, $rowFn);
            return $tpl;
        };

        $activeHtml = $renderWishlist($rawTpl, $sampleEntries);
        $emptyHtml  = $renderWishlist($rawTpl, []);

        $emptyFallback = '<p style="font-style:italic;color:#888">The template does not include any content when the wishlist is empty. Add an always-visible block outside the <code>{if_has_*}</code> sections if you want an empty-state message.</p>';

        $label = $usingSample ? 'Sample Data' : 'Active Data';

        return self::previewSectionLabel($label)
            . ($activeHtml !== '' ? $activeHtml : '<p style="font-style:italic;color:#888">No active wishlist entries to preview with.</p>')
            . self::previewSectionLabel('Empty State')
            . (trim($emptyHtml) !== '' ? $emptyHtml : $emptyFallback);
    }

    private function coltplPreviewCollectiveUpdates(string $tpl, Database $db, Config $config): string
    {
        if (!$this->tableExists($db, 'lstr_newsfeed')) {
            return '<p style="font-style:italic;color:#888">The News Feed add-on is not installed.</p>';
        }

        $tpl = str_replace('{site_name}', htmlspecialchars((string) $config->get('site_name', 'Collective'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $tpl);

        $liveUpdates   = (new NewsfeedRepo($db))->getCollective(1, 5);
        $sampleUpdates = !empty($liveUpdates) ? $liveUpdates : SampleTemplateData::updates();

        $sample = NewsfeedPreviewHelper::render($sampleUpdates, $tpl);
        $empty  = NewsfeedPreviewHelper::render([], $tpl);

        if (trim($empty) === '') {
            $empty = '<p style="font-style:italic;color:#888">No updates posted yet.</p>';
        }

        $label = empty($liveUpdates) ? 'Sample Data' : 'Active Data';

        return self::previewSectionLabel($label)
            . $sample
            . self::previewSectionLabel('Empty State')
            . $empty;
    }

    private function coltplPreviewCodes(string $tpl, Database $db, string $uploadsUrl, ?Request $request = null): string
    {
        $codesRepo      = new CodesRepo($db);
        $codeDonorsRepo = new CodeDonorsRepo($db);
        $catsRepo       = new CodeCategoriesRepo($db);

        $codes      = $codesRepo->getByListing(null, 'approved');
        $donors     = $codeDonorsRepo->getByListing(null);
        $categories = $catsRepo->getByListing(null);
        $catMap     = $catsRepo->getCategoryMapForListing(null);

        $donorMap = [];
        foreach ($donors as $donor) {
            $donorMap[$donor->id] = $donor;
        }

        $categoryMap = [];
        foreach ($categories as $cat) {
            $categoryMap[$cat->id] = $cat;
        }

        $grouped = [];
        foreach ($codes as $code) {
            if ($code->width > 0 && $code->height > 0) {
                $key   = (int) $code->width . 'x' . (int) $code->height;
                $label = (int) $code->width . 'x' . (int) $code->height . 'px';
            } else {
                $key   = 'unknown';
                $label = 'Other';
            }
            if (!isset($grouped[$key])) {
                $grouped[$key] = ['label' => $label, 'codes' => []];
            }
            $grouped[$key]['codes'][] = $code;
        }
        uksort($grouped, function (string $a, string $b): int {
            if ($a === 'unknown') return 1;
            if ($b === 'unknown') return -1;
            [$aw, $ah] = explode('x', $a);
            [$bw, $bh] = explode('x', $b);
            return $aw !== $bw ? (int) $aw - (int) $bw : (int) $ah - (int) $bh;
        });

        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Extract {codes_submit_toggle} / {codes_submit_form} from the raw
        // template BEFORE any other substitution so a site name or other
        // token value can never inject markup the raw template didn't
        // actually request.
        $csTogglePlaceholder = '<!--lstr-cs-toggle-' . bin2hex(random_bytes(8)) . '-->';
        $csFormPlaceholder   = '<!--lstr-cs-form-' . bin2hex(random_bytes(8)) . '-->';
        $hasCsToggle = str_contains($tpl, '{codes_submit_toggle}') || str_contains($tpl, '{codes_submit_form}');
        if ($hasCsToggle) {
            $tpl = str_replace(
                ['{codes_submit_toggle}', '{codes_submit_form}'],
                [$csTogglePlaceholder, $csFormPlaceholder],
                $tpl,
            );
        }

        $siteName = (string) ($this->app->getConfig()->get('site_name', '') ?: 'Collective');
        $tpl = str_replace('{site_name}', htmlspecialchars($siteName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $tpl);

        // {codes_submit_toggle} / {codes_submit_form} — trigger button + a
        // separately-placed code donation form. The placeholders are left in
        // $tpl through rendering (just like production's codes.php) and
        // swapped for the real HTML afterward, so codes-template syntax that
        // happens to appear inside a customized donation-form template is
        // never re-interpreted by CodesTemplateRenderer.
        $toggleHtmlActive = '';
        $formHtmlActive   = '';
        $toggleHtmlEmpty  = '';
        $formHtmlEmpty    = '';
        if ($hasCsToggle) {
            $siteRepo   = new SiteSettingsRepo($db);
            $csEnabled  = ($siteRepo->get('collective_codes_submit_enabled') ?? '1') === '1';
            if ($csEnabled) {
                // null = key absent from POST (not a live preview); '' = sent as empty ("Use saved default")
                $previewCsStyle = $request ? $request->post('toggle_style', null) : null;
                $csStyle = $previewCsStyle !== null
                    ? (in_array(trim((string) $previewCsStyle), ['link', 'button'], true) ? trim((string) $previewCsStyle) : ($siteRepo->get('collective_codes_submit_style') ?? 'button'))
                    : (string) ($siteRepo->get('collective_codes_submit_style') ?? 'button');
                if (!in_array($csStyle, ['link', 'button'], true)) {
                    $csStyle = 'button';
                }
                // A blank posted label previews as the built-in default, not the still-saved
                // value — saving blank clears the setting to NULL, which renders as the
                // built-in default too (see updateTemplates()). Preview must match.
                $previewCsLabel = $request ? $request->post('toggle_label', null) : null;
                $csLabel = $previewCsLabel !== null
                    ? (trim((string) $previewCsLabel) !== '' ? trim((string) $previewCsLabel) : 'Donate a code?')
                    : (string) ($siteRepo->get('collective_codes_submit_toggle_label') ?? 'Donate a code?');
                $csFormTpl = $siteRepo->get('collective_codes_submit_form_template') ?? '';
                if ($csFormTpl === '') {
                    $csFormTpl = SiteSettingsRepo::defaultCollectiveCodesSubmitFormTemplate();
                }
                $csFormHtml = $this->coltplPreviewCodesSubmitForm($csFormTpl, $this->app->getConfig());

                if ($csFormHtml !== '') {
                    $buildCsToggle = static function (string $idSuffix) use ($e, $csFormHtml, $csStyle, $csLabel): array {
                        $formId = 'fl-codes-submit-form-' . $e($idSuffix);
                        $tag    = $csStyle === 'link' ? 'a' : 'button';
                        $attrs  = $csStyle === 'link' ? ' role="button" tabindex="0"' : ' type="button"';
                        $toggle = '<' . $tag . ' class="fl-codes-submit-toggle fl-codes-submit-toggle--' . $e($csStyle) . '"'
                            . $attrs
                            . ' data-fl-toggle="' . $formId . '" aria-expanded="false" aria-controls="' . $formId . '">'
                            . $e($csLabel)
                            . '</' . $tag . '>';
                        $form = '<div class="fl-codes-submit-form-wrap" id="' . $formId . '" hidden>'
                            . $csFormHtml
                            . '</div>';
                        return [$toggle, $form];
                    };
                    [$toggleHtmlActive, $formHtmlActive] = $buildCsToggle('active');
                    [$toggleHtmlEmpty, $formHtmlEmpty]   = $buildCsToggle('empty');
                }
            }
        }

        $html = CodesTemplateRenderer::render(
            $tpl, $codes, $grouped, $donorMap, $uploadsUrl,
            $catMap, $categoryMap, $siteName,
        );

        if ($html === null) {
            return '<p style="color:#c00">Template must include an {each_code} or {each_size_group} block.</p>';
        }

        $empty = CodesTemplateRenderer::render(
            $tpl, [], [], [], $uploadsUrl, [], [], $siteName,
        );

        if ($hasCsToggle) {
            $html  = $html  !== null ? str_replace([$csTogglePlaceholder, $csFormPlaceholder], [$toggleHtmlActive, $formHtmlActive], $html)  : $html;
            $empty = $empty !== null ? str_replace([$csTogglePlaceholder, $csFormPlaceholder], [$toggleHtmlEmpty, $formHtmlEmpty], $empty) : $empty;
        }

        return self::previewSectionLabel(empty($codes) ? 'Empty State' : 'Active Data') . ($html ?: '<p style="font-style:italic;color:#888">No approved collective codes to preview with.</p>')
            . (empty($codes) ? '' : self::previewSectionLabel('Empty State') . ($empty ?: '<p style="font-style:italic;color:#888">No approved collective codes to preview with.</p>'));
    }

    private static function previewSectionLabel(string $label): string
    {
        return '<div style="margin:1.5rem 0 .5rem;padding:.35rem .6rem;background:#eef1f8;'
            . 'border-left:3px solid #889bd6;font:600 .75rem sans-serif;color:#445;'
            . 'text-transform:uppercase;letter-spacing:.04em">'
            . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</div>';
    }

    private function coltplPreviewCodesSubmitForm(string $tpl, Config $config): string
    {
        $siteName = (string) $config->get('site_name', 'Collective');
        $siteUrl  = (string) ($config->get('site_url', ''));

        $captchaQuestion = Captcha::getQuestion('code_submit');
        $fb = $this->app->getFormFieldBuilder();

        $descriptors = [
            ['type' => 'file',  'name' => 'code_image',      'id' => 'code-image',  'label' => 'Upload Code',                'required' => true,  'attrs' => ['accept' => 'image/jpeg,image/png,image/gif,image/webp'], 'hint' => 'JPEG, PNG, GIF, or WebP. Max 500 KB.'],
            ['type' => 'text',  'name' => 'submitter_name',  'id' => 'sub-name',    'label' => 'Name',                       'required' => true,  'value' => '', 'attrs' => ['maxlength' => '150', 'autocomplete' => 'name']],
            ['type' => 'email', 'name' => 'submitter_email', 'id' => 'sub-email',   'label' => 'Email',                      'required' => true,  'value' => '', 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email'], 'hint' => 'Used only to notify you when your code is approved.'],
            ['type' => 'url',   'name' => 'submitter_url',   'id' => 'sub-url',     'label' => 'Website (optional)',         'required' => false, 'value' => '', 'attrs' => ['maxlength' => '255', 'placeholder' => 'https://', 'autocomplete' => 'url'], 'hint' => 'Displayed as a link on your donated code.'],
            ['type' => 'text',  'name' => 'captcha',         'id' => 'captcha',     'label' => 'Captcha: ' . $captchaQuestion, 'required' => true, 'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
        ];
        $f = $fb->build($descriptors, '');

        $tokens = [
            '{listing_title}'               => View::e($siteName),
            '{listing_url}'                 => View::e($siteUrl),
            '{site_name}'                   => View::e($siteName),
            '{field_code_image}'            => $f['code_image'],
            '{field_code_image_input}'      => $f['code_image_input'],
            '{field_submitter_name}'        => $f['submitter_name'],
            '{field_submitter_name_input}'  => $f['submitter_name_input'],
            '{field_submitter_email}'       => $f['submitter_email'],
            '{field_submitter_email_input}' => $f['submitter_email_input'],
            '{field_submitter_url}'         => $f['submitter_url'],
            '{field_submitter_url_input}'   => $f['submitter_url_input'],
            '{captcha_block}'               => $f['captcha'],
            '{captcha_block_input}'         => $f['captcha_input'],
            '{captcha_question}'            => View::e($captchaQuestion),
            '{submit_button}'               => '<button type="submit" class="fl-submit">Submit Code</button>',
            '{clear_button}'               => '<button type="reset" class="fl-btn-secondary">Clear</button>',
        ];

        $inner = FormTemplateRenderer::expandTokens($tpl, $tokens);
        return FormTemplateRenderer::wrapForm($inner, '#', $this->app->getCSRF()->token(), true, preview: true);
    }

    private function coltplPreviewContactForm(string $tpl, Config $config): string
    {
        $siteName = (string) $config->get('site_name', 'Collective');
        $siteUrl  = (string) ($config->get('site_url', ''));

        $captchaQuestion = Captcha::getQuestion('contact_collective');
        $fb = $this->app->getFormFieldBuilder();

        $subjectHtml = '<select id="contact-subject" name="subject" required>'
                     . '<option value="" disabled selected>Choose &mdash;</option>'
                     . '<option value="Comments">Comments</option>'
                     . '<option value="Questions/Concerns">Questions/Concerns</option>'
                     . '<option value="Contact/Other">Contact/Other</option>'
                     . '</select>';

        $descriptors = [
            ['type' => 'text',     'name' => 'name',           'id' => 'contact-name',    'label' => 'Name',        'required' => true,  'value' => '', 'attrs' => ['maxlength' => '100', 'autocomplete' => 'name']],
            ['type' => 'email',    'name' => 'email',          'id' => 'contact-email',   'label' => 'Email',       'required' => true,  'value' => '', 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
            ['type' => 'url',      'name' => 'url',            'id' => 'contact-url',     'label' => 'Website URL', 'required' => false, 'value' => '', 'attrs' => ['maxlength' => '255', 'autocomplete' => 'url']],
            ['type' => 'select',   'name' => 'subject',        'id' => 'contact-subject', 'label' => 'Subject',     'required' => true,  'raw_input' => $subjectHtml],
            ['type' => 'textarea', 'name' => 'message',        'id' => 'contact-message', 'label' => 'Message',     'required' => true,  'value' => '', 'attrs' => ['maxlength' => '5000', 'rows' => '6']],
            ['type' => 'text',     'name' => 'captcha_answer', 'id' => 'contact-captcha', 'label' => 'Captcha: ' . $captchaQuestion, 'required' => true, 'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
        ];
        $f = $fb->build($descriptors, '');

        $tokens = [
            '{site_name}'            => View::e($siteName),
            '{listing_title}'        => View::e($siteName),
            '{listing_url}'          => View::e($siteUrl),
            '{field_name}'           => $f['name'],
            '{field_name_input}'     => $f['name_input'],
            '{field_email}'          => $f['email'],
            '{field_email_input}'    => $f['email_input'],
            '{field_url}'            => $f['url'],
            '{field_url_input}'      => $f['url_input'],
            '{field_subject}'        => $f['subject'],
            '{field_subject_input}'  => $f['subject_input'],
            '{field_message}'        => $f['message'],
            '{field_message_input}'  => $f['message_input'],
            '{captcha_block}'        => $f['captcha_answer'],
            '{captcha_block_input}'  => $f['captcha_answer_input'],
            '{captcha_question}'     => View::e($captchaQuestion),
            '{submit_button}'        => '<button type="submit" class="fl-submit">Send Message</button>',
            '{clear_button}'         => '<button type="reset" class="fl-btn-secondary">Clear</button>',
        ];

        $inner = FormTemplateRenderer::expandTokens($tpl, $tokens);
        return FormTemplateRenderer::wrapForm($inner, '#', $this->app->getCSRF()->token(), true, preview: true);
    }

    private function coltplPreviewCollectiveUpdateForm(string $tpl, Config $config): string
    {
        $fb       = $this->app->getFormFieldBuilder();
        $siteName = (string) $config->get('site_name', 'Collective');

        // Step 1: email request (template-controlled)
        $f = $fb->build([
            ['type' => 'email', 'name' => 'email', 'id' => 'cu-email', 'label' => 'Your email address', 'required' => true, 'attrs' => ['maxlength' => '255']],
        ], '');

        $tokens = [
            '{site_name}'         => View::e($siteName),
            '{field_email}'       => $f['email'],
            '{field_email_input}' => $f['email_input'],
            '{submit_button}'     => '<button type="submit" class="fl-submit">Send Verification Link</button>',
            '{clear_button}'      => '<button type="reset" class="fl-btn-secondary">Clear</button>',
        ];

        $step1Inner = FormTemplateRenderer::expandTokens($tpl, $tokens);
        $step1Html  = FormTemplateRenderer::wrapForm($step1Inner, '#', $this->app->getCSRF()->token(), false, preview: true);

        // Step 2: update form (hardcoded — listing checkboxes are dynamic, not template-controlled)
        $_e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $f2 = $fb->build([
            ['type' => 'text',     'name' => 'name',                'id' => 'cu-name',            'label' => 'New Name',             'required' => false, 'attrs' => ['maxlength' => '100', 'placeholder' => 'Usagi']],
            ['type' => 'email',    'name' => 'new_email',           'id' => 'cu-email',           'label' => 'New Email',            'required' => false, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email', 'placeholder' => 'fan@example.com'], 'hint' => 'Changes the email on every fanlisting you select below.'],
            ['type' => 'url',      'name' => 'url',                 'id' => 'cu-url',             'label' => 'New Website URL',      'required' => false,
                'raw_input' => '<input class="fl-input" type="url" id="cu-url" name="url" value="" placeholder="https://example.com" maxlength="500">'
                    . '<label class="fl-check-label"><input type="checkbox" class="fl-checkbox" name="delete_url" value="1"> Remove my website URL</label>'],
            ['type' => 'select',   'name' => 'country',             'id' => 'cu-country',         'label' => 'Country',              'required' => false,
                'raw_input' => '<select class="fl-select" id="cu-country" name="country"><option value="US" selected>United States</option></select>'],
            ['type' => 'password', 'name' => 'new_password',        'id' => 'cu-password',        'label' => 'New password',         'required' => false, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password'], 'hint' => 'Minimum 12 characters. Leave blank to keep your current password.'],
            ['type' => 'password', 'name' => 'new_password_confirm', 'id' => 'cu-password-confirm', 'label' => 'Confirm new password', 'required' => false, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password']],
        ], '');
        $deleteMembershipHtml = '<div class="fl-field fl-field--danger">'
            . '<label class="fl-label fl-label-danger">'
            . '<input type="checkbox" class="fl-checkbox" id="cu-delete-membership" disabled>'
            . ' Permanently delete my membership(s) instead of updating them.'
            . '</label>'
            . '<p class="fl-field-hint">This immediately and permanently removes your membership from every fanlisting you select above. Any changes below are ignored, and this cannot be undone.</p>'
            . '</div>';
        $sampleRows = [
            ['title' => SampleTemplateData::LISTING_TITLE_A, 'subject' => SampleTemplateData::LISTING_SUBJECT, 'url' => SampleTemplateData::LISTING_URL, 'joined' => 'January 15th 2020'],
            ['title' => SampleTemplateData::LISTING_TITLE_B, 'subject' => 'Star Wars', 'url' => 'https://example.com/jedi-order', 'joined' => 'March 3rd 2026'],
        ];
        $listingRows = '';
        foreach ($sampleRows as $i => $row) {
            $checkboxId = 'cu-listing-preview-' . $i;
            $listingRows .= '<tr>'
                . '<td><input type="checkbox" class="fl-checkbox" id="' . $_e($checkboxId) . '" checked disabled></td>'
                . '<td><label for="' . $_e($checkboxId) . '">' . $_e($row['title']) . '</label></td>'
                . '<td>' . $_e($row['subject']) . '</td>'
                . '<td><a href="' . $_e($row['url']) . '">' . $_e($row['url']) . '</a></td>'
                . '<td>' . $_e($row['joined']) . '</td>'
                . '</tr>';
        }
        $listingsHtml = '<div class="fl-collective-update-table-wrap">'
            . '<table class="fl-collective-update-table">'
            . '<thead><tr><th></th><th>Listing</th><th>Subject</th><th>Website URL</th><th>Date Joined</th></tr></thead>'
            . '<tbody>' . $listingRows . '</tbody>'
            . '</table></div>';
        $changesHtml = '<fieldset class="fl-fieldset fl-collective-update-changes">'
            . '<legend class="fl-label">Changes to apply to selected fanlistings</legend>'
            . $f2['name'] . $f2['new_email'] . $f2['url'] . $f2['country']
            . $f2['new_password'] . $f2['new_password_confirm']
            . '</fieldset>';
        $step2Html = '<div class="fl-collective-update">'
            . '<p class="fl-form-intro">Updating memberships for: ' . $_e('fan@example.com') . '</p>'
            . '<p class="fl-form-intro">Select the listings to apply changes to below, fill in any fields you want to change, and click "Update Memberships." Leave a field blank to keep your current value.</p>'
            . $listingsHtml
            . $deleteMembershipHtml
            . $changesHtml
            . '<div class="fl-form-actions"><button class="fl-submit fl-btn" disabled>Update Memberships</button> <button class="fl-btn" disabled>Clear</button></div>'
            . '</div>';

        return self::previewSectionLabel('Step 1 — Request Verification Link') . $step1Html
             . self::previewSectionLabel('Step 2 — Update Form (after link is clicked)') . $step2Html;
    }

    private function coltplPreviewContactSent(string $tpl): string
    {
        return SuccessRenderer::render($tpl, 'contact-sent', [
            'sender_name'  => 'Alice',
            'sender_email' => 'alice@example.com',
            'site_name'    => 'My Collective',
            'owner_name'   => 'Site Owner',
            'owner_email'  => 'owner@example.com',
        ]);
    }

    // -----------------------------------------------------------------------
    // GET /admin/collective/snippets
    // -----------------------------------------------------------------------

    /**
     * Renders the Collective Embed Snippets page, which shows ready-to-paste
     * PHP include snippets for every collective embed entry point.
     */
    public function collectiveSnippets(Request $request, array $params): Response
    {
        $lusterBase = defined('LUSTER_BASE') ? \LUSTER_BASE : dirname(__DIR__, 3);
        $embedPath  = realpath($lusterBase . '/public/embed');
        $embedPath  = $embedPath !== false
            ? rtrim(str_replace('\\', '/', $embedPath), '/')
            : rtrim(str_replace('\\', '/', $lusterBase . '/public/embed'), '/');

        return $this->adminLayout('admin/collective/snippets.php', 'Embed Snippets', 'collective', [
            'embed_path' => $embedPath,
        ], '', [
            $this->crumb('Collective', $this->adminUrl('/admin/collective')),
        ]);
    }

    // -----------------------------------------------------------------------
    // GET /admin/collective/settings
    // -----------------------------------------------------------------------

    /**
     * Renders the Collective Settings page (collective contact, affiliate
     * applications, and collective form URL overrides).
     */
    public function collectiveSettings(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error',   '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        $siteRepo     = new SiteSettingsRepo($this->app->getDatabase());
        $siteSettings = $siteRepo->getMany([
            'collective_contact_enabled',
            'collective_contact_recipient',
            'collective_affiliate_apply_enabled',
            'collective_form_url_codes_submit',
            'collective_form_url_affiliate_apply',
            'collective_form_url_adopt',
            'collective_form_url_contact',
            'kim_url_signup',
            'kim_url_update',
            'kim_url_reset',
            'kim_url_list',
            'collective_codes_submit_enabled',
        ]);

        return $this->adminLayout('admin/collective/settings.php', 'Collective Settings', 'collective', [
            'site_settings'       => $siteSettings,
            'adoption_installed'  => $this->app->isAddonInstalled('Adoption'),
            'kim_installed'       => $this->app->isAddonInstalled('Kim'),
            'success'             => $success,
            'error'               => $error,
        ], '', [
            $this->crumb('Collective',     $this->adminUrl('/admin/collective')),
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/collective/settings
    // -----------------------------------------------------------------------

    /**
     * Saves collective settings (contact toggle/recipient, affiliate applications
     * toggle, and collective form URL overrides) to lstr_site_settings.
     */
    public function saveCollectiveSettings(Request $request, array $params): Response
    {
        $session  = $this->app->getSession();
        $baseUrl  = $this->app->getBaseUrl();

        $siteSettings = new SiteSettingsRepo($this->app->getDatabase());

        $collectiveContactEnabled   = $request->post('collective_contact_enabled') === '1' ? '1' : '0';
        $collectiveContactRecipient = trim((string) $request->post('collective_contact_recipient', ''));
        if ($collectiveContactRecipient !== '' && !filter_var($collectiveContactRecipient, FILTER_VALIDATE_EMAIL)) {
            $collectiveContactRecipient = '';
        }
        $siteSettings->set('collective_contact_enabled',   $collectiveContactEnabled);
        $siteSettings->set('collective_contact_recipient', $collectiveContactRecipient !== '' ? $collectiveContactRecipient : null);

        $siteSettings->set('collective_affiliate_apply_enabled', $request->post('collective_affiliate_apply_enabled') === '1' ? '1' : '0');

        $codesSubmitUrl     = trim((string) $request->post('collective_form_url_codes_submit',    ''));
        $affiliateApplyUrl  = trim((string) $request->post('collective_form_url_affiliate_apply', ''));
        $contactUrl         = trim((string) $request->post('collective_form_url_contact',         ''));
        $siteSettings->set('collective_form_url_codes_submit',    $codesSubmitUrl    !== '' ? $codesSubmitUrl    : null);
        $siteSettings->set('collective_form_url_affiliate_apply', $affiliateApplyUrl !== '' ? $affiliateApplyUrl : null);
        $siteSettings->set('collective_form_url_contact',         $contactUrl        !== '' ? $contactUrl        : null);

        if ($this->app->isAddonInstalled('Adoption')) {
            $adoptUrl = trim((string) $request->post('collective_form_url_adopt', ''));
            $siteSettings->set('collective_form_url_adopt', $adoptUrl !== '' ? $adoptUrl : null);
        }

        if ($this->app->isAddonInstalled('Kim')) {
            foreach (['kim_url_signup', 'kim_url_update', 'kim_url_reset', 'kim_url_list'] as $kimKey) {
                $kimVal = trim((string) $request->post($kimKey, ''));
                $siteSettings->set($kimKey, $kimVal !== '' ? $kimVal : null);
            }
        }

        $collCsEnabled = $request->post('collective_codes_submit_enabled') === '1' ? '1' : '0';
        $siteSettings->set('collective_codes_submit_enabled', $collCsEnabled);

        (new ActivityRepo($this->app->getDatabase()))->log(
            (int) $session->get('lstr_admin_id'),
            'settings.save',
            'Collective settings updated',
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Collective settings saved.');
        return Response::redirect($baseUrl . '/admin/collective/settings');
    }

    // GET /admin/settings
    // -----------------------------------------------------------------------

    /**
     * Renders the main Settings page: Admin Profile, Admin Accounts, Appearance,
     * General, Notifications, SMTP, Spam Filtering, and System Details panels.
     */
    public function index(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $error       = (string) $session->get('lstr_flash_error', '');
        $fieldErrors = json_decode((string) $session->get('lstr_flash_field_errors', '{}'), true);
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_field_errors');

        $adminId   = (int) $session->get('lstr_admin_id');
        $adminRepo = new AdminUserRepo($this->app->getDatabase());
        $adminUser = $adminRepo->getById($adminId);

        $db = $this->app->getDatabase();

        $blacklistEntries = (new BlacklistRepo($db))->getAll();
        $rateLimitBlocks  = (new RateLimit($db))->getActiveBlocks();

        $pdo           = $db->pdo();
        $dbVersion     = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $errorLogCount = (new ErrorLogRepo($db))->count();

        $siteRepo     = new SiteSettingsRepo($db);
        $siteSettings = $siteRepo->getMany([
            'owned_template',
            'joined_template',
            'collective_stats_template',
            'import_show_enthusiast',
            'import_show_listing_admin',
            'import_show_fanupdate',
            'import_show_codesort',
            'import_show_bellabuffs',
            'import_show_fanatically',
            'selected_skin',
            'selected_skin_hash',
            'overdue_notify_enabled',
            'overdue_days',
            'overdue_notify_last_sent',
            'stale_days',
            'update_email_notify',
            'pending_digest_enabled',
            'admin_default_theme',
        ]);

        // Detect community skin files in public/assets/css/skins/
        $skinsDir      = LUSTER_BASE . '/public/assets/css/skins';
        $communitySkins = [];
        if (is_dir($skinsDir)) {
            foreach (glob($skinsDir . '/*.css') ?: [] as $file) {
                $slug = basename($file, '.css');
                if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
                    $communitySkins[] = $slug;
                }
            }
        }

        $updateStatus = (new UpdateChecker($siteRepo))->getStatus();

        return $this->adminLayout('admin/settings/index.php', 'Settings', 'settings', [
            'cfg'                  => $this->app->getConfig()->all(),
            'admin_user'           => $adminUser,
            'users'                => $adminRepo->getAll(),
            'current_admin_id'     => $adminId,
            'blacklist_entries'    => $blacklistEntries,
            'rate_limit_blocks'    => $rateLimitBlocks,
            'my_ip'                => $request->ip(),
            'site_settings'        => $siteSettings,
            'community_skins'      => $communitySkins,
            'success'              => $success,
            'error'                => $error,
            'field_errors'         => is_array($fieldErrors) ? $fieldErrors : [],
            'php_version'          => PHP_VERSION,
            'db_version'           => $dbVersion,
            'error_log_count'      => $errorLogCount,
            'update_status'        => $updateStatus,
            'session_path_diagnostics' => $session->getPathDiagnostics(),
            'remembered_devices'   => $this->getRememberedDevices($adminId),
            'health_data'          => $this->computeHealthData(),
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/settings
    // -----------------------------------------------------------------------

    /**
     * Saves the General settings panel: site name, site URL, embed form URL,
     * timezone, owner identity, and date/time display format. Writes to
     * config/app.php and persists display format preferences to lstr_site_settings.
     */
    public function updateGeneral(Request $request, array $params): Response
    {
        $session      = $this->app->getSession();
        $baseUrl      = $this->app->getBaseUrl();
        $activityRepo = new ActivityRepo($this->app->getDatabase());

        $siteName    = trim((string) $request->post('site_name', ''));
        $siteUrl     = self::httpsify(rtrim(trim((string) $request->post('site_url', '')), '/'));
        $timezone    = trim((string) $request->post('timezone', 'UTC'));
        $ownerEmail  = trim((string) $request->post('owner_email', ''));
        $embedBaseUrl = self::httpsify(rtrim(trim((string) $request->post('embed_base_url', '')), '/'));

        $v = new Validator([
            'site_name'      => $siteName,
            'site_url'       => $siteUrl,
            'owner_email'    => $ownerEmail,
            'embed_base_url' => $embedBaseUrl,
        ]);
        $v->field('site_name')->required()->maxLength(100);
        $v->field('site_url')->required()->httpUrl()->maxLength(500);
        $v->field('owner_email')->email()->maxLength(255);
        $v->field('embed_base_url')->httpUrl()->maxLength(500);

        if ($v->fails()) {
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()));
            $session->set('lstr_flash_field_errors', json_encode($v->errors()));
            return Response::redirect($baseUrl . '/admin/settings#panel-general');
        }

        // Validate timezone
        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = 'UTC';
        }

        // Load the current config and merge in the allowed updated keys
        $existing = $this->app->getConfig()->all();

        $updated = $existing;
        $updated['site_name'] = $siteName;
        $updated['site_url']  = $siteUrl;
        $updated['timezone']  = $timezone;

        // Site owner identity (used in email templates)
        $updated['owner_name']  = trim((string) $request->post('owner_name',  ''));
        $updated['owner_email'] = $ownerEmail;

        // Embed form submission URL (blank = fall back to app_url at runtime)
        $updated['embed_base_url'] = $embedBaseUrl;

        $this->writeConfig($updated);

        // Save date_format and time_format to lstr_site_settings (UI preferences, not in config/app.php)
        $siteSettings = new SiteSettingsRepo($this->app->getDatabase());

        $allowedDateFormats = ['j M Y', 'M j, Y', 'F j, Y', 'd/m/Y', 'm/d/Y', 'Y-m-d', 'd-m-Y', 'm-d-Y'];
        $dateFormat = in_array($request->post('date_format'), $allowedDateFormats, true)
            ? (string) $request->post('date_format')
            : 'j M Y';
        $siteSettings->set('date_format', $dateFormat);

        $allowedTimeFormats = ['H:i', 'g:i A', 'g:i a'];
        $timeFormat = in_array($request->post('time_format'), $allowedTimeFormats, true)
            ? (string) $request->post('time_format')
            : 'H:i';
        $siteSettings->set('time_format', $timeFormat);

        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'settings.save',
            'Application settings updated',
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Settings saved successfully.');
        return Response::redirect($baseUrl . '/admin/settings#panel-general');
    }

    // -----------------------------------------------------------------------
    // POST /admin/settings/smtp
    // -----------------------------------------------------------------------

    /**
     * Saves the SMTP / Email panel: smtp_enabled, host, port, user, secure mode,
     * from address, and from name. A blank password field preserves the existing
     * stored value rather than overwriting it with an empty string.
     */
    public function updateSmtp(Request $request, array $params): Response
    {
        $session      = $this->app->getSession();
        $baseUrl      = $this->app->getBaseUrl();
        $activityRepo = new ActivityRepo($this->app->getDatabase());

        // Load the current config and merge in the allowed updated keys
        $existing = $this->app->getConfig()->all();
        $updated  = $existing;

        $updated['smtp_enabled']   = $request->post('smtp_enabled') === '1' ? '1' : '';
        $updated['smtp_host']      = trim((string) $request->post('smtp_host',      ''));
        $updated['smtp_port']      = max(1, min(65535, (int) $request->post('smtp_port', 587)));
        $updated['smtp_user']      = trim((string) $request->post('smtp_user',      ''));
        $updated['smtp_from']      = trim((string) $request->post('smtp_from',      ''));
        $updated['smtp_from_name'] = trim((string) $request->post('smtp_from_name', ''));

        $allowedSecure = ['tls', 'ssl', 'none'];
        $smtpSecure = trim((string) $request->post('smtp_secure', 'tls'));
        $updated['smtp_secure'] = in_array($smtpSecure, $allowedSecure, true) ? $smtpSecure : 'tls';

        // Blank password on save keeps the existing stored value
        $smtpPass = (string) $request->post('smtp_pass', '');
        if ($smtpPass !== '') {
            $updated['smtp_pass'] = $smtpPass;
        }
        // else: leave $updated['smtp_pass'] as whatever was in $existing

        $this->writeConfig($updated);

        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'settings.save',
            'SMTP / email settings updated',
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Settings saved successfully.');
        return Response::redirect($baseUrl . '/admin/settings#panel-smtp');
    }

    // -----------------------------------------------------------------------
    // POST /admin/settings/test-email
    // -----------------------------------------------------------------------

    /**
     * Sends a test email to the currently logged-in admin to verify that the
     * server's mail delivery is working. Reports the transport mode (SMTP or
     * PHP mail()) in the success flash message.
     */
    public function testEmail(Request $request, array $params): Response
    {
        $session  = $this->app->getSession();
        $baseUrl  = $this->app->getBaseUrl();
        $config   = $this->app->getConfig();

        $fromEmail = (string) $config->get('owner_email', '');
        if ($fromEmail === '') {
            $session->set('lstr_flash_error', 'Owner Email is not set. Add it in Settings > General before sending a test.');
            return Response::redirect($baseUrl . '/admin/settings#panel-general');
        }

        $adminId   = (int) $session->get('lstr_admin_id');
        $adminRepo = new AdminUserRepo($this->app->getDatabase());
        $adminUser = $adminRepo->getById($adminId);

        if ($adminUser === null) {
            $session->set('lstr_flash_error', 'Could not determine your email address.');
            return Response::redirect($baseUrl . '/admin/settings');
        }

        $siteName = (string) $config->get('site_name', 'Luster');

        try {
            $mailer = new Mailer($config);
            $mailer->send(
                $adminUser->email,
                $adminUser->username,
                '[' . $siteName . '] Test email',
                "This is a test email sent from your " . $siteName . " admin panel.\n\n"
                . "If you received this message, your server's mail() function is working correctly.\n\n"
                . "— Luster",
            );

            $activityRepo = new ActivityRepo($this->app->getDatabase());
            $activityRepo->log(
                $adminId,
                'settings.test_email',
                'Test email sent to ' . $adminUser->email,
                $request->ip(),
            );

            $smtpMode = (string) $config->get('smtp_enabled', '') === '1';
            $transport = $smtpMode ? 'SMTP (' . $config->get('smtp_host', '') . ')' : 'server mail()';
            $session->set('lstr_flash_success', 'Test email sent to ' . $adminUser->email . ' via ' . $transport . '.');
        } catch (\Throwable $e) {
            $session->set('lstr_flash_error', 'Test email failed: ' . $e->getMessage());
        }

        return Response::redirect($baseUrl . '/admin/settings#panel-general');
    }

    // -----------------------------------------------------------------------
    // POST /admin/settings/profile
    // -----------------------------------------------------------------------

    /**
     * Saves the Admin Profile panel: username, email, display name, and optional
     * password change (requires current-password confirmation). Reflects updated
     * identity into the session immediately so the topbar updates.
     */
    public function updateProfile(Request $request, array $params): Response
    {
        $session      = $this->app->getSession();
        $baseUrl      = $this->app->getBaseUrl();
        $db           = $this->app->getDatabase();
        $adminId      = (int) $session->get('lstr_admin_id');
        $adminRepo    = new AdminUserRepo($db);
        $activityRepo = new ActivityRepo($db);

        $username        = trim((string) $request->post('admin_username',        ''));
        $email           = trim((string) $request->post('admin_email',           ''));
        $displayName       = trim((string) $request->post('admin_display_name',  ''));
        $currentPassword = (string) $request->post('admin_current_password',     '');
        $newPassword     = (string) $request->post('admin_new_password',         '');
        $confirmPassword = (string) $request->post('admin_confirm_password',     '');

        $v = new Validator(['username' => $username, 'email' => $email, 'owner_name' => $displayName]);
        $v->field('username')->required()->maxLength(50);
        $v->field('email')->required()->email()->maxLength(255);
        $v->field('owner_name')->required()->maxLength(100);

        if ($v->fails()) {
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()));
            $session->set('lstr_flash_field_errors', json_encode($v->errors()));
            return Response::redirect($baseUrl . '/admin/settings#panel-admin');
        }

        if ($newPassword !== '') {
            // Require current-password confirmation before accepting a new password
            $currentAdmin = $adminRepo->getById($adminId);
            if ($currentAdmin === null || !password_verify($currentPassword, $currentAdmin->password)) {
                $session->set('lstr_flash_error', 'Current password is incorrect.');
                $session->set('lstr_flash_field_errors', json_encode(['admin_current_password' => ['Current password is incorrect.']]));
                return Response::redirect($baseUrl . '/admin/settings#panel-admin');
            }
            $vp = new Validator(['admin_new_password' => $newPassword]);
            $vp->field('admin_new_password')->minLength(12)->maxLength(255);
            if ($vp->fails()) {
                $session->set('lstr_flash_error', implode(' ', $vp->allErrors()));
                $session->set('lstr_flash_field_errors', json_encode($vp->errors()));
                return Response::redirect($baseUrl . '/admin/settings#panel-admin');
            }
            if ($newPassword !== $confirmPassword) {
                $session->set('lstr_flash_error', 'New password and confirmation do not match.');
                $session->set('lstr_flash_field_errors', json_encode(['admin_confirm_password' => ['New password and confirmation do not match.']]));
                return Response::redirect($baseUrl . '/admin/settings#panel-admin');
            }
        }

        // Uniqueness checks — exclude the current user
        $existingByUsername = $adminRepo->findByUsername($username);
        if ($existingByUsername !== null && $existingByUsername->id !== $adminId) {
            $session->set('lstr_flash_error', 'That username is already taken.');
            $session->set('lstr_flash_field_errors', json_encode(['username' => ['That username is already taken.']]));
            return Response::redirect($baseUrl . '/admin/settings#panel-admin');
        }

        $existingByEmail = $adminRepo->findByEmail($email);
        if ($existingByEmail !== null && $existingByEmail->id !== $adminId) {
            $session->set('lstr_flash_error', 'That email address is already in use.');
            $session->set('lstr_flash_field_errors', json_encode(['email' => ['That email address is already in use.']]));
            return Response::redirect($baseUrl . '/admin/settings#panel-admin');
        }

        $adminRepo->updateProfile($adminId, $username, $email, $displayName ?: null);

        // Reflect changes in session so the topbar updates immediately
        $session->set('lstr_admin_username',     $username);
        $session->set('lstr_admin_email',        $email);
        $session->set('lstr_admin_display_name', $displayName);

        if ($newPassword !== '') {
            $adminRepo->updatePassword($adminId, password_hash($newPassword, PASSWORD_ARGON2ID));
        }

        $activityRepo->log(
            $adminId,
            'admin.profile.update',
            'Admin profile updated',
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Profile updated successfully.');
        return Response::redirect($baseUrl . '/admin/settings');
    }

    // -----------------------------------------------------------------------
    // POST /admin/settings/devices/{id}/revoke
    // -----------------------------------------------------------------------

    /**
     * Revokes a single remembered device token. Only the currently logged-in
     * admin may revoke their own tokens.
     */
    public function revokeDevice(Request $request, array $params): Response
    {
        $this->app->getCSRF()->validate($request->post('_csrf', ''));

        $session  = $this->app->getSession();
        $adminId  = (int) $session->get('lstr_admin_id');
        $deviceId = (int) ($params['id'] ?? 0);
        $db       = $this->app->getDatabase();

        $db->prepare(
            'DELETE FROM lstr_admin_remember_tokens
             WHERE id = :id AND admin_user_id = :uid'
        )->execute(['id' => $deviceId, 'uid' => $adminId]);

        $session->set('lstr_flash_success', 'Device revoked.');
        return Response::redirect($this->app->getBaseUrl() . '/admin/settings#panel-devices');
    }

    // -----------------------------------------------------------------------
    // POST /admin/settings/devices/revoke-all
    // -----------------------------------------------------------------------

    /**
     * Revokes all remembered device tokens for the current admin.
     */
    public function revokeAllDevices(Request $request, array $params): Response
    {
        $this->app->getCSRF()->validate($request->post('_csrf', ''));

        $session = $this->app->getSession();
        $adminId = (int) $session->get('lstr_admin_id');
        $db      = $this->app->getDatabase();

        $db->prepare(
            'DELETE FROM lstr_admin_remember_tokens WHERE admin_user_id = :uid'
        )->execute(['uid' => $adminId]);

        $session->set('lstr_flash_success', 'All remembered devices have been revoked.');
        return Response::redirect($this->app->getBaseUrl() . '/admin/settings#panel-devices');
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Returns remembered devices for the given admin, newest first.
     *
     * @return list<array{id: int, device_label: string, created_at: string, last_used_at: string|null}>
     */
    private function getRememberedDevices(int $adminId): array
    {
        try {
            $stmt = $this->app->getDatabase()->prepare(
                'SELECT id, device_label, created_at, last_used_at, user_agent_hint
                 FROM lstr_admin_remember_tokens
                 WHERE admin_user_id = :uid
                   AND expires_at > NOW()
                 ORDER BY COALESCE(last_used_at, created_at) DESC, id DESC'
            );
            $stmt->execute(['uid' => $adminId]);
            $rows = $stmt->fetchAll();

            return array_map(static fn(array $r): array => [
                'id'           => (int) $r['id'],
                'device_label' => (string) ($r['device_label'] ?? 'Unknown device'),
                'created_at'   => (string) $r['created_at'],
                'last_used_at' => $r['last_used_at'] !== null ? (string) $r['last_used_at'] : null,
            ], $rows ?: []);
        } catch (\PDOException) {
            // Pre-migration — column may not exist yet.
            try {
                $stmt = $this->app->getDatabase()->prepare(
                    'SELECT id, created_at FROM lstr_admin_remember_tokens
                     WHERE admin_user_id = :uid AND expires_at > NOW()
                     ORDER BY created_at DESC, id DESC'
                );
                $stmt->execute(['uid' => $adminId]);
                $rows = $stmt->fetchAll();
                return array_map(static fn(array $r): array => [
                    'id'           => (int) $r['id'],
                    'device_label' => 'Remembered device',
                    'created_at'   => (string) $r['created_at'],
                    'last_used_at' => null,
                ], $rows ?: []);
            } catch (\PDOException) {
                return [];
            }
        }
    }

    // -----------------------------------------------------------------------
    // POST /admin/settings/templates
    // -----------------------------------------------------------------------

    /**
     * Saves all collective embed templates submitted from the Collective
     * Templates page. Null-stores blank submissions so built-in defaults
     * are used at render time.
     */
    public function updateTemplates(Request $request, array $params): Response
    {
        $session  = $this->app->getSession();
        $baseUrl  = $this->app->getBaseUrl();

        $groupWhitelist = [
            'all',
            'owned', 'latest-owned', 'random-owned',
            'joined', 'latest-joined', 'random-joined',
            'stats',
            'codes', 'codes-form',
            'affiliates', 'affiliate-form',
            'contact',
            'kim-overview', 'kim-subject', 'kim-signup', 'kim-update', 'kim-reset',
            'adoption',
            'wishlist',
            'newsfeed',
            'collective-update-form',
        ];
        $returnGroup = (string) $request->post('return_group', 'all');
        if (!in_array($returnGroup, $groupWhitelist, true)) {
            $returnGroup = 'all';
        }

        $ownedTemplate                       = (string) $request->post('owned_template',                            '');
        $joinedTemplate                      = (string) $request->post('joined_template',                           '');
        $cStatsTemplate                      = (string) $request->post('collective_stats_template',                 '');
        $collectiveAffiliatesSectionTpl      = (string) $request->post('collective_affiliates_section_template',    '');
        $randomListingTemplate               = (string) $request->post('random_listing_template',                   '');
        $latestListingTemplate               = (string) $request->post('latest_listing_template',                   '');
        $randomJoinedListingTemplate         = (string) $request->post('random_joined_listing_template',            '');
        $latestJoinedListingTemplate         = (string) $request->post('latest_joined_listing_template',            '');
        $collectiveAffiliateApplyFormTpl     = (string) $request->post('collective_affiliate_apply_form_template',  '');
        $collectiveAdoptionFormTpl           = (string) $request->post('collective_adoption_form_template',           '');
        $kimSignupFormTpl                    = (string) $request->post('kim_signup_form_template',                   '');
        $wishlistTemplate                    = (string) $request->post('wishlist_template',                          '');
        $collectiveUpdatesTemplate           = (string) $request->post('collective_newsfeed_template',                '');
        $collectiveCodesTemplate             = (string) $request->post('collective_codes_template',                  '');
        $collectiveCodesSubmitFormTpl        = (string) $request->post('collective_codes_submit_form_template',      '');
        $kimListOverviewTpl                  = (string) $request->post('collective_kim_list_overview_template',      '');
        $kimListSubjectTpl                   = (string) $request->post('collective_kim_list_subject_template',       '');
        $kimUpdateFormTpl                    = (string) $request->post('kim_update_form_template',                   '');
        $kimResetFormTpl                     = (string) $request->post('kim_reset_form_template',                    '');
        $contactFormTpl                      = (string) $request->post('collective_contact_form_template',           '');
        $collectiveUpdateFormTpl             = (string) $request->post('collective_update_form_template',             '');

        $db = $this->app->getDatabase();

        // Record revision history for any template values about to change.
        $siteRepo    = new SiteSettingsRepo($db);
        $oldValues   = $siteRepo->getMany(TemplatePackController::COLLECTIVE_TEMPLATE_KEYS);
        $adminId     = (int) $session->get('lstr_admin_id');
        $incomingRev = [
            'owned_template'                          => $ownedTemplate                   !== '' ? $ownedTemplate                   : null,
            'joined_template'                         => $joinedTemplate                  !== '' ? $joinedTemplate                  : null,
            'collective_stats_template'               => $cStatsTemplate                  !== '' ? $cStatsTemplate                  : null,
            'collective_affiliates_section_template'  => $collectiveAffiliatesSectionTpl  !== '' ? $collectiveAffiliatesSectionTpl  : null,
            'random_listing_template'                 => $randomListingTemplate            !== '' ? $randomListingTemplate            : null,
            'latest_listing_template'                 => $latestListingTemplate            !== '' ? $latestListingTemplate            : null,
            'random_joined_listing_template'          => $randomJoinedListingTemplate      !== '' ? $randomJoinedListingTemplate      : null,
            'latest_joined_listing_template'          => $latestJoinedListingTemplate      !== '' ? $latestJoinedListingTemplate      : null,
            'collective_affiliate_apply_form_template' => $collectiveAffiliateApplyFormTpl !== '' ? $collectiveAffiliateApplyFormTpl : null,
            'collective_adoption_form_template'       => $collectiveAdoptionFormTpl        !== '' ? $collectiveAdoptionFormTpl        : null,
            'kim_signup_form_template'                => $kimSignupFormTpl                 !== '' ? $kimSignupFormTpl                 : null,
            'wishlist_template'                       => $wishlistTemplate                 !== '' ? $wishlistTemplate                 : null,
            'collective_newsfeed_template'             => $collectiveUpdatesTemplate        !== '' ? $collectiveUpdatesTemplate        : null,
            'collective_codes_template'               => $collectiveCodesTemplate          !== '' ? $collectiveCodesTemplate          : null,
            'collective_codes_submit_form_template'   => $collectiveCodesSubmitFormTpl     !== '' ? $collectiveCodesSubmitFormTpl     : null,
            'collective_kim_list_overview_template'   => $kimListOverviewTpl               !== '' ? $kimListOverviewTpl               : null,
            'collective_kim_list_subject_template'    => $kimListSubjectTpl                !== '' ? $kimListSubjectTpl                : null,
            'kim_update_form_template'                => $kimUpdateFormTpl                 !== '' ? $kimUpdateFormTpl                 : null,
            'kim_reset_form_template'                 => $kimResetFormTpl                  !== '' ? $kimResetFormTpl                  : null,
            'collective_contact_form_template'        => $contactFormTpl                   !== '' ? $contactFormTpl                   : null,
            'collective_update_form_template'         => $collectiveUpdateFormTpl           !== '' ? $collectiveUpdateFormTpl           : null,
        ];
        (new TemplateRevisionRepo($db))->recordChanged(TemplateRevisionRepo::COLLECTIVE_ID, $oldValues, $incomingRev, $adminId);

        $siteRepo->set('owned_template',            $ownedTemplate  !== '' ? $ownedTemplate  : null);
        $siteRepo->set('joined_template',           $joinedTemplate !== '' ? $joinedTemplate : null);
        $siteRepo->set('collective_stats_template', $cStatsTemplate !== '' ? $cStatsTemplate : null);
        // Affiliates section template: null = feature off (same as empty)
        $siteRepo->set('collective_affiliates_section_template', $collectiveAffiliatesSectionTpl !== '' ? $collectiveAffiliatesSectionTpl : null);
        $siteRepo->set('random_listing_template',   $randomListingTemplate !== '' ? $randomListingTemplate : null);
        $siteRepo->set('latest_listing_template',   $latestListingTemplate !== '' ? $latestListingTemplate : null);
        $siteRepo->set('random_joined_listing_template', $randomJoinedListingTemplate !== '' ? $randomJoinedListingTemplate : null);
        $siteRepo->set('latest_joined_listing_template', $latestJoinedListingTemplate !== '' ? $latestJoinedListingTemplate : null);
        $siteRepo->set('collective_affiliate_apply_form_template', $collectiveAffiliateApplyFormTpl !== '' ? $collectiveAffiliateApplyFormTpl : null);
        $siteRepo->set('collective_adoption_form_template',        $collectiveAdoptionFormTpl       !== '' ? $collectiveAdoptionFormTpl       : null);
        $siteRepo->set('kim_signup_form_template', $kimSignupFormTpl !== '' ? $kimSignupFormTpl : null);
        $siteRepo->set('wishlist_template',            $wishlistTemplate          !== '' ? $wishlistTemplate          : null);
        $siteRepo->set('collective_newsfeed_template',  $collectiveUpdatesTemplate !== '' ? $collectiveUpdatesTemplate : null);
        $siteRepo->set('collective_codes_template',    $collectiveCodesTemplate   !== '' ? $collectiveCodesTemplate   : null);
        $siteRepo->set('collective_codes_submit_form_template', $collectiveCodesSubmitFormTpl !== '' ? $collectiveCodesSubmitFormTpl : null);
        $siteRepo->set('collective_kim_list_overview_template', $kimListOverviewTpl !== '' ? $kimListOverviewTpl : null);
        $siteRepo->set('collective_kim_list_subject_template',  $kimListSubjectTpl  !== '' ? $kimListSubjectTpl  : null);
        $siteRepo->set('kim_update_form_template',  $kimUpdateFormTpl !== '' ? $kimUpdateFormTpl : null);
        $siteRepo->set('kim_reset_form_template',   $kimResetFormTpl  !== '' ? $kimResetFormTpl  : null);
        $siteRepo->set('collective_contact_form_template', $contactFormTpl !== '' ? $contactFormTpl : null);
        $siteRepo->set('collective_update_form_template', $collectiveUpdateFormTpl !== '' ? $collectiveUpdateFormTpl : null);

        $collAffStyle = (string) $request->post('collective_affiliate_apply_style', 'button');
        $siteRepo->set('collective_affiliate_apply_style', in_array($collAffStyle, ['link', 'button'], true) ? $collAffStyle : 'button');
        $collAffLabel = trim((string) $request->post('collective_affiliate_apply_toggle_label', ''));
        $siteRepo->set('collective_affiliate_apply_toggle_label', $collAffLabel !== '' ? $collAffLabel : null);

        $collCsStyle = (string) $request->post('collective_codes_submit_style', 'button');
        $siteRepo->set('collective_codes_submit_style', in_array($collCsStyle, ['link', 'button'], true) ? $collCsStyle : 'button');
        $collCsLabel = trim((string) $request->post('collective_codes_submit_toggle_label', ''));
        $siteRepo->set('collective_codes_submit_toggle_label', $collCsLabel !== '' ? $collCsLabel : null);

        $activityRepo = new ActivityRepo($this->app->getDatabase());
        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'settings.templates.update',
            'Updated collective embed templates',
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Collective templates saved.');
        $redirect = $baseUrl . '/admin/collective/templates';
        if ($returnGroup !== 'all') {
            $redirect .= '?group=' . rawurlencode($returnGroup);
        }
        return Response::redirect($redirect);
    }

    // -----------------------------------------------------------------------
    // POST /admin/settings/templates/reset-all
    // -----------------------------------------------------------------------

    /**
     * Resets all collective embed templates to their built-in defaults by
     * setting every template key to NULL in lstr_site_settings.
     */
    public function resetAllTemplates(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $siteRepo = new SiteSettingsRepo($this->app->getDatabase());
        $keys = [
            'owned_template', 'joined_template',
            'collective_stats_template', 'collective_affiliates_section_template',
            'random_listing_template', 'latest_listing_template',
            'random_joined_listing_template', 'latest_joined_listing_template',
            'collective_affiliate_apply_form_template', 'collective_adoption_form_template',
            'kim_signup_form_template', 'wishlist_template',
            'collective_newsfeed_template', 'collective_codes_template',
            'collective_codes_submit_form_template',
            'collective_kim_list_overview_template', 'collective_kim_list_subject_template',
            'kim_update_form_template', 'kim_reset_form_template',
            'collective_contact_form_template',
            'collective_contact_sent_template',
            'collective_update_form_template',
        ];
        foreach ($keys as $key) {
            $siteRepo->set($key, null);
        }

        $session->set('lstr_flash_success', 'All collective templates reset to defaults.');
        return Response::redirect($baseUrl . '/admin/collective/templates');
    }

    private function tableExists(Database $db, string $table): bool
    {
        try {
            $stmt = $db->prepare(
                'SELECT 1 FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = :table
                 LIMIT 1'
            );
            $stmt->execute(['table' => $table]);
            return $stmt->fetch() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    // -----------------------------------------------------------------------
    // POST /admin/settings/check-update
    // -----------------------------------------------------------------------

    /**
     * Forces a fresh update check against the GitHub releases API and flashes
     * the result. Supports both AJAX (JSON) and regular POST (redirect) callers.
     */
    public function checkUpdate(Request $request, array $params): Response
    {
        $session  = $this->app->getSession();
        $baseUrl  = $this->app->getBaseUrl();
        $siteRepo = new SiteSettingsRepo($this->app->getDatabase());
        $status   = (new UpdateChecker($siteRepo))->getStatus(force: true);

        $isAjax = $request->isAjax();

        if ($status->checkFailed) {
            $msg = 'Update check failed: ' . ($status->error ?? 'Unknown error.');
            if ($isAjax) {
                return Response::json(['ok' => false, 'message' => $msg]);
            }
            $session->set('lstr_flash_error', $msg);
        } elseif ($status->updateAvailable) {
            $msg = 'A new version is available: ' . $status->latest . '.';
            if ($isAjax) {
                return Response::json(['ok' => true, 'message' => $msg]);
            }
            $session->set('lstr_flash_success', $msg);
        } else {
            $msg = 'Luster is up to date (version ' . $status->installed . ').';
            if ($isAjax) {
                return Response::json(['ok' => true, 'message' => $msg]);
            }
            $session->set('lstr_flash_success', $msg);
        }

        return Response::redirect($baseUrl . '/admin/settings#panel-system');
    }

    // -----------------------------------------------------------------------
    // Hosting health data — computed on every Settings page load
    // -----------------------------------------------------------------------

    /**
     * Returns a snapshot of key server health indicators for the Hosting Health
     * panel on the System tab.
     *
     * @return array{
     *   extensions: array<string,bool>,
     *   uploads_writable: bool,
     *   logs_writable: bool,
     *   applied_migration: int|null,
     *   latest_migration: int,
     * }
     */
    private function computeHealthData(): array
    {
        $base = LUSTER_BASE;

        $extensions = [
            'pdo_mysql' => extension_loaded('pdo_mysql'),
            'curl'      => extension_loaded('curl'),
            'fileinfo'  => extension_loaded('fileinfo'),
            'gd_imagick'=> extension_loaded('gd') || extension_loaded('imagick'),
            'json'      => extension_loaded('json'),
            'mbstring'  => extension_loaded('mbstring'),
        ];

        $uploadsPath = $base . '/public/uploads';
        $logsPath    = $base . '/storage/logs';

        $appliedMigration = null;
        try {
            $stmt = $this->app->getDatabase()->prepare('SELECT MAX(migration_number) AS m FROM lstr_migrations');
            $stmt->execute();
            $row = $stmt->fetch();
            if ($row !== false && $row['m'] !== null) {
                $appliedMigration = (int) $row['m'];
            }
        } catch (\Throwable) {
            // lstr_migrations may not exist on a very old install — health panel shows "unknown"
        }

        return [
            'extensions'        => $extensions,
            'uploads_writable'  => is_dir($uploadsPath) && is_writable($uploadsPath),
            'logs_writable'     => is_dir($logsPath)    && is_writable($logsPath),
            'applied_migration' => $appliedMigration,
            'latest_migration'  => Version::LATEST_MIGRATION,
        ];
    }

    // -----------------------------------------------------------------------
    // POST /admin/settings/check-http
    // -----------------------------------------------------------------------

    /**
     * Makes a quick outbound HTTPS HEAD request to a known safe public endpoint
     * (GitHub API) to verify that this server can reach the internet with TLS
     * verification enabled. Returns JSON {ok, message} for the inline result span.
     */
    public function checkHttp(Request $request, array $params): Response
    {
        $this->app->getCSRF()->validate($request->post('_csrf', ''));

        if (!function_exists('curl_init')) {
            return Response::json(['ok' => false, 'message' => 'cURL is not available on this server.']);
        }

        $ch = curl_init('https://api.github.com/');
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'Luster/' . Version::CURRENT . ' (health-check)',
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error !== '') {
            return Response::json(['ok' => false, 'message' => 'HTTPS test failed: ' . $error]);
        }

        if ($httpCode === 0) {
            return Response::json(['ok' => false, 'message' => 'HTTPS test failed: no response from server.']);
        }

        return Response::json(['ok' => true, 'message' => 'Outbound HTTPS is working (HTTP ' . $httpCode . ').']);
    }

    // -----------------------------------------------------------------------
    // POST /admin/settings/import-visibility
    // -----------------------------------------------------------------------

    /**
     * Saves the import-tool visibility checkboxes and config-reader toggle
     * from the System Details panel to lstr_site_settings.
     */
    public function saveImportVisibility(Request $request, array $params): Response
    {
        $session      = $this->app->getSession();
        $baseUrl      = $this->app->getBaseUrl();
        $siteSettings = new SiteSettingsRepo($this->app->getDatabase());

        $siteSettings->set('import_show_enthusiast',    $request->post('import_show_enthusiast')    === '1' ? '1' : '0');
        $siteSettings->set('import_show_listing_admin', $request->post('import_show_listing_admin') === '1' ? '1' : '0');
        $siteSettings->set('import_show_fanupdate',     $request->post('import_show_fanupdate')     === '1' ? '1' : '0');
        $siteSettings->set('import_show_codesort',      $request->post('import_show_codesort')      === '1' ? '1' : '0');
        $siteSettings->set('import_show_bellabuffs',       $request->post('import_show_bellabuffs')       === '1' ? '1' : '0');
        $siteSettings->set('import_show_fanatically',      $request->post('import_show_fanatically')      === '1' ? '1' : '0');
        $siteSettings->set('import_allow_config_reader',   $request->post('import_allow_config_reader')   === '1' ? '1' : '0');

        (new ActivityRepo($this->app->getDatabase()))->log(
            (int) $session->get('lstr_admin_id'),
            'settings.save',
            'Import tool visibility updated',
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Import visibility settings saved.');
        return Response::redirect($baseUrl . '/admin/settings#panel-system');
    }

    // -----------------------------------------------------------------------
    // POST /admin/settings/default-theme
    // -----------------------------------------------------------------------

    /**
     * Saves the admin default colour scheme ('light' or 'dark') to
     * lstr_site_settings. New admin sessions will start with this theme.
     */
    public function saveDefaultTheme(Request $request, array $params): Response
    {
        $session  = $this->app->getSession();
        $baseUrl  = $this->app->getBaseUrl();
        $theme    = (string) $request->post('admin_default_theme', 'dark');

        if (!in_array($theme, ['light', 'dark'], true)) {
            $theme = 'dark';
        }

        (new SiteSettingsRepo($this->app->getDatabase()))->set('admin_default_theme', $theme);

        (new ActivityRepo($this->app->getDatabase()))->log(
            (int) $session->get('lstr_admin_id'),
            'settings.save',
            'Admin default theme changed to: ' . $theme,
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Default theme saved.');
        return Response::redirect($baseUrl . '/admin/settings#panel-appearance');
    }

    // -----------------------------------------------------------------------
    // POST /admin/settings/notifications
    // -----------------------------------------------------------------------

    /**
     * Saves the notification and stale-listing settings: update email flag,
     * pending digest toggle, overdue notification toggle, stale threshold
     * (days), and overdue threshold (days).
     */
    public function saveNotifications(Request $request, array $params): Response
    {
        $session      = $this->app->getSession();
        $baseUrl      = $this->app->getBaseUrl();
        $db           = $this->app->getDatabase();
        $siteSettings = new SiteSettingsRepo($db);

        $siteSettings->set('update_email_notify',   $request->post('update_email_notify')   === '1' ? '1' : '0');
        $siteSettings->set('pending_digest_enabled', $request->post('pending_digest_enabled') === '1' ? '1' : '0');
        $siteSettings->set('overdue_notify_enabled', $request->post('overdue_notify_enabled') === '1' ? '1' : '0');
        $siteSettings->set('stale_days',   (string) max(1, min(365, (int) $request->post('stale_days',   '45'))));
        $siteSettings->set('overdue_days', (string) max(1, min(365, (int) $request->post('overdue_days', '55'))));

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'settings.save',
            'Notification settings updated',
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Notification settings saved.');
        return Response::redirect($baseUrl . '/admin/settings#panel-notifications');
    }

    // -----------------------------------------------------------------------
    // POST /admin/settings/skin
    // -----------------------------------------------------------------------

    /**
     * Activates the chosen admin skin. Validates that the slug is a known
     * bundled skin or an existing community skin file, then rejects community
     * skins that contain external url() references or @import rules before
     * saving the slug and a SHA-256 hash to lstr_site_settings.
     */
    public function saveSkin(Request $request, array $params): Response
    {
        $session  = $this->app->getSession();
        $baseUrl  = $this->app->getBaseUrl();
        $slug     = trim((string) $request->post('skin', ''));

        // Validate: empty string (default/Velvet Geode) or lowercase alphanumeric-with-hyphens
        if ($slug !== '' && !preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
            $session->set('lstr_flash_error', 'Invalid skin name.');
            return Response::redirect($baseUrl . '/admin/settings#panel-appearance');
        }

        // Verify the slug is a known bundled skin or an existing community skin file
        $skinsDir      = LUSTER_BASE . '/public/assets/css/skins';
        $communityFile = $skinsDir . '/' . $slug . '.css';
        if ($slug !== '' && !SkinCatalog::isBundled($slug) && !is_file($communityFile)) {
            $session->set('lstr_flash_error', 'Skin not found.');
            return Response::redirect($baseUrl . '/admin/settings#panel-appearance');
        }

        $skinHash = null;

        if ($slug !== '' && !SkinCatalog::isBundled($slug)) {
            // Reject community skins that reference external URLs — CSS url() in a
            // background-image etc. is an image fetch, not covered by style-src, and
            // could be used to exfiltrate form field values via attribute selectors.
            if (!is_readable($communityFile)) {
                $session->set('lstr_flash_error', 'Skin file could not be read.');
                return Response::redirect($baseUrl . '/admin/settings#panel-appearance');
            }

            $css = file_get_contents($communityFile);
            if ($css === false) {
                $session->set('lstr_flash_error', 'Skin file could not be read.');
                return Response::redirect($baseUrl . '/admin/settings#panel-appearance');
            }

            if (
                preg_match('/url\s*\(\s*["\']?\s*(https?:|data:|\/\/)/i', $css)
                || preg_match('/@import\s/i', $css)
            ) {
                $session->set('lstr_flash_error',
                    'Skin rejected: external url() references and @import are not permitted.');
                return Response::redirect($baseUrl . '/admin/settings#panel-appearance');
            }

            $skinHash = hash_file('sha256', $communityFile);
            if ($skinHash === false) {
                $session->set('lstr_flash_error', 'Skin file could not be hashed.');
                return Response::redirect($baseUrl . '/admin/settings#panel-appearance');
            }
        }

        $siteRepo = new SiteSettingsRepo($this->app->getDatabase());
        $siteRepo->set('selected_skin', $slug);
        $siteRepo->set('selected_skin_hash', $skinHash);

        $skinName = $slug === '' ? 'Velvet Geode (default)' : $slug;
        if ($slug !== '') {
            foreach (SkinCatalog::all() as $skin) {
                if ($skin['slug'] === $slug) {
                    $skinName = $skin['name'];
                    break;
                }
            }
        }

        (new ActivityRepo($this->app->getDatabase()))->log(
            (int) $session->get('lstr_admin_id'),
            'settings.save',
            'Skin changed to: ' . $skinName,
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Skin updated.');
        return Response::redirect($baseUrl . '/admin/settings#panel-appearance');
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Writes the config array to config/app.php via a safe temp-file pattern.
     *
     * @throws RuntimeException on write failure
     */
    private function writeConfig(array $config): void
    {
        $configPath = $this->app->getBasePath() . '/config/app.php';
        $tmpPath    = $configPath . '.tmp';

        $export  = "<?php\n\ndeclare(strict_types=1);\n\nreturn ";
        $export .= var_export($config, true);
        $export .= ";\n";

        $bytes = file_put_contents($tmpPath, $export, LOCK_EX);

        if ($bytes === false) {
            throw new RuntimeException('Could not write temp config file: ' . $tmpPath);
        }

        if (!rename($tmpPath, $configPath)) {
            unlink($tmpPath);
            throw new RuntimeException('Could not rename temp config file to: ' . $configPath);
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($configPath, true);
        }
    }

    // -----------------------------------------------------------------------
    // GET /admin/compat-bridge — download Enthusiast compatibility bridge ZIP
    // -----------------------------------------------------------------------

    /**
     * Generates and streams a ZIP file containing the Enthusiast, Listing Admin,
     * or CodeSort compatibility bridge shims for the requested legacy-script set.
     */
    public function compatBridgeDownload(Request $request, array $params): Response
    {
        $set = (string) ($params['set'] ?? '');
        $allowed = ['enthusiast', 'listing-admin', 'codesort'];

        if (!in_array($set, $allowed, true)) {
            return Response::notFound('Unknown bridge type.');
        }

        $lusterBase = defined('LUSTER_BASE') ? \LUSTER_BASE : dirname(__DIR__, 3);
        $sourceDir  = $lusterBase . '/public/compat/' . $set;

        if (!is_dir($sourceDir)) {
            return Response::notFound('Compatibility wrapper directory not found.');
        }

        $bridgeTemplate = $sourceDir . '/luster-bridge.php';
        if (!is_file($bridgeTemplate)) {
            return Response::notFound('Bridge template not found.');
        }

        $zip = new \ZipArchive();
        $tmp = sys_get_temp_dir() . '/luster_compat_bridge_' . $set . '_' . bin2hex(random_bytes(4)) . '.zip';

        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return Response::plain('Could not create ZIP archive.', 500);
        }

        $bridgeSource = file_get_contents($bridgeTemplate);
        if ($bridgeSource !== false) {
            $safePath     = str_replace('\\', '/', $lusterBase);
            $bridgeSource = str_replace(
                "'%%LUSTER_PATH%%'",
                var_export($safePath, true),
                $bridgeSource,
            );
            $zip->addFromString('luster-bridge.php', $bridgeSource);
        }

        foreach (new \DirectoryIterator($sourceDir) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            if ($file->getFilename() === 'luster-bridge.php') {
                continue;
            }
            $zip->addFile($file->getPathname(), $file->getFilename());
        }

        $zip->close();

        $zipContents = file_get_contents($tmp);
        @unlink($tmp);

        if ($zipContents === false) {
            return Response::plain('Could not read generated ZIP.', 500);
        }

        return Response::download($zipContents, 'luster-' . $set . '-bridge.zip', 'application/zip');
    }

}
