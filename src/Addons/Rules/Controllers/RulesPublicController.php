<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Public controller for the rules gate page. Shows the listing's rules to
 the fan and records their agreement in the session keyed by listing ID
 and rule-set fingerprint. When the admin edits the rule body, the version
 increments and the fingerprint changes, requiring fans to re-agree.
*/

declare(strict_types=1);

namespace Luster\Addons\Rules\Controllers;

use Luster\Addons\Rules\RuleSet;
use Luster\Addons\Rules\RulesRepo;
use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\SiteSettingsRepo;

/**
 * Public rules gate.
 *
 * Routes (registered by RulesAddon::registerRoutes()):
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
    // POST /rules/{id}/agree  (CSRF validated by CsrfMiddleware)
    // -----------------------------------------------------------------------

    /**
     * Records the visitor's agreement in the session as a fingerprint string and redirects
     * back. If the "agree" checkbox value is not "1" the action is a no-op. The redirect
     * target is resolved in priority order: session-stored embed return URL, posted
     * return_to value, listing URL, and finally the app base URL.
     */
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
            $listingRepo   = new \Luster\Repositories\ListingRepo($this->app->getDatabase(), $this->app->getConfig());
            $listing       = $listingRepo->getById($listingId);
            $fallback      = $listing !== null ? $listing->url : ($this->app->getConfig()->get('app_url', $baseUrl));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallback);
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

    /**
     * Returns true when $url is either a valid absolute HTTP or HTTPS address
     * with a non-empty host (needed for e.g. a listing's own registered URL,
     * which can legitimately be any external domain — this check can't be
     * tightened to a specific expected host without breaking that case), or
     * a root-relative path (starts with exactly one "/", not "//" — which is
     * protocol-relative and can point at a different host). Used to validate
     * post-agreement redirect targets before following them.
     *
     * The root-relative case exists specifically for lstr_embed_return_to:
     * rules-gate.php resolves a relative $luster_redirect_url override (e.g.
     * "join.php" from samples/listing/rules.php) to a path only, never a full
     * scheme+host URL, deliberately not trusting $_SERVER['HTTP_HOST'] to
     * build one — see that file's own comment for why. Accepting a
     * root-relative path here is what lets that value pass this check at
     * all; without it, every relative-override redirect would fall through
     * to the listing/base-URL fallback below instead.
     */
    private function isValidReturnUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return true;
        }

        $parsed = parse_url($url);
        if ($parsed === false) {
            return false;
        }
        $scheme = strtolower($parsed['scheme'] ?? '');
        $host   = $parsed['host'] ?? '';
        return in_array($scheme, ['http', 'https'], true) && $host !== '';
    }

}
