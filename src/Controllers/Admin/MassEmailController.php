<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Mailer;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\MemberRepo;
use Luster\Repositories\SavedEmailRepo;

/**
 * Mass email — send an announcement to approved members of one or all listings.
 *
 * GET  /admin/emails                     → compose()        — compose form
 * POST /admin/emails/preview             → preview()        — validate + show recipient count
 * POST /admin/emails/send                → send()           — perform the send loop + results
 * GET  /admin/new-email-template                   → savedEmails()    — list + manage saved templates
 * POST /admin/new-email-template/save              → saveTemplate()   — create a saved template
 * POST /admin/new-email-template/{id}/update       → updateTemplate() — update a saved template
 * POST /admin/new-email-template/{id}/delete       → deleteTemplate() — delete a saved template
 *
 * Listing ID = 0 means "all listings" (recipients are deduplicated by email).
 *
 * Supported body tokens:
 *   {name}         → member's name
 *   {email}        → member's email address
 *   {listing_name} → name of the listing they are a member of (or app name for all-listing sends)
 *   {site_name}    → site/collective name from config
 */
final class MassEmailController extends AdminController
{

    // -----------------------------------------------------------------------
    // GET /admin/emails
    // -----------------------------------------------------------------------

    public function compose(Request $request, array $params): Response
    {
        $db       = $this->app->getDatabase();
        $listings = (new ListingRepo($db, $this->app->getConfig()))->getAll();
        $session  = $this->app->getSession();
        $repo     = new SavedEmailRepo($db);

        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        $savedEmails   = $repo->getAll();
        $etRepo        = new \Luster\Repositories\EmailTemplateRepo($db);
        $globalEtRaw   = $etRepo->getAllGlobal();

        // Resolve each global email template — saved row or built-in default
        $typeLabels = [
            \Luster\Repositories\EmailTemplateRepo::TYPE_JOIN        => 'Join confirmation',
            \Luster\Repositories\EmailTemplateRepo::TYPE_APPROVE     => 'Membership approved',
            \Luster\Repositories\EmailTemplateRepo::TYPE_UPDATE      => 'Membership updated',
            \Luster\Repositories\EmailTemplateRepo::TYPE_REMOVE      => 'Membership removed',
            \Luster\Repositories\EmailTemplateRepo::TYPE_RESET       => 'Password reset',
            \Luster\Repositories\EmailTemplateRepo::TYPE_NOTIFY_JOIN => 'New member notification',
        ];
        $globalEmailTemplates = [];
        foreach ($typeLabels as $type => $label) {
            $row = $globalEtRaw[$type] ?? null;
            $globalEmailTemplates[] = (object) [
                'label'   => $label,
                'subject' => ($row !== null && $row->subject !== '') ? $row->subject : \Luster\Repositories\EmailTemplateRepo::defaultSubject($type),
                'body'    => ($row !== null && $row->body    !== '') ? $row->body    : \Luster\Repositories\EmailTemplateRepo::defaultBody($type),
            ];
        }

        // Pre-fill from a saved template when ?load=ID is passed (from saved-templates page)
        $loadId     = (int) $request->query('load', 0);
        $preSubject = '';
        $preBody    = '';
        if ($loadId > 0) {
            $tpl = $repo->getById($loadId);
            if ($tpl !== null) {
                $preSubject = $tpl->subject;
                $preBody    = $tpl->body;
            }
        }

        // Per-listing approved member counts + deduplicated total for preview modal
        $config     = $this->app->getConfig();
        $memberRepo = new \Luster\Repositories\MemberRepo($db, $config);
        $listingCounts = [];
        $allEmails     = [];
        foreach ($listings as $lst) {
            $count = $lst->dbprefix !== '' ? $memberRepo->countApproved($lst->dbprefix) : 0;
            $listingCounts[$lst->id] = $count;
            if ($lst->dbprefix !== '') {
                foreach ($memberRepo->getApproved($lst->dbprefix) as $m) {
                    $allEmails[strtolower($m->email)] = true;
                }
            }
        }
        $deduplicatedTotal = count($allEmails);

        $smtpFrom     = (string) $config->get('smtp_from',      '');
        $smtpFromName = (string) $config->get('smtp_from_name', '');
        $fromEmail    = $smtpFrom     !== '' ? $smtpFrom     : (string) $config->get('owner_email', '');
        $fromName     = $smtpFromName !== '' ? $smtpFromName : (string) $config->get('owner_name',  '');

        return $this->adminLayout('admin/mass-email/compose.php', 'Emails', 'emails', [
            'listings'              => $listings,
            'listing_counts'        => $listingCounts,
            'deduplicated_total'    => $deduplicatedTotal,
            'saved_emails'          => $savedEmails,
            'global_email_templates' => $globalEmailTemplates,
            'error'              => $error,
            'success'            => $success,
            'old_listing_id'     => 0,
            'old_subject'        => $preSubject,
            'old_body'           => $preBody,
            'site_name_val'      => (string) $config->get('site_name', ''),
            'from_name_val'      => $fromName,
            'from_email_val'     => $fromEmail,
        ], 'Emails › Bulk Email');
    }

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

