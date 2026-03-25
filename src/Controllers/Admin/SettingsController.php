<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\Mailer;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\AdminUserRepo;
use Luster\Repositories\BlacklistRepo;
use Luster\Repositories\ErrorLogRepo;
use Luster\Repositories\SiteSettingsRepo;
use RuntimeException;

/**
 * Manages application settings stored in config/app.php.
 *
 * Saves values using var_export() into config/app.php via a safe write
 * pattern (write temp file → rename). Only keys enumerated in the
 * ALLOWED_KEYS list are accepted; all others are ignored.
 *
 * Routes:
 *   GET  /admin/settings — show settings form
 *   POST /admin/settings — save settings
 */
final class SettingsController
{
    /**
     * Keys that are safe to update via the settings form.
     * DB credentials and debug flag are intentionally excluded from
     * in-panel editing (they are set once at install time).
     */
    private const ALLOWED_KEYS = [
        'site_name',
        'site_url',
        'smtp_host',
        'smtp_port',
        'smtp_user',
        'smtp_pass',
        'smtp_from',
        'smtp_from_name',
        'smtp_secure',
        'timezone',
        'joined_display_mode',
        'joined_per_page',
        'install_path',
        'session_cookie_domain',
        'embed_base_url',
        'owner_name',
        'owner_email',
    ];

    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /admin/listings/collective-templates
    // -----------------------------------------------------------------------

    public function collectiveTemplates(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error',   '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        $siteRepo = new SiteSettingsRepo($this->app->getDatabase());
        $collectiveTemplates = $siteRepo->getMany([
            'owned_template',
            'joined_template',
            'collective_stats_template',
        ]);

        return $this->layout('admin/listings/collective-templates.php', [
            'collective_templates' => $collectiveTemplates,
            'success'              => $success,
            'error'                => $error,
        ], 'Collective Templates', 'listings');
    }

    // GET /admin/settings
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $error       = (string) $session->get('lstr_flash_error', '');
        $fieldErrors = json_decode((string) $session->get('lstr_flash_field_errors', '{}'), true);
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_field_errors');

        $adminId   = (int) $session->get('lstr_admin_id');
        $adminRepo = new AdminUserRepo($this->app->getDatabase());
        $adminUser = $adminRepo->getById($adminId);

        $db = $this->app->getDatabase();

        $blacklistEntries = (new BlacklistRepo($db))->getAll();

        $pdo           = $db->pdo();
        $dbVersion     = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $errorLogCount = (new ErrorLogRepo($db))->count();

