<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Read-only reference page that generates copyable PHP embed snippets for every
 embed entry point. Snippets are pre-filled with the selected listing's ID and
 the configured install path. Also provides the compat-test renderer, which
 smoke-tests legacy compatibility wrappers (Enthusiast, Listing Admin, CodeSort)
 by rendering their output inline for visual verification.
*/

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\Countries;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Models\Member;
use Luster\Repositories\FieldRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Support\SampleTemplateData;

/**
 * Renders the Embed Codes page, which generates copy-paste PHP snippets for every
 * embed entry point, and drives the live preview iframe used on that page.
 *
 * The index action lists all 25+ embed types with pre-filled snippets (install path
 * and a real listing ID). The preview action runs the requested embed in an isolated
 * output buffer and returns a minimal HTML document so the admin can see exactly what
 * a visitor would see. Session-gated embeds (Update Membership, KIM Update) are
 * rendered synthetically with placeholder data so the preview never consumes or
 * mutates the current session's auth state. The compat-test action smoke-tests legacy
 * compatibility wrappers by executing a selected embed and displaying the raw output.
 */
final class EmbedCodesController
{
    public function __construct(private readonly Application $app) {}

    /**
     * Renders the Embed Codes index page.
     *
     * Picks an example listing ID (from the ?listing= query parameter, or the first
     * active listing) and passes it, the embed directory path, and all listings to the
     * template so the snippet generator can pre-fill realistic values.
     */
    public function index(Request $request, array $params): Response
    {
        $db        = $this->app->getDatabase();
        $config    = $this->app->getConfig();
        $view      = $this->app->getView();
        $session   = $this->app->getSession();
        $baseUrl   = $this->app->getBaseUrl();
        $csrfToken = $this->app->getCSRF()->token();

        $listingRepo = new ListingRepo($db, $config);
        $listings    = $listingRepo->getAll();

        // Pick a real listing ID for snippet examples.
        // Honour ?listing=ID so callers can deep-link to a specific listing.
        $requestedId = (int) $request->query('listing', 0);
        $exampleId   = 1;
        foreach ($listings as $fl) {
            if ($requestedId > 0 && $fl->id === $requestedId) {
                $exampleId = $fl->id;
                break;
            }
            if ($requestedId === 0 && !$fl->upcoming && !$fl->trouble) {
                $exampleId = $fl->id;
                break;
            }
        }

        // Absolute server path to the embed directory
        $lusterBase = defined('LUSTER_BASE') ? \LUSTER_BASE : dirname(__DIR__, 3);
        $resolved   = realpath($lusterBase . '/public/embed');
        $embedPath  = $resolved !== false
            ? rtrim(str_replace('\\', '/', $resolved), '/')
            : rtrim(str_replace('\\', '/', $lusterBase), '/') . '/public/embed';

        $inner = $view->render('admin/embed-codes/index.php', [
            'embed_path'  => $embedPath,
            'example_id'  => $exampleId,
            'listings'    => $listings,
            'base_url'    => $baseUrl,
            'csrf_token'  => $csrfToken,
        ]);

        return Response::html($view->render('admin/layout.php', array_merge([
            'page_title'      => 'Embed Codes',
            'site_name'       => (string) $config->get('site_name', 'Luster'),
            'admin_username'  => (string) $session->get('lstr_admin_username', ''),
            'csrf_token'      => $csrfToken,
            'current_section' => 'embed-codes',
            'base_url'        => $baseUrl,
            'content'         => $inner,
        ], $this->app->getAdminNavData())));
    }

    // -----------------------------------------------------------------------
    // GET /admin/embed-codes/preview
    // -----------------------------------------------------------------------