        return $this->adminLayout('admin/mass-email/preview.php', 'Emails', 'emails', [
            'listing_id'   => $listingId,
            'listing_name' => $listingName,
            'subject'      => $subject,
            'body'         => $body,
            'total'        => count($recipients),
            'sample'       => $sample,
        ], 'Emails › Bulk Email');
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
        $siteName   = (string) $config->get('site_name',      'Luster');
        $ownerName  = (string) ($config->get('smtp_from_name', '') ?: $config->get('owner_name',  ''));
        $ownerEmail = (string) ($config->get('smtp_from',      '') ?: $config->get('owner_email', ''));
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

        return $this->adminLayout('admin/mass-email/result.php', 'Emails', 'emails', [
            'listing_name' => $listingName,
            'subject'      => $subject,
            'sent'         => $sent,
            'total'        => count($recipients),
            'failed'       => $failed,
        ], 'Emails › Bulk Email');
    }

    // -----------------------------------------------------------------------
    // GET /admin/new-email-template — redirect to unified page
    // -----------------------------------------------------------------------

    public function savedEmailsRedirect(Request $request, array $params): Response
    {
        return Response::redirect($this->app->getBaseUrl() . '/admin/emails?tab=custom');
    }

    // -----------------------------------------------------------------------
    // GET /admin/new-email-template (legacy — no longer routed, kept for reference)
    // -----------------------------------------------------------------------

    public function savedEmails(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();

        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        $savedEmails = (new SavedEmailRepo($db))->getAll();

        return $this->adminLayout('admin/mass-email/saved-templates.php', 'Emails', 'emails', [
            'saved_emails' => $savedEmails,
            'success'      => $success,
            'error'        => $error,
        ], 'Emails › New Template');
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
        $redirect = ($referer !== '' && str_starts_with($referer, $baseUrl))
            ? $referer
            : $baseUrl . '/admin/emails?tab=custom';

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
                    'listing_name'     => $listing->name,
                    'listing_subject'  => $listing->subject,
                    'listing_url'      => $listing->url,
                ];
            }

            return [$recipients, $listing->name];
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
                        'listing_name'     => $listing->name,
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
     *         {listing_name}, {listing_subject}, {listing_url},
     *         {site_name}, {owner_name}, {owner_email}
     * Legacy aliases: {name} → {fan_name}, {email} → {fan_email}
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
                '{fan_name}', '{fan_email}', '{fan_url}', '{fan_country}', '{fan_password}',
                '{name}',                    '{email}',
                '{listing_name}',            '{listing_subject}',            '{listing_url}',
                '{site_name}',  '{owner_name}', '{owner_email}',
            ],
            [
                $recipient['name'], $recipient['email'], $recipient['url'], $recipient['country'], '',
                $recipient['name'],                      $recipient['email'],
                $recipient['listing_name'], $recipient['listing_subject'], $recipient['listing_url'],
                $siteName,      $ownerName,     $ownerEmail,
            ],
            $text,
        );
    }

}
