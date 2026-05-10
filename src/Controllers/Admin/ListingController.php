<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\AffiliateRepo;
use Luster\Repositories\CategoryRepo;
use Luster\Repositories\CodeCategoriesRepo;
use Luster\Repositories\CodeDonorsRepo;
use Luster\Repositories\CodesRepo;
use Luster\Repositories\FieldRepo;
use Luster\Repositories\EmailTemplateRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\MemberRepo;

/**
 * Manages the listing list and per-listing settings.
 *
 * Listings are created/deleted in Enthusiast; Luster only edits
 * metadata (name, URL, email, status) and Luster-specific settings.
 */
final class ListingController extends AdminController
{

    // -----------------------------------------------------------------------
    // GET /admin/listings
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $listingRepo = new ListingRepo($db, $config);
        $memberRepo     = new MemberRepo($db, $config);
        $settingsRepo   = new ListingSettingsRepo($db);

        $categoryRepo  = new CategoryRepo($db);

        $cfgAll    = $config->all();
        $ownedSort = (string) ($cfgAll['owned_sort'] ?? 'id');

        $search        = trim((string) $request->query('search', ''));
        $filterNetwork = trim((string) $request->query('network', ''));
        $filterCat     = trim((string) $request->query('category', ''));

        // Text search via SQL; network/category filtering done in PHP below.
        $listings = $search !== ''
            ? $listingRepo->search($search)
            : $listingRepo->getAll();

