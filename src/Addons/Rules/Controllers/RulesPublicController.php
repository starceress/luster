<?php

declare(strict_types=1);

namespace Luster\Addons\Rules\Controllers;

use Luster\Addons\Rules\RuleSet;
use Luster\Addons\Rules\RulesRepo;
use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\View;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\RulesBodyRenderer;

/**
 * Public rules gate.
 *
 * Routes (registered by RulesAddon::registerRoutes()):
 *   GET  /rules/{id}        gate()   — show the rules (full-page standalone view)
 *   POST /rules/{id}/agree  agree()  — record agreement in session, redirect back
 *
 * Agreement is tracked in the session as:
 *   lstr_rules_agreed_{listingId} = "{row_id}:{version}"  (fingerprint string)
 *
 * When the admin updates the rule body, the version increments and any stored
 * session value will no longer match, requiring the fan to re-agree.
 */
final class RulesPublicController
{
    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /rules/{id}
    // -----------------------------------------------------------------------

    public function gate(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);
        $ruleSet   = $this->requireActiveRules($listingId);

        if ($ruleSet instanceof Response) {
            return $ruleSet;
        }

        $session    = $this->app->getSession();
        $baseUrl    = $this->app->getBaseUrl();
        $csrf       = $this->app->getCSRF()->token();
        $returnTo   = (string) $session->get('lstr_embed_return_to', '');
        if ($returnTo === '') {
            // Standalone (non-embed) visits have no captured host-page URL —
            // default to the join form so agreeing doesn't redirect back to
            // this same gate (which would then show "already agreed").
            $returnTo = $baseUrl . '/join/' . $listingId;
        }

        $alreadyAgreed = $this->hasAgreed($listingId, $ruleSet);

        $listing = (new ListingRepo($this->app->getDatabase(), $this->app->getConfig()))
            ->getById($listingId);

        [$gateCheckboxHtml, $gateButtonHtml] = $this->buildGateTokens($ruleSet);

        $rulesBodyHtml = RulesBodyRenderer::render(
            $ruleSet->body,
            [
                'subject'       => $listing?->subject ?? '',
                'listing_title' => $listing?->title ?? '',
                'listing_url'   => $listing?->url ?? '',
                'join_url'      => $baseUrl . '/join/' . $listingId,
                'update_url'    => $baseUrl . '/update/' . $listingId,
            ],
            [
                'gate_checkbox' => $gateCheckboxHtml,
                'gate_button'   => $gateButtonHtml,
            ]
        );

        if (!RulesBodyRenderer::hasGateToken($ruleSet->body)) {
            $rulesBodyHtml .= "\n" . $gateCheckboxHtml . "\n" . $gateButtonHtml;
        }

        $html = $this->app->getView()->renderPublic('addons/rules/gate.php', [
            'listing'         => $listing,
            'listing_name'    => $listing?->title ?? '',
            'listing_url'     => $listing?->url ?? '',
            'page_title'      => 'Join Rules',
            'rules'           => $ruleSet,
            'already_agreed'  => $alreadyAgreed,
            'form_action'     => $baseUrl . '/rules/' . $listingId . '/agree',
            'return_to'       => $returnTo,
            'csrf_token'      => $csrf,
            'rules_body_html' => $rulesBodyHtml,
            'base_url'        => $baseUrl,
            'theme_path'      => $baseUrl . '/assets/css/default/',
        ]);

