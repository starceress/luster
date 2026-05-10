<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\AdminUserRepo;
use Luster\Repositories\EmailTemplateRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\MemberRepo;
use Luster\Repositories\SavedEmailRepo;

/**
 * Unified Emails admin page.
 *
 * GET /admin/emails  — unified tabbed page for all email management:
 *   ?tab=templates   — Email Templates
 *   ?tab=bulk        — Bulk Email (compose form)
 *   ?tab=custom      — Custom Templates (saved email templates)
 *   ?tab=smtp        — SMTP Settings
 *
 * All data for all tabs is loaded eagerly on every GET.
 * POST actions are handled by their original controllers.
 */
final class EmailsController extends AdminController
{
    private const VALID_TABS = ['templates', 'bulk', 'custom', 'smtp'];

    // -----------------------------------------------------------------------
    // GET /admin/emails
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();

        // Determine active tab
        $tab = $request->query('tab', 'templates');
        if (!in_array($tab, self::VALID_TABS, true)) {
            $tab = 'templates';
        }

        // Flash messages (consumed once)
        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error',   '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        // ── Shared: all listings ──────────────────────────────────────────
        $listingRepo = new ListingRepo($db, $config);
        $allListings = $listingRepo->getAll();

        // ── Email Templates tab ───────────────────────────────────────────
        $emailRepo       = new EmailTemplateRepo($db);
        $globalTemplates = $emailRepo->getAllGlobal();

        $scope    = $tab === 'templates' ? $request->query('listing', 'global') : 'global';
        $listing  = null;
        $perListingTemplates = [];

        if ($scope !== 'global') {
            $listingId = (int) $scope;
            $listing   = $listingRepo->getById($listingId);
            if ($listing === null) {
                $scope = 'global';
            } else {
                $perListingTemplates = $emailRepo->getAll($listingId);
            }
        }

        $isGlobal = ($scope === 'global');

        // Build cards (pre-filled values and customisation state per template type)
        $cards = [];
        foreach (EmailTemplateRepo::TYPES as $type) {
            $globalTpl  = $globalTemplates[$type] ?? null;
            $listingTpl = $perListingTemplates[$type] ?? null;

            if ($isGlobal) {
                $savedSubject = $globalTpl?->subject ?? '';
                $savedBody    = $globalTpl?->body    ?? '';
                $fillSubject  = $savedSubject !== '' ? $savedSubject : EmailTemplateRepo::defaultSubject($type);
                $fillBody     = $savedBody    !== '' ? $savedBody    : EmailTemplateRepo::defaultBody($type);
                $isEnabled    = $globalTpl === null || $globalTpl->enabled;
                $isCustomised = false;
            } else {
                $savedSubject = $listingTpl?->subject ?? '';
                $savedBody    = $listingTpl?->body    ?? '';
                $isCustomised = $listingTpl !== null && trim($listingTpl->subject) !== '';
                $isEnabled    = $listingTpl === null
                    ? ($globalTpl === null || $globalTpl->enabled)
                    : $listingTpl->enabled;

                if ($isCustomised) {
                    $fillSubject = $savedSubject;
                    $fillBody    = $savedBody;
                } else {
                    $fillSubject = ($globalTpl !== null && trim($globalTpl->subject) !== '')
                        ? $globalTpl->subject
                        : EmailTemplateRepo::defaultSubject($type);
                    $fillBody = ($globalTpl !== null && trim($globalTpl->body) !== '')
                        ? $globalTpl->body
                        : EmailTemplateRepo::defaultBody($type);
                }
            }

            $cards[$type] = [
                'fillSubject'  => $fillSubject,
                'fillBody'     => $fillBody,
                'isEnabled'    => $isEnabled,
                'isCustomised' => $isCustomised,
            ];
        }

        // ── Bulk Email tab ────────────────────────────────────────────────
        $savedEmailRepo = new SavedEmailRepo($db);
        $savedEmails    = $savedEmailRepo->getAll();

