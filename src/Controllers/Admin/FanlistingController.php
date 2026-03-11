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
use Luster\Repositories\FieldRepo;
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

        $categoryRepo  = new CategoryRepo($db);

        $cfgAll    = $config->all();
        $ownedSort = (string) ($cfgAll['owned_sort'] ?? 'id');

        $search        = trim((string) ($_GET['search']   ?? ''));
        $filterNetwork = trim((string) ($_GET['network']  ?? ''));
        $filterCat     = trim((string) ($_GET['category'] ?? ''));

        // Text search via SQL; network/category filtering done in PHP below.
        $fanlistings = $search !== ''
            ? $fanlistingRepo->search($search)
            : $fanlistingRepo->getAll();

        // Network filter — keep only fanlistings whose catid pipe-string contains
        // at least one ID belonging to the selected network source.
        if ($filterNetwork !== '') {
            $netCats   = $categoryRepo->getBySource($filterNetwork);
            $netCatIds = array_column($netCats, 'id');
            $fanlistings = array_values(array_filter(
                $fanlistings,
                static function ($fl) use ($netCatIds): bool {
                    $catid = (string) ($fl->catid ?? '');
                    foreach ($netCatIds as $cid) {
                        if (str_contains($catid, '|' . $cid . '|')) {
                            return true;
                        }
                    }
                    return false;
                }
            ));
        }

        // Category filter — narrow to a specific category.
        // Works with or without a network selected.
        if ($filterCat !== '') {
            if ($filterNetwork !== '') {
                // Scoped to the selected network — find the single matching ID.
                $cat    = $categoryRepo->findBySourceAndName($filterNetwork, $filterCat);
                $catIds = $cat !== null ? [$cat->id] : [];
            } else {
                // No network selected — match the category name across all sources.
                $catIds = $categoryRepo->findIdsByName($filterCat);
            }

            if (!empty($catIds)) {
                $fanlistings = array_values(array_filter(
                    $fanlistings,
                    static function ($fl) use ($catIds): bool {
                        $catid = (string) ($fl->catid ?? '');
                        foreach ($catIds as $cid) {
                            if (str_contains($catid, '|' . $cid . '|')) {
                                return true;
                            }
                        }
                        return false;
                    }
                ));
            } else {
                $fanlistings = [];
            }
        }

        $memberCounts  = [];
        $pendingCounts = [];
        $images        = [];
        $listingTypes  = [];
        $categoryNames = [];
        foreach ($fanlistings as $fl) {
            $memberCounts[$fl->id]  = $fl->dbprefix !== '' ? $memberRepo->countApproved($fl->dbprefix) : 0;
            $pendingCounts[$fl->id] = $fl->dbprefix !== '' ? $memberRepo->countPending($fl->dbprefix) : 0;
            $images[$fl->id]        = $settingsRepo->getImage($fl->id);
            $listingTypes[$fl->id]  = $settingsRepo->getListingType($fl->id);
            $categoryNames[$fl->id] = $fanlistingRepo->getCatNames((string) ($fl->catid ?? ''), $fl->categoryId);
        }

        $filterType  = trim((string) ($_GET['type'] ?? ''));
        $typeOptions = array_unique(array_values($listingTypes));
        sort($typeOptions);
        if ($filterType !== '') {
            $fanlistings = array_values(array_filter($fanlistings, fn($fl) => ($listingTypes[$fl->id] ?? 'fanlisting') === $filterType));
        }

        // Apply configured sort order
        if ($ownedSort === 'subject') {
            usort($fanlistings, fn($a, $b) => strcasecmp((string) ($a->subject ?? ''), (string) ($b->subject ?? '')));
        } elseif ($ownedSort === 'category') {
            usort($fanlistings, fn($a, $b) => strcasecmp($categoryNames[$a->id] ?? '', $categoryNames[$b->id] ?? ''));
        } elseif ($ownedSort === 'status') {
            usort($fanlistings, static function ($a, $b): int {
                $rank = static fn($fl): int => $fl->trouble ? 2 : ($fl->upcoming ? 1 : 0);
                return $rank($a) <=> $rank($b);
            });
        }
        // 'id' is already sorted numerically by SQL

        $perPage    = max(5, min(200, (int) ($cfgAll['owned_per_page'] ?? 10)));
        $totalItems = count($fanlistings);
        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $page       = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
        $fanlistings = array_slice($fanlistings, ($page - 1) * $perPage, $perPage);

        $customNetworks = $categoryRepo->getCustomSources();
        $customCats     = [];
        foreach ($customNetworks as $cn) {
            $customCats[$cn] = $categoryRepo->getBySource($cn);
        }

        return $this->layout('admin/fanlistings/index.php', [
            'fanlistings'     => $fanlistings,
            'member_counts'   => $memberCounts,
            'pending_counts'  => $pendingCounts,
            'images'          => $images,
            'listing_types'   => $listingTypes,
            'category_names'  => $categoryNames,
            'cfg'             => $config->all(),
            'search'          => $search,
            'filter_network'  => $filterNetwork,
            'filter_cat'      => $filterCat,
            'filter_type'     => $filterType,
            'type_options'    => $typeOptions,
            'tfl_cats'        => $categoryRepo->getBySource('tfl'),
            'tafl_cats'       => $categoryRepo->getBySource('tafl'),
            'custom_networks' => $customNetworks,
            'custom_cats'     => $customCats,
            'page'            => $page,
            'total_pages'     => $totalPages,
        ], 'Owned Listings', 'fanlistings');
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
    // GET /admin/fanlistings/add
    // -----------------------------------------------------------------------

    public function addForm(Request $request, array $params): Response
    {
        $db           = $this->app->getDatabase();
        $categoryRepo = new CategoryRepo($db);

        $tflCats        = $categoryRepo->getBySource('tfl');
        $taflCats       = $categoryRepo->getBySource('tafl');
        $customNetworks = $categoryRepo->getCustomSources();

        return $this->layout('admin/fanlistings/add.php', [
            'tfl_cats'       => $tflCats,
            'tafl_cats'      => $taflCats,
            'custom_networks' => $customNetworks,
            'old'            => [],
            'error'          => '',
            'default_date'   => date('Y-m-d'),
        ], 'Add Listing', 'fanlistings');
    }

    // -----------------------------------------------------------------------
    // POST /admin/fanlistings/add
    // -----------------------------------------------------------------------

    public function add(Request $request, array $params): Response
    {
        $db             = $this->app->getDatabase();
        $config         = $this->app->getConfig();
        $fanlistingRepo = new FanlistingRepo($db, $config);
        $categoryRepo   = new CategoryRepo($db);

        $title    = trim((string) $request->post('title', ''));
        $subject  = trim((string) $request->post('subject', ''));
        $url      = trim((string) $request->post('url', ''));
        $email    = trim((string) $request->post('email', ''));
        $statusRaw = (int) $request->post('status', 2);
        $status   = in_array($statusRaw, [0, 1, 2], true) ? $statusRaw : 2;
        $opened   = trim((string) $request->post('opened', '')) ?: date('Y-m-d');

        $tflCats        = $categoryRepo->getBySource('tfl');
        $taflCats       = $categoryRepo->getBySource('tafl');
        $customNetworks = $categoryRepo->getCustomSources();

        $networks = array_values((array) ($_POST['cat_network'] ?? []));
        $names    = array_values((array) ($_POST['cat_name'] ?? []));
        $others   = array_values((array) ($_POST['cat_network_other'] ?? []));
        $catIds   = [];
        foreach ($networks as $i => $rawNet) {
            $net = trim((string) $rawNet);
            if ($net === 'other') $net = trim((string) ($others[$i] ?? ''));
            $n = trim((string) ($names[$i] ?? ''));
            if ($net === '' || $n === '') continue;
            $catIds[] = $categoryRepo->findOrCreate($net, $n);
        }
        $catidStr = $catIds ? '|' . implode('|', $catIds) . '|' : '';

        if ($subject === '' || $url === '') {
            return $this->layout('admin/fanlistings/add.php', [
                'tfl_cats'        => $tflCats,
                'tafl_cats'       => $taflCats,
                'custom_networks' => $customNetworks,
                'old'             => $request->post(),
                'error'           => 'Subject and URL are required.',
                'default_date'    => date('Y-m-d'),
            ], 'Add Listing', 'fanlistings');
        }

        $image     = null;
        $uploadErr = (int) ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadErr === UPLOAD_ERR_OK) {
            $tmp      = (string) ($_FILES['image']['tmp_name'] ?? '');
            $fileSize = (int) ($_FILES['image']['size'] ?? 0);
            $mime     = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
            $allowed  = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

            if ($fileSize > 2 * 1024 * 1024) {
                return $this->layout('admin/fanlistings/add.php', [
                    'tfl_cats'        => $tflCats,
                    'tafl_cats'       => $taflCats,
                    'custom_networks' => $customNetworks,
                    'old'             => $request->post(),
                    'error'           => 'Image must be 2 MB or smaller.',
                    'default_date'    => date('Y-m-d'),
                ], 'Add Listing', 'fanlistings');
            }

            if (!in_array($mime, $allowed, true)) {
                return $this->layout('admin/fanlistings/add.php', [
                    'tfl_cats'        => $tflCats,
                    'tafl_cats'       => $taflCats,
                    'custom_networks' => $customNetworks,
                    'old'             => $request->post(),
                    'error'           => 'Invalid image type.',
                    'default_date'    => date('Y-m-d'),
                ], 'Add Listing', 'fanlistings');
            }
            $ext      = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mime];
            $dir      = dirname(__DIR__, 3) . '/public/uploads/fanlistings/';
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $filename = 'fl_' . bin2hex(random_bytes(8)) . '.' . $ext;
            move_uploaded_file($tmp, $dir . $filename);
            $image    = $filename;
        }

        $fanlistingRepo->insert($title, $subject, $url, $email, $status, $image, null, $opened, $catidStr);

        return Response::redirect($this->app->getBaseUrl() . '/admin/fanlistings');
    }

    public function bulk(Request $request, array $params): Response
    {
        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $action   = (string) $request->post('action', '');
        $selected = array_filter(array_map('intval', (array) ($_POST['selected'] ?? [])));

        if ($action === 'delete' && !empty($selected)) {
            $fanlistingRepo = new FanlistingRepo($db, $config);
            $settingsRepo   = new FanlistingSettingsRepo($db);
            $activityRepo   = new ActivityRepo($db);
            $deleted        = 0;

            foreach ($selected as $id) {
                $fanlisting = $fanlistingRepo->getById($id);
                if ($fanlisting === null) {
                    continue;
                }

                $prefix = $fanlisting->dbprefix;

                if ($prefix !== '' && preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) {
                    $db->prepare("DROP TABLE IF EXISTS `{$prefix}`")->execute();
                }

                $db->prepare('DELETE FROM lstr_fanlisting_settings WHERE fanlisting_id = :id')
                   ->execute(['id' => $id]);
                $db->prepare('DELETE FROM lstr_affiliates WHERE fanlisting_id = :id')
                   ->execute(['id' => $id]);

                $imageFilename = $settingsRepo->getImage($id);
                if ($imageFilename !== null) {
                    $imagePath = $this->ensureUploadDirectory() . '/' . $imageFilename;
                    if (is_file($imagePath)) {
                        unlink($imagePath);
                    }
                }

                $fanlistingRepo->delete($id);

                $activityRepo->log(
                    (int) $session->get('lstr_admin_id'),
                    'fanlisting.delete',
                    "Deleted fanlisting #{$id} ({$fanlisting->name}) via bulk action",
                    $request->ip(),
                );

                $deleted++;
            }

            $session->set('lstr_flash_success', "Deleted {$deleted} fanlisting(s).");
        }

        return Response::redirect($baseUrl . '/admin/fanlistings');
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
        $fieldRepo      = new FieldRepo($db, $config);

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
            $status      = (string) $request->post('status', 'current');
            $autoApprove = $request->post('auto_approve') === '1';
            $subject     = trim((string) $request->post('subject', ''));
            $opened      = trim((string) $request->post('opened', ''));
            $nets        = array_values((array) ($_POST['cat_network'] ?? []));
            $nms         = array_values((array) ($_POST['cat_name']    ?? []));
            $catids      = [];
            foreach ($nets as $i => $net) {
                $nm = trim((string) ($nms[$i] ?? ''));
                if ($nm === '' || $net === '') continue;
                $cat = $categoryRepo->findBySourceAndName($net, $nm);
                if ($cat) $catids[] = $cat->id;
            }
            $catidStr    = $catids ? '|' . implode('|', $catids) . '|' : '';
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
                    'affiliates'          => $affiliateRepo->getByFanlisting($id)['items'],
                    'fields'              => $fieldRepo->getFieldsForFanlisting($id),
                    'templates'           => $settingsRepo->getTemplates($id),
                    'categories'          => $categoryRepo->getAll(),
                    'current_category_id' => $fanlisting->categoryId ?? null,
                    'listing_type'        => $listingType,
                    'members_per_page'    => $membersPerPage,
                ], 'Edit ' . $fanlisting->name, 'fanlistings');
            }

            // --- All valid: persist ---
            $upcoming = ($status === 'upcoming');
            $trouble  = ($status === 'pending');

            $fanlistingRepo->updateMeta($id, $title, $url, $email, $upcoming, $trouble);
            $fanlistingRepo->update($id, !empty($catids) ? $catids[0] : null, $subject, $opened, $catidStr);
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
        $embedSettings      = $settingsRepo->getEmbedSettings($id);
        $approved           = $fanlisting->dbprefix !== '' ? $memberRepo->countApproved($fanlisting->dbprefix) : 0;
        $pending            = $fanlisting->dbprefix !== '' ? $memberRepo->countPending($fanlisting->dbprefix) : 0;
        $countries          = $fanlisting->dbprefix !== '' ? $memberRepo->countUniqueCountries($fanlisting->dbprefix) : 0;
        $lastUpdated        = $fanlisting->dbprefix !== '' ? $memberRepo->getLastUpdated($fanlisting->dbprefix) : null;
        $tflCats            = $categoryRepo->getBySource('tfl');
        $taflCats           = $categoryRepo->getBySource('tafl');
        $customNetworks     = $categoryRepo->getCustomSources();
        $customCats         = [];
        foreach ($customNetworks as $cn) {
            $customCats[$cn] = $categoryRepo->getBySource($cn);
        }
        $affiliates         = $affiliateRepo->getByFanlisting($id)['items'];
        $fields             = $fieldRepo->getFieldsForFanlisting($id);
        $templates          = $settingsRepo->getTemplates($id);
        $categories         = $categoryRepo->getAll();
        $currentCategoryId  = $fanlisting->categoryId ?? null;
        $success            = (string) $session->get('lstr_flash_success', '');
        $error              = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->layout('admin/fanlistings/edit.php', [
            'fanlisting'          => $fanlisting,
            'auto_approve'        => $autoApprove,
            'success'             => $success,
            'error'               => $error,
            'old'                 => [],
            'current_image'       => $currentImage,
            'cfg'                 => $config->all(),
            'approved'            => $approved,
            'pending'             => $pending,
            'countries'           => $countries,
            'last_updated'        => $lastUpdated,
            'tfl_cats'            => $tflCats,
            'tafl_cats'           => $taflCats,
            'custom_networks'     => $customNetworks,
            'custom_cats'         => $customCats,
            'affiliates'          => $affiliates,
            'fields'              => $fields,
            'templates'           => $templates,
            'categories'          => $categories,
            'current_category_id' => $currentCategoryId,
            'listing_type'        => $listingType,
            'members_per_page'    => $membersPerPage,
            'embed_settings'      => $embedSettings,
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

        $prefix  = $fanlisting->dbprefix;
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();
        $name    = $fanlisting->name;

        // 1. Drop the member table (only if a table name exists and is safe)
        if ($prefix !== '' && preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) {
            $db->prepare("DROP TABLE IF EXISTS `{$prefix}`")->execute();
        }

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
    // POST /admin/fanlistings/{id}/embed
    // -----------------------------------------------------------------------

    public function saveEmbed(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $fanlistingRepo = new FanlistingRepo($db, $config);
        $fanlisting     = $fanlistingRepo->getById($id);

        if ($fanlisting === null) {
            return Response::notFound('Fanlisting not found.');
        }

        $settingsRepo = new FanlistingSettingsRepo($db);

        $countryFieldEnabled = $request->post('country_field_enabled') === '1';
        $redirectUrlJoin     = trim((string) $request->post('redirect_url_join',    ''));
        $redirectUrlUpdate   = trim((string) $request->post('redirect_url_update',  ''));
        $redirectUrlRemove   = trim((string) $request->post('redirect_url_remove',  ''));
        $dbHost              = trim((string) $request->post('db_host',              ''));
        $dbName              = trim((string) $request->post('db_name',              ''));
        $dbUser              = trim((string) $request->post('db_user',              ''));
        $newDbPass           = (string) $request->post('db_pass',                   '');

        // Only update db_pass if a new value was submitted; empty = keep existing
        if ($newDbPass === '') {
            $existing = $settingsRepo->getEmbedSettings($id);
            $dbPass   = $existing['db_pass'];
        } else {
            $dbPass = $newDbPass;
        }

        $settingsRepo->saveEmbedSettings(
            $id,
            $countryFieldEnabled,
            $redirectUrlJoin,
            $redirectUrlUpdate,
            $redirectUrlRemove,
            $dbHost,
            $dbName,
            $dbUser,
            $dbPass,
        );

        $activityRepo = new ActivityRepo($db);
        $session      = $this->app->getSession();
        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'fanlisting.embed.save',
            "Saved embed settings for fanlisting #{$id} ({$fanlisting->name})",
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Embed settings saved successfully.');

        return Response::redirect($this->app->getBaseUrl() . '/admin/fanlistings/' . $id . '/edit');
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

        $fanlistingRepo->update($id, $categoryId, $fanlisting->subject, (string) ($fanlisting->joined ?? ''), (string) ($fanlisting->catid ?? ''));

        $this->app->getSession()->set('lstr_flash_success', 'Category updated.');

        return Response::redirect($this->app->getBaseUrl() . '/admin/fanlistings/' . $id);
    }

    // -----------------------------------------------------------------------
    // GET /admin/fanlistings/settings
    // -----------------------------------------------------------------------

    public function indexSettings(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->layout('admin/fanlistings/settings.php', [
            'cfg'     => $this->app->getConfig()->all(),
            'success' => $success,
            'error'   => $error,
        ], 'Listings Display Settings', 'fanlistings');
    }

    // -----------------------------------------------------------------------
    // POST /admin/fanlistings/settings
    // -----------------------------------------------------------------------

    public function saveSettings(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $sort        = trim((string) $request->post('owned_sort', 'id'));
        $perPage     = max(5, min(200, (int) $request->post('owned_per_page', 10)));
        $previewSize = trim((string) $request->post('image_preview_size', 'medium'));

        if (!in_array($sort, ['id', 'subject', 'category', 'status'], true)) {
            $sort = 'id';
        }

        if (!in_array($previewSize, ['small', 'medium', 'large'], true)) {
            $previewSize = 'medium';
        }

        $updated                       = $this->app->getConfig()->all();
        $updated['owned_sort']         = $sort;
        $updated['owned_per_page']     = $perPage;
        $updated['image_preview_size'] = $previewSize;

        try {
            $this->writeConfig($updated);
        } catch (\RuntimeException $e) {
            $session->set('lstr_flash_error', 'Could not save settings: ' . $e->getMessage());
            return Response::redirect($baseUrl . '/admin/fanlistings/settings');
        }

        $activityRepo = new ActivityRepo($this->app->getDatabase());
        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'fanlistings.settings.save',
            'Owned listings display settings updated',
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Settings saved successfully.');
        return Response::redirect($baseUrl . '/admin/fanlistings/settings');
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

        $flash      = (string) $session->get('lstr_flash_success', '');
        $flashError = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        // Merge order: $data takes priority over flash defaults so controllers
        // that pre-read flash (e.g. indexSettings) keep their values intact.
        $inner = $view->render($template, array_merge([
            'base_url'   => $baseUrl,
            'success'    => $flash,
            'error'      => $flashError,
            'csrf_token' => $csrfToken,
        ], $data));

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

    /**
     * Writes the config array to config/app.php via a safe temp-file pattern.
     *
     * @throws \RuntimeException on write failure
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
            throw new \RuntimeException('Could not write temp config file: ' . $tmpPath);
        }

        if (!rename($tmpPath, $configPath)) {
            unlink($tmpPath);
            throw new \RuntimeException('Could not rename temp config file to: ' . $configPath);
        }
    }
}
