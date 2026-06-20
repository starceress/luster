<?php

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
 * Bulk email — send an announcement to approved members of one or all listings.
 *
 * POST /admin/emails/preview             → preview()        — validate + show recipient count
 * POST /admin/emails/send                → send()           — perform the send loop + results
 * GET  /admin/new-email-template                   → savedEmailsRedirect() — redirect to /admin/emails?tab=custom
 * POST /admin/new-email-template/save              → saveTemplate()   — create a saved template
 * POST /admin/new-email-template/{id}/update       → updateTemplate() — update a saved template
 * POST /admin/new-email-template/{id}/delete       → deleteTemplate() — delete a saved template
 *
 * The compose form and saved-template list are rendered by EmailsController::index()
 * (?tab=bulk and ?tab=custom respectively).
 *
 * Listing ID = 0 means "all listings" (recipients are deduplicated by email).
 *
 * Supported body tokens (per-recipient substitution):
 *   {fan_name}, {fan_email}, {fan_url}, {fan_country}
 *   {listing_title}, {listing_subject}, {listing_url}
 *   {site_name}, {owner_name}, {owner_email}
 */
final class BulkEmailController extends AdminController
{

    // -----------------------------------------------------------------------
    // POST /admin/emails/preview
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

    public function savedEmailsRedirect(Request $request, array $params): Response
    {
        return Response::redirect($this->app->getBaseUrl() . '/admin/emails?tab=custom');
    }

    // -----------------------------------------------------------------------
    // POST /admin/new-email-template/save
    // -----------------------------------------------------------------------

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