        // Resolve global email templates for the compose sidebar
        $typeLabels = [
            EmailTemplateRepo::TYPE_JOIN        => 'Join confirmation',
            EmailTemplateRepo::TYPE_APPROVE     => 'Membership approved',
            EmailTemplateRepo::TYPE_UPDATE      => 'Membership updated',
            EmailTemplateRepo::TYPE_REMOVE      => 'Membership removed',
            EmailTemplateRepo::TYPE_RESET       => 'Password reset',
            EmailTemplateRepo::TYPE_NOTIFY_JOIN => 'New member notification',
        ];
        $globalEmailTemplates = [];
        foreach ($typeLabels as $type => $label) {
            $row = $globalTemplates[$type] ?? null;
            $globalEmailTemplates[] = (object) [
                'label'   => $label,
                'subject' => ($row !== null && $row->subject !== '') ? $row->subject : EmailTemplateRepo::defaultSubject($type),
                'body'    => ($row !== null && $row->body    !== '') ? $row->body    : EmailTemplateRepo::defaultBody($type),
            ];
        }

        // Pre-fill compose from a saved template when ?load=ID is given,
        // or from flash old data (repopulate after failed preview validation)
        $loadId     = (int) $request->query('load', 0);
        $preSubject = '';
        $preBody    = '';
        $preListingId = 0;
        $flashOld = $session->get('lstr_flash_old');
        $session->remove('lstr_flash_old');
        if (is_array($flashOld) && isset($flashOld['subject'])) {
            $preSubject   = (string) ($flashOld['subject']    ?? '');
            $preBody      = (string) ($flashOld['body']       ?? '');
            $preListingId = (int)    ($flashOld['listing_id'] ?? 0);
        } elseif ($loadId > 0) {
            $tpl = $savedEmailRepo->getById($loadId);
            if ($tpl !== null) {
                $preSubject = $tpl->subject;
                $preBody    = $tpl->body;
            }
        } elseif ($tab === 'bulk') {
            $preListingId = (int) $request->query('listing', 0);
        }

        // Per-listing approved member counts + deduplicated total
        $memberRepo        = new MemberRepo($db, $config);
        $listingCounts     = [];
        $allEmails         = [];
        foreach ($allListings as $lst) {
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

        // ── SMTP tab ──────────────────────────────────────────────────────
        $cfg = $config->all();

        $adminId   = (int) $session->get('lstr_admin_id');
        $adminRepo = new AdminUserRepo($db);
        $adminUser = $adminRepo->getById($adminId);

        // ── Render ────────────────────────────────────────────────────────
        $tabLabel = match ($tab) {
            'bulk'   => 'Bulk Email',
            'custom' => 'Saved Templates',
            'smtp'   => 'SMTP Settings',
            default  => 'Email Templates',
        };
        $breadcrumbs = [];
        if ($tab !== 'templates' || $listing !== null) {
            $breadcrumbs = [
                $this->crumb('Emails', $this->adminUrl('/admin/emails')),
                $this->crumb($tabLabel, $tab !== 'templates' ? $this->adminUrl('/admin/emails?tab=' . $tab) : null),
            ];
            if ($listing !== null) {
                $breadcrumbs[] = $this->crumb($listing->subject);
            }
        }

        return $this->adminLayout('admin/emails/index.php', 'Emails', 'email-templates', [
            'active_tab'          => $tab,
            'success'             => $success,
            'error'               => $error,

            // Templates tab
            'all_listings'        => $allListings,
            'scope'               => $scope,
            'listing'             => $listing,
            'cards'               => $cards,
            'owner_name'          => (string) $config->get('owner_name', ''),
            'owner_email'         => (string) $config->get('owner_email', ''),
            'site_name_val'       => (string) $config->get('site_name', ''),

            // Bulk email tab
            'listings'            => $allListings,
            'listing_counts'      => $listingCounts,
            'deduplicated_total'  => $deduplicatedTotal,
            'saved_emails'        => $savedEmails,
            'global_email_templates' => $globalEmailTemplates,
            'old_listing_id'      => $preListingId,
            'old_subject'         => $preSubject,
            'old_body'            => $preBody,
            'from_name_val'       => $fromName,
            'from_email_val'      => $fromEmail,

            // SMTP tab
            'cfg'                 => $cfg,
            'admin_user'          => $adminUser,
        ], '', $breadcrumbs);
    }

}