        return $this->layout('admin/settings/index.php', [
            'cfg'                  => $this->app->getConfig()->all(),
            'admin_user'           => $adminUser,
            'users'                => $adminRepo->getAll(),
            'current_admin_id'     => $adminId,
            'blacklist_entries'    => $blacklistEntries,
            'success'              => $success,
            'error'                => $error,
            'field_errors'         => is_array($fieldErrors) ? $fieldErrors : [],
            'php_version'          => PHP_VERSION,
            'db_version'           => $dbVersion,
            'error_log_count'      => $errorLogCount,
        ], 'Settings', 'settings');
    }

    // -----------------------------------------------------------------------
    // POST /admin/settings
    // -----------------------------------------------------------------------

    public function update(Request $request, array $params): Response
    {
        $session     = $this->app->getSession();
        $baseUrl     = $this->app->getBaseUrl();
        $activityRepo = new ActivityRepo($this->app->getDatabase());

        $siteName   = trim((string) $request->post('site_name', ''));
        $siteUrl    = rtrim(trim((string) $request->post('site_url', '')), '/');
        $timezone  = trim((string) $request->post('timezone', 'UTC'));

        $v = new Validator(['site_name' => $siteName, 'site_url' => $siteUrl]);
        $v->field('site_name')->required()->maxLength(100);
        $v->field('site_url')->required()->maxLength(500);

        if ($v->fails()) {
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()));
            $session->set('lstr_flash_field_errors', json_encode($v->errors()));
            return Response::redirect($baseUrl . '/admin/settings#panel-general');
        }

        // Validate timezone
        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = 'UTC';
        }

        // Load the current config and merge in the allowed updated keys
        $existing = $this->app->getConfig()->all();

        $updated = $existing;
        $updated['site_name']      = $siteName;
        $updated['site_url']       = $siteUrl;
        $updated['timezone']       = $timezone;
        $updated['smtp_host']      = trim((string) $request->post('smtp_host', ''));
        $updated['smtp_port']      = max(1, min(65535, (int) $request->post('smtp_port', 587)));
        $updated['smtp_user']      = trim((string) $request->post('smtp_user', ''));
        $updated['smtp_from']      = trim((string) $request->post('smtp_from', ''));
        $updated['smtp_from_name'] = trim((string) $request->post('smtp_from_name', ''));
        $updated['smtp_secure']    = in_array(
            trim((string) $request->post('smtp_secure', 'tls')),
            ['tls', 'ssl', ''],
            true,
        ) ? trim((string) $request->post('smtp_secure', 'tls')) : 'tls';

        // Only update smtp_pass if a new value was submitted (empty = keep existing)
        $newSmtpPass = (string) $request->post('smtp_pass', '');
        if ($newSmtpPass !== '') {
            $updated['smtp_pass'] = $newSmtpPass;
        }

        // Embed & Paths
        $installPath = rtrim(trim((string) $request->post('install_path', '')), '/\\');
        $updated['install_path']          = $installPath !== '' ? $installPath . '/' : '';
        $updated['session_cookie_domain'] = trim((string) $request->post('session_cookie_domain', ''));
        $updated['embed_base_url']        = rtrim(trim((string) $request->post('embed_base_url', '')), '/');

        // Site owner identity (used in email templates)
        $updated['owner_name']  = trim((string) $request->post('owner_name',  ''));
        $updated['owner_email'] = trim((string) $request->post('owner_email', ''));

        $this->writeConfig($updated);

        // Save date_format and time_format to lstr_site_settings (UI preferences, not in config/app.php)
        $siteSettings = new SiteSettingsRepo($this->app->getDatabase());

        $allowedDateFormats = ['j M Y', 'M j, Y', 'F j, Y', 'd/m/Y', 'm/d/Y', 'Y-m-d', 'd-m-Y', 'm-d-Y'];
        $dateFormat = in_array($request->post('date_format'), $allowedDateFormats, true)
            ? (string) $request->post('date_format')
            : 'j M Y';
        $siteSettings->set('date_format', $dateFormat);

        $allowedTimeFormats = ['H:i', 'g:i A', 'g:i a'];
        $timeFormat = in_array($request->post('time_format'), $allowedTimeFormats, true)
            ? (string) $request->post('time_format')
            : 'H:i';
        $siteSettings->set('time_format', $timeFormat);

        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'settings.save',
            'Application settings updated',
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Settings saved successfully.');
        return Response::redirect($baseUrl . '/admin/settings#panel-general');
    }

    // -----------------------------------------------------------------------
    // GET /admin/emails/smtp-settings — redirect to unified Emails page
    // -----------------------------------------------------------------------

    public function emailSettingsRedirect(Request $request, array $params): Response
    {
        return Response::redirect($this->app->getBaseUrl() . '/admin/emails?tab=smtp');
    }

    // -----------------------------------------------------------------------
    // GET /admin/emails/smtp-settings (legacy — no longer routed, kept for reference)
    // -----------------------------------------------------------------------

    public function emailSettings(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        $adminId   = (int) $session->get('lstr_admin_id');
        $adminRepo = new AdminUserRepo($this->app->getDatabase());
        $adminUser = $adminRepo->getById($adminId);

        return $this->emailLayout('admin/emails/smtp-settings.php', [
            'cfg'        => $this->app->getConfig()->all(),
            'admin_user' => $adminUser,
            'success'    => $success,
            'error'      => $error,
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/emails/smtp-settings/save
    // -----------------------------------------------------------------------

    public function saveEmailSettings(Request $request, array $params): Response
    {
        $session      = $this->app->getSession();
        $baseUrl      = $this->app->getBaseUrl();
        $activityRepo = new ActivityRepo($this->app->getDatabase());

        $existing = $this->app->getConfig()->all();
        $updated  = $existing;

        $updated['smtp_host']              = trim((string) $request->post('smtp_host', ''));
        $updated['smtp_port']              = max(1, min(65535, (int) $request->post('smtp_port', 587)));
        $updated['smtp_user']              = trim((string) $request->post('smtp_user', ''));
        $updated['smtp_from']      = trim((string) $request->post('smtp_from', ''));
        $updated['smtp_from_name'] = trim((string) $request->post('smtp_from_name', ''));
        $updated['smtp_secure']    = in_array(
            trim((string) $request->post('smtp_secure', 'tls')),
            ['tls', 'ssl', ''],
            true,
        ) ? trim((string) $request->post('smtp_secure', 'tls')) : 'tls';

        $newSmtpPass = (string) $request->post('smtp_pass', '');
        if ($newSmtpPass !== '') {
            $updated['smtp_pass'] = $newSmtpPass;
        }

        $this->writeConfig($updated);

        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'settings.save',
            'Email/SMTP settings updated',
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Email settings saved.');
        return Response::redirect($baseUrl . '/admin/emails?tab=smtp');
    }

    // -----------------------------------------------------------------------
    // POST /admin/settings/validate-smtp   (returns JSON — AJAX only)
    // -----------------------------------------------------------------------

    public function validateSmtp(Request $request, array $params): Response
    {
        $host   = trim((string) $request->post('smtp_host',   ''));
        $port   = (int)          $request->post('smtp_port',   587);
        $user   = trim((string) $request->post('smtp_user',   ''));
        $pass   = trim((string) $request->post('smtp_pass',   ''));
        $secure = trim((string) $request->post('smtp_secure', 'tls'));

        // Fall back to stored password if the form field was left blank
        if ($pass === '') {
            $pass = (string) $this->app->getConfig()->get('smtp_pass', '');
        }

        if ($host === '') {
            return Response::json(['ok' => false, 'message' => 'SMTP host is empty.']);
        }

        $smtp = new \PHPMailer\PHPMailer\SMTP();
        $smtp->do_debug = \PHPMailer\PHPMailer\SMTP::DEBUG_OFF;

        try {
            if (!$smtp->connect($host, $port, 10)) {
                return Response::json(['ok' => false, 'message' => "Could not connect to {$host}:{$port}. Check host and port."]);
            }

            $ehloHost = gethostname() ?: 'localhost';

            if (!$smtp->hello($ehloHost)) {
                $smtp->quit();
                return Response::json(['ok' => false, 'message' => 'EHLO failed — server did not respond correctly.']);
            }

            if ($secure === 'tls') {
                if (!$smtp->startTLS()) {
                    $smtp->quit();
                    return Response::json(['ok' => false, 'message' => 'STARTTLS failed — server may not support TLS on this port.']);
                }
                if (!$smtp->hello($ehloHost)) {
                    $smtp->quit();
                    return Response::json(['ok' => false, 'message' => 'EHLO after STARTTLS failed.']);
                }
            }

            if ($user !== '') {
                if (!$smtp->authenticate($user, $pass)) {
                    $smtp->quit();
                    return Response::json(['ok' => false, 'message' => 'Authentication failed — check username and password.']);
                }
            }

            $smtp->quit();
            $authNote = $user !== '' ? ' and authenticated successfully' : ' (no auth configured)';
            return Response::json(['ok' => true, 'message' => "Connected to {$host}:{$port}{$authNote}."]);

        } catch (\Throwable $e) {
            try { $smtp->quit(); } catch (\Throwable) {}
            return Response::json(['ok' => false, 'message' => $e->getMessage()]);
        }
    }

    // -----------------------------------------------------------------------
    // POST /admin/settings/test-email
    // -----------------------------------------------------------------------

    public function testEmail(Request $request, array $params): Response
    {
        $session  = $this->app->getSession();
        $baseUrl  = $this->app->getBaseUrl();
        $config   = $this->app->getConfig();

        $fromEmail = (string) ($config->get('smtp_from', '') ?: $config->get('owner_email', ''));
        if ($fromEmail === '') {
            $session->set('lstr_flash_error', 'No From address is set. Add a From Address in SMTP settings, or set the Owner Email in General settings.');
            return Response::redirect($baseUrl . '/admin/emails?tab=smtp');
        }

        $adminId   = (int) $session->get('lstr_admin_id');
        $adminRepo = new AdminUserRepo($this->app->getDatabase());
        $adminUser = $adminRepo->getById($adminId);

        if ($adminUser === null) {
            $session->set('lstr_flash_error', 'Could not determine your email address.');
            return Response::redirect($baseUrl . '/admin/settings');
        }

        $siteName = (string) $config->get('site_name', 'Luster');

        try {
            $mailer = new Mailer($config);
            $mailer->send(
                $adminUser->email,
                $adminUser->username,
                '[' . $siteName . '] SMTP test email',
                "This is a test email sent from your " . $siteName . " admin panel.\n\n"
                . "If you received this message, your SMTP configuration is working correctly.\n\n"
                . "— Luster",
            );

            $activityRepo = new ActivityRepo($this->app->getDatabase());
            $activityRepo->log(
                $adminId,
                'settings.test_email',
                'Test email sent to ' . $adminUser->email,
                $request->ip(),
            );

            $session->set('lstr_flash_success', 'Test email sent to ' . $adminUser->email . '.');
        } catch (\Throwable $e) {
            $session->set('lstr_flash_error', 'Test email failed: ' . $e->getMessage());
        }

        return Response::redirect($baseUrl . '/admin/emails?tab=smtp');
    }

    // -----------------------------------------------------------------------
    // POST /admin/settings/profile
    // -----------------------------------------------------------------------

    public function updateProfile(Request $request, array $params): Response
    {
        $session      = $this->app->getSession();
        $baseUrl      = $this->app->getBaseUrl();
        $db           = $this->app->getDatabase();
        $adminId      = (int) $session->get('lstr_admin_id');
        $adminRepo    = new AdminUserRepo($db);
        $activityRepo = new ActivityRepo($db);

        $username        = trim((string) $request->post('admin_username', ''));
        $email           = trim((string) $request->post('admin_email', ''));
        $newPassword     = (string) $request->post('admin_new_password', '');
        $confirmPassword = (string) $request->post('admin_confirm_password', '');

        $v = new Validator(['username' => $username, 'email' => $email]);
        $v->field('username')->required()->maxLength(50);
        $v->field('email')->required()->email()->maxLength(255);

        if ($v->fails()) {
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()));
            $session->set('lstr_flash_field_errors', json_encode($v->errors()));
            return Response::redirect($baseUrl . '/admin/settings#panel-admin');
        }

        if ($newPassword !== '') {
            if (strlen($newPassword) < 8) {
                $session->set('lstr_flash_error', 'New password must be at least 8 characters.');
                $session->set('lstr_flash_field_errors', json_encode(['admin_new_password' => ['New password must be at least 8 characters.']]));
                return Response::redirect($baseUrl . '/admin/settings#panel-admin');
            }
            if ($newPassword !== $confirmPassword) {
                $session->set('lstr_flash_error', 'New password and confirmation do not match.');
                $session->set('lstr_flash_field_errors', json_encode(['admin_confirm_password' => ['New password and confirmation do not match.']]));
                return Response::redirect($baseUrl . '/admin/settings#panel-admin');
            }
        }

        // Uniqueness checks — exclude the current user
        $existingByUsername = $adminRepo->findByUsername($username);
        if ($existingByUsername !== null && $existingByUsername->id !== $adminId) {
            $session->set('lstr_flash_error', 'That username is already taken.');
            $session->set('lstr_flash_field_errors', json_encode(['username' => ['That username is already taken.']]));
            return Response::redirect($baseUrl . '/admin/settings#panel-admin');
        }

        $existingByEmail = $adminRepo->findByEmail($email);
        if ($existingByEmail !== null && $existingByEmail->id !== $adminId) {
            $session->set('lstr_flash_error', 'That email address is already in use.');
            $session->set('lstr_flash_field_errors', json_encode(['email' => ['That email address is already in use.']]));
            return Response::redirect($baseUrl . '/admin/settings#panel-admin');
        }

        $adminRepo->updateProfile($adminId, $username, $email);

        // Reflect changes in session so the topbar updates immediately
        $session->set('lstr_admin_username', $username);
        $session->set('lstr_admin_email', $email);

        if ($newPassword !== '') {
            $adminRepo->updatePassword($adminId, password_hash($newPassword, PASSWORD_ARGON2ID));
        }

        $activityRepo->log(
            $adminId,
            'admin.profile.update',
            'Admin profile updated',
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Profile updated successfully.');
        return Response::redirect($baseUrl . '/admin/settings');
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    // -----------------------------------------------------------------------
    // POST /admin/settings/templates
    // -----------------------------------------------------------------------

    public function updateTemplates(Request $request, array $params): Response
    {
        $session  = $this->app->getSession();
        $baseUrl  = $this->app->getBaseUrl();

        $ownedTemplate       = (string) $request->post('owned_template', '');
        $joinedTemplate      = (string) $request->post('joined_template', '');
        $cStatsTemplate      = (string) $request->post('collective_stats_template', '');

        $siteRepo = new SiteSettingsRepo($this->app->getDatabase());
        $siteRepo->set('owned_template',            $ownedTemplate      !== '' ? $ownedTemplate      : null);
        $siteRepo->set('joined_template',           $joinedTemplate     !== '' ? $joinedTemplate     : null);
        $siteRepo->set('collective_stats_template', $cStatsTemplate     !== '' ? $cStatsTemplate     : null);

        $activityRepo = new ActivityRepo($this->app->getDatabase());
        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'settings.templates.update',
            'Updated collective embed templates',
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Collective templates saved.');
        return Response::redirect($baseUrl . '/admin/listings/collective-templates');
    }

    /**
     * Writes the config array to config/app.php via a safe temp-file pattern.
     *
     * @throws RuntimeException on write failure
     */
    private function writeConfig(array $config): void
    {
        $configPath = $this->app->getBasePath() . '/config/app.php';
        $tmpPath    = $configPath . '.tmp';

        $export  = "<?php\n\ndeclare(strict_types=1);\n\nreturn ";
        $export .= var_export($config, true);
        $export .= ";\n";

        $bytes = file_put_contents($tmpPath, $export, LOCK_EX);

        if ($bytes === false) {
            throw new RuntimeException('Could not write temp config file: ' . $tmpPath);
        }

        if (!rename($tmpPath, $configPath)) {
            unlink($tmpPath);
            throw new RuntimeException('Could not rename temp config file to: ' . $configPath);
        }
    }

    private function emailLayout(string $template, array $data): Response
    {
        $view      = $this->app->getView();
        $config    = $this->app->getConfig();
        $session   = $this->app->getSession();
        $baseUrl   = $this->app->getBaseUrl();
        $csrfToken = $this->app->getCSRF()->token();

        $inner = $view->render($template, array_merge($data, [
            'base_url'   => $baseUrl,
            'csrf_token' => $csrfToken,
        ]));

        return Response::html($view->render('admin/layout.php', array_merge([
            'page_title'      => 'Emails',
            'page_subtitle'   => 'Emails › Email / SMTP Settings',
            'site_name'       => (string) $config->get('site_name', 'Luster'),
            'admin_username'  => (string) $session->get('lstr_admin_username', ''),
            'csrf_token'      => $csrfToken,
            'current_section' => 'email-templates',
            'base_url'        => $baseUrl,
            'content'         => $inner,
        ], $this->app->getAdminNavData())));
    }

    private function layout(string $template, array $data, string $pageTitle, string $section): Response
    {
        $view      = $this->app->getView();
        $config    = $this->app->getConfig();
        $session   = $this->app->getSession();
        $baseUrl   = $this->app->getBaseUrl();
        $csrfToken = $this->app->getCSRF()->token();

        $inner = $view->render($template, array_merge($data, [
            'base_url'   => $baseUrl,
            'csrf_token' => $csrfToken,
        ]));

        return Response::html($view->render('admin/layout.php', array_merge([
            'page_title'      => $pageTitle,
            'site_name'       => (string) $config->get('site_name', 'Luster'),
            'admin_username'  => (string) $session->get('lstr_admin_username', ''),
            'csrf_token'      => $csrfToken,
            'current_section' => $section,
            'base_url'        => $baseUrl,
            'content'         => $inner,
        ], $this->app->getAdminNavData())));
    }
}
