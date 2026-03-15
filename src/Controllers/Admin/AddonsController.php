<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\AddonInterface;
use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\ActivityRepo;
use PDOException;
use Throwable;

/**
 * Admin add-ons management — discover, install, and uninstall add-ons.
 *
 * GET  /admin/addons           → index()    list discovered add-ons
 * POST /admin/addons/install   → install()  install a single add-on
 * POST /admin/addons/uninstall → uninstall() uninstall a single add-on
 */
final class AddonsController
{
    public function __construct(private readonly Application $app) {}

    // -------------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $view    = $this->app->getView();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        $discovered = $this->app->discoverAddons();
        $installed  = array_flip($this->app->getInstalledAddonKeys()); // key => true

        $inner = $view->render('admin/addons/index.php', [
            'app_name'        => (string) $config->get('app_name', 'Luster'),
            'admin_username'  => (string) $session->get('lstr_admin_username', ''),
            'csrf_token'      => $this->app->getCSRF()->token(),
            'base_url'        => $baseUrl,
            'discovered'      => $discovered,
            'installed'       => $installed,
            'success'         => $success,
            'error'           => $error,
        ]);

        return Response::html($view->render('admin/layout.php', [
            'page_title'      => 'Add-ons',
            'app_name'        => (string) $config->get('app_name', 'Luster'),
            'admin_username'  => (string) $session->get('lstr_admin_username', ''),
            'csrf_token'      => $this->app->getCSRF()->token(),
            'current_section' => 'addons',
            'base_url'        => $baseUrl,
            'content'         => $inner,
        ]));
    }

    // -------------------------------------------------------------------------

    public function install(Request $request, array $params): Response
    {
        $key      = trim((string) $request->post('addon_key'));
        $baseUrl  = $this->app->getBaseUrl();
        $session  = $this->app->getSession();
        $redirect = $baseUrl . '/admin/addons';

        if ($key === '') {
            $session->set('lstr_flash_error', 'No add-on key provided.');
            return Response::redirect($redirect);
        }

        $discovered = $this->app->discoverAddons();

        if (!isset($discovered[$key])) {
            $session->set('lstr_flash_error', 'Unknown add-on: ' . $key);
            return Response::redirect($redirect);
        }

        if ($this->app->isAddonInstalled($key)) {
            $session->set('lstr_flash_error', 'Add-on is already installed.');
            return Response::redirect($redirect);
        }

        $addon = $discovered[$key];
        $pdo   = $this->app->getDatabase()->pdo();

        try {
            // DDL (CREATE TABLE) causes an implicit commit in MySQL, so we
            // cannot wrap install() in a transaction — run steps sequentially.
            $addon->install($pdo);
            $stmt = $pdo->prepare('INSERT INTO `lstr_addons` (`addon_key`) VALUES (:k)');
            $stmt->execute(['k' => $key]);
        } catch (Throwable $e) {
            $session->set('lstr_flash_error', 'Install failed: ' . $e->getMessage());
            return Response::redirect($redirect);
        }

        $activityRepo = new ActivityRepo($this->app->getDatabase());
        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'addon.install',
            'Installed add-on: ' . $addon->getName() . ' (' . $key . ')',
            $request->ip(),
        );

        $session->set('lstr_flash_success', '"' . $addon->getName() . '" installed successfully.');
        return Response::redirect($redirect);
    }

    // -------------------------------------------------------------------------

    public function uninstall(Request $request, array $params): Response
    {
        $key      = trim((string) $request->post('addon_key'));
        $baseUrl  = $this->app->getBaseUrl();
        $session  = $this->app->getSession();
        $redirect = $baseUrl . '/admin/addons';

        if ($key === '') {
            $session->set('lstr_flash_error', 'No add-on key provided.');
            return Response::redirect($redirect);
        }

        $discovered = $this->app->discoverAddons();

        if (!isset($discovered[$key])) {
            $session->set('lstr_flash_error', 'Unknown add-on: ' . $key);
            return Response::redirect($redirect);
        }

        if (!$this->app->isAddonInstalled($key)) {
            $session->set('lstr_flash_error', 'Add-on is not installed.');
            return Response::redirect($redirect);
        }

        $addon = $discovered[$key];
        $pdo   = $this->app->getDatabase()->pdo();

        try {
            // DDL (DROP TABLE) causes an implicit commit in MySQL, so we
            // cannot wrap uninstall() in a transaction — run steps sequentially.
            $addon->uninstall($pdo);
            $stmt = $pdo->prepare('DELETE FROM `lstr_addons` WHERE `addon_key` = :k');
            $stmt->execute(['k' => $key]);
        } catch (Throwable $e) {
            $session->set('lstr_flash_error', 'Uninstall failed: ' . $e->getMessage());
            return Response::redirect($redirect);
        }

        $activityRepo = new ActivityRepo($this->app->getDatabase());
        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'addon.uninstall',
            'Uninstalled add-on: ' . $addon->getName() . ' (' . $key . ')',
            $request->ip(),
        );

        $session->set('lstr_flash_success', '"' . $addon->getName() . '" uninstalled successfully.');
        return Response::redirect($redirect);
    }
}
