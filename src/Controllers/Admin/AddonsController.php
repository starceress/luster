<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Manages the add-on system from the admin panel. Discovers add-on packages under
 src/Addons/, displays their install status, and handles one-click install and
 uninstall actions. Each add-on implements AddonInterface and manages its own
 database tables during install/uninstall.
*/

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
 * Manages the add-on system from the admin panel.
 *
 * Discovers add-on packages under src/Addons/, displays their install/uninstall
 * state, and handles one-click install and uninstall. Each add-on implements
 * AddonInterface and owns its own database tables. Installing adds a registry row
 * to lstr_addons and calls AddonInterface::install(), which runs DDL statements.
 * Uninstalling removes only the registry row — data tables are preserved so
 * reinstalling recovers existing data without re-running the full install.
 */
final class AddonsController
{
    public function __construct(private readonly Application $app) {}

    // -------------------------------------------------------------------------

    /**
     * Renders the Add-ons management page with a card per discovered add-on.
     *
     * Each card shows the add-on name, description, install status, and the
     * appropriate Install or Disable button.
     */
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
            'site_name'       => (string) $config->get('site_name', 'Luster'),
            'admin_username'  => (string) $session->get('lstr_admin_username', ''),
            'csrf_token'      => $this->app->getCSRF()->token(),
            'base_url'        => $baseUrl,
            'discovered'      => $discovered,
            'installed'       => $installed,
            'success'         => $success,
            'error'           => $error,
        ]);

        return Response::html($view->render('admin/layout.php', array_merge([
            'page_title'      => 'Add-ons',
            'site_name'       => (string) $config->get('site_name', 'Luster'),
            'admin_username'  => (string) $session->get('lstr_admin_username', ''),
            'csrf_token'      => $this->app->getCSRF()->token(),
            'current_section' => 'addons',
            'base_url'        => $baseUrl,
            'content'         => $inner,
        ], $this->app->getAdminNavData())));
    }

    // -------------------------------------------------------------------------

    /**
     * Installs an add-on by calling its install() method and registering it in lstr_addons.
     *
     * DDL statements inside install() cause implicit commits in MySQL, so the steps
     * are run sequentially outside a transaction rather than atomically. If install()
     * throws, the error is surfaced as a flash message and the add-on is not registered.
     */
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

    /**
     * Disables an add-on by removing its row from lstr_addons without dropping data tables.
     *
     * The add-on's nav link disappears immediately. Reinstalling later re-registers
     * the add-on and restores access to all previously stored data.
     */
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
            // Only deregister from lstr_addons — data tables are preserved so
            // reinstalling the add-on recovers all existing data.
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
            'Disabled add-on: ' . $addon->getName() . ' (' . $key . ')',
            $request->ip(),
        );

        $session->set('lstr_flash_success', '"' . $addon->getName() . '" disabled. Data is preserved — re-enable to restore access.');
        return Response::redirect($redirect);
    }
}
