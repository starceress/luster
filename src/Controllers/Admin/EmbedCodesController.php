<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\Captcha;
use Luster\Core\Countries;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Models\Member;
use Luster\Repositories\FieldRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;

/**
 * Read-only reference page showing copyable PHP embed snippets, and the
 * compat wrapper smoke-test renderer.
 *
 * Routes:
 *   GET  /admin/embed-codes
 *   GET  /admin/compat-test
 *   POST /admin/compat-test
 */
final class EmbedCodesController
{
    public function __construct(private readonly Application $app) {}

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
            'updates'      => 'updates.php',
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
            'col-updates'  => 'collective-updates.php',
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
            $html = $this->previewSectionLabel('Step 1 — Sign In')
                . $this->previewUpdateSignInForm($listingId)
                . $this->previewSectionLabel('Step 2 — Update Form (Signed In)')
                . $this->previewUpdateSignedInForm($listingId);

            return Response::html($this->previewWrap($html, $appUrl));
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
        if ($q('reset_step') !== null && $q('reset_step') !== '') { $luster_reset_step          = (string) $q('reset_step'); }
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
            $embedSettings  = $settingsRepo->getEmbedSettings($listingId);
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
                'listing_name'      => $listing->title,
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
                'id'      => 0,
                'name'    => 'Jane Fan',
                'email'   => 'fan@example.com',
                'url'     => 'https://example.com',
                'country' => 'United States',
                'pending' => 0,
            ]);

            $fb         = $this->app->getFormFieldBuilder();
            // Use a dedicated captcha session slot so this preview never overwrites
            // (and invalidates) a real in-progress update form's captcha answer.
            $captchaQ   = Captcha::getQuestion('embed_preview_update');
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
                ['type' => 'password', 'name' => 'new_password',        'id' => 'upd-new-pass',     'label' => 'New Password',         'required' => false, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password', 'placeholder' => 'Leave blank to keep current']],
                ['type' => 'password', 'name' => 'new_password_confirm', 'id' => 'upd-confirm-pass', 'label' => 'Confirm New Password', 'required' => false, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password', 'placeholder' => 'Repeat new password']],
                ['type' => 'text',     'name' => 'captcha_answer',      'id' => 'upd-captcha',      'label' => 'Captcha: ' . $captchaQ, 'required' => true, 'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
            ];

            $f = $fb->build($descriptors, $wrapperTpl);

            return $this->app->getView()->renderFragment('update.php', [
                'listing'      => $listing,
                'listing_name' => $listing->title,
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
     * Renders a small labelled section divider between stacked preview states.
     * Matches ListingController::previewSectionLabel() / RulesBodyRenderer.
     */
    private function previewSectionLabel(string $label): string
    {
        return '<div style="margin:1.5rem 0 .5rem;padding:.35rem .6rem;background:#eef1f8;'
            . 'border-left:3px solid #889bd6;font:600 .75rem sans-serif;color:#445;'
            . 'text-transform:uppercase;letter-spacing:.04em">'
            . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</div>';
    }

    private function previewWrap(string $content, string $appUrl): string
    {
        $cssUrl = htmlspecialchars(
            rtrim($appUrl, '/') . '/assets/css/default/style.css',
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