        // Network filter — keep only listings whose catid pipe-string contains
        // at least one ID belonging to the selected network source.
        if ($filterNetwork !== '') {
            $netCats   = $categoryRepo->getBySource($filterNetwork);
            $netCatIds = array_column($netCats, 'id');
            $listings = array_values(array_filter(
                $listings,
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
                $listings = array_values(array_filter(
                    $listings,
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
                $listings = [];
            }
        }

        $memberCounts  = [];
        $pendingCounts = [];
        $images        = [];
        $listingTypes  = [];
        $categoryNames = [];
        foreach ($listings as $fl) {
            $memberCounts[$fl->id]  = $fl->dbprefix !== '' ? $memberRepo->countApproved($fl->dbprefix) : 0;
            $pendingCounts[$fl->id] = $fl->dbprefix !== '' ? $memberRepo->countPending($fl->dbprefix) : 0;
            $images[$fl->id]        = $settingsRepo->getImage($fl->id);
            $listingTypes[$fl->id]  = $settingsRepo->getListingType($fl->id);
            $categoryNames[$fl->id] = $listingRepo->getCatNames((string) ($fl->catid ?? ''), $fl->categoryId);
        }

        $filterType  = trim((string) $request->query('type', ''));
        $typeOptions = array_unique(array_values($listingTypes));
        sort($typeOptions);
        if ($filterType !== '') {
            $listings = array_values(array_filter($listings, fn($fl) => ($listingTypes[$fl->id] ?? 'listing') === $filterType));
        }

        // Column-header sort (URL param) overrides the configured display sort.
        $sort = trim((string) $request->query('sort', ''));
        $dir  = trim((string) $request->query('dir', 'asc'));
        if (!in_array($sort, ['id', 'subject', 'name', 'category', 'status'], true)) { $sort = ''; }
        if (!in_array($dir, ['asc', 'desc'], true)) { $dir = 'asc'; }

        $activeSort = $sort !== '' ? $sort : $ownedSort;
        $activeDir  = $sort !== '' ? $dir  : 'asc';

        if ($activeSort === 'subject') {
            usort($listings, static function ($a, $b) use ($activeDir): int {
                $cmp = strcasecmp((string) ($a->subject ?? ''), (string) ($b->subject ?? ''));
                return $activeDir === 'desc' ? -$cmp : $cmp;
            });
        } elseif ($activeSort === 'name') {
            usort($listings, static function ($a, $b) use ($activeDir): int {
                $cmp = strcasecmp((string) ($a->name ?? ''), (string) ($b->name ?? ''));
                return $activeDir === 'desc' ? -$cmp : $cmp;
            });
        } elseif ($activeSort === 'category') {
            usort($listings, static function ($a, $b) use ($activeDir, $categoryNames): int {
                $cmp = strcasecmp($categoryNames[$a->id] ?? '', $categoryNames[$b->id] ?? '');
                return $activeDir === 'desc' ? -$cmp : $cmp;
            });
        } elseif ($activeSort === 'status') {
            usort($listings, static function ($a, $b) use ($activeDir): int {
                $rank = static fn($fl): int => $fl->trouble ? 2 : ($fl->upcoming ? 1 : 0);
                $cmp  = $rank($a) <=> $rank($b);
                return $activeDir === 'desc' ? -$cmp : $cmp;
            });
        } elseif ($activeSort === 'id') {
            usort($listings, static function ($a, $b) use ($activeDir): int {
                $cmp = $a->id <=> $b->id;
                return $activeDir === 'desc' ? -$cmp : $cmp;
            });
        }
        // if $activeSort is empty, SQL already sorted by id/name

        $perPage    = max(5, min(200, (int) ($cfgAll['owned_per_page'] ?? 10)));
        $totalItems = count($listings);
        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $page       = max(1, min($totalPages, (int) $request->query('page', 1)));
        $listings = array_slice($listings, ($page - 1) * $perPage, $perPage);

        $customNetworks = $categoryRepo->getCustomSources();
        $customCats     = [];
        foreach ($customNetworks as $cn) {
            $customCats[$cn] = $categoryRepo->getBySource($cn);
        }

        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->adminLayout('admin/listings/index.php', 'Owned Listings', 'listings', [
            'listings'     => $listings,
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
            'custom_cats'     => $customCats,
            'page'            => $page,
            'total_pages'     => $totalPages,
            'total'           => $totalItems,
            'per_page'        => $perPage,
            'sort'            => $sort,
            'dir'             => $dir,
            'success'         => $success,
            'error'           => $error,
        ]);
    }

    // -----------------------------------------------------------------------
    // GET /admin/listings/{id}
    // -----------------------------------------------------------------------

    public function show(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        return Response::redirect($this->app->getBaseUrl() . '/admin/listings/' . $id . '/edit');
    }

    // -----------------------------------------------------------------------
    // GET /admin/listings/add
    // -----------------------------------------------------------------------

    public function addForm(Request $request, array $params): Response
    {
        $db           = $this->app->getDatabase();
        $categoryRepo = new CategoryRepo($db);

        $tflCats        = $categoryRepo->getBySource('tfl');
        $taflCats       = $categoryRepo->getBySource('tafl');
        $customNetworks = $categoryRepo->getCustomSources();
        $customCats     = [];
        foreach ($customNetworks as $cn) {
            $customCats[$cn] = $categoryRepo->getBySource($cn);
        }

        return $this->adminLayout('admin/listings/add.php', 'Add Listing', 'listings', [
            'tfl_cats'        => $tflCats,
            'tafl_cats'       => $taflCats,
            'custom_networks' => $customNetworks,
            'custom_cats'     => $customCats,
            'old'             => [],
            'error'           => '',
            'default_date'    => date('Y-m-d'),
        ], '', [
            $this->crumb('Owned Listings', $this->adminUrl('/admin/listings')),
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/add
    // -----------------------------------------------------------------------

    public function add(Request $request, array $params): Response
    {
        $db             = $this->app->getDatabase();
        $config         = $this->app->getConfig();
        $listingRepo = new ListingRepo($db, $config);
        $categoryRepo   = new CategoryRepo($db);

        $title    = trim((string) $request->post('title', ''));
        $subject  = trim((string) $request->post('subject', ''));
        $url      = trim((string) $request->post('url', ''));
        $email    = trim((string) $request->post('email', ''));
        $statusRaw = (int) $request->post('status', 2);
        $status   = in_array($statusRaw, [0, 1, 2], true) ? $statusRaw : 2;
        $opened   = $status !== 2
            ? 'TBA'
            : (trim((string) $request->post('opened', '')) ?: date('Y-m-d'));

        $tflCats        = $categoryRepo->getBySource('tfl');
        $taflCats       = $categoryRepo->getBySource('tafl');
        $customNetworks = $categoryRepo->getCustomSources();
        $customCats     = [];
        foreach ($customNetworks as $cn) {
            $customCats[$cn] = $categoryRepo->getBySource($cn);
        }

        $networks = array_values((array) $request->post('cat_network', []));
        $names    = array_values((array) $request->post('cat_name', []));
        $others   = array_values((array) $request->post('cat_network_other', []));
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
            $addFieldErrors = [];
            if ($subject === '') {
                $addFieldErrors['subject'] = ['Subject is required.'];
            }
            if ($url === '') {
                $addFieldErrors['url'] = ['URL is required.'];
            }
            return $this->adminLayout('admin/listings/add.php', 'Add Listing', 'listings', [
                'tfl_cats'        => $tflCats,
                'tafl_cats'       => $taflCats,
                'custom_networks' => $customNetworks,
            'custom_cats'     => $customCats,
                'old'             => $request->post(),
                'error'           => 'Subject and URL are required.',
                'field_errors'    => $addFieldErrors,
                'default_date'    => date('Y-m-d'),
            ], '', [
                $this->crumb('Owned Listings', $this->adminUrl('/admin/listings')),
            ]);
        }

        $image      = null;
        $imageFile  = $request->file('image') ?? [];
        $uploadErr  = (int) ($imageFile['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadErr === UPLOAD_ERR_OK) {
            $tmp      = (string) ($imageFile['tmp_name'] ?? '');
            $fileSize = (int) ($imageFile['size'] ?? 0);
            $mime     = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
            $allowed  = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

            if ($fileSize > 2 * 1024 * 1024) {
                return $this->adminLayout('admin/listings/add.php', 'Add Listing', 'listings', [
                    'tfl_cats'        => $tflCats,
                    'tafl_cats'       => $taflCats,
                    'custom_networks' => $customNetworks,
            'custom_cats'     => $customCats,
                    'old'             => $request->post(),
                    'error'           => 'Image must be 2 MB or smaller.',
                    'default_date'    => date('Y-m-d'),
                ], '', [
                    $this->crumb('Owned Listings', $this->adminUrl('/admin/listings')),
                ]);
            }

            if (!in_array($mime, $allowed, true)) {
                return $this->adminLayout('admin/listings/add.php', 'Add Listing', 'listings', [
                    'tfl_cats'        => $tflCats,
                    'tafl_cats'       => $taflCats,
                    'custom_networks' => $customNetworks,
            'custom_cats'     => $customCats,
                    'old'             => $request->post(),
                    'error'           => 'Invalid image type.',
                    'default_date'    => date('Y-m-d'),
                ], '', [
                    $this->crumb('Owned Listings', $this->adminUrl('/admin/listings')),
                ]);
            }
            $ext      = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mime];
            $dir      = dirname(__DIR__, 3) . '/public/uploads/listings/';
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $filename = 'fl_' . bin2hex(random_bytes(8)) . '.' . $ext;
            move_uploaded_file($tmp, $dir . $filename);
            $image    = $filename;
        }

        $newId = $listingRepo->insert($title, $subject, $url, $email, $status, $image, null, $opened, $catidStr);

        // Pre-fill default embed page names for the new listing.
        $settingsRepo = new ListingSettingsRepo($db);
        $settingsRepo->saveEmbedSettings(
            $newId,
            'join.php',          // form_url_join
            'update.php',        // form_url_update
            'remove.php',        // form_url_remove
            'reset.php',         // form_url_reset
            'codes-submit.php',  // form_url_codes_submit
            '',                  // db_host
            '',                  // db_name
            '',                  // db_user
            '',                  // db_pass
        );

        return Response::redirect($this->app->getBaseUrl() . '/admin/listings');
    }

    public function bulk(Request $request, array $params): Response
    {
        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $action   = (string) $request->post('action', '');
        $selected = array_filter(array_map('intval', (array) $request->post('selected', [])));

        if ($action === 'delete' && !empty($selected)) {
            $listingRepo = new ListingRepo($db, $config);
            $settingsRepo   = new ListingSettingsRepo($db);
            $activityRepo   = new ActivityRepo($db);
            $deleted        = 0;

            foreach ($selected as $id) {
                $listing = $listingRepo->getById($id);
                if ($listing === null) {
                    continue;
                }

                $prefix = $listing->dbprefix;

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

                $listingRepo->delete($id);

                $activityRepo->log(
                    (int) $session->get('lstr_admin_id'),
                    'listing.delete',
                    "Deleted listing #{$id} ({$listing->name}) via bulk action",
                    $request->ip(),
                );

                $deleted++;
            }

            $session->set('lstr_flash_success', "Deleted {$deleted} listing(s).");
        }

        return Response::redirect($baseUrl . '/admin/listings');
    }

    // -----------------------------------------------------------------------
    // GET + POST /admin/listings/{id}/edit
    // -----------------------------------------------------------------------

    public function edit(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $listingRepo = new ListingRepo($db, $config);
        $settingsRepo   = new ListingSettingsRepo($db);
        $activityRepo   = new ActivityRepo($db);
        $memberRepo     = new MemberRepo($db, $config);
        $affiliateRepo  = new AffiliateRepo($db);
        $categoryRepo   = new CategoryRepo($db);
        $fieldRepo      = new FieldRepo($db, $config);
        $codesRepo      = new CodesRepo($db);
        $codeCatsRepo   = new CodeCategoriesRepo($db);
        $codeDonorsRepo = new CodeDonorsRepo($db);

        $listing = $listingRepo->getById($id);

        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        // ---- POST: save ----
        if ($request->isPost()) {
            $title       = trim((string) $request->post('title', ''));
            $url         = trim((string) $request->post('url', ''));
            $email       = trim((string) $request->post('email', ''));
            $status      = (string) $request->post('status', 'current');
            $subject     = trim((string) $request->post('subject', ''));
            $opened      = $status !== 'current'
                ? 'TBA'
                : trim((string) $request->post('opened', ''));
            $nets       = array_values((array) $request->post('cat_network', []));
            $nms        = array_values((array) $request->post('cat_name', []));
            $otherNets  = array_values((array) $request->post('cat_network_other', []));
            $catids     = [];
            foreach ($nets as $i => $net) {
                $net = trim((string) $net);
                $nm  = trim((string) ($nms[$i] ?? ''));
                if ($net === 'other') {
                    $net = trim((string) ($otherNets[$i] ?? ''));
                }
                if ($nm === '' || $net === '') continue;
                $catids[] = $categoryRepo->findOrCreate($net, $nm);
            }
            $catidStr    = $catids ? '|' . implode('|', $catids) . '|' : '';
            $deleteImage = $request->post('delete_image') === '1';

            $allowedTypes = ['listing', 'clique', 'collective', 'shrine', 'webring', 'other'];
            $listingType  = (string) ($request->post('listing_type', 'listing'));
            if (!in_array($listingType, $allowedTypes, true)) {
                $listingType = 'listing';
            }

            $membersPerPage   = max(5, min(200, (int) $request->post('members_per_page', 25)));
            $contactEnabled   = $request->post('contact_enabled') === '1';
            $contactRecipient = trim((string) $request->post('contact_recipient', ''));
            if ($contactRecipient !== '' && !filter_var($contactRecipient, FILTER_VALIDATE_EMAIL)) {
                $contactRecipient = '';
            }

            // --- Image upload validation (before moving any file) ---
            $imageError  = '';
            $newFilename = null;
            $tmpPath     = '';
            $imageFile   = $request->file('image') ?? [];

            $uploadErr = (int) ($imageFile['error'] ?? UPLOAD_ERR_NO_FILE);

            if (!$deleteImage && $uploadErr !== UPLOAD_ERR_NO_FILE) {
                if ($uploadErr !== UPLOAD_ERR_OK) {
                    $imageError = 'Image upload failed (error code ' . $uploadErr . ').';
                } else {
                    $tmpPath  = (string) ($imageFile['tmp_name'] ?? '');
                    $fileSize = (int) ($imageFile['size'] ?? 0);

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

                return $this->adminLayout('admin/listings/edit.php', 'Edit Listing', 'listings', [
                    'listing'          => $listing,
                    'error'               => implode(' ', $messages),
                    'field_errors'        => $v->errors(),
                    'old'                 => ['title' => $title, 'url' => $url, 'email' => $email, 'status' => $status],
                    'current_image'       => $settingsRepo->getImage($id),
                    'cfg'                 => $config->all(),
                    'approved'            => $memberRepo->countApproved($listing->dbprefix),
                    'pending'             => $memberRepo->countPending($listing->dbprefix),
                    'affiliates'          => $affiliateRepo->getByListing($id)['items'],
                    'fields'              => $fieldRepo->getFieldsForListing($id),
                    'templates'           => $settingsRepo->getTemplates($id),
                    'categories'          => $categoryRepo->getAll(),
                    'current_category_id' => $listing->categoryId ?? null,
                    'listing_type'        => $listingType,
                    'members_per_page'    => $membersPerPage,
                    'contact_enabled'     => $contactEnabled,
                    'contact_recipient'   => $contactRecipient,
                ], '', [
                    $this->crumb('Owned Listings', $this->adminUrl('/admin/listings')),
                    $this->crumb($listing->subject),
                ]);
            }

            // --- All valid: persist ---
            $upcoming = ($status === 'upcoming');
            $trouble  = ($status === 'pending');

            $lastUpdatedInput = trim((string) $request->post('fl_last_updated', ''));
            // Validate: must be empty or a parseable date in YYYY-MM-DD format
            if ($lastUpdatedInput !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $lastUpdatedInput)) {
                $lastUpdatedInput = '';
            }

            $listingRepo->updateMeta($id, $title, $url, $email, $upcoming, $trouble);
            $listingRepo->update($id, !empty($catids) ? $catids[0] : null, $subject, $opened, $catidStr);
            $settingsRepo->saveListingType($id, $listingType);
            $settingsRepo->saveMembersPerPage($id, $membersPerPage);
            $settingsRepo->saveContactSettings($id, $contactEnabled, $contactRecipient !== '' ? $contactRecipient : null);
            if ($lastUpdatedInput !== '' || $request->post('fl_last_updated') !== null) {
                $settingsRepo->setLastUpdated($id, $lastUpdatedInput !== '' ? $lastUpdatedInput : null);
            }

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
                'listing.edit',
                "Edited listing #{$id} ({$title})",
                $request->ip(),
            );

            $session->set('lstr_flash_success', 'Listing updated successfully.');
            return Response::redirect($baseUrl . '/admin/listings/' . $id);
        }

        // ---- GET: show form ----
        $currentImage       = $settingsRepo->getImage($id);
        $listingType        = $settingsRepo->getListingType($id);
        $membersPerPage     = $settingsRepo->getMembersPerPage($id);
        $flLastUpdated      = $settingsRepo->getLastUpdated($id);
        $embedSettings      = $settingsRepo->getEmbedSettings($id);
        $contactSettings    = $settingsRepo->getContactSettings($id);
        $approved           = $listing->dbprefix !== '' ? $memberRepo->countApproved($listing->dbprefix) : 0;
        $pending            = $listing->dbprefix !== '' ? $memberRepo->countPending($listing->dbprefix) : 0;
        $countries          = $listing->dbprefix !== '' ? $memberRepo->countUniqueCountries($listing->dbprefix) : 0;
        $lastUpdated        = $listing->dbprefix !== '' ? $memberRepo->getLastUpdated($listing->dbprefix) : null;
        $tflCats            = $categoryRepo->getBySource('tfl');
        $taflCats           = $categoryRepo->getBySource('tafl');
        $customNetworks     = $categoryRepo->getCustomSources();
        $customCats         = [];
        foreach ($customNetworks as $cn) {
            $customCats[$cn] = $categoryRepo->getBySource($cn);
        }
        $affiliates         = $affiliateRepo->getByListing($id)['items'];
        $fields             = $fieldRepo->getFieldsForListing($id);
        $templates          = $settingsRepo->getTemplates($id);
        $categories         = $categoryRepo->getAll();
        $currentCategoryId  = $listing->categoryId ?? null;

        // Codes tab data
        $codeStatusFilter = in_array((string) $request->query('status_filter', ''), array_merge([''], CodesRepo::STATUSES), true)
            ? (string) $request->query('status_filter', '')
            : '';
        $codeEditCatId   = (int) $request->query('edit_cat',   '0');
        $codeEditDonorId = (int) $request->query('edit_donor', '0');
        $allCodes        = $codesRepo->getByListing($id);
        $codeStatusCounts = ['approved' => 0, 'pending' => 0, 'rejected' => 0];
        foreach ($allCodes as $c) {
            $codeStatusCounts[$c->status] = ($codeStatusCounts[$c->status] ?? 0) + 1;
        }
        $codes       = $allCodes;
        $codeCats    = $codeCatsRepo->getByListing($id);
        $codeDonors  = $codeDonorsRepo->getByListing($id);
        $codeCategoryIds = $codeCatsRepo->getCategoryMapForListing($id);
        $codeCatMap  = [];
        foreach ($codeCats as $cat) { $codeCatMap[$cat->id] = $cat; }
        $codeDonorMap = [];
        foreach ($codeDonors as $donor) { $codeDonorMap[$donor->id] = $donor; }
        $codeGrouped = [];
        foreach ($allCodes as $code) {
            if ($code->width > 0 && $code->height > 0) {
                $gKey   = (int) $code->width . 'x' . (int) $code->height;
                $gLabel = (int) $code->width . '×' . (int) $code->height . 'px';
            } else {
                $gKey   = 'unknown';
                $gLabel = 'Unknown dimensions';
            }
            if (!isset($codeGrouped[$gKey])) {
                $codeGrouped[$gKey] = ['label' => $gLabel, 'codes' => []];
            }
            $codeGrouped[$gKey]['codes'][] = $code;
        }
        uksort($codeGrouped, function (string $a, string $b): int {
            if ($a === 'unknown') return 1;
            if ($b === 'unknown') return -1;
            [$aw, $ah] = explode('x', $a);
            [$bw, $bh] = explode('x', $b);
            return $aw !== $bw ? (int) $aw - (int) $bw : (int) $ah - (int) $bh;
        });

        $success            = (string) $session->get('lstr_flash_success', '');
        $error              = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->adminLayout('admin/listings/edit.php', 'Edit Listing', 'listings', [
            'listing'          => $listing,
            'success'             => $success,
            'error'               => $error,
            'old'                 => [],
            'current_image'       => $currentImage,
            'cfg'                 => $config->all(),
            'approved'            => $approved,
            'pending'             => $pending,
            'countries'           => $countries,
            'last_updated'        => $lastUpdated,
            'fl_last_updated'     => $flLastUpdated,
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
            'contact_enabled'     => $contactSettings['enabled'],
            'contact_recipient'   => $contactSettings['recipient'] ?? '',
            'codes'               => $codes,
            'code_cats'           => $codeCats,
            'code_donors'         => $codeDonors,
            'code_grouped'        => $codeGrouped,
            'code_category_ids'   => $codeCategoryIds,
            'code_cat_map'        => $codeCatMap,
            'code_donor_map'      => $codeDonorMap,
            'code_edit_cat_id'    => $codeEditCatId,
            'code_edit_donor_id'  => $codeEditDonorId,
            'code_status_filter'  => $codeStatusFilter,
            'code_status_counts'  => $codeStatusCounts,
            'total_codes'         => count($allCodes),
            'uploads_url'         => $this->app->getBaseUrl() . '/uploads/codes',
        ], '', [
            $this->crumb('Owned Listings', $this->adminUrl('/admin/listings')),
            $this->crumb($listing->subject),
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/{id}/delete
    // -----------------------------------------------------------------------

    public function delete(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $listingRepo = new ListingRepo($db, $config);
        $settingsRepo   = new ListingSettingsRepo($db);
        $activityRepo   = new ActivityRepo($db);

        $listing = $listingRepo->getById($id);

        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        $prefix  = $listing->dbprefix;
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();
        $name    = $listing->name;

        // 1. Drop the member table (only if a table name exists and is safe)
        if ($prefix !== '' && preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) {
            $db->prepare("DROP TABLE IF EXISTS `{$prefix}`")->execute();
        }

        // 2. Delete Luster rows that reference this listing
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

        // 4. Delete the listing registry row
        $listingRepo->delete($id);

        // 5. Log (fanlisting row is gone but session identity is intact)
        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'listing.delete',
            "Deleted listing #{$id} ({$name})",
            $request->ip(),
        );

        $session->set('lstr_flash_success', "Listing \"{$name}\" deleted successfully.");
        return Response::redirect($baseUrl . '/admin/listings');
    }

    // -----------------------------------------------------------------------
    // GET + POST /admin/listings/{id}/edit-prefix
    // -----------------------------------------------------------------------

    public function editPrefix(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $listingRepo = new ListingRepo($db, $config);
        $activityRepo   = new ActivityRepo($db);

        $listing = $listingRepo->getById($id);

        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        // Check whether the current member table exists
        $tableExistsStmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :name'
        );
        $tableExistsStmt->execute(['name' => $listing->dbprefix]);
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
            } elseif ($newPrefix === $listing->dbprefix) {
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
                    $error = "The prefix \"{$newPrefix}\" is already in use by another listing.";
                }
            }

            if ($error !== '') {
                return $this->adminLayout('admin/listings/edit-prefix.php', 'Change Table Prefix', 'listings', [
                    'listing'   => $listing,
                    'table_exists' => $tableExists,
                    'error'        => $error,
                ], '', [
                    $this->crumb('Owned Listings', $this->adminUrl('/admin/listings')),
                    $this->crumb($listing->subject, $this->adminUrl('/admin/listings/' . $listing->id . '/edit')),
                ]);
            }

            $oldPrefix = $listing->dbprefix;
            $listingRepo->updateDbPrefix($id, $oldPrefix, $newPrefix);

            $activityRepo->log(
                (int) $session->get('lstr_admin_id'),
                'listing.prefix_changed',
                "Changed table prefix for listing #{$id}: {$oldPrefix} → {$newPrefix}",
                $request->ip(),
            );

            $session->set('lstr_flash_success', "Table prefix changed from \"{$oldPrefix}\" to \"{$newPrefix}\" successfully.");
            return Response::redirect($baseUrl . '/admin/listings/' . $id . '/edit');
        }

        // ---- GET: show form ----
        return $this->adminLayout('admin/listings/edit-prefix.php', 'Change Table Prefix', 'listings', [
            'listing'   => $listing,
            'table_exists' => $tableExists,
            'error'        => '',
        ], '', [
            $this->crumb('Owned Listings', $this->adminUrl('/admin/listings')),
            $this->crumb($listing->subject, $this->adminUrl('/admin/listings/' . $listing->id . '/edit')),
        ]);
    }

    // -----------------------------------------------------------------------
    // Ensures public/uploads/listings/ exists with an .htaccess guard
    // -----------------------------------------------------------------------

    private function ensureUploadDirectory(): string
    {
        $uploadDir = dirname(__DIR__, 3) . '/public/uploads/listings';

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
    // POST /admin/listings/{id}/templates
    // -----------------------------------------------------------------------

    public function saveTemplates(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $listingRepo = new ListingRepo($db, $config);
        $listing     = $listingRepo->getById($id);

        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        $statsRaw      = (string) $request->post('stats_template',      '');
        $membersRaw    = (string) $request->post('members_template',    '');
        $affiliatesRaw = (string) $request->post('affiliates_template', '');

        // Treat blank / whitespace-only input as null (use built-in default)
        $statsTemplate      = trim($statsRaw)      !== '' ? $statsRaw      : null;
        $membersTemplate    = trim($membersRaw)    !== '' ? $membersRaw    : null;
        $affiliatesTemplate = trim($affiliatesRaw) !== '' ? $affiliatesRaw : null;

        $settingsRepo = new ListingSettingsRepo($db);
        $settingsRepo->saveTemplates($id, $statsTemplate, $membersTemplate, $affiliatesTemplate);

        $this->app->getSession()->set('lstr_flash_success', 'Templates saved successfully.');

        return Response::redirect($this->app->getBaseUrl() . '/admin/listings/' . $id);
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/{id}/embed
    // -----------------------------------------------------------------------

    public function saveEmbed(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $listingRepo = new ListingRepo($db, $config);
        $listing     = $listingRepo->getById($id);

        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        $settingsRepo = new ListingSettingsRepo($db);

        $formUrlJoin         = ltrim(trim((string) $request->post('form_url_join',         '')), '/');
        $formUrlUpdate       = ltrim(trim((string) $request->post('form_url_update',       '')), '/');
        $formUrlRemove       = ltrim(trim((string) $request->post('form_url_remove',       '')), '/');
        $formUrlReset        = ltrim(trim((string) $request->post('form_url_reset',        '')), '/');
        $formUrlCodesSubmit  = ltrim(trim((string) $request->post('form_url_codes_submit', '')), '/');
        $dbHost              = trim((string) $request->post('db_host', ''));
        $dbName              = trim((string) $request->post('db_name', ''));
        $dbUser              = trim((string) $request->post('db_user', ''));
        $newDbPass           = (string) $request->post('db_pass', '');

        // Only update db_pass if a new value was submitted; empty = keep existing
        if ($newDbPass === '') {
            $existing = $settingsRepo->getEmbedSettings($id);
            $dbPass   = $existing['db_pass'];
        } else {
            $dbPass = $newDbPass;
        }

        $settingsRepo->saveEmbedSettings(
            $id,
            $formUrlJoin,
            $formUrlUpdate,
            $formUrlRemove,
            $formUrlReset,
            $formUrlCodesSubmit,
            $dbHost,
            $dbName,
            $dbUser,
            $dbPass,
        );

        $activityRepo = new ActivityRepo($db);
        $session      = $this->app->getSession();
        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'listing.embed.save',
            "Saved embed settings for listing #{$id} ({$listing->name})",
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Embed settings saved successfully.');

        return Response::redirect($this->app->getBaseUrl() . '/admin/listings/' . $id . '/edit');
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/{id}/update
    // -----------------------------------------------------------------------

    public function update(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $listingRepo = new ListingRepo($db, $config);
        $listing     = $listingRepo->getById($id);

        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        $rawCategoryId = (int) $request->post('category_id', 0);
        $categoryId    = $rawCategoryId > 0 ? $rawCategoryId : null;

        $listingRepo->update($id, $categoryId, $listing->subject, (string) ($listing->joined ?? ''), (string) ($listing->catid ?? ''));

        $this->app->getSession()->set('lstr_flash_success', 'Category updated.');

        return Response::redirect($this->app->getBaseUrl() . '/admin/listings/' . $id);
    }

    // -----------------------------------------------------------------------
    // GET /admin/listings/settings
    // -----------------------------------------------------------------------

    public function indexSettings(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->adminLayout('admin/listings/settings.php', 'Listings Display Settings', 'listings', [
            'cfg'     => $this->app->getConfig()->all(),
            'success' => $success,
            'error'   => $error,
        ], '', [
            $this->crumb('Owned Listings', $this->adminUrl('/admin/listings')),
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/settings
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
            return Response::redirect($baseUrl . '/admin/listings/settings');
        }

        $activityRepo = new ActivityRepo($this->app->getDatabase());
        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'listings.settings.save',
            'Owned listings display settings updated',
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Settings saved successfully.');
        return Response::redirect($baseUrl . '/admin/listings/settings');
    }

    // -----------------------------------------------------------------------
    // GET|POST /admin/listings/{id}/email-templates  → unified page
    // -----------------------------------------------------------------------

    public function emailTemplatesRedirect(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        return Response::redirect($this->app->getBaseUrl() . '/admin/emails?tab=templates&listing=' . $id);
    }

    // -----------------------------------------------------------------------
    // GET /admin/listings/{id}/email-templates  (kept for reference; no longer routed)
    // -----------------------------------------------------------------------

    public function emailTemplatesForm(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $listingRepo = new ListingRepo($db, $config);
        $listing     = $listingRepo->getById($id);

        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        $emailRepo = new EmailTemplateRepo($db);
        $templates = $emailRepo->getAll($id);

        return $this->adminLayout('admin/listings/email-templates.php', 'Email Templates', 'listings', [
            'listing' => $listing,
            'templates'  => $templates,
        ], '', [
            $this->crumb('Owned Listings', $this->adminUrl('/admin/listings')),
            $this->crumb($listing->subject, $this->adminUrl('/admin/listings/' . $listing->id . '/edit')),
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/{id}/email-templates
    // -----------------------------------------------------------------------

    public function saveEmailTemplates(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $listingRepo = new ListingRepo($db, $config);
        $listing     = $listingRepo->getById($id);

        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        $emailRepo = new EmailTemplateRepo($db);

        foreach (EmailTemplateRepo::TYPES as $type) {
            $enabled = $request->post('email_enabled_' . $type) === '1';
            $subject = trim((string) $request->post('email_subject_' . $type, ''));
            $body    = trim((string) $request->post('email_body_'    . $type, ''));

            $emailRepo->save($id, $type, $subject, $body, $enabled);
        }

        $this->app->getSession()->set('lstr_flash_success', 'Email templates saved.');

        return Response::redirect(
            $this->app->getBaseUrl() . '/admin/listings/' . $id . '/email-templates'
        );
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
