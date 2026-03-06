<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\AffiliateRepo;
use Luster\Repositories\CategoryRepo;
use Luster\Repositories\FanlistingRepo;
use Luster\Repositories\FanlistingSettingsRepo;
use Luster\Repositories\MemberRepo;

/**
 * Manages the fanlisting list and per-fanlisting settings.
 *
 * Fanlistings are created/deleted in Enthusiast; Luster only edits
 * metadata (name, URL, email, status) and Luster-specific settings
 * (auto_approve). Requires migrate.php to have been run for auto_approve.
 */
final class FanlistingController
{
    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /admin/fanlistings
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $fanlistingRepo = new FanlistingRepo($db, $config);
        $memberRepo     = new MemberRepo($db, $config);
        $settingsRepo   = new FanlistingSettingsRepo($db);

        $fanlistings = $fanlistingRepo->getAll();

        $memberCounts  = [];
        $pendingCounts = [];
        $images        = [];
        foreach ($fanlistings as $fl) {
            $memberCounts[$fl->id]  = $memberRepo->countApproved($fl->dbprefix);
            $pendingCounts[$fl->id] = $memberRepo->countPending($fl->dbprefix);
            $images[$fl->id]        = $settingsRepo->getImage($fl->id);
        }

        return $this->layout('admin/fanlistings/index.php', [
            'fanlistings'    => $fanlistings,
            'member_counts'  => $memberCounts,
            'pending_counts' => $pendingCounts,
            'images'         => $images,
            'cfg'            => $config->all(),
        ], 'Fanlistings', 'fanlistings');
    }

    // -----------------------------------------------------------------------
    // GET /admin/fanlistings/{id}
    // -----------------------------------------------------------------------

    public function show(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        return Response::redirect($this->app->getBaseUrl() . '/admin/fanlistings/' . $id . '/edit');

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $fanlistingRepo  = new FanlistingRepo($db, $config);
        $memberRepo      = new MemberRepo($db, $config);
        $settingsRepo    = new FanlistingSettingsRepo($db);
        $affiliateRepo   = new AffiliateRepo($db);

        $fanlisting = $fanlistingRepo->getById($id);

        if ($fanlisting === null) {
            return Response::notFound('Fanlisting not found.');
        }

        $approved    = $memberRepo->countApproved($fanlisting->dbprefix);
        $pending     = $memberRepo->countPending($fanlisting->dbprefix);
        $autoApprove = $settingsRepo->getAutoApprove($id);
        $affiliates  = $affiliateRepo->getByFanlisting($id);
        $templates   = $settingsRepo->getTemplates($id);

        $categoryRepo        = new CategoryRepo($db);
        $categories          = $categoryRepo->getAll();
        $currentCategoryId   = $fanlisting->categoryId;
        $currentImage        = $settingsRepo->getImage($id);

        return $this->layout('admin/fanlistings/show.php', [
            'fanlisting'          => $fanlisting,
            'approved'            => $approved,
            'pending'             => $pending,
            'auto_approve'        => $autoApprove,
            'affiliates'          => $affiliates,
            'stats_template'      => $templates['stats_template'],
            'members_template'    => $templates['members_template'],
            'affiliates_template' => $templates['affiliates_template'],
            'categories'          => $categories,
            'current_category_id' => $currentCategoryId,
            'current_image'       => $currentImage,
            'cfg'                 => $config->all(),
        ], $fanlisting->name, 'fanlistings');
    }

    // -----------------------------------------------------------------------
    // GET + POST /admin/fanlistings/{id}/edit
    // -----------------------------------------------------------------------

    public function edit(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $fanlistingRepo = new FanlistingRepo($db, $config);
        $settingsRepo   = new FanlistingSettingsRepo($db);
        $activityRepo   = new ActivityRepo($db);
        $memberRepo     = new MemberRepo($db, $config);
        $affiliateRepo  = new AffiliateRepo($db);
        $categoryRepo   = new CategoryRepo($db);

        $fanlisting = $fanlistingRepo->getById($id);

        if ($fanlisting === null) {
            return Response::notFound('Fanlisting not found.');
        }

        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        // ---- POST: save ----
        if ($request->isPost()) {
            $title       = trim((string) $request->post('title', ''));
            $url         = trim((string) $request->post('url', ''));
            $email       = trim((string) $request->post('email', ''));
            $status      = (string) $request->post('status', 'active');
            $autoApprove = $request->post('auto_approve') === '1';
            $deleteImage = $request->post('delete_image') === '1';

            $allowedTypes = ['fanlisting', 'clique', 'collective', 'shrine', 'webring', 'other'];
            $listingType  = (string) ($request->post('listing_type', 'fanlisting'));
            if (!in_array($listingType, $allowedTypes, true)) {
                $listingType = 'fanlisting';
            }

            $membersPerPage = max(5, min(200, (int) $request->post('members_per_page', 25)));

            // --- Image upload validation (before moving any file) ---
            $imageError  = '';
            $newFilename = null;
            $tmpPath     = '';

            $uploadErr = (int) ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE);

            if (!$deleteImage && $uploadErr !== UPLOAD_ERR_NO_FILE) {
                if ($uploadErr !== UPLOAD_ERR_OK) {
                    $imageError = 'Image upload failed (error code ' . $uploadErr . ').';
                } else {
                    $tmpPath  = (string) ($_FILES['image']['tmp_name'] ?? '');
                    $fileSize = (int) ($_FILES['image']['size']     ?? 0);

                    if ($fileSize > 2 * 1024 * 1024) {
                        $imageError = 'Image must be 2 MB or smaller.';
                    } else {
                        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                        $finfo        = new \finfo(FILEINFO_MIME_TYPE);
                        $mime         = $finfo->file($tmpPath);

                        if ($mime === false || !in_array($mime, $allowedMimes, true)) {
                            $imageError = 'Image must be JPEG, PNG, GIF, or WebP.';
                        } else {
                            $extMap = [
                                'image/jpeg' => 'jpg',
                                'image/png'  => 'png',
                                'image/gif'  => 'gif',
                                'image/webp' => 'webp',
                            ];
                            $newFilename = uniqid('fl_', true) . '.' . $extMap[$mime];
                        }
                    }
                }
            }

            // --- Field validation ---
            $v = new Validator([
                'title' => $title,
                'url'   => $url,
                'email' => $email,
            ]);
            $v->field('title')->required()->maxLength(255);
            $v->field('url')->required()->url()->maxLength(500);
            $v->field('email')->required()->email()->maxLength(255);

            if ($imageError !== '' || $v->fails()) {
                $messages = $v->fails() ? $v->allErrors() : [];
                if ($imageError !== '') {
                    array_unshift($messages, $imageError);
                }

                return $this->layout('admin/fanlistings/edit.php', [
                    'fanlisting'          => $fanlisting,
                    'auto_approve'        => $autoApprove,
                    'error'               => implode(' ', $messages),
                    'old'                 => ['title' => $title, 'url' => $url, 'email' => $email, 'status' => $status],
                    'current_image'       => $settingsRepo->getImage($id),
                    'cfg'                 => $config->all(),
                    'approved'            => $memberRepo->countApproved($fanlisting->dbprefix),
                    'pending'             => $memberRepo->countPending($fanlisting->dbprefix),
                    'affiliates'          => $affiliateRepo->getByFanlisting($id),
                    'templates'           => $settingsRepo->getTemplates($id),
                    'categories'          => $categoryRepo->getAll(),
                    'current_category_id' => $fanlisting->categoryId ?? null,
                    'listing_type'        => $listingType,
                    'members_per_page'    => $membersPerPage,
                ], 'Edit ' . $fanlisting->name, 'fanlistings');
            }

            // --- All valid: persist ---
            $upcoming = ($status === 'upcoming');
            $trouble  = ($status === 'trouble');

            $fanlistingRepo->updateMeta($id, $title, $url, $email, $upcoming, $trouble);
            $settingsRepo->setAutoApprove($id, $autoApprove);
            $settingsRepo->saveListingType($id, $listingType);
            $settingsRepo->saveMembersPerPage($id, $membersPerPage);

            if ($deleteImage) {
                $oldFilename = $settingsRepo->getImage($id);
                if ($oldFilename !== null && $oldFilename !== '') {
                    $uploadDir = $this->ensureUploadDirectory();
                    $oldPath   = $uploadDir . '/' . $oldFilename;
                    if (is_file($oldPath)) {
                        unlink($oldPath);
                    }
                    $settingsRepo->saveImage($id, '');
                }
            } elseif ($newFilename !== null) {
                $uploadDir = $this->ensureUploadDirectory();
                $destPath  = $uploadDir . '/' . $newFilename;

                if (!move_uploaded_file($tmpPath, $destPath)) {
                    throw new \RuntimeException("Failed to move uploaded image to {$destPath}.");
                }

                $oldFilename = $settingsRepo->getImage($id);
                if ($oldFilename !== null && $oldFilename !== '') {
                    $oldPath = $uploadDir . '/' . $oldFilename;
                    if (is_file($oldPath)) {
                        unlink($oldPath);
                    }
                }

                $settingsRepo->saveImage($id, $newFilename);
            }

            $activityRepo->log(
                (int) $session->get('lstr_admin_id'),
                'fanlisting.edit',
                "Edited fanlisting #{$id} ({$title})",
                $request->ip(),
            );

            $session->set('lstr_flash_success', 'Fanlisting updated successfully.');
            return Response::redirect($baseUrl . '/admin/fanlistings/' . $id);
        }

        // ---- GET: show form ----
        $autoApprove        = $settingsRepo->getAutoApprove($id);
        $currentImage       = $settingsRepo->getImage($id);
        $listingType        = $settingsRepo->getListingType($id);
        $membersPerPage     = $settingsRepo->getMembersPerPage($id);
        $approved           = $memberRepo->countApproved($fanlisting->dbprefix);
        $pending            = $memberRepo->countPending($fanlisting->dbprefix);
        $affiliates         = $affiliateRepo->getByFanlisting($id);
        $templates          = $settingsRepo->getTemplates($id);
        $categories         = $categoryRepo->getAll();
        $currentCategoryId  = $fanlisting->categoryId ?? null;
        $error              = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_error');

        return $this->layout('admin/fanlistings/edit.php', [
            'fanlisting'          => $fanlisting,
            'auto_approve'        => $autoApprove,
            'error'               => $error,
            'old'                 => [],
            'current_image'       => $currentImage,
            'cfg'                 => $config->all(),
            'approved'            => $approved,
            'pending'             => $pending,
            'affiliates'          => $affiliates,
            'templates'           => $templates,
            'categories'          => $categories,
            'current_category_id' => $currentCategoryId,
            'listing_type'        => $listingType,
            'members_per_page'    => $membersPerPage,
        ], 'Edit ' . $fanlisting->name, 'fanlistings');
    }

    // -----------------------------------------------------------------------
    // POST /admin/fanlistings/{id}/delete
    // -----------------------------------------------------------------------

    public function delete(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $fanlistingRepo = new FanlistingRepo($db, $config);
        $settingsRepo   = new FanlistingSettingsRepo($db);
        $activityRepo   = new ActivityRepo($db);

        $fanlisting = $fanlistingRepo->getById($id);

        if ($fanlisting === null) {
            return Response::notFound('Fanlisting not found.');
        }

        $prefix = $fanlisting->dbprefix;

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) {
            throw new \RuntimeException(
                "Invalid dbprefix '{$prefix}': only [a-zA-Z0-9_] allowed."
            );
        }

        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();
        $name    = $fanlisting->name;

        // 1. Drop the member table
        $db->prepare("DROP TABLE IF EXISTS `{$prefix}`")->execute();

        // 2. Delete Luster rows that reference this fanlisting
        $db->prepare('DELETE FROM lstr_fanlisting_settings WHERE fanlisting_id = :id')
           ->execute(['id' => $id]);

        $db->prepare('DELETE FROM lstr_affiliates WHERE fanlisting_id = :id')
           ->execute(['id' => $id]);

        // 3. Delete the image file if one exists
        $imageFilename = $settingsRepo->getImage($id);
        if ($imageFilename !== null) {
            $imagePath = $this->ensureUploadDirectory() . '/' . $imageFilename;
            if (is_file($imagePath)) {
                unlink($imagePath);
            }
        }

        // 4. Delete the fanlisting registry row
        $fanlistingRepo->delete($id);

        // 5. Log (fanlisting row is gone but session identity is intact)
        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'fanlisting.delete',
            "Deleted fanlisting #{$id} ({$name})",
            $request->ip(),
        );

        $session->set('lstr_flash_success', "Fanlisting \"{$name}\" deleted successfully.");
        return Response::redirect($baseUrl . '/admin/fanlistings');
    }

    // -----------------------------------------------------------------------
    // GET + POST /admin/fanlistings/{id}/edit-prefix
    // -----------------------------------------------------------------------

    public function editPrefix(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $fanlistingRepo = new FanlistingRepo($db, $config);
        $activityRepo   = new ActivityRepo($db);

        $fanlisting = $fanlistingRepo->getById($id);

        if ($fanlisting === null) {
            return Response::notFound('Fanlisting not found.');
        }

        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        // Check whether the current member table exists
        $tableExistsStmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :name'
        );
        $tableExistsStmt->execute(['name' => $fanlisting->dbprefix]);
        $tableExists = (int) $tableExistsStmt->fetchColumn() > 0;

        // ---- POST: save ----
        if ($request->isPost()) {
            $newPrefix = trim((string) $request->post('new_prefix', ''));
            $confirm   = trim((string) $request->post('confirm_prefix', ''));
            $error     = '';

            if ($newPrefix === '') {
                $error = 'New prefix cannot be empty.';
            } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $newPrefix)) {
                $error = 'Prefix may only contain letters, numbers, and underscores.';
            } elseif ($newPrefix === $fanlisting->dbprefix) {
                $error = 'New prefix is the same as the current prefix — nothing to change.';
            } elseif ($confirm !== $newPrefix) {
                $error = 'New prefix confirmation does not match.';
            }

            if ($error === '') {
                // Check $newPrefix is not already an existing table
                $checkTableStmt = $db->prepare(
                    'SELECT COUNT(*) FROM information_schema.tables
                     WHERE table_schema = DATABASE() AND table_name = :name'
                );
                $checkTableStmt->execute(['name' => $newPrefix]);
                if ((int) $checkTableStmt->fetchColumn() > 0) {
                    $error = "A table named \"{$newPrefix}\" already exists in the database.";
                }
            }

            if ($error === '') {
                // Check $newPrefix is not already registered to another fanlisting
                $isOwned      = ((string) $config->get('enth_table_prefix', '') === '');
                $regTable     = $isOwned ? 'owned'          : 'enth_fanlistings';
                $regPkCol     = $isOwned ? 'listingid'      : 'id';
                $regPrefixCol = $isOwned ? 'dbtable'        : 'dbprefix';

                $checkRegStmt = $db->prepare(
                    "SELECT COUNT(*) FROM `{$regTable}`
                     WHERE `{$regPrefixCol}` = :new AND `{$regPkCol}` != :id"
                );
                $checkRegStmt->execute(['new' => $newPrefix, 'id' => $id]);
                if ((int) $checkRegStmt->fetchColumn() > 0) {
                    $error = "The prefix \"{$newPrefix}\" is already in use by another fanlisting.";
                }
            }

            if ($error !== '') {
                return $this->layout('admin/fanlistings/edit-prefix.php', [
                    'fanlisting'   => $fanlisting,
                    'table_exists' => $tableExists,
                    'error'        => $error,
                ], 'Change Table Prefix — ' . $fanlisting->name, 'fanlistings');
            }

            $oldPrefix = $fanlisting->dbprefix;
            $fanlistingRepo->updateDbPrefix($id, $oldPrefix, $newPrefix);

            $activityRepo->log(
                (int) $session->get('lstr_admin_id'),
                'fanlisting.prefix_changed',
                "Changed table prefix for fanlisting #{$id}: {$oldPrefix} → {$newPrefix}",
                $request->ip(),
            );

            $session->set('lstr_flash_success', "Table prefix changed from \"{$oldPrefix}\" to \"{$newPrefix}\" successfully.");
            return Response::redirect($baseUrl . '/admin/fanlistings/' . $id . '/edit');
        }

        // ---- GET: show form ----
        return $this->layout('admin/fanlistings/edit-prefix.php', [
            'fanlisting'   => $fanlisting,
            'table_exists' => $tableExists,
            'error'        => '',
        ], 'Change Table Prefix — ' . $fanlisting->name, 'fanlistings');
    }

    // -----------------------------------------------------------------------
    // Ensures public/uploads/fanlistings/ exists with an .htaccess guard
    // -----------------------------------------------------------------------

    private function ensureUploadDirectory(): string
    {
        $uploadDir = dirname(__DIR__, 3) . '/public/uploads/fanlistings';

        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                throw new \RuntimeException("Failed to create upload directory: {$uploadDir}");
            }
        }

        $htaccess = $uploadDir . '/.htaccess';
        if (!is_file($htaccess)) {
            $content = "<FilesMatch \"\\.ph(p[0-9]?|tml)\$\">\n    Deny from all\n</FilesMatch>\n";
            if (file_put_contents($htaccess, $content) === false) {
                throw new \RuntimeException("Failed to write .htaccess in {$uploadDir}");
            }
        }

        return $uploadDir;
    }

    // -----------------------------------------------------------------------
    // POST /admin/fanlistings/{id}/templates
    // -----------------------------------------------------------------------

    public function saveTemplates(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $fanlistingRepo = new FanlistingRepo($db, $config);
        $fanlisting     = $fanlistingRepo->getById($id);

        if ($fanlisting === null) {
            return Response::notFound('Fanlisting not found.');
        }

        $statsRaw      = (string) $request->post('stats_template',      '');
        $membersRaw    = (string) $request->post('members_template',    '');
        $affiliatesRaw = (string) $request->post('affiliates_template', '');

        // Treat blank / whitespace-only input as null (use built-in default)
        $statsTemplate      = trim($statsRaw)      !== '' ? $statsRaw      : null;
        $membersTemplate    = trim($membersRaw)    !== '' ? $membersRaw    : null;
        $affiliatesTemplate = trim($affiliatesRaw) !== '' ? $affiliatesRaw : null;

        $settingsRepo = new FanlistingSettingsRepo($db);
        $settingsRepo->saveTemplates($id, $statsTemplate, $membersTemplate, $affiliatesTemplate);

        $this->app->getSession()->set('lstr_flash_success', 'Templates saved successfully.');

        return Response::redirect($this->app->getBaseUrl() . '/admin/fanlistings/' . $id);
    }

    // -----------------------------------------------------------------------
    // POST /admin/fanlistings/{id}/update
    // -----------------------------------------------------------------------

    public function update(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $fanlistingRepo = new FanlistingRepo($db, $config);
        $fanlisting     = $fanlistingRepo->getById($id);

        if ($fanlisting === null) {
            return Response::notFound('Fanlisting not found.');
        }

        $rawCategoryId = (int) $request->post('category_id', 0);
        $categoryId    = $rawCategoryId > 0 ? $rawCategoryId : null;

        $fanlistingRepo->update($id, $categoryId);

        $this->app->getSession()->set('lstr_flash_success', 'Category updated.');

        return Response::redirect($this->app->getBaseUrl() . '/admin/fanlistings/' . $id);
    }

    // -----------------------------------------------------------------------
    // Shared render helper
    // -----------------------------------------------------------------------

    private function layout(string $template, array $data, string $pageTitle, string $section): Response
    {
        $view      = $this->app->getView();
        $config    = $this->app->getConfig();
        $session   = $this->app->getSession();
        $baseUrl   = $this->app->getBaseUrl();
        $csrfToken = $this->app->getCSRF()->token();

        $flash = (string) $session->get('lstr_flash_success', '');
        $session->remove('lstr_flash_success');

        $inner = $view->render($template, array_merge($data, [
            'base_url'   => $baseUrl,
            'success'    => $flash,
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
