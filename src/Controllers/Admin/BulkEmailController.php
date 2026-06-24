<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Sends bulk announcements to approved members of one or all listings. Handles
 the preview step (validation and recipient count) and the send step (per-recipient
 token substitution and delivery loop). Also manages saved email templates (create,
 update, delete) that can be loaded into the compose form. When sending to all
 listings, recipients are deduplicated by email address.
*/

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Mailer;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\UrlGuard;
use Luster\Core\Validator;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\MemberRepo;
use Luster\Repositories\SavedEmailRepo;

/**
 * Handles bulk announcement emails and the saved email template library.
 *
 * The preview action validates the compose form and shows a recipient count
 * and sample list before the email is sent. The send action performs the
 * actual per-recipient loop with token substitution and logs the delivery.
 * When sending to all listings, recipients are deduplicated by email address
 * so a member of multiple listings only receives one copy. The saved-template
 * actions (save, update, delete) manage the reusable template library that
 * can be loaded into any compose form.
 */
final class BulkEmailController extends AdminController
{

    // -----------------------------------------------------------------------
    // POST /admin/emails/preview
    // -----------------------------------------------------------------------

    /**
     * Validates the bulk-email compose form and renders a pre-send confirmation page.
     *
     * Collects recipients for the selected scope (one listing or all listings),
     * shows the total count, and displays up to five sample recipient emails so the
     * admin can verify the audience before committing to send.
     */
    public function preview(Request $request, array $params): Response
    {
        $listingId = (int) $request->post('listing_id', '0');
        $subject   = trim((string) $request->post('subject', ''));
        $body      = trim((string) $request->post('body',    ''));

        $v = new Validator(['subject' => $subject, 'body' => $body]);
        $v->field('subject')->required()->maxLength(255);
        $v->field('body')->required()->maxLength(50000);

        if ($v->fails()) {
            $session = $this->app->getSession();
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()));
            $session->set('lstr_flash_old', [
                'listing_id' => $listingId,
                'subject'    => $subject,
                'body'       => $body,
            ]);
            return Response::redirect($this->app->getBaseUrl() . '/admin/emails?tab=bulk');
        }

        [$recipients, $listingName] = $this->collectRecipients($listingId);

        $sample = array_slice(array_keys($recipients), 0, 5, true);

        return $this->adminLayout('admin/emails/preview.php', 'Emails', 'emails', [
            'listing_id'   => $listingId,
            'listing_title' => $listingName,
            'subject'      => $subject,
            'body'         => $body,
            'total'        => count($recipients),
            'sample'       => $sample,
        ], '', [
            $this->crumb('Emails', $this->adminUrl('/admin/emails')),
            $this->crumb('Bulk Email'),
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/emails/send
    // -----------------------------------------------------------------------

    /**
     * Sends the bulk email to all recipients in scope, one delivery per recipient.
     *
     * Re-validates the hidden subject/body fields, re-collects recipients, applies
     * per-recipient token substitution ({fan_name}, {fan_email}, etc.), and delivers
     * via Mailer. Increases the time limit to 5 minutes for large lists. Logs a
     * summary of sent and failed addresses to the activity log, then renders the
     * result page.
     */
    public function send(Request $request, array $params): Response
    {
        $listingId = (int) $request->post('listing_id', '0');
        $subject   = trim((string) $request->post('subject', ''));
        $body      = trim((string) $request->post('body',    ''));

        // Re-validate — never trust hidden fields without checking
        if ($subject === '' || $body === '') {
            return Response::redirect($this->app->getBaseUrl() . '/admin/emails?tab=bulk');
        }

        [$recipients, $listingName] = $this->collectRecipients($listingId);

        $config     = $this->app->getConfig();
        $siteName   = (string) $config->get('site_name',    'Luster');
        $ownerName  = (string) $config->get('owner_name',  '');
        $ownerEmail = (string) $config->get('owner_email', '');
        $mailer     = new Mailer($config);

        // Allow long-running send without hitting the default 30 s limit
        set_time_limit(300);

        $sent   = 0;
        $failed = [];

        foreach ($recipients as $email => $recipient) {
            $personalBody    = $this->applyTokens($body,    $recipient, $siteName, $ownerName, $ownerEmail);
            $personalSubject = $this->applyTokens($subject, $recipient, $siteName, $ownerName, $ownerEmail);

            try {
                $mailer->send($email, $recipient['name'], $personalSubject, $personalBody);
                $sent++;
            } catch (\Throwable $e) {
                $failed[] = $email;
                error_log('[Luster] Bulk email failed for ' . $email . ': ' . $e->getMessage());
            }
        }

        $adminId  = (int) $this->app->getSession()->get('lstr_admin_id');
        $scope    = $listingId === 0 ? 'all listings' : "listing #{$listingId} ({$listingName})";
        (new ActivityRepo($this->app->getDatabase()))->log(
            $adminId,
            'bulk_email.send',
            "Sent bulk email to {$sent} member(s) across {$scope}. Subject: {$subject}",
            $request->ip(),
        );

        return $this->adminLayout('admin/emails/result.php', 'Emails', 'emails', [
            'listing_title' => $listingName,
            'subject'      => $subject,
            'sent'         => $sent,
            'total'        => count($recipients),
            'failed'       => $failed,
        ], '', [
            $this->crumb('Emails', $this->adminUrl('/admin/emails')),
            $this->crumb('Bulk Email'),
        ]);
    }

    // -----------------------------------------------------------------------
    // GET /admin/new-email-template — redirect to unified page
    // -----------------------------------------------------------------------

    /**
     * Redirects the legacy /admin/new-email-template URL to the Saved Templates tab
     * on the unified Emails page.
     */
    public function savedEmailsRedirect(Request $request, array $params): Response
    {
        return Response::redirect($this->app->getBaseUrl() . '/admin/emails?tab=custom');
    }

    // -----------------------------------------------------------------------
    // POST /admin/new-email-template/save
    // -----------------------------------------------------------------------

    /**
     * Creates a new saved email template from the compose form.
     *
     * Validates that name and body are non-empty. If the POST includes aff_ids
     * (selected-affiliate context), those IDs are stashed in the session so the
     * compose form reloads correctly after save. The referer header is honoured
     * for the redirect when it is a same-origin URL.
     */
    public function saveTemplate(Request $request, array $params): Response
    {
        $name    = trim((string) $request->post('tpl_name', ''));
        $subject = trim((string) $request->post('tpl_subject', ''));
        $body    = trim((string) $request->post('tpl_body', ''));

        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $referer = trim((string) $request->post('referer', ''));
        $redirect = ($referer !== '' && UrlGuard::isSameOriginUrl($referer, $baseUrl))
            ? $referer
            : $baseUrl . '/admin/emails?tab=custom';

        // Restore selected-recipient context so selected-mode composers reload correctly
        $affIds = array_values(array_filter(
            array_map('intval', (array) $request->post('aff_ids', [])),
            fn(int $id) => $id > 0
        ));
        if (!empty($affIds)) {
            $session->set('lstr_aff_selected_ids', $affIds);
        }

        if ($name === '' || $body === '') {
            $session->set('lstr_flash_error', 'Template name and body are required.');
            return Response::redirect($redirect);
        }

        (new SavedEmailRepo($this->app->getDatabase()))->create($name, $subject, $body);

        $session->set('lstr_flash_success', 'Template "' . $name . '" saved.');
        return Response::redirect($redirect);
    }

    // -----------------------------------------------------------------------
    // POST /admin/new-email-template/{id}/update
    // -----------------------------------------------------------------------

    /**
     * Updates an existing saved email template's name, subject, and body.
     *
     * Returns a flash error if the template does not exist or the required
     * fields (name, body) are blank.
     */
    public function updateTemplate(Request $request, array $params): Response
    {
        $id      = (int) ($params['id'] ?? 0);
        $name    = trim((string) $request->post('tpl_name', ''));
        $subject = trim((string) $request->post('tpl_subject', ''));
        $body    = trim((string) $request->post('tpl_body', ''));

        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        if ($name === '' || $body === '') {
            $session->set('lstr_flash_error', 'Template name and body are required.');
            return Response::redirect($baseUrl . '/admin/emails?tab=custom');
        }

        $repo = new SavedEmailRepo($this->app->getDatabase());
        if ($repo->getById($id) === null) {
            $session->set('lstr_flash_error', 'Template not found.');
            return Response::redirect($baseUrl . '/admin/emails?tab=custom');
        }

        $repo->update($id, $name, $subject, $body);

        $session->set('lstr_flash_success', 'Template "' . $name . '" updated.');
        return Response::redirect($baseUrl . '/admin/emails?tab=custom');
    }

    // -----------------------------------------------------------------------
    // POST /admin/new-email-template/{id}/delete
    // -----------------------------------------------------------------------

    /**
     * Deletes a saved email template. The delete is unconditional — no confirmation
     * page is shown; the admin's POST is treated as the confirmation.
     */
    public function deleteTemplate(Request $request, array $params): Response
    {
        $id      = (int) ($params['id'] ?? 0);
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        (new SavedEmailRepo($this->app->getDatabase()))->delete($id);

        $session->set('lstr_flash_success', 'Template deleted.');
        return Response::redirect($baseUrl . '/admin/emails?tab=custom');
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
                $recipients[$member->email] = [
                    'name'             => $member->name,
                    'email'            => $member->email,
                    'url'              => $member->url,
                    'country'          => $member->country,
                    'listing_title'     => $listing->title,
                    'listing_subject'  => $listing->subject,
                    'listing_url'      => $listing->url,
                ];
            }

            return [$recipients, $listing->title];
        }

        // All listings — deduplicate by email (first listing wins)
        $recipients = [];
        foreach ($listingRepo->getAll() as $listing) {
            if ($listing->dbprefix === '') {
                continue;
            }
            foreach ($memberRepo->getApproved($listing->dbprefix) as $member) {
                if (!isset($recipients[$member->email])) {
                    $recipients[$member->email] = [
                        'name'             => $member->name,
                        'email'            => $member->email,
                        'url'              => $member->url,
                        'country'          => $member->country,
                        'listing_title'     => $listing->title,
                        'listing_subject'  => $listing->subject,
                        'listing_url'      => $listing->url,
                    ];
                }
            }
        }

        return [$recipients, $siteName];
    }

    /**
     * Replaces all supported tokens in a string with per-recipient values.
     *
     * Tokens: {fan_name}, {fan_email}, {fan_url}, {fan_country},
     *         {listing_title}, {listing_subject}, {listing_url},
     *         {site_name}, {owner_name}, {owner_email}
     *
     * @param array{name:string,url:string,country:string,listing_name:string,listing_subject:string,listing_url:string} $recipient
     */
    private function applyTokens(
        string $text,
        array  $recipient,
        string $siteName,
        string $ownerName,
        string $ownerEmail,
    ): string {
        return str_replace(
            [
                '{fan_name}', '{fan_email}', '{fan_url}', '{fan_country}',
                '{listing_title}',            '{listing_subject}',            '{listing_url}',
                '{site_name}',  '{owner_name}', '{owner_email}',
            ],
            [
                $recipient['name'], $recipient['email'], $recipient['url'], $recipient['country'],
                $recipient['listing_title'], $recipient['listing_subject'], $recipient['listing_url'],
                $siteName,      $ownerName,     $ownerEmail,
            ],
            $text,
        );
    }

}
