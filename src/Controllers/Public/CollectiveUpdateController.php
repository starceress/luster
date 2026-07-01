<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Handles POST submissions for the collective-update embed. Covers two
 actions: requesting a verification link (request) and applying the
 actual changes after the link is clicked (submit). The GET step that
 displays the update form is handled directly by the embed file.
*/

declare(strict_types=1);

namespace Luster\Controllers\Public;

use Luster\Core\Application;
use Luster\Core\Mailer;
use Luster\Core\RateLimit;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Models\Listing;
use Luster\Models\Member;
use Luster\Repositories\CollectiveUpdateTokenRepo;
use Luster\Repositories\ListingLifecycleRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\MemberRepo;
use Luster\Repositories\PendingUpdateRepo;

/**
 * Handles collective-level cross-listing membership updates.
 *
 * The flow is email-ownership verification via a single-use token:
 *  1. Member submits their email.
 *  2. If they have memberships, a verification link is emailed to them.
 *  3. Clicking the link opens the update form (rendered by the embed file).
 *  4. Submitting the form calls submit(), which applies the changes.
 */
final class CollectiveUpdateController
{
    public function __construct(private readonly Application $app) {}

    /**
     * POST handler for the initial email-submission step.
     * Finds memberships for the submitted email, issues a verification token,
     * and sends a link. Always responds with the same neutral flash regardless
     * of whether the email was found, to prevent email enumeration.
     */
    public function request(Request $request): Response
    {
        $session       = $this->app->getSession();
        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');
        $rateLimit     = $this->app->getRateLimit();
        $ip            = $request->ip();

        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_COLLECTIVE_UPDATE_REQ)) {
            $session->set('lstr_flash_error', 'Too many attempts. Please try again later.');
            return Response::redirect($embedReturnTo);
        }

        // Honeypot — silent accept so bots see no difference
        if ((string) $request->post('website_confirm', '') !== '') {
            $session->set('lstr_flash_success_html', '<p class="fl-success">If your email is registered for one or more fanlistings here, you\'ll receive a verification link shortly.</p>');
            return Response::redirect($embedReturnTo);
        }

        // Count every real submission against the rate limit, not just invalid ones
        $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_COLLECTIVE_UPDATE_REQ);

        $email = trim((string) $request->post('email', ''));

        $v = new Validator(['email' => $email]);
        $v->field('email')->required()->email()->maxLength(255);

        if ($v->fails()) {
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()));
            $session->set('lstr_flash_old_email', $email);
            return Response::redirect($embedReturnTo);
        }

        $memberships = self::findMembershipsForEmail($email, $this->app);

        if ($memberships !== []) {
            $tokenRepo = new CollectiveUpdateTokenRepo($this->app->getDatabase());
            $rawToken  = $tokenRepo->create($email);

            $sep       = str_contains($embedReturnTo, '?') ? '&' : '?';
            $verifyUrl = $embedReturnTo . $sep . '_luster_cu_token=' . urlencode($rawToken);

            try {
                $cfg      = $this->app->getConfig();
                $siteName = (string) $cfg->get('site_name', 'Luster');
                $fromName = (string) $cfg->get('owner_name', $siteName);
                $subject  = 'Update your memberships at ' . $siteName;

                $listingList = implode("\n", array_map(
                    static fn(array $m) => '- ' . $m['listing']->title,
                    $memberships
                ));

                $body = "Hi,\n\n"
                    . "You (or someone using this email address) requested a link to update your memberships at {$siteName}.\n\n"
                    . "Your email is registered for:\n{$listingList}\n\n"
                    . "Click the link below to update your membership details. This link expires in 60 minutes and can only be used once.\n\n"
                    . $verifyUrl . "\n\n"
                    . "If you did not request this, you can safely ignore this email.\n\n"
                    . "-- {$fromName}";

                $mailer = new Mailer($cfg);
                $mailer->send($email, '', $subject, $body);
            } catch (\Throwable $e) {
                error_log('[Luster] Collective update email failed: ' . $e->getMessage());
            }
        }

        // Always the same neutral message to prevent enumeration
        $session->set('lstr_flash_success_html', '<p class="fl-success">If your email is registered for one or more fanlistings here, you\'ll receive a verification link shortly.</p>');
        return Response::redirect($embedReturnTo);
    }

    /**
     * POST handler for the update-submission step.
     * Validates the token, applies updates to each selected listing,
     * and consumes the token to prevent replay.
     */
    public function submit(Request $request): Response
    {
        $session       = $this->app->getSession();
        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');
        $rateLimit     = $this->app->getRateLimit();
        $ip            = $request->ip();

        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_UPDATE)) {
            $session->set('lstr_flash_error', 'Too many attempts. Please try again later.');
            return Response::redirect($embedReturnTo);
        }

        $rawToken = (string) $request->post('_cu_token', '');

        if ($rawToken === '') {
            $session->set('lstr_flash_error', 'Missing update token. Please use the link from your email.');
            return Response::redirect($embedReturnTo);
        }

        $tokenRepo = new CollectiveUpdateTokenRepo($this->app->getDatabase());
        $email     = $tokenRepo->verifyAndConsume($rawToken);

        if ($email === null) {
            $session->set('lstr_flash_error', 'That update link is invalid or has expired. Please request a new one.');
            return Response::redirect($embedReturnTo);
        }

        $memberships = self::findMembershipsForEmail($email, $this->app);

        if ($memberships === []) {
            $session->set('lstr_flash_error', 'No active memberships were found for your email address.');
            return Response::redirect($embedReturnTo);
        }

        // Build a map of valid listing IDs to their membership entry
        $validMap = [];
        foreach ($memberships as $m) {
            $validMap[$m['listing']->id] = $m;
        }

        // Submitted listing IDs must be a subset of valid memberships
        $rawIds      = (array) $request->post('listing_ids', []);
        $selectedIds = array_unique(array_filter(array_map('intval', $rawIds)));
        $selectedIds = array_values(array_filter($selectedIds, fn(int $id) => isset($validMap[$id])));

        $newName    = trim((string) $request->post('name', ''));
        $newUrl     = trim((string) $request->post('url', ''));
        $deleteUrl  = ((string) $request->post('delete_url', '') === '1');
        $newCountry = trim((string) $request->post('country', ''));

        $v = new Validator(['name' => $newName, 'url' => $newUrl, 'country' => $newCountry]);
        if ($newName !== '') {
            $v->field('name')->maxLength(100);
        }
        if ($newUrl !== '' && !$deleteUrl) {
            $v->field('url')->httpUrl()->maxLength(500);
        }
        if ($newCountry !== '') {
            $v->field('country')->maxLength(100);
        }

        if ($v->fails()) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_UPDATE);
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()));
            return Response::redirect($embedReturnTo);
        }

        if ($selectedIds === []) {
            $session->set('lstr_flash_error', 'Please select at least one fanlisting to update.');
            return Response::redirect($embedReturnTo);
        }

        $memberRepo    = new MemberRepo($this->app->getDatabase(), $this->app->getConfig());
        $settingsRepo  = new ListingSettingsRepo($this->app->getDatabase());
        $lifecycleRepo = new ListingLifecycleRepo($this->app->getDatabase());
        $results       = ['updated' => [], 'queued' => [], 'skipped' => []];

        foreach ($selectedIds as $listingId) {
            $entry   = $validMap[$listingId];
            $listing = $entry['listing'];
            $member  = $entry['member'];

            // Skip if archived since the memberships were loaded
            if ($lifecycleRepo->getByListingId($listingId) !== null) {
                $results['skipped'][] = $listing->title;
                continue;
            }

            $updateData = [];
            if ($newName !== '' && $newName !== $member->name) {
                $updateData['name'] = $newName;
            }
            if ($deleteUrl) {
                $updateData['url']     = '';
                $updateData['showurl'] = 0;
            } elseif ($newUrl !== '' && $newUrl !== $member->url) {
                $updateData['url']     = $newUrl;
                $updateData['showurl'] = 1;
            }
            if ($newCountry !== '' && $newCountry !== $member->country) {
                $updateData['country'] = $newCountry;
            }

            if ($updateData === []) {
                $results['skipped'][] = $listing->title;
                continue;
            }

            if ($settingsRepo->getQueueUpdates($listingId)) {
                (new PendingUpdateRepo($this->app->getDatabase()))->insert($listingId, $email, $updateData);
                $results['queued'][] = $listing->title;
            } else {
                $memberRepo->update($listing->dbprefix, $email, $updateData);
                $results['updated'][] = $listing->title;
            }
        }

        $parts = [];
        if ($results['updated'] !== []) {
            $parts[] = 'Updated: ' . implode(', ', array_map('htmlspecialchars', $results['updated']));
        }
        if ($results['queued'] !== []) {
            $parts[] = 'Pending approval: ' . implode(', ', array_map('htmlspecialchars', $results['queued']));
        }
        if ($results['skipped'] !== [] && ($results['updated'] !== [] || $results['queued'] !== [])) {
            $parts[] = 'No changes: ' . implode(', ', array_map('htmlspecialchars', $results['skipped']));
        }

        if ($parts === []) {
            $session->set('lstr_flash_success_html', '<p class="fl-success">No changes were needed — your membership details were already up to date.</p>');
        } else {
            $session->set('lstr_flash_success_html', '<p class="fl-success">Done! ' . implode('. ', $parts) . '.</p>');
        }

        $session->remove('lstr_embed_return_to');
        return Response::redirect($embedReturnTo);
    }

    /**
     * Finds all active (non-archived) listings where the given email has an
     * approved membership. Returns an array of ['listing' => Listing, 'member' => Member].
     *
     * @return array<int, array{listing: Listing, member: Member}>
     */
    public static function findMembershipsForEmail(string $email, Application $app): array
    {
        $listingRepo   = new ListingRepo($app->getDatabase(), $app->getConfig());
        $memberRepo    = new MemberRepo($app->getDatabase(), $app->getConfig());
        $lifecycleRepo = new ListingLifecycleRepo($app->getDatabase());

        $listings = $listingRepo->getAll();

        if ($listings === []) {
            return [];
        }

        $lifecycleMap = $lifecycleRepo->getMapForListings(
            array_map(static fn(Listing $l) => $l->id, $listings)
        );

        $found = [];
        foreach ($listings as $listing) {
            // Skip archived listings
            if (isset($lifecycleMap[$listing->id])) {
                continue;
            }

            $member = $memberRepo->getByEmail($listing->dbprefix, $email);
            if ($member !== null && $member->approved) {
                $found[] = ['listing' => $listing, 'member' => $member];
            }
        }

        return $found;
    }
}
