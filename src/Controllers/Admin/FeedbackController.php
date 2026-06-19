<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\FeedbackReport;
use Luster\Core\Mailer;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Version;
use Luster\Repositories\ErrorLogRepo;

/**
 * Admin feedback form — sends a report to the Luster developer.
 *
 * Routes:
 *   GET  /admin/feedback — show the form with diagnostic previews
 *   POST /admin/feedback — validate, compose, and send the report
 */
final class FeedbackController extends AdminController
{
    private const ALLOWED_TYPES = ['bug', 'question', 'feature', 'other'];

    // -----------------------------------------------------------------------
    // GET /admin/feedback
    // -----------------------------------------------------------------------

    public function showForm(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $session->remove('lstr_flash_success');

        return $this->adminLayout('admin/feedback/index.php', 'Send a Report', 'feedback',
            array_merge($this->gatherViewData(), [
                'success'       => $success,
                'field_errors'  => [],
                'old'           => [],
                'send_failed'   => false,
                'composed_body' => '',
            ])
        );
    }

    // -----------------------------------------------------------------------
    // POST /admin/feedback
    // -----------------------------------------------------------------------

    public function submit(Request $request, array $params): Response
    {
        $session = $this->app->getSession();

        $type         = trim((string) $request->post('type',          ''));
        $subject      = trim((string) $request->post('subject',       ''));
        $message      = trim((string) $request->post('message',       ''));
        $contactName  = trim((string) $request->post('contact_name',  ''));
        $contactEmail = trim((string) $request->post('contact_email', ''));
        $contactUrl   = trim((string) $request->post('contact_url',   ''));
        $includeSysinfo  = $request->post('include_sysinfo',  '') === '1';
        $includeErrorlog = $request->post('include_errorlog', '') === '1';

        $old = [
            'type'             => $type,
            'subject'          => $subject,
            'message'          => $message,
            'contact_name'     => $contactName,
            'contact_email'    => $contactEmail,
            'contact_url'      => $contactUrl,
            'include_sysinfo'  => $includeSysinfo  ? '1' : '',
            'include_errorlog' => $includeErrorlog ? '1' : '',
        ];

        $fieldErrors = $this->validateFields($type, $subject, $message, $contactName, $contactEmail, $contactUrl);
        $viewData    = $this->gatherViewData();

        if ($fieldErrors !== []) {
            return $this->adminLayout('admin/feedback/index.php', 'Send a Report', 'feedback',
                array_merge($viewData, [
                    'success'       => '',
                    'field_errors'  => $fieldErrors,
                    'old'           => $old,
                    'send_failed'   => false,
                    'composed_body' => '',
                ])
            );
        }

        $composedBody = FeedbackReport::compose(
            [
                'type'             => $type,
                'subject'          => $subject,
                'message'          => $message,
                'contact_name'     => $contactName,
                'contact_email'    => $contactEmail,
                'contact_url'      => $contactUrl,
                'include_sysinfo'  => $includeSysinfo,
                'include_errorlog' => $includeErrorlog,
            ],
            $viewData['sysinfo_text'],
            $viewData['errorlog_text'],
        );

        try {
            $mailer = new Mailer($this->app->getConfig());
            $mailer->sendWithReplyTo(
                Version::SUPPORT_EMAIL,
                Version::SUPPORT_NAME,
                '[Luster Feedback] ' . FeedbackReport::typeLabel($type) . ' - ' . $subject,
                $composedBody,
                $contactEmail,
            );

            $session->set('lstr_flash_success', 'Your report has been sent. Thank you!');
            return Response::redirect($this->app->getBaseUrl() . '/admin/feedback');

        } catch (\RuntimeException) {
            return $this->adminLayout('admin/feedback/index.php', 'Send a Report', 'feedback',
                array_merge($viewData, [
                    'success'       => '',
                    'field_errors'  => [],
                    'old'           => $old,
                    'send_failed'   => true,
                    'composed_body' => $composedBody,
                ])
            );
        }
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    private function validateFields(
        string $type,
        string $subject,
        string $message,
        string $contactName,
        string $contactEmail,
        string $contactUrl = '',
    ): array {
        $errors = [];

        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            $errors['type'] = 'Please select a report type.';
        }
        if ($subject === '') {
            $errors['subject'] = 'Subject is required.';
        }
        if ($message === '') {
            $errors['message'] = 'Please describe your issue or request.';
        }
        if ($contactName === '') {
            $errors['contact_name'] = 'Please enter your name.';
        }
        if ($contactEmail === '') {
            $errors['contact_email'] = 'Please enter your email address.';
        } elseif (!filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            $errors['contact_email'] = 'Please enter a valid email address.';
        }
        if ($contactUrl !== '' && !preg_match('/^https?:\/\//i', $contactUrl)) {
            $errors['contact_url'] = 'The URL must start with http:// or https://.';
        }

        return $errors;
    }

