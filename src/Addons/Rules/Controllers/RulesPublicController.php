<?php

declare(strict_types=1);

namespace Luster\Addons\Rules\Controllers;

use Luster\Addons\Rules\RuleSet;
use Luster\Addons\Rules\RulesRepo;
use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\TemplateEngine;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;

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
            $returnTo = $baseUrl . '/rules/' . $listingId;
        }

        $alreadyAgreed = $this->hasAgreed($listingId, $ruleSet);

        $listing = (new ListingRepo($this->app->getDatabase(), $this->app->getConfig()))
            ->getById($listingId);

        $rawIntro    = (new ListingSettingsRepo($this->app->getDatabase()))->getTemplates($listingId)['rules_intro_template'] ?? null;
        $rulesIntroHtml = '';
        if ($rawIntro !== null && trim($rawIntro) !== '') {
            $rulesIntroHtml = TemplateEngine::render($rawIntro, escaped: [
                'listing_title'   => $listing?->title ?? '',
                'listing_url'     => $listing?->url ?? '',
                'listing_subject' => $listing?->subject ?? '',
            ]);
        }

        $html = $this->app->getView()->renderPublic('addons/rules/gate.php', [
            'listing'          => $listing,
            'listing_name'     => $listing?->title ?? '',
            'listing_url'      => $listing?->url ?? '',
            'page_title'       => $ruleSet->title,
            'rules'            => $ruleSet,
            'already_agreed'   => $alreadyAgreed,
            'form_action'      => $baseUrl . '/rules/' . $listingId . '/agree',
            'return_to'        => $returnTo,
            'csrf_token'       => $csrf,
            'rules_intro_html' => $rulesIntroHtml,
            'base_url'         => $baseUrl,
            'theme_path'       => $baseUrl . '/assets/css/default/',
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
            return Response::redirect($baseUrl . '/rules/' . $listingId);
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
     *
     * @return \Luster\Addons\Rules\RuleSet|Response
     */
    private function requireActiveRules(int $listingId): mixed
    {
        if ($listingId <= 0) {
            return Response::notFound('Rules not found.');
        }

        $ruleSet = (new RulesRepo($this->app->getDatabase()))->getForListing($listingId);

        if ($ruleSet === null || !$ruleSet->enabled || trim($ruleSet->body) === '') {
            return Response::notFound('Rules not found.');
        }

        return $ruleSet;
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
}