        return Response::html($html);
    }

    // -----------------------------------------------------------------------
    // POST /rules/{id}/agree  (CSRF validated by CsrfMiddleware)
    // -----------------------------------------------------------------------

    public function agree(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);
        $ruleSet   = $this->requireActiveRules($listingId);

        if ($ruleSet instanceof Response) {
            return $ruleSet;
        }

        $session  = $this->app->getSession();
        $baseUrl  = $this->app->getBaseUrl();

        // Require explicit checkbox confirmation — browser required= is client-only
        if ($request->post('agree', '') !== '1') {
            $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $baseUrl . '/rules/' . $listingId);
        }

        // Record agreement as a fingerprint (row ID + version) so that a
        // delete→recreate cycle never reuses a prior session agreement.
        $session->set('lstr_rules_agreed_' . $listingId, $ruleSet->fingerprint());

        // Resolve where to redirect after agreement — validate each candidate
        // so only proper http/https URLs with a real host are accepted.
        $returnTo = (string) $session->get('lstr_embed_return_to', '');
        if (!$this->isValidReturnUrl($returnTo)) {
            $returnTo = trim((string) $request->post('return_to', ''));
        }
        if (!$this->isValidReturnUrl($returnTo)) {
            $listing    = (new ListingRepo($this->app->getDatabase(), $this->app->getConfig()))
                ->getById($listingId);
            $listingUrl = $listing?->url ?? '';
            $returnTo   = $this->isValidReturnUrl($listingUrl) ? $listingUrl : $baseUrl;
        }

        $session->remove('lstr_embed_return_to');

        return Response::redirect($returnTo);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Returns the active RuleSet for the listing, or a 404 Response if none.
     * Tries per-listing rules first, then falls back to the global template.
     *
     * @return \Luster\Addons\Rules\RuleSet|Response
     */
    private function requireActiveRules(int $listingId): mixed
    {
        $ruleSet = $this->resolveActiveRules($listingId);
        return $ruleSet ?? Response::notFound('Rules not found.');
    }

    /**
     * Resolves the active RuleSet: per-listing first, then global fallback.
     * Returns null when no active rules exist anywhere.
     */
    private function resolveActiveRules(int $listingId): ?RuleSet
    {
        if ($listingId <= 0) {
            return null;
        }

        $ruleSet = (new RulesRepo($this->app->getDatabase()))->getForListing($listingId);
        if ($ruleSet !== null && $ruleSet->enabled && trim($ruleSet->body) !== '') {
            return $ruleSet;
        }

        // Global template fallback — same logic as the embed
        try {
            $siteRepo   = new SiteSettingsRepo($this->app->getDatabase());
            $globalBody    = trim((string) ($siteRepo->get('rules_global_body') ?? ''));
            $globalEnabled = ($siteRepo->get('rules_global_gate_enabled') ?? '0') === '1';
            if ($globalBody === '' || !$globalEnabled) {
                return null;
            }
            return new RuleSet(
                id:           0,
                fanlistingId: $listingId,
                body:         $globalBody,
                enabled:      true,
                version:      (int) ($siteRepo->get('rules_global_version') ?? 1),
                updatedAt:    '',
                gateType:     (string) ($siteRepo->get('rules_global_gate_type')  ?? 'checkbox'),
                gateLabel:    (string) ($siteRepo->get('rules_global_gate_label') ?? ''),
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function hasAgreed(int $listingId, RuleSet $ruleSet): bool
    {
        $stored = (string) $this->app->getSession()->get('lstr_rules_agreed_' . $listingId, '');
        return $stored === $ruleSet->fingerprint();
    }

    private function isValidReturnUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        $parsed = parse_url($url);
        if ($parsed === false) {
            return false;
        }
        $scheme = strtolower($parsed['scheme'] ?? '');
        $host   = $parsed['host'] ?? '';
        return in_array($scheme, ['http', 'https'], true) && $host !== '';
    }

    /**
     * Returns [checkboxHtml, buttonHtml] gate token values for the given rule set.
     *
     * @return array{string, string}
     */
    private function buildGateTokens(RuleSet $ruleSet): array
    {
        $label = View::e($ruleSet->effectiveGateLabel());

        if ($ruleSet->gateType === 'button') {
            return [
                '',
                '<button type="submit" class="fl-submit fl-rules-gate-btn" name="agree" value="1">'
                    . $label . '</button>',
            ];
        }

        return [
            '<input type="checkbox" class="fl-rules-agree-check" name="agree" value="1" required>',
            '<button type="submit" class="fl-submit fl-rules-gate-btn">' . $label . '</button>',
        ];
    }
}