    private function gatherViewData(): array
    {
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();

        // Database version
        $dbVersion = 'unknown';
        try {
            $stmt = $db->prepare('SELECT VERSION()');
            $stmt->execute();
            $dbVersion = (string) $stmt->fetchColumn();
        } catch (\Throwable) {}

        // Current migration level
        $migrationLevel = null;
        try {
            $stmt = $db->prepare('SELECT MAX(migration_number) FROM `lstr_migrations`');
            $stmt->execute();
            $val = $stmt->fetchColumn();
            if ($val !== false) {
                $migrationLevel = (int) $val;
            }
        } catch (\Throwable) {}

        // Installed add-ons
        $addons = [];
        try {
            $stmt = $db->prepare('SELECT addon_key FROM `lstr_addons` ORDER BY addon_key');
            $stmt->execute();
            $addons = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable) {}

        // Recent error log entries — strip the absolute server path from both the
        // source field and the message body so reports only reveal project-relative paths.
        // "called in C:\xampp\htdocs\luster\templates\foo.php" → "called in templates\foo.php"
        $errorLogRows = [];
        try {
            $repo = new ErrorLogRepo($db);
            $errorLogRows = $repo->getPaginated(1, 10)['rows'];

            $stripPrefix = LUSTER_BASE . DIRECTORY_SEPARATOR;
            $errorLogRows = array_map(static function (array $row) use ($stripPrefix): array {
                if (isset($row['source']) && str_starts_with($row['source'], $stripPrefix)) {
                    $row['source'] = substr($row['source'], strlen($stripPrefix));
                }
                if (isset($row['message'])) {
                    $row['message'] = str_replace($stripPrefix, '', $row['message']);
                }
                return $row;
            }, $errorLogRows);
        } catch (\Throwable) {}

        $ownerEmail = (string) $config->get('owner_email', '');

        $senderWarning = $ownerEmail === '' ? 'strong' : '';

        $sysinfoData = [
            'luster_version'   => Version::CURRENT,
            'base_url'         => ltrim((string) parse_url($this->app->getBaseUrl(), PHP_URL_PATH), '/'),
            'report_timestamp' => date('Y-m-d H:i:s'),
            'php_version'      => PHP_VERSION,
            'db_version'       => $dbVersion,
            'debug_enabled'    => (bool) $config->get('app_debug', false),
            'mail_configured'  => $ownerEmail !== '',
            'admin_username'   => (string) $session->get('lstr_admin_username', ''),
            'addons'           => $addons,
            'migration_level'  => $migrationLevel,
        ];

        return [
            'sysinfo_text'   => FeedbackReport::formatSysInfo($sysinfoData),
            'errorlog_text'  => FeedbackReport::formatErrorLog($errorLogRows),
            'errorlog_empty' => ($errorLogRows === []),
            'sender_warning' => $senderWarning,
            'admin_email'    => (string) $session->get('lstr_admin_email', ''),
            'owner_name'     => (string) $config->get('owner_name', ''),
        ];
    }
}
