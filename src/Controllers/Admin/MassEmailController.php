<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\Mailer;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\MemberRepo;

/**
 * Mass email — send an announcement to approved members of one or all listings.
 *
 * GET  /admin/mass-email          → compose()  — compose form
 * POST /admin/mass-email/preview  → preview()  — validate + show recipient count
 * POST /admin/mass-email/send     → send()     — perform the send loop + results
 *
 * Listing ID = 0 means "all listings" (recipients are deduplicated by email).
 *
 * Supported body tokens:
 *   {name}         → member's name
 *   {email}        → member's email address
 *   {listing_name} → name of the listing they are a member of (or app name for all-listing sends)
 *   {site_name}    → site/collective name from config
 */
final class MassEmailController
{
    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /admin/mass-email
    // -----------------------------------------------------------------------

    public function compose(Request $request, array $params): Response
    {
        $listings = (new ListingRepo($this->app->getDatabase(), $this->app->getConfig()))->getAll();

        return $this->layout('mass-email/compose.php', [
            'listings' => $listings,
            'error'    => '',
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/mass-email/preview
    // -----------------------------------------------------------------------

    public function preview(Request $request, array $params): Response
    {
        $listingId = (int) $request->post('listing_id', '0');
        $subject   = trim((string) $request->post('subject', ''));
        $body      = trim((string) $request->post('body',    ''));

        $v = new Validator(['subject' => $subject, 'body' => $body]);
        $v->field('subject')->required()->maxLength(255);
        $v->field('body')->required()->maxLength(50000);

        if ($v->fails()) {
            $listings = (new ListingRepo($this->app->getDatabase(), $this->app->getConfig()))->getAll();
            return $this->layout('mass-email/compose.php', [
                'listings'   => $listings,
                'error'      => implode(' ', $v->allErrors()),
                'old_listing_id' => $listingId,
                'old_subject'    => $subject,
                'old_body'       => $body,
            ]);
        }

        [$recipients, $listingName] = $this->collectRecipients($listingId);

        $sample = array_slice(array_keys($recipients), 0, 5, true);

        return $this->layout('mass-email/preview.php', [
            'listing_id'   => $listingId,
            'listing_name' => $listingName,
            'subject'      => $subject,
            'body'         => $body,
            'total'        => count($recipients),
            'sample'       => $sample,
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/mass-email/send
    // -----------------------------------------------------------------------

    public function send(Request $request, array $params): Response
    {
        $listingId = (int) $request->post('listing_id', '0');
        $subject   = trim((string) $request->post('subject', ''));
        $body      = trim((string) $request->post('body',    ''));

        // Re-validate — never trust hidden fields without checking
        if ($subject === '' || $body === '') {
            return Response::redirect($this->app->getBaseUrl() . '/admin/mass-email');
        }

        [$recipients, $listingName] = $this->collectRecipients($listingId);

        $siteName = (string) $this->app->getConfig()->get('site_name', 'Luster');
        $mailer   = new Mailer($this->app->getConfig());

        // Allow long-running send without hitting the default 30 s limit
        set_time_limit(300);

        $sent     = 0;
        $failed   = [];

        foreach ($recipients as $email => $name) {
            $personalBody    = $this->applyTokens($body,    $name, $email, $listingName, $siteName);
            $personalSubject = $this->applyTokens($subject, $name, $email, $listingName, $siteName);

            try {
                $mailer->send($email, $name, $personalSubject, $personalBody);
                $sent++;
            } catch (\Throwable $e) {
                $failed[] = $email;
                error_log('[Luster] Mass email failed for ' . $email . ': ' . $e->getMessage());
            }
        }

        $adminId  = (int) $this->app->getSession()->get('lstr_admin_id');
        $scope    = $listingId === 0 ? 'all listings' : "listing #{$listingId} ({$listingName})";
        (new ActivityRepo($this->app->getDatabase()))->log(
            $adminId,
            'mass_email.send',
            "Sent mass email to {$sent} member(s) across {$scope}. Subject: {$subject}",
        );

        return $this->layout('mass-email/result.php', [
            'listing_name' => $listingName,
            'subject'      => $subject,
            'sent'         => $sent,
            'total'        => count($recipients),
            'failed'       => $failed,
        ]);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Collects approved recipients for the given listing ID.
     *
     * listing_id = 0  → all listings, deduplicated by email (first listing wins on name)
     * listing_id > 0  → specific listing only
     *
     * @return array{array<string,string>, string}  [email => name map, listing label]
     */
    private function collectRecipients(int $listingId): array
    {
        $db          = $this->app->getDatabase();
        $config      = $this->app->getConfig();
        $listingRepo = new ListingRepo($db, $config);
        $memberRepo  = new MemberRepo($db, $config);
        $siteName    = (string) $config->get('site_name', 'Luster');

        if ($listingId > 0) {
            $listing = $listingRepo->getById($listingId);
            if ($listing === null || $listing->dbprefix === '') {
                return [[], $siteName];
            }

            $recipients = [];
            foreach ($memberRepo->getApproved($listing->dbprefix) as $member) {
                $recipients[$member->email] = $member->name;
            }

            return [$recipients, $listing->name];
        }

        // All listings — deduplicate by email
        $recipients = [];
        foreach ($listingRepo->getAll() as $listing) {
            if ($listing->dbprefix === '') {
                continue;
            }
            foreach ($memberRepo->getApproved($listing->dbprefix) as $member) {
                if (!isset($recipients[$member->email])) {
                    $recipients[$member->email] = $member->name;
                }
            }
        }

        return [$recipients, $siteName];
    }

    /**
     * Replaces supported tokens in a string with per-recipient values.
     *
     * Tokens: {name}, {email}, {listing_name}, {site_name}
     */
    private function applyTokens(
        string $text,
        string $name,
        string $email,
        string $listingName,
        string $siteName,
    ): string {
        return str_replace(
            ['{name}', '{email}', '{listing_name}', '{site_name}'],
            [$name,    $email,    $listingName,      $siteName],
            $text,
        );
    }

    private function layout(string $template, array $data): Response
    {
        $session = $this->app->getSession();

        $data['page_title']      = 'Mass Email';
        $data['current_section'] = 'mass-email';
        $data['site_name']       = (string) $this->app->getConfig()->get('site_name', 'Luster');
        $data['admin_username']  = (string) $session->get('lstr_admin_username', '');
        $data['csrf_token']      = $this->app->getCSRF()->token();
        $data['base_url']        = $this->app->getBaseUrl();

        ob_start();
        extract($data);
        require \LUSTER_BASE . '/templates/admin/' . $template;
        $content = ob_get_clean();

        ob_start();
        extract(['content' => $content] + $data);
        require \LUSTER_BASE . '/templates/admin/layout.php';
        return Response::html((string) ob_get_clean());
    }
}
