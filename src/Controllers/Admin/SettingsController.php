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
        'app_name',
        'app_url',
        'smtp_host',
        'smtp_port',
        'smtp_user',
        'smtp_pass',
        'smtp_from',
        'smtp_from_name',
        'smtp_from_listing',
        'smtp_from_listing_name',
        'smtp_secure',
        'timezone',
        'joined_display_mode',
        'joined_per_page',
        'install_path',
        'session_cookie_domain',
        'embed_base_url',
    ];

    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /admin/settings
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        $adminId   = (int) $session->get('lstr_admin_id');
        $adminRepo = new AdminUserRepo($this->app->getDatabase());
        $adminUser = $adminRepo->getById($adminId);

        return $this->layout('admin/settings/index.php', [
            'cfg'        => $this->app->getConfig()->all(),
            'admin_user' => $adminUser,
            'success'    => $success,
            'error'      => $error,
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

        $appName   = trim((string) $request->post('app_name', ''));
        $appUrl    = rtrim(trim((string) $request->post('app_url', '')), '/');
        $timezone  = trim((string) $request->post('timezone', 'UTC'));

        $v = new Validator(['app_name' => $appName, 'app_url' => $appUrl]);
        $v->field('app_name')->required()->maxLength(100);
        $v->field('app_url')->required()->maxLength(500);

        if ($v->fails()) {
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()));
            return Response::redirect($baseUrl . '/admin/settings');
        }

        // Validate timezone
        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = 'UTC';
        }

        // Load the current config and merge in the allowed updated keys
        $existing = $this->app->getConfig()->all();

        $updated = $existing;
        $updated['app_name']       = $appName;
        $updated['app_url']        = $appUrl;
        $updated['timezone']       = $timezone;
        $updated['smtp_host']      = trim((string) $request->post('smtp_host', ''));
        $updated['smtp_port']      = max(1, min(65535, (int) $request->post('smtp_port', 587)));
        $updated['smtp_user']      = trim((string) $request->post('smtp_user', ''));
        $updated['smtp_from']      = trim((string) $request->post('smtp_from', ''));
        $updated['smtp_from_name']          = trim((string) $request->post('smtp_from_name', ''));
        $updated['smtp_from_listing']    = trim((string) $request->post('smtp_from_listing', ''));
        $updated['smtp_from_listing_name'] = trim((string) $request->post('smtp_from_listing_name', ''));
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

        $this->writeConfig($updated);

        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'settings.save',
            'Application settings updated',
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Settings saved successfully.');
        return Response::redirect($baseUrl . '/admin/settings');
    }

    // -----------------------------------------------------------------------
    // POST /admin/settings/test-email
    // -----------------------------------------------------------------------

    public function testEmail(Request $request, array $params): Response
    {
        $session  = $this->app->getSession();
        $baseUrl  = $this->app->getBaseUrl();
        $config   = $this->app->getConfig();

        $smtpHost = (string) $config->get('smtp_host', '');
        if ($smtpHost === '') {
            $session->set('lstr_flash_error', 'Email is not configured. Set an SMTP host first.');
            return Response::redirect($baseUrl . '/admin/settings');
        }

        $adminId   = (int) $session->get('lstr_admin_id');
        $adminRepo = new AdminUserRepo($this->app->getDatabase());
        $adminUser = $adminRepo->getById($adminId);

        if ($adminUser === null) {
            $session->set('lstr_flash_error', 'Could not determine your email address.');
            return Response::redirect($baseUrl . '/admin/settings');
        }

        $appName = (string) $config->get('app_name', 'Luster');

        try {
            $mailer = new Mailer($config);
            $mailer->send(
                $adminUser->email,
                $adminUser->username,
                '[' . $appName . '] SMTP test email',
                "This is a test email sent from your " . $appName . " admin panel.\n\n"
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

        return Response::redirect($baseUrl . '/admin/settings');
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
            return Response::redirect($baseUrl . '/admin/settings');
        }

        if ($newPassword !== '') {
            if (strlen($newPassword) < 8) {
                $session->set('lstr_flash_error', 'New password must be at least 8 characters.');
                return Response::redirect($baseUrl . '/admin/settings');
            }
            if ($newPassword !== $confirmPassword) {
                $session->set('lstr_flash_error', 'New password and confirmation do not match.');
                return Response::redirect($baseUrl . '/admin/settings');
            }
        }

        // Uniqueness checks — exclude the current user
        $existingByUsername = $adminRepo->findByUsername($username);
        if ($existingByUsername !== null && $existingByUsername->id !== $adminId) {
            $session->set('lstr_flash_error', 'That username is already taken.');
            return Response::redirect($baseUrl . '/admin/settings');
        }

        $existingByEmail = $adminRepo->findByEmail($email);
        if ($existingByEmail !== null && $existingByEmail->id !== $adminId) {
            $session->set('lstr_flash_error', 'That email address is already in use.');
            return Response::redirect($baseUrl . '/admin/settings');
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

        return Response::html($view->render('admin/layout.php', [
            'page_title'      => $pageTitle,
            'app_name'        => (string) $config->get('app_name', 'Luster'),
            'admin_username'  => (string) $session->get('lstr_admin_username', ''),
            'csrf_token'      => $csrfToken,
            'current_section' => $section,
            'base_url'        => $baseUrl,
            'content'         => $inner,
        ]));
    }
}