    /**
     * Executes a requested embed type and returns a minimal standalone HTML page
     * containing the rendered output, suitable for display inside a preview iframe.
     *
     * Most embeds are executed by requiring the real embed file with $luster_return=true
     * and any query-string overrides forwarded as embed variables. Exceptions:
     * - "update" and "kim-update" are session-gated and rendered synthetically via
     *   separate private helpers so the preview never reads or mutates auth session state.
     * - "members", "owned", and "joined" are executed three times each with different
     *   display-mode options, presented behind a pure-CSS tab toggle.
     * The returned document loads the public stylesheet for accurate styling.
     */
    public function preview(Request $request, array $params): Response
    {
        $config     = $this->app->getConfig();
        $lusterBase = defined('LUSTER_BASE') ? \LUSTER_BASE : dirname(__DIR__, 3);
        $appUrl     = rtrim((string) $config->get('app_url', ''), '/');

        $fileMap = [
            'join'         => 'join.php',
            'members'      => 'members.php',
            'stats'        => 'stats.php',
            'affiliates'   => 'affiliates.php',
            'aff-apply'    => 'affiliate-apply.php',
            'update'       => 'update.php',
            'reset'        => 'reset.php',
            'codes'        => 'codes.php',
            'codes-submit' => 'codes-submit.php',
            'contact'      => 'contact.php',
            'newsfeed'     => 'newsfeed.php',
            'adopt'        => 'adopt.php',
            'owned'        => 'owned.php',
            'joined'       => 'joined.php',
            'cstats'       => 'collective-stats.php',
            'aff-col'      => 'affiliates.php',
            'col-aff-apply'=> 'affiliate-apply.php',
            'col-codes'    => 'codes.php',
            'col-codes-submit' => 'codes-submit.php',
            'random'       => 'random-listing.php',
            'latest'       => 'latest-listing.php',
            'random-joined'=> 'random-joined-listing.php',
            'latest-joined'=> 'latest-joined-listing.php',
            'kim'          => 'kim-signup.php',
            'kim-list'     => 'kim-list.php',
            'kim-update'   => 'kim-update.php',
            'kim-reset'    => 'kim-reset.php',
            'col-newsfeed' => 'collective-newsfeed.php',
            'col-contact'  => 'collective-contact.php',
            'wishlist'     => 'wishlist.php',
            'rules-gate'   => 'rules-gate.php',
        ];

        $key = (string) $request->query('key', '');

        if (!isset($fileMap[$key])) {
            return Response::html($this->previewWrap('<p>Unknown embed type.</p>', $appUrl));
        }

        // The Update Membership embed is session-gated (lstr_update_authed_{id},
        // lstr_flash_success_html, etc.). Rendering the real embed here would let
        // this admin preview reflect — and consume/mutate — the current session's
        // state for that listing. Render both states synthetically instead, with
        // placeholder member data and inert (non-functional) form actions/tokens
        // so submitting the preview can never act on a real membership.
        if ($key === 'update') {
            $listingId = max(1, (int) $request->query('lid', 1));
            $panels = [
                ['label' => 'Sign In',    'html' => $this->previewUpdateSignInForm($listingId)],
                ['label' => 'Update Form','html' => $this->previewUpdateSignedInForm($listingId)],
            ];
            return Response::html($this->previewWrap($this->previewTabbedPanels($panels), $appUrl));
        }

        // KIM Update is also session-gated. Render both steps synthetically so the
        // admin sees the full flow without consuming or mutating auth session state.
        if ($key === 'kim-update') {
            $panels = [
                ['label' => 'Sign In',   'html' => $this->previewKimUpdateSignInForm()],
                ['label' => 'Dashboard', 'html' => $this->previewKimUpdateDashboard()],
            ];
            return Response::html($this->previewWrap($this->previewTabbedPanels($panels), $appUrl));
        }

        // Members: run the real embed three times with different sort orders so
        // the admin sees actual member data from the selected listing.
        if ($key === 'members') {
            $embedFile = $lusterBase . '/public/embed/members.php';
            if (!is_file($embedFile)) {
                return Response::html($this->previewWrap('<p>Embed file not found.</p>', $appUrl));
            }
            $lid         = max(1, (int) $request->query('lid', 1));
            $dateFormat  = (string) $request->query('date_format', '');
            $runSort = static function (string $sortBy) use ($embedFile, $lid, $dateFormat): string {
                $luster_return        = true;
                $luster_fanlisting_id = $lid;
                $luster_sort_by       = $sortBy;
                $luster_show_search   = true;
                $luster_date_format   = $dateFormat;
                try {
                    ob_start();
                    $result   = require $embedFile;
                    $buffered = ob_get_clean() ?: '';
                    return is_string($result) ? $result : $buffered;
                } catch (\Throwable $ex) {
                    ob_end_clean();
                    return '<p style="color:#c00;font-family:monospace">Error: '
                        . htmlspecialchars($ex->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
                }
            };
            $panels = [
                ['label' => 'Date Joined', 'html' => $runSort('added')],
                ['label' => 'Name',     'html' => $runSort('name')],
                ['label' => 'Country',  'html' => $runSort('country')],
            ];
            return Response::html($this->previewWrap($this->previewTabbedPanels($panels), $appUrl));
        }

        // Owned listings: run the real embed four times with different category
        // navigation modes so the admin sees actual listings and categories.
        if ($key === 'owned') {
            $embedFile = $lusterBase . '/public/embed/owned.php';
            if (!is_file($embedFile)) {
                return Response::html($this->previewWrap('<p>Embed file not found.</p>', $appUrl));
            }
            $runOwned = static function (array $vars) use ($embedFile): string {
                $luster_return = true;
                extract($vars);
                try {
                    ob_start();
                    $result   = require $embedFile;
                    $buffered = ob_get_clean() ?: '';
                    return is_string($result) ? $result : $buffered;
                } catch (\Throwable $ex) {
                    ob_end_clean();
                    return '<p style="color:#c00;font-family:monospace">Error: '
                        . htmlspecialchars($ex->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
                }
            };
            $panels = [
                ['label' => 'List View',     'html' => $runOwned(['luster_browse_by_category' => true])],
                ['label' => 'Dropdown View', 'html' => $runOwned([])],
                ['label' => 'No Categories', 'html' => $runOwned(['luster_hide_dropdown' => true])],
            ];
            return Response::html($this->previewWrap($this->previewTabbedPanels($panels), $appUrl));
        }

        // Joined listings: three display-mode panels using real data.
        if ($key === 'joined') {
            $embedFile = $lusterBase . '/public/embed/joined.php';
            if (!is_file($embedFile)) {
                return Response::html($this->previewWrap('<p>Embed file not found.</p>', $appUrl));
            }
            $runJoined = static function (array $vars) use ($embedFile): string {
                $luster_return = true;
                extract($vars);
                try {
                    ob_start();
                    $result   = require $embedFile;
                    $buffered = ob_get_clean() ?: '';
                    return is_string($result) ? $result : $buffered;
                } catch (\Throwable $ex) {
                    ob_end_clean();
                    return '<p style="color:#c00;font-family:monospace">Error: '
                        . htmlspecialchars($ex->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
                }
            };

            $panels = [
                ['label' => 'List View',     'html' => $runJoined(['luster_browse_by_category' => true])],
                ['label' => 'Dropdown View', 'html' => $runJoined([])],
                ['label' => 'Show All',      'html' => $runJoined(['luster_category' => 'all'])],
            ];
            return Response::html($this->previewWrap($this->previewTabbedPanels($panels), $appUrl));
        }

        $embedFile = $lusterBase . '/public/embed/' . $fileMap[$key];

        if (!is_file($embedFile)) {
            return Response::html($this->previewWrap('<p>Embed file not found.</p>', $appUrl));
        }

        // Variables the embed files read (set only those present in query)
        $luster_return = true;

        if ($key === 'aff-col' || $key === 'col-aff-apply' || $key === 'col-codes' || $key === 'col-codes-submit') {
            $luster_fanlisting_id = 0;
        } elseif ($request->query('lid') !== null) {
            $luster_fanlisting_id = max(0, (int) $request->query('lid', 1));
        }

        $q = static fn(string $k): ?string => $request->query($k);

        if ($q('per_page') !== null && $q('per_page') !== '')     { $luster_per_page            = max(1, (int) $q('per_page')); }
        if ($q('count') !== null && $q('count') !== '')           { $luster_count               = max(1, (int) $q('count')); }
        if ($q('cache_ttl') !== null && $q('cache_ttl') !== '')   { $luster_cache_ttl           = max(0, (int) $q('cache_ttl')); }
        if ($q('sort_by') !== null && $q('sort_by') !== '')       { $luster_sort_by             = (string) $q('sort_by'); }
        if ($q('show_search') !== null)                           { $luster_show_search         = $q('show_search') !== 'false'; }
        if ($q('stats_template') !== null && $q('stats_template') !== '') { $luster_stats_template = (string) $q('stats_template'); }
        if ($q('browse_by_category') !== null)                    { $luster_browse_by_category  = $q('browse_by_category') === 'true'; }
        if ($q('category_id') !== null && $q('category_id') !== '') { $luster_category_id        = max(0, (int) $q('category_id')); }
        if ($q('category') !== null && $q('category') !== '')     { $luster_category            = (string) $q('category'); }
        if ($q('status') !== null && $q('status') !== '')         { $luster_status              = (string) $q('status'); }
        if ($q('hide_dropdown') !== null)                         { $luster_hide_dropdown       = $q('hide_dropdown') === 'true'; }
        if ($q('show_list') !== null)                             { $luster_show_list           = $q('show_list') === 'true'; }
        if ($q('show_cat_switcher') !== null)                     { $luster_show_cat_switcher   = $q('show_cat_switcher') !== 'false'; }
        if ($q('exclude_pending') !== null)                       { $luster_exclude_pending     = $q('exclude_pending') !== 'false'; }
        if ($q('show_all_by_default') !== null)                   { $luster_show_all_by_default = $q('show_all_by_default') === 'true'; }
        if ($q('show_cats') !== null)                             { $luster_show_cats           = $q('show_cats') === 'true'; }
        if ($q('wishlist_type') !== null && $q('wishlist_type') !== '') { $luster_wishlist_type = (string) $q('wishlist_type'); }
        if ($q('only_with_signups') !== null)                     { $luster_kim_only_with_signups = $q('only_with_signups') !== 'false'; }

        try {
            ob_start();
            $result   = require $embedFile;
            $buffered = ob_get_clean() ?: '';
            $html     = is_string($result) ? $result : $buffered;
        } catch (\Throwable $e) {
            ob_end_clean();
            $html = '<p style="color:#c00;font-family:monospace">Error: '
                . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }

        if ($key === 'rules-gate' && trim($html) === '') {
            $html = '<p style="color:#777;font-style:italic">No rules are enabled for this listing. '
                . 'Enable rules from <strong>Admin &rarr; Join Rules</strong> to see a preview.</p>';
        }

        return Response::html($this->previewWrap($html, $appUrl));
    }

    /**
     * Renders step 1 of the Update Membership embed (the sign-in form) with an
     * inert action/token. Built synthetically rather than by requiring the real
     * embed, since the real embed reads/consumes session-gated flash and auth
     * state (lstr_flash_success_html, lstr_update_authed_{id}) that must not be
     * disturbed — or substituted in — by an admin preview.
     */
    private function previewUpdateSignInForm(int $listingId): string
    {
        try {
            $db     = $this->app->getDatabase();
            $config = $this->app->getConfig();

            $listingRepo = new ListingRepo($db, $config);
            $listing     = $listingRepo->getById($listingId);

            if ($listing === null) {
                return '<p>Listing not found.</p>';
            }

            $settingsRepo   = new ListingSettingsRepo($db);
            $wrapperTpl     = $settingsRepo->getWrapperTemplate($listingId) ?? '';
            $embedSettings  = $settingsRepo->getResolvedEmbedSettings($listingId);
            $resetPage = (string) ($embedSettings['form_url_reset'] ?? '');
            // Inert preview: '#' instead of a real reset-page URL, so "Forgot your
            // password?" cannot navigate the admin out of the preview into a real,
            // session-affecting public page.
            $lostPasswordUrl = $resetPage !== '' ? '#' : '';

            $fb = $this->app->getFormFieldBuilder();
            $descriptors = [
                ['type' => 'email',    'name' => 'email',    'id' => 'upd-email',    'label' => 'Email Address', 'required' => true, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
                ['type' => 'password', 'name' => 'password', 'id' => 'upd-password', 'label' => 'Password',       'required' => true, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'current-password']],
            ];
            $f = $fb->build($descriptors, $wrapperTpl);

            return $this->app->getView()->renderFragment('update.php', [
                'listing'           => $listing,
                'listing_title'      => $listing->title,
                'listing_url'       => $listing->url,
                'page_title'        => 'Update Membership',
                'signed_in'         => false,
                // Inert preview: '#' action + blank CSRF token mean this form can
                // never reach UpdateController::signIn() (CsrfMiddleware rejects any
                // POST with a missing/invalid token).
                'sign_in_action'    => '#',
                'csrf_token'        => '',
                'errors'            => '',
                'lost_password_url' => $lostPasswordUrl,
                'fields'            => [],
                'f'                 => $f,
                'base_url'          => $this->app->getBaseUrl(),
                'theme_path'        => $this->app->getBaseUrl() . '/assets/default/',
            ]);
        } catch (\Throwable $e) {
            return '<p style="color:#c00;font-family:monospace">Error: '
                . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }
    }

    /**
     * Renders step 2 of the Update Membership embed (the authenticated form,
     * including the delete-membership checkbox) using placeholder member data,
     * since the preview has no real signed-in fan to source values from.
     */
    private function previewUpdateSignedInForm(int $listingId): string
    {
        try {
            $db     = $this->app->getDatabase();
            $config = $this->app->getConfig();

            $listingRepo = new ListingRepo($db, $config);
            $listing     = $listingRepo->getById($listingId);

            if ($listing === null) {
                return '<p>Listing not found.</p>';
            }

            $settingsRepo = new ListingSettingsRepo($db);
            $wrapperTpl   = $settingsRepo->getWrapperTemplate($listingId) ?? '';

            $fieldRepo = new FieldRepo($db, $config);
            $fields    = $fieldRepo->getFieldsForListing($listingId);

            $member = Member::fromRow([
                'name'    => 'Usagi',
                'email'   => 'usagi@example.com',
                'url'     => 'https://example.com/usagi',
                'country' => 'Japan',
                'pending' => 0,
            ]);

            $fb         = $this->app->getFormFieldBuilder();
            $countrySel = Countries::buildSelect($member->country);

            $urlVal = htmlspecialchars($member->url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $urlRaw = '<input class="fl-input" type="url" id="upd-url" name="url" value="' . $urlVal . '" maxlength="500" placeholder="https://">'
                    . '<div class="fl-field-hint"><label><input type="checkbox" name="delete_url" value="1"> Remove my website URL</label></div>';

            $oldFields = [];
            foreach ($fields as $field) {
                $oldFields['field_' . $field->name] = 'Sample ' . $field->label;
            }

            $descriptors = [
                ['type' => 'text',     'name' => 'new_name',            'id' => 'upd-name',         'label' => 'New Name',             'required' => false, 'attrs' => ['maxlength' => '100', 'autocomplete' => 'name',         'placeholder' => $member->name]],
                ['type' => 'email',    'name' => 'new_email',           'id' => 'upd-new-email',    'label' => 'New Email',            'required' => false, 'attrs' => ['maxlength' => '255',                                    'placeholder' => $member->email]],
                ['type' => 'url',      'name' => 'url',                 'id' => 'upd-url',          'label' => 'New Website URL',      'required' => false, 'raw_input' => $urlRaw],
                ['type' => 'text',     'name' => 'country',             'id' => '',                 'label' => 'New Country',          'required' => false, 'raw_input' => $countrySel],
                ...$fb->descriptorsFromAdditionalFields($fields, $oldFields),
                ['type' => 'password', 'name' => 'new_password',        'id' => 'upd-new-pass',     'label' => 'New Password',         'required' => false, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password'], 'hint' => 'Minimum 12 characters. Leave blank to keep current.'],
                ['type' => 'password', 'name' => 'new_password_confirm', 'id' => 'upd-confirm-pass', 'label' => 'Confirm New Password', 'required' => false, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password', 'placeholder' => 'Repeat new password']],
            ];

            $f = $fb->build($descriptors, $wrapperTpl);

            return $this->app->getView()->renderFragment('update.php', [
                'listing'      => $listing,
                'listing_title' => $listing->title,
                'listing_url'  => $listing->url,
                'page_title'   => 'Update Membership',
                'signed_in'    => true,
                // Inert preview: '#' action + blank CSRF token mean this form can
                // never reach UpdateController::submit() (CsrfMiddleware rejects any
                // POST with a missing/invalid token), so it cannot update or delete
                // a real membership even if submitted.
                'sign_out_url' => '#',
                'form_action'  => '#',
                'csrf_token'   => '',
                'errors'       => '',
                'field_email'  => $member->email,
                'fields'       => $fields,
                'f'            => $f,
                'base_url'     => $this->app->getBaseUrl(),
                'theme_path'   => $this->app->getBaseUrl() . '/assets/default/',
            ]);
        } catch (\Throwable $e) {
            return '<p style="color:#c00;font-family:monospace">Error: '
                . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }
    }

    /**
     * Renders step 1 of the KIM Update embed (the sign-in form) using the
     * hardcoded public template with placeholder/inert values. Never touches
     * the real KIM auth session (lstr_kim_update_authed).
     */
    private function previewKimUpdateSignInForm(): string
    {
        try {
            $fb = $this->app->getFormFieldBuilder();
            $f  = $fb->build([
                ['type' => 'email',    'name' => 'email',    'id' => 'kim-email',    'label' => 'Email',    'required' => true,  'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
                ['type' => 'password', 'name' => 'password', 'id' => 'kim-password', 'label' => 'Password', 'required' => true,  'attrs' => ['maxlength' => '255', 'autocomplete' => 'current-password']],
            ], '');

            return $this->app->getView()->renderFragment('addons/kim/update.php', [
                'form_action'  => '#',
                'csrf_token'   => '',
                'errors'       => '',
                'success_html' => '',
                'f'            => $f,
                'form_html'    => null,
                'form_marker'  => '',
                'link_reset'   => null,
                'link_list'    => null,
                'base_url'     => $this->app->getBaseUrl(),
                'theme_path'   => $this->app->getBaseUrl() . '/assets/default/',
            ]);
        } catch (\Throwable $e) {
            return '<p style="color:#c00;font-family:monospace">Error: '
                . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }
    }

    /**
     * Renders step 2 of the KIM Update embed (the authenticated dashboard)
     * using placeholder member data and inert form actions. Never touches the
     * real KIM auth session or any KIM member records.
     */
    private function previewKimUpdateDashboard(): string
    {
        try {
            $fb = $this->app->getFormFieldBuilder();
            $f  = $fb->build([
                ['type' => 'text',     'name' => 'new_name',             'id' => 'kim-new-name',             'label' => 'New Name',             'required' => false, 'attrs' => ['maxlength' => '100'],                                                   'hint' => 'Leave blank to keep current'],
                ['type' => 'email',    'name' => 'new_email',            'id' => 'kim-new-email',            'label' => 'New Email',            'required' => false, 'attrs' => ['maxlength' => '255'],                                                   'hint' => 'Leave blank to keep current'],
                ['type' => 'url',      'name' => 'new_url',              'id' => 'kim-new-url',              'label' => 'New Website URL',      'required' => false, 'attrs' => ['maxlength' => '500', 'placeholder' => 'https://'],                       'hint' => 'Leave blank to keep current'],
                ['type' => 'password', 'name' => 'new_password',         'id' => 'kim-new-password',         'label' => 'New Password',         'required' => false, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password'], 'hint' => 'Minimum 12 characters. Leave blank to keep current.'],
                ['type' => 'password', 'name' => 'new_password_confirm', 'id' => 'kim-new-password-confirm', 'label' => 'Confirm New Password', 'required' => false, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password']],
            ], '');

            $sampleEntries = [
                ['id' => 1, 'fanlisting_id' => 1, 'listing_title' => SampleTemplateData::LISTING_TITLE_A, 'name' => 'Usagi', 'url' => 'https://example.com/usagi', 'email' => 'usagi@example.com', 'pending' => false],
                ['id' => 2, 'fanlisting_id' => 2, 'listing_title' => SampleTemplateData::LISTING_TITLE_B, 'name' => 'Misty', 'url' => '',                         'email' => 'misty@example.com', 'pending' => false],
            ];

            return $this->app->getView()->renderFragment('addons/kim/update-dashboard.php', [
                'auth_email'         => 'fan@example.com',
                'entries'            => $sampleEntries,
                'save_action'      => '#',
                'sign_out_url'     => '#',
                'csrf_token'       => '',
                'errors'           => '',
                'success_html'     => '',
                'f'                => $f,
                'save_form_marker' => '',
                'link_reset'         => null,
                'link_list'          => null,
                'base_url'           => $this->app->getBaseUrl(),
                'theme_path'         => $this->app->getBaseUrl() . '/assets/default/',
            ]);
        } catch (\Throwable $e) {
            return '<p style="color:#c00;font-family:monospace">Error: '
                . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }
    }

    /**
     * Renders a set of panels behind pill-toggle labels so the admin can
     * switch views without scrolling through a long stacked preview.
     *
     * Pure CSS — hidden radio inputs + :checked sibling selectors, so the
     * toggle works even under strict CSP that blocks inline scripts.
     *
     * @param list<array{label: string, html: string}> $panels
     */
    /**
     * Wraps a set of HTML panels behind a pure-CSS pill-toggle so the admin can
     * switch between display modes without scrolling through a tall stacked layout.
     *
     * Uses hidden radio inputs + CSS :checked sibling selectors — no JavaScript
     * required, so the toggle works under strict CSP that blocks inline scripts.
     *
     * @param list<array{label: string, html: string}> $panels
     */
    private function previewTabbedPanels(array $panels): string
    {
        $e = static fn(string $s): string =>
            htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $radios  = '';
        $pills   = '<div class="preview-pills">';
        $content = '';
        $showCss = '';
        $activeParts = [];

        foreach ($panels as $i => $panel) {
            $checked = $i === 0 ? ' checked' : '';
            $radios  .= '<input type="radio" name="preview-tab" id="ptab-' . $i . '"' . $checked . '>';
            $pills   .= '<label for="ptab-' . $i . '" class="preview-pill">' . $e($panel['label']) . '</label>';
            $content .= '<div class="preview-panel" id="ppanel-' . $i . '">' . $panel['html'] . '</div>';
            $showCss .= '#ptab-' . $i . ':checked~#ppanel-' . $i . '{display:block}';
            $activeParts[] = '#ptab-' . $i . ':checked~.preview-pills [for="ptab-' . $i . '"]';
        }
        $pills .= '</div>';

        $css = '<style>'
            . 'input[name="preview-tab"]{position:absolute;opacity:0;pointer-events:none}'
            . '.preview-pills{display:flex;justify-content:center;gap:6px;padding:12px 0 4px;flex-wrap:wrap;'
            . 'position:sticky;top:0;background:#fff;z-index:10}'
            . '.preview-pill{padding:5px 14px;border:1px solid #c0c8d8;border-radius:99px;background:#f5f6fa;'
            . 'color:#556;font:600 .78rem/1.4 sans-serif;cursor:pointer;transition:background .15s,color .15s,border-color .15s}'
            . '.preview-pill:hover{background:#e8ecf4}'
            . '.preview-panel{display:none}'
            . $showCss
            . implode(',', $activeParts) . '{background:#5b6eae;color:#fff;border-color:#5b6eae}'
            . '</style>';

        return $css . $radios . $pills . $content;
    }

    /**
     * Wraps embed preview HTML in a minimal standalone HTML document.
     *
     * Loads the public stylesheet with a cache-busting timestamp so the preview
     * reflects the current CSS without requiring a hard refresh.
     */
    private function previewWrap(string $content, string $appUrl): string
    {
        $base    = rtrim($appUrl, '/');
        $cssPath = defined('LUSTER_BASE')
            ? \LUSTER_BASE . '/public/assets/css/default/style.css'
            : '';
        $cacheBust = ($cssPath !== '' && is_file($cssPath))
            ? '?v=' . filemtime($cssPath)
            : '';
        $cssUrl = htmlspecialchars(
            $base . '/assets/css/default/style.css' . $cacheBust,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        return '<!DOCTYPE html><html lang="en"><head>'
            . '<meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<link rel="stylesheet" href="' . $cssUrl . '">'
            . '<style>body{font-family:system-ui,sans-serif;font-size:15px;line-height:1.6;'
            . 'padding:1.5rem;margin:0;background:#fff;color:#333}'
            . 'input,select,textarea,button{font-family:inherit}'
            . 'p{margin:.5rem 0}</style>'
            . '</head><body>'
            . $content
            . '</body></html>';
    }

    // -----------------------------------------------------------------------
    // GET/POST /admin/compat-test
    // -----------------------------------------------------------------------

    /**
     * Renders the compat-test page, which lets admins smoke-test legacy compatibility
     * wrappers (Enthusiast, Listing Admin, CodeSort shims) by executing a selected
     * embed type for a selected listing and displaying the raw HTML output inline.
     *
     * The form posts back to itself; on POST the selected embed file is required with
     * $luster_return=true and the output is captured for display in the result panel.
     * Any Throwable from the embed is caught and shown as an error rather than crashing.
     */
    public function compatTest(Request $request, array $params): Response
    {
        $db        = $this->app->getDatabase();
        $config    = $this->app->getConfig();
        $view      = $this->app->getView();
        $session   = $this->app->getSession();
        $baseUrl   = $this->app->getBaseUrl();
        $csrfToken = $this->app->getCSRF()->token();

        $listingRepo = new ListingRepo($db, $config);
        $listings    = $listingRepo->getAll();

        $embedTypes = ['join', 'members', 'update', 'reset', 'stats', 'affiliates'];

        $selectedListingId = (int) $request->post('listing_id', $request->query('listing_id', 0));
        $selectedType      = (string) $request->post('embed_type', $request->query('embed_type', ''));
        $output            = null;
        $error             = null;

        if ($request->method() === 'POST') {
            $this->app->getCSRF()->validate($request->post('_csrf', ''));

            if ($selectedListingId > 0 && in_array($selectedType, $embedTypes, true)) {
                $lusterBase  = defined('LUSTER_BASE') ? \LUSTER_BASE : dirname(__DIR__, 3);
                $embedFile   = $lusterBase . '/public/embed/' . $selectedType . '.php';

                if (!is_file($embedFile)) {
                    $error = 'Embed file not found: ' . htmlspecialchars($embedFile, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                } else {
                    ob_start();
                    $luster_fanlisting_id = $selectedListingId;
                    $luster_return        = true;
                    try {
                        $result   = require $embedFile;
                        $buffered = ob_get_clean();
                        $output   = is_string($result) ? $result : (is_string($buffered) ? $buffered : '');
                    } catch (\Throwable $e) {
                        ob_end_clean();
                        $error = 'Embed error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    }
                }
            }
        }

        $inner = $view->render('admin/compat-test.php', [
            'listings'            => $listings,
            'embed_types'         => $embedTypes,
            'selected_listing_id' => $selectedListingId,
            'selected_type'       => $selectedType,
            'output'              => $output,
            'error'               => $error,
            'base_url'            => $baseUrl,
            'csrf_token'          => $csrfToken,
        ]);

        return Response::html($view->render('admin/layout.php', array_merge([
            'page_title'      => 'Compat Test',
            'site_name'       => (string) $config->get('site_name', 'Luster'),
            'admin_username'  => (string) $session->get('lstr_admin_username', ''),
            'csrf_token'      => $csrfToken,
            'current_section' => 'compat-test',
            'base_url'        => $baseUrl,
            'content'         => $inner,
        ], $this->app->getAdminNavData())));
    }
}
