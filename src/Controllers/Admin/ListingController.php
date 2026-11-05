<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 The biggest admin controller. Handles the listings index (sortable, filterable,
 with status tabs), listing add/edit forms, per-listing settings (templates,
 join rules, codes, affiliates section), and the tabbed detail view that ties
 together members, codes, affiliates, additional fields, and email templates
 for a single listing. Also renders template previews with sample data.
*/

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Captcha;
use Luster\Core\Countries;
use Luster\Core\ListingLifecycleService;
use Luster\Core\Mailer;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\TemplateEngine;
use Luster\Core\Validator;
use Luster\Core\View;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\AffiliateRepo;
use Luster\Repositories\CategoryRepo;
use Luster\Repositories\CodeCategoriesRepo;
use Luster\Repositories\CodeDonorsRepo;
use Luster\Repositories\CodesRepo;
use Luster\Repositories\FieldRepo;
use Luster\Repositories\EmailTemplateRepo;
use Luster\Repositories\ListingLifecycleRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\MemberRepo;
use Luster\Repositories\PreviousOwnersRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\AffiliateRowRenderer;
use Luster\Support\CodesTemplateRenderer;
use Luster\Support\DateFormatter;
use Luster\Support\FormTemplateRenderer;
use Luster\Support\ListingTemplateResolver;
use Luster\Support\MemberStatsService;
use Luster\Support\MembersTemplateRenderer;
use Luster\Support\PreviousOwnersRenderer;
use Luster\Support\RulesBodyRenderer;
use Luster\Support\SampleTemplateData;
use Luster\Support\SuccessRenderer;

/**
 * The primary admin listings controller.
 *
 * Handles the paginated/filterable/sortable listings index (with network, category,
 * type, and status-tab filters), add/edit/delete/bulk actions, table-prefix renaming,
 * per-listing embed settings, template management (save, reset, live preview for every
 * template type), global collective templates, per-listing email templates, and the
 * stale-listing settings page.
 */
final class ListingController extends AdminController
{

    // -----------------------------------------------------------------------
    // GET /admin/listings
    // -----------------------------------------------------------------------

    /**
     * Renders the paginated owned-listings index with network/category/type/status
     * filters and column-header sorting. Auto-lands on the pending tab when there
     * are pending listings.
     */
    public function index(Request $request, array $params): Response
    {
        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $listingRepo = new ListingRepo($db, $config);
        $memberRepo     = new MemberRepo($db, $config);
        $settingsRepo   = new ListingSettingsRepo($db);

        $cfgAll = $config->all();

        $tabExplicit = $request->query('tab', null) !== null;
        $tab         = trim((string) $request->query('tab', 'all'));
        if (!in_array($tab, ['all', 'current', 'upcoming', 'pending', 'closed', 'adopted_out'], true)) {
            $tab = 'all';
        }

        $search        = trim((string) $request->query('search', ''));
        $filterNetwork = trim((string) $request->query('network', ''));
        $filterCat     = trim((string) $request->query('category', ''));

        // Text search via SQL; network/category/type filtering done in PHP below.
        $listings = $search !== ''
            ? $listingRepo->search($search)
            : $listingRepo->getAll();

        // Batch-apply lifecycle status (Closed/Adopted Out) so tab filtering, the
        // status column, and bulk actions all see it without a per-row query.
        $lifecycleMap = (new ListingLifecycleRepo($db))->getMapForListings(
            array_map(static fn($fl) => $fl->id, $listings)
        );
        if (!empty($lifecycleMap)) {
            $listings = array_map(
                static fn($fl) => $lifecycleMap[$fl->id] ?? null
                    ? $fl->withLifecycle($lifecycleMap[$fl->id])
                    : $fl,
                $listings
            );
        }

        // First pass: resolve category network+name and listing type for all listings.
        // Primary source: junction table (lstr_listing_categories) via batch query.
        // Fallback: getCatItems() for installs where the junction table
        // doesn't exist yet.
        $allCategoryData  = [];
        $allCategoryNames = [];
        $allListingTypes  = [];
        $approvedByMap    = $settingsRepo->getAllApprovedBy();

        $allListingIds = array_map(static fn($fl) => $fl->id, $listings);
        $junctionData  = $listingRepo->getCategoryDataByListingIds($allListingIds);

        foreach ($listings as $fl) {
            if ($junctionData !== null) {
                $catItems = $junctionData[$fl->id] ?? [];
            } else {
                // Dall back to catid pipe-string resolution
                $catItems = $listingRepo->getCatItems((string) ($fl->catid ?? ''), $fl->categoryId);
            }
            $allCategoryData[$fl->id]  = $catItems;
            $allCategoryNames[$fl->id] = $catItems !== [] ? implode(', ', array_map(fn($c) => $c['name'], $catItems)) : '—';
            $allListingTypes[$fl->id]  = $settingsRepo->getListingType($fl->id);
        }

        // Derive in-use categories for filter dropdowns from the resolved listing data.
        // Only shows networks/categories actually assigned to owned listings, grouped
        // and sorted alphabetically, matching the shape the template and JS expect.
        $usedByNetwork = [];
        foreach ($allCategoryData as $catItems) {
            foreach ($catItems as $cat) {
                $net  = strtoupper((string) ($cat['network'] ?? ''));
                $name = (string) ($cat['name'] ?? '');
                if ($name === '' || $net === '') { continue; }
                $usedByNetwork[$net][$name] = true;
            }
        }
        $tflCats        = [];
        $taflCats       = [];
        $customCats     = [];
        $customNetworks = [];
        foreach ($usedByNetwork as $net => $names) {
            ksort($names);
            $cats = array_map(static fn(string $n): array => ['name' => $n], array_keys($names));
            if ($net === 'TFL') {
                $tflCats = $cats;
            } elseif ($net === 'TAFL') {
                $taflCats = $cats;
            } else {
                $customCats[$net]  = $cats;
                $customNetworks[]  = $net;
            }
        }
        sort($customNetworks);

        // Count listings per network and per category (before any filtering).
        // Each listing is counted once per network even if it has multiple categories in that network.
        $networkCounts  = [];
        $categoryCounts = [];
        foreach ($allCategoryData as $flId => $catItems) {
            $netsForListing = [];
            foreach ($catItems as $cat) {
                $net  = strtoupper((string) ($cat['network'] ?? ''));
                $name = (string) ($cat['name'] ?? '');
                if ($net  !== '') { $netsForListing[$net]  = true; }
                if ($name !== '') { $categoryCounts[$name] = ($categoryCounts[$name] ?? 0) + 1; }
            }
            foreach (array_keys($netsForListing) as $net) {
                $networkCounts[$net] = ($networkCounts[$net] ?? 0) + 1;
            }
        }

        // Count listings per type (before any filtering).
        $typeCountsAll = [];
        foreach ($allListingTypes as $type) {
            $typeCountsAll[$type] = ($typeCountsAll[$type] ?? 0) + 1;
        }

        // Total listing count before network/category/type filtering.
        // Used for the "All Networks" and "All Categories" option labels in the filter
        // dropdowns — using array_sum($networkCounts) would over-count multi-network listings.
        $totalBeforeFilter = count($listings);

        // Network filter — name-based via resolved category data.
        if ($filterNetwork !== '') {
            $netUpper = strtoupper($filterNetwork);
            $listings = array_values(array_filter(
                $listings,
                static function ($fl) use ($allCategoryData, $netUpper): bool {
                    foreach ($allCategoryData[$fl->id] as $cat) {
                        if (strtoupper($cat['network']) === $netUpper) {
                            return true;
                        }
                    }
                    return false;
                }
            ));
        }

        // Count after network filter but before category/type filter — used for
        // the "All Categories (N)" label so it reflects the active network scope.
        $totalAfterNetworkFilter = count($listings);

        // Category filter — name-based, optionally scoped to selected network.
        if ($filterCat !== '') {
            $netUpper = strtoupper($filterNetwork);
            $listings = array_values(array_filter(
                $listings,
                static function ($fl) use ($allCategoryData, $filterCat, $netUpper): bool {
                    foreach ($allCategoryData[$fl->id] as $cat) {
                        if (strcasecmp($cat['name'], $filterCat) === 0) {
                            if ($netUpper === '' || strtoupper($cat['network']) === $netUpper) {
                                return true;
                            }
                        }
                    }
                    return false;
                }
            ));
        }

        $filterType  = trim((string) $request->query('type', ''));
        $typeOptions = array_unique(array_map(fn($fl) => $allListingTypes[$fl->id] ?? 'listing', $listings));
        sort($typeOptions);
        if ($filterType !== '') {
            $listings = array_values(array_filter($listings, fn($fl) => ($allListingTypes[$fl->id] ?? 'listing') === $filterType));
        }

        // Column-header sort (URL param). No configurable default sort — the
        // column headers themselves are the only way to change sort order.
        $sort = trim((string) $request->query('sort', ''));
        $dir  = trim((string) $request->query('dir', 'asc'));
        if (!in_array($sort, ['id', 'subject', 'name', 'category', 'status'], true)) { $sort = ''; }
        if (!in_array($dir, ['asc', 'desc'], true)) { $dir = 'asc'; }

        $activeSort = $sort;
        $activeDir  = $dir;

        if ($activeSort === 'subject') {
            usort($listings, static function ($a, $b) use ($activeDir): int {
                $cmp = strcasecmp((string) ($a->subject ?? ''), (string) ($b->subject ?? ''));
                return $activeDir === 'desc' ? -$cmp : $cmp;
            });
        } elseif ($activeSort === 'name') {
            usort($listings, static function ($a, $b) use ($activeDir): int {
                $cmp = strcasecmp((string) ($a->title ?? ''), (string) ($b->title ?? ''));
                return $activeDir === 'desc' ? -$cmp : $cmp;
            });
        } elseif ($activeSort === 'category') {
            usort($listings, static function ($a, $b) use ($activeDir, $allCategoryNames): int {
                $cmp = strcasecmp($allCategoryNames[$a->id] ?? '', $allCategoryNames[$b->id] ?? '');
                return $activeDir === 'desc' ? -$cmp : $cmp;
            });
        } elseif ($activeSort === 'status') {
            usort($listings, static function ($a, $b) use ($activeDir): int {
                $rank = static fn($fl): int => match (true) {
                    $fl->lifecycle === 'closed'      => 4,
                    $fl->lifecycle === 'adopted_out' => 3,
                    $fl->trouble                     => 2,
                    $fl->upcoming                    => 1,
                    default                           => 0,
                };
                $cmp  = $rank($a) <=> $rank($b);
                return $activeDir === 'desc' ? -$cmp : $cmp;
            });
        } elseif ($activeSort === 'id') {
            usort($listings, static function ($a, $b) use ($activeDir): int {
                $cmp = $a->id <=> $b->id;
                return $activeDir === 'desc' ? -$cmp : $cmp;
            });
        } else {
            // No column header clicked: default the index to newest-listing-first.
            // This is an admin-index-only default, not a change to ListingRepo's
            // shared getAll()/search() ordering (those stay subject-ASC for every
            // other caller, including public embeds).
            usort($listings, static fn($a, $b): int => $b->id <=> $a->id);
        }

        // Count per status tab across all currently-filtered listings (before tab filter).
        $tabCounts = ['all' => count($listings), 'current' => 0, 'upcoming' => 0, 'pending' => 0, 'closed' => 0, 'adopted_out' => 0];
        foreach ($listings as $fl) {
            if ($fl->lifecycle === 'closed')      { $tabCounts['closed']++; }
            elseif ($fl->lifecycle === 'adopted_out') { $tabCounts['adopted_out']++; }
            elseif ($fl->upcoming)                { $tabCounts['upcoming']++; }
            elseif ($fl->trouble)                 { $tabCounts['pending']++; }
            else                                   { $tabCounts['current']++; }
        }

        // When no tab was explicitly requested, land on "pending" if there are pending
        // listings so the admin's attention is drawn there first.
        if (!$tabExplicit && $tabCounts['pending'] > 0) {
            $tab = 'pending';
        }

        // Apply status tab filter.
        if ($tab !== 'all') {
            $listings = array_values(array_filter($listings, static function ($fl) use ($tab): bool {
                if ($tab === 'closed')      { return $fl->lifecycle === 'closed'; }
                if ($tab === 'adopted_out') { return $fl->lifecycle === 'adopted_out'; }
                if ($fl->lifecycle !== null) { return false; }
                if ($tab === 'current')  { return !$fl->upcoming && !$fl->trouble; }
                if ($tab === 'upcoming') { return $fl->upcoming; }
                if ($tab === 'pending')  { return $fl->trouble; }
                return true;
            }));
        }

        $perPage    = max(5, min(200, (int) ($cfgAll['owned_per_page'] ?? 10)));
        $totalItems = count($listings);
        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $page       = max(1, min($totalPages, (int) $request->query('page', 1)));
        $listings = array_slice($listings, ($page - 1) * $perPage, $perPage);

        // Second pass — only the paged slice: member counts and images (one query per listing).
        $memberCounts  = [];
        $pendingCounts = [];
        $images        = [];
        $listingTypes  = [];
        $categoryData  = [];
        $categoryNames = [];
        foreach ($listings as $fl) {
            $memberCounts[$fl->id]  = $fl->dbprefix !== '' ? $memberRepo->countApproved($fl->dbprefix) : 0;
            $pendingCounts[$fl->id] = $fl->dbprefix !== '' ? $memberRepo->countPending($fl->dbprefix) : 0;
            $images[$fl->id]        = $settingsRepo->getImage($fl->id);
            $listingTypes[$fl->id]  = $allListingTypes[$fl->id];
            $categoryData[$fl->id]  = $allCategoryData[$fl->id];
            $categoryNames[$fl->id] = $allCategoryNames[$fl->id];
        }

        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->adminLayout('admin/listings/index.php', 'Owned Listings', 'listings', [
            'listings'        => $listings,
            'member_counts'   => $memberCounts,
            'pending_counts'  => $pendingCounts,
            'images'          => $images,
            'listing_types'   => $listingTypes,
            'category_names'  => $categoryNames,
            'category_data'   => $categoryData,
            'approved_by_map' => $approvedByMap,
            'cfg'             => $config->all(),
            'search'          => $search,
            'filter_network'  => $filterNetwork,
            'filter_cat'      => $filterCat,
            'filter_type'     => $filterType,
            'type_options'    => $typeOptions,
            'tfl_cats'        => $tflCats,
            'tafl_cats'       => $taflCats,
            'custom_networks' => $customNetworks,
            'custom_cats'     => $customCats,
            'network_counts'           => $networkCounts,
            'category_counts'          => $categoryCounts,
            'type_counts'              => $typeCountsAll,
            'total_before_filter'      => $totalBeforeFilter,
            'total_after_network_filter' => $totalAfterNetworkFilter,
            'page'            => $page,
            'total_pages'     => $totalPages,
            'total'           => $totalItems,
            'per_page'        => $perPage,
            'sort'            => $sort,
            'dir'             => $dir,
            'tab'             => $tab,
            'tab_counts'      => $tabCounts,
            'success'         => $success,
            'error'           => $error,
        ]);
    }

    // -----------------------------------------------------------------------
    // GET /admin/listings/{id}
    // -----------------------------------------------------------------------

    /**
     * Redirects /listings/{id} to /listings/{id}/edit (canonical detail URL).
     */
    public function show(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        return Response::redirect($this->app->getBaseUrl() . '/admin/listings/' . $id . '/edit');
    }

    // -----------------------------------------------------------------------
    // GET /admin/listings/add
    // -----------------------------------------------------------------------

    /**
     * Where the Add Listing form and its save go on the enth_ table layout,
     * which ListingRepo::insert() doesn't support: back to Owned Listings with
     * a message, having read and written nothing.
     */
    private function addListingUnsupported(): Response
    {
        $this->app->getSession()->set('lstr_flash_error',
            "This Luster database uses the older Enthusiast table layout (enth_ tables), and adding a listing from the admin panel doesn't work on that layout yet. Nothing was saved.");
        return Response::redirect($this->app->getBaseUrl() . '/admin/listings');
    }

    /**
     * Renders the add-listing form with category selector dropdowns.
     */
    public function addForm(Request $request, array $params): Response
    {
        $db           = $this->app->getDatabase();
        if (!(new ListingRepo($db, $this->app->getConfig()))->supportsInsert()) {
            return $this->addListingUnsupported();
        }
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

    /**
     * Creates a new listing with optional image upload, resolves category IDs,
     * inserts the registry row, and pre-fills default embed page names.
     */
    public function add(Request $request, array $params): Response
    {
        $db             = $this->app->getDatabase();
        $config         = $this->app->getConfig();
        $listingRepo = new ListingRepo($db, $config);
        // Before anything else: an image upload or a new category would be
        // left behind by an insert that can't work.
        if (!$listingRepo->supportsInsert()) {
            return $this->addListingUnsupported();
        }
        $categoryRepo   = new CategoryRepo($db);

        $title    = trim((string) $request->post('title', ''));
        $subject  = trim((string) $request->post('subject', ''));
        if ($title === '') {
            $title = $subject;
        }
        $url      = self::httpsify(trim((string) $request->post('url', '')));
        $email    = trim((string) $request->post('email', ''));
        $statusRaw = (string) $request->post('status', 'current');
        $allowedStatuses = ['current', 'upcoming', 'pending', 'closed', 'adopted_out'];
        $status = in_array($statusRaw, $allowedStatuses, true) ? $statusRaw : 'current';
        $isArchiving   = ListingLifecycleService::isLifecycleStatus($status);
        $adoptedToName = $status === 'adopted_out' ? trim((string) $request->post('adopted_to_name', '')) : null;
        $adoptedToUrl  = $status === 'adopted_out' ? self::httpsify(trim((string) $request->post('adopted_to_url', ''))) : null;
        $archiveNote   = $isArchiving ? trim((string) $request->post('archive_note', '')) : null;
        $archivedAtUnknown = $request->post('archived_at_unknown') === '1';
        [$archivedAtValue, $archivedAtError] = $isArchiving
            ? self::resolveArchivedAt(
                trim((string) $request->post('archived_at', '')),
                $archivedAtUnknown,
                null,
                !$archivedAtUnknown || (new ListingLifecycleRepo($db))->supportsUnknownDate(),
            )
            : ['', ''];
        $listingType   = (string) $request->post('listing_type', 'listing');
        if (!in_array($listingType, ['listing', 'clique', 'collective', 'shrine', 'webring', 'other'], true)) {
            $listingType = 'listing';
        }
        $description = trim((string) $request->post('description', ''));
        $openedRaw = trim((string) $request->post('opened', ''));
        $opened = match (true) {
            in_array($status, ['upcoming', 'pending'], true) => null,
            $status === 'current' => ($openedRaw !== '' ? $openedRaw : date('Y-m-d')),
            // closed / adopted_out — these were open before being archived, so
            // preserve a real opened date instead of forcing it blank like TBA.
            default => ($openedRaw !== '' ? $openedRaw : null),
        };

        $tflCats        = $categoryRepo->getBySource('tfl');
        $taflCats       = $categoryRepo->getBySource('tafl');
        $customNetworks = $categoryRepo->getCustomSources();
        $customCats     = [];
        foreach ($customNetworks as $cn) {
            $customCats[$cn] = $categoryRepo->getBySource($cn);
        }

        // Only read here; the categories are created once nothing else can stop
        // the save (below), so a refused save doesn't leave new ones behind.
        $catPicks = self::categoryPicks($request);

        $urlRequired = !$isArchiving;
        $v = new Validator([
            'subject'         => $subject,
            'title'           => $title,
            'url'             => $url,
            'email'           => $email,
            'adopted_to_name' => (string) ($adoptedToName ?? ''),
            'adopted_to_url'  => (string) ($adoptedToUrl  ?? ''),
        ]);
        $v->field('subject')->required()->maxLength(255);
        $v->field('title')->maxLength(255);
        $urlField = $v->field('url');
        if ($urlRequired) {
            $urlField->required();
        }
        // 255, not 500 — matches the owned.url column width (install/index.php).
        $urlField->httpUrl()->maxLength(255);
        $v->field('email')->required()->email()->maxLength(255);
        $v->field('adopted_to_name')->maxLength(255);
        $v->field('adopted_to_url')->httpUrl()->maxLength(500);

        if ($v->fails() || $archivedAtError !== '') {
            $messages    = $v->allErrors();
            $fieldErrors = $v->errors();
            if ($archivedAtError !== '') {
                $messages[] = $archivedAtError;
                $fieldErrors['archived_at'] = [$archivedAtError];
            }

            return $this->adminLayout('admin/listings/add.php', 'Add Listing', 'listings', [
                'tfl_cats'        => $tflCats,
                'tafl_cats'       => $taflCats,
                'custom_networks' => $customNetworks,
                'custom_cats'     => $customCats,
                'old'             => $request->postAll(),
                'error'           => implode(' ', $messages),
                'field_errors'    => $fieldErrors,
                'default_date'    => date('Y-m-d'),
            ], '', [
                $this->crumb('Owned Listings', $this->adminUrl('/admin/listings')),
            ]);
        }

        $image     = null;
        $imageFile = $request->file('image') ?? [];
        $uploadErr = (int) ($imageFile['error'] ?? UPLOAD_ERR_NO_FILE);
        // Asked before an image or a new category is saved, so a listing table that can't be
        // read leaves nothing behind.
        $formError = $listingRepo->insertProblem() ?? '';

        if ($formError === '' && $uploadErr !== UPLOAD_ERR_NO_FILE) {
            if ($uploadErr !== UPLOAD_ERR_OK) {
                $formError = 'Image upload failed (error code ' . $uploadErr . ').';
            } else {
                $tmp      = (string) ($imageFile['tmp_name'] ?? '');
                $fileSize = (int) ($imageFile['size'] ?? 0);

                if ($tmp === '' || !is_uploaded_file($tmp)) {
                    $formError = 'Invalid image upload. Please try again.';
                } elseif ($fileSize <= 0) {
                    $formError = 'Image file is empty.';
                } elseif ($fileSize > 2 * 1024 * 1024) {
                    $formError = 'Image must be 2 MB or smaller.';
                } else {
                    $mime     = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
                    $allowed  = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                    if (!in_array($mime, $allowed, true)) {
                        $formError = 'Image must be JPEG, PNG, GIF, or WebP.';
                    } elseif (@getimagesize($tmp) === false) {
                        $formError = 'The uploaded file is not a valid image.';
                    } else {
                        $extMap   = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
                        $dir      = dirname(__DIR__, 3) . '/public/uploads/listings/';
                        if (!is_dir($dir)) {
                            mkdir($dir, 0755, true);
                        }
                        $filename = 'fl_' . bin2hex(random_bytes(8)) . '.' . $extMap[$mime];
                        if (!move_uploaded_file($tmp, $dir . $filename)) {
                            $formError = 'Could not save uploaded image. Please try again.';
                        } else {
                            $image = $filename;
                        }
                    }
                }
            }
        }

        if ($formError !== '') {
            return $this->adminLayout('admin/listings/add.php', 'Add Listing', 'listings', [
                'tfl_cats'        => $tflCats,
                'tafl_cats'       => $taflCats,
                'custom_networks' => $customNetworks,
                'custom_cats'     => $customCats,
                'old'             => $request->postAll(),
                'error'           => $formError,
                'default_date'    => date('Y-m-d'),
            ], '', [
                $this->crumb('Owned Listings', $this->adminUrl('/admin/listings')),
            ]);
        }

        $statusInt = match(true) {
            $status === 'current'  => 2,
            $status === 'upcoming' => 1,
            default               => 0,
        };

        $catIds   = self::createCategories($categoryRepo, $catPicks);
        $catidStr = $catIds ? '|' . implode('|', $catIds) . '|' : '';

        $highestBefore = null;
        try {
            $highestBefore = $listingRepo->highestId();
            $newId = $listingRepo->insert($title, $subject, $url, $email, $statusInt, $image, !empty($catIds) ? $catIds[0] : null, $opened, $catidStr, $catIds);
        } catch (\RuntimeException $e) {
            error_log('[Luster] Add Listing: saving the listing failed: ' . $e->getMessage());

            // An error doesn't prove nothing was saved: a MyISAM table can't undo a write.
            // Only reading the table back can say. If it can't, the image stays, because
            // the listing may be using it, and a retry could add a second listing.
            if ($highestBefore !== null && !$listingRepo->nothingAddedSince($highestBefore)) {
                $this->app->getSession()->set('lstr_flash_error',
                    "Something went wrong while saving this listing, and Luster can't be sure whether it was added. If it's in the list below, it isn't finished: delete it, then add it again.");
                return Response::redirect($this->app->getBaseUrl() . '/admin/listings');
            }

            if ($image !== null && is_file(dirname(__DIR__, 3) . '/public/uploads/listings/' . $image)) {
                unlink(dirname(__DIR__, 3) . '/public/uploads/listings/' . $image);
            }

            return $this->adminLayout('admin/listings/add.php', 'Add Listing', 'listings', [
                'tfl_cats'        => $tflCats,
                'tafl_cats'       => $taflCats,
                'custom_networks' => $customNetworks,
                'custom_cats'     => $customCats,
                'old'             => $request->postAll(),
                'error'           => "Couldn't save this listing because of a database error, so it wasn't added. Please try again.",
                'default_date'    => date('Y-m-d'),
            ], '', [
                $this->crumb('Owned Listings', $this->adminUrl('/admin/listings')),
            ]);
        }

        // Unprefixed schema only: a fresh listing has nowhere to store members
        // until it has its own physical member table (dbtable was left blank
        // by insert() above) — provision one now, no-op on the enth_ schema.
        $listingRepo->provisionMemberTable($newId, $subject);

        // Create embed settings row — leave form URLs blank so they inherit
        // from the global defaults in Owned Listings Settings.
        $settingsRepo = new ListingSettingsRepo($db);
        $settingsRepo->saveEmbedSettings(
            $newId,
            '',                  // form_url_join (inherits global)
            '',                  // form_url_update (inherits global)
            '',                  // form_url_reset (inherits global)
            '',                  // form_url_codes_submit (inherits global)
            true,                // codes_submit_enabled
            '',                  // db_host
            '',                  // db_name
            '',                  // db_user
            '',                  // db_pass
        );
        $settingsRepo->saveListingType($newId, $listingType);
        $settingsRepo->saveDescription($newId, $description);
        if ($image !== null) {
            // The registry insert stores imagefile for Enthusiast compat, but the admin
            // UI and public renderers read from lstr_fanlisting_settings.image — sync both.
            $settingsRepo->saveImage($newId, $image);
        }

        if ($isArchiving) {
            $lifecycleRepo = new ListingLifecycleRepo($db);
            $lifecycleRepo->upsert($newId, $status, false, false, $adoptedToName, $adoptedToUrl, $archiveNote, $archivedAtValue);
            $session      = $this->app->getSession();
            $activityRepo = new ActivityRepo($db);
            $activityRepo->log(
                (int) $session->get('lstr_admin_id'),
                'listing.archive',
                "Archived new listing #{$newId} ({$title}) as " . ($status === 'adopted_out' ? 'Adopted Out' : 'Closed'),
                $request->ip(),
            );
        }

        return Response::redirect($this->app->getBaseUrl() . '/admin/listings');
    }

    /**
     * Handles bulk operations on selected listings: mark_updated (touch
     * last_updated) or delete. Supports a "select all matching filter" mode
     * that re-applies the current search/network/category/tab to derive the
     * full set server-side.
     */
    public function bulk(Request $request, array $params): Response
    {
        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $action    = (string) $request->post('action', '');
        $selectAll = $request->post('select_all', '0') === '1';

        if ($selectAll) {
            $saTab     = trim((string) $request->post('sa_filter_tab', 'all'));
            if (!in_array($saTab, ['all', 'current', 'upcoming', 'pending'], true)) { $saTab = 'all'; }
            $saSearch  = trim((string) $request->post('sa_filter_search', ''));
            $saNetwork = trim((string) $request->post('sa_filter_network', ''));
            $saCat     = trim((string) $request->post('sa_filter_cat', ''));
            $saType    = trim((string) $request->post('sa_filter_type', ''));

            $listingRepoAll  = new ListingRepo($db, $config);
            $settingsRepoAll = new ListingSettingsRepo($db);
            $allListings     = $saSearch !== '' ? $listingRepoAll->search($saSearch) : $listingRepoAll->getAll();
            $allCatData      = [];
            $allListingIds   = array_map(static fn($fl) => $fl->id, $allListings);
            $junctionData    = $listingRepoAll->getCategoryDataByListingIds($allListingIds);

            foreach ($allListings as $fl) {
                $allCatData[$fl->id] = $junctionData !== null ? ($junctionData[$fl->id] ?? []) : $listingRepoAll->getCatItems((string) ($fl->catid ?? ''), $fl->categoryId);
            }

            if ($saNetwork !== '') {
                $netUpper   = strtoupper($saNetwork);
                $allListings = array_values(array_filter($allListings, static function ($fl) use ($allCatData, $netUpper): bool {
                    foreach ($allCatData[$fl->id] as $cat) {
                        if (strtoupper($cat['network']) === $netUpper) { return true; }
                    }
                    return false;
                }));
            }
            if ($saCat !== '') {
                $netUpper   = strtoupper($saNetwork);
                $allListings = array_values(array_filter($allListings, static function ($fl) use ($allCatData, $saCat, $netUpper): bool {
                    foreach ($allCatData[$fl->id] as $cat) {
                        if (strcasecmp($cat['name'], $saCat) === 0 && ($netUpper === '' || strtoupper($cat['network']) === $netUpper)) { return true; }
                    }
                    return false;
                }));
            }
            if ($saType !== '') {
                $allListings = array_values(array_filter($allListings, static fn($fl): bool => ($settingsRepoAll->getListingType($fl->id)) === $saType));
            }
            if ($saTab !== 'all') {
                $allListings = array_values(array_filter($allListings, static function ($fl) use ($saTab): bool {
                    if ($saTab === 'current')  { return !$fl->upcoming && !$fl->trouble; }
                    if ($saTab === 'upcoming') { return $fl->upcoming; }
                    if ($saTab === 'pending')  { return $fl->trouble; }
                    return true;
                }));
            }

            $selected = array_map(static fn($fl): int => $fl->id, $allListings);
        } else {
            $selected = array_filter(array_map('intval', (array) $request->post('selected', [])));
        }

        if ($action === 'mark_updated' && !empty($selected)) {
            $listingRepo  = new ListingRepo($db, $config);
            $settingsRepo = new ListingSettingsRepo($db);
            $activityRepo = new ActivityRepo($db);
            $updated      = 0;

            foreach ($selected as $id) {
                $listing = $listingRepo->getById($id);
                if ($listing === null) {
                    continue;
                }

                $settingsRepo->touchLastUpdated($id);
                $updated++;
            }

            $activityRepo->log(
                (int) $session->get('lstr_admin_id'),
                'listing.mark_updated',
                "Bulk-marked {$updated} listing(s) as updated",
                $request->ip(),
            );

            $session->set('lstr_flash_success', "Last Updated date set to today for {$updated} listing(s).");
        }

        if (($action === 'archive_closed' || $action === 'archive_adopted_out') && !empty($selected)) {
            $listingRepo     = new ListingRepo($db, $config);
            $lifecycleRepo   = new ListingLifecycleRepo($db);
            $lifecycleSvc    = new ListingLifecycleService($listingRepo, $lifecycleRepo);
            $activityRepo    = new ActivityRepo($db);
            $targetStatus    = $action === 'archive_closed' ? ListingLifecycleService::STATUS_CLOSED : ListingLifecycleService::STATUS_ADOPTED_OUT;
            $archived        = 0;

            foreach ($selected as $id) {
                if ($lifecycleSvc->archive($id, $targetStatus)) {
                    $archived++;
                }
            }

            $activityRepo->log(
                (int) $session->get('lstr_admin_id'),
                'listing.archive',
                "Bulk-archived {$archived} listing(s) as " . ($targetStatus === 'adopted_out' ? 'Adopted Out' : 'Closed'),
                $request->ip(),
            );

            $session->set('lstr_flash_success', "Archived {$archived} listing(s).");
        }

        if ($action === 'reopen' && !empty($selected)) {
            $listingRepo   = new ListingRepo($db, $config);
            $lifecycleRepo = new ListingLifecycleRepo($db);
            $lifecycleSvc  = new ListingLifecycleService($listingRepo, $lifecycleRepo);
            $activityRepo  = new ActivityRepo($db);
            $reopened      = 0;

            foreach ($selected as $id) {
                if ($lifecycleSvc->reopen($id)) {
                    $reopened++;
                }
            }

            $activityRepo->log(
                (int) $session->get('lstr_admin_id'),
                'listing.reopen',
                "Bulk-reopened {$reopened} listing(s)",
                $request->ip(),
            );

            $session->set('lstr_flash_success', "Reopened {$reopened} listing(s).");
        }

        if ($action === 'delete' && !empty($selected)) {
            $listingRepo  = new ListingRepo($db, $config);
            $activityRepo = new ActivityRepo($db);
            $deleted      = 0;

            foreach ($selected as $id) {
                $listing = $listingRepo->getById($id);
                if ($listing === null) {
                    continue;
                }

                $this->purgeListingData($id, $listing->dbprefix);

                $listingRepo->delete($id);

                $activityRepo->log(
                    (int) $session->get('lstr_admin_id'),
                    'listing.delete',
                    "Deleted listing #{$id} ({$listing->title}) via bulk action",
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

    /**
     * Tabbed listing detail view. GET renders the full edit form across all tabs
     * (general, members, codes, affiliates, fields, templates, settings, email
     * templates). POST saves the general-tab fields: title, URL, email, status,
     * subject, opened date, categories, image, listing type, per-page, contact,
     * join settings, affiliate apply, approved-by, description, and last-updated.
     */
    public function edit(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $listingRepo  = new ListingRepo($db, $config);
        $settingsRepo = new ListingSettingsRepo($db);
        $activityRepo = new ActivityRepo($db);
        $categoryRepo = new CategoryRepo($db);
        // Members/affiliates/fields/codes repos are no longer needed here directly —
        // buildEditFormViewData() builds its own local copies of those, shared by
        // both the GET render and the POST-validation-failure re-render.

        $listing = $listingRepo->getById($id);

        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        $lifecycleRepo  = new ListingLifecycleRepo($db);
        $lifecycleRow   = $lifecycleRepo->getByListingId($id);
        $listing        = $listing->withLifecycle($lifecycleRow['lifecycle_status'] ?? null);
        // The real last-saved date, truncated to Y-m-d for the date input — never
        // sourced from $old, so the "Reset" button always restores the actual
        // saved value even after a validation failure on some other field.
        $archivedAtRaw          = (string) ($lifecycleRow['archived_at'] ?? '');
        $archivedAtOriginalVal  = strlen($archivedAtRaw) >= 10 ? substr($archivedAtRaw, 0, 10) : '';
        // An archived listing whose date is unknown: "Date unknown" starts ticked,
        // and Reset goes back to that.
        $archivedAtUnknownOriginal = $lifecycleRow !== null && $lifecycleRow['archived_at'] === null;

        // Previous Owners — fetched once up front since both the GET render and
        // the POST-validation-failure render need them (see edit.php's Previous
        // Owners section). The discoverability hint checks the *resolved* Stats
        // template (per-listing override -> global default -> built-in default,
        // same resolution stats.php itself uses) for any of the four public
        // token forms, not just the flat {previous_owners} token.
        $previousOwnersRepo = new PreviousOwnersRepo($db);
        $previousOwners     = $previousOwnersRepo->getForListing($id);

        $siteSettingsRepo    = new SiteSettingsRepo($db);
        $resolvedStatsTemplate = ListingTemplateResolver::resolve(
            $settingsRepo->getTemplates($id)['stats_template'] ?? null,
            $siteSettingsRepo->getGlobalTemplates()['listing_default_stats_template'] ?? null,
            ListingSettingsRepo::defaultStatsTemplate(),
        )['value'];
        $statsTemplateHasPreviousOwnersToken =
            str_contains($resolvedStatsTemplate, '{previous_owners}')
            || str_contains($resolvedStatsTemplate, '{each_previous_owner}')
            || str_contains($resolvedStatsTemplate, '{previous_owners_count}')
            || str_contains($resolvedStatsTemplate, '{if_previous_owners}');

        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        // ---- POST: save ----
        if ($request->isPost()) {
            $title       = trim((string) $request->post('title', ''));
            $url         = self::httpsify(trim((string) $request->post('url', '')));
            $email       = trim((string) $request->post('email', ''));
            $status      = (string) $request->post('status', 'current');
            $allowedStatuses = ['current', 'upcoming', 'pending', 'closed', 'adopted_out'];
            if (!in_array($status, $allowedStatuses, true)) {
                $status = 'current';
            }
            $isArchiving = ListingLifecycleService::isLifecycleStatus($status);
            $adoptedToName = $status === 'adopted_out' ? trim((string) $request->post('adopted_to_name', '')) : null;
            $adoptedToUrl  = $status === 'adopted_out' ? self::httpsify(trim((string) $request->post('adopted_to_url', ''))) : null;
            $archiveNote   = $isArchiving ? trim((string) $request->post('archive_note', '')) : null;
            $archivedAtInput   = $isArchiving ? trim((string) $request->post('archived_at', '')) : null;
            $archivedAtUnknown = $request->post('archived_at_unknown') === '1';
            [$archivedAtValue, $archivedAtError] = $isArchiving
                ? self::resolveArchivedAt(
                    (string) $archivedAtInput,
                    $archivedAtUnknown,
                    $lifecycleRow,
                    !$archivedAtUnknown || $lifecycleRepo->supportsUnknownDate(),
                )
                : ['', ''];
            $subject     = trim((string) $request->post('subject', ''));
            if ($title === '') {
                $title = $subject;
            }
            $openedRaw   = trim((string) $request->post('opened', ''));
            // upcoming/pending have no real opened date yet; closed/adopted_out
            // were open before being archived, so preserve their real date
            // instead of overwriting it with TBA on every save.
            $opened      = in_array($status, ['upcoming', 'pending'], true)
                ? null
                : ($openedRaw !== '' ? $openedRaw : null);
            // Only read here; the categories are created once the save has passed
            // validation (below), so a refused save doesn't leave new ones behind.
            $catPicks    = self::categoryPicks($request);
            $deleteImage = $request->post('delete_image') === '1';

            $allowedTypes = ['listing', 'clique', 'collective', 'shrine', 'webring', 'other'];
            $listingType  = (string) ($request->post('listing_type', 'listing'));
            if (!in_array($listingType, $allowedTypes, true)) {
                $listingType = 'listing';
            }

            $approvedByInput = (string) $request->post('approved_by', '');
            if (!in_array($approvedByInput, ['tfl', 'tafl'], true)) {
                $approvedByInput = '';
            }

            $description = trim((string) $request->post('description', ''));

            $forAdoptionInput = $this->app->isAddonInstalled('Adoption')
                             && $request->post('for_adoption') === '1';

            // members_per_page lives on the Templates tab's own form now
            // (saveTemplates()) — not read or saved here, so saving the
            // General tab can never clobber it.
            $contactEnabled   = $request->post('contact_enabled') === '1';
            $contactRecipient = trim((string) $request->post('contact_recipient', ''));
            if ($contactRecipient !== '' && !filter_var($contactRecipient, FILTER_VALIDATE_EMAIL)) {
                $contactRecipient = '';
            }

            // The "Allow Codes Donations" checkbox lives on this (General) tab,
            // but the column it controls is written via saveEmbedSettings() —
            // shared with the Embed/Advanced tabs' own forms, neither of which
            // carries this field. Read the rest of that row's current values
            // so this save only changes codes_submit_enabled, nothing else.
            $codesSubmitEnabledInput = $request->post('codes_submit_enabled') === '1';

            $affiliateApplyEnabled = $request->post('affiliate_apply_enabled') === '1';
            $existingAffApplySettings = $settingsRepo->getAffiliateApplySettings($id);
            $affiliateApplyFormUrl = $existingAffApplySettings['form_url'];
            $affiliateApplyRedirectUrl = $existingAffApplySettings['redirect_url'];

            // affiliate_apply_style/toggle_label, codes_submit_style/toggle_label,
            // and stat_newmembers_limit live on the Templates tab's own form now
            // (saveTemplates()) — not read or saved here, so saving the General
            // tab can never clobber them.

            $queueUpdates     = $request->post('queue_updates') === '1';

            // Read early — purely so a validation failure can redraw the form with
            // what was actually just typed instead of stale DB values. The
            // authoritative versions of these are read again further down once the
            // submission is confirmed valid, since a couple depend on data (e.g. the
            // previous approved_by, for the "clear approval_url on network change"
            // rule) that only matters once we know the save is going through.
            $lastUpdatedInputRaw  = trim((string) $request->post('fl_last_updated', ''));
            $approvalUrlInputRaw  = trim((string) $request->post('approval_url', ''));
            $codeApprovalEmailRaw = $request->post('code_approval_email');

            // --- Image upload validation (before moving any file) ---
            $imageError  = '';
            $newFilename = null;
            $tmpPath     = '';
            $imageFile   = $request->file('image') ?? [];

            $uploadErr = (int) ($imageFile['error'] ?? UPLOAD_ERR_NO_FILE);

            if ($uploadErr !== UPLOAD_ERR_NO_FILE) {
                if ($uploadErr !== UPLOAD_ERR_OK) {
                    $imageError = 'Image upload failed (error code ' . $uploadErr . ').';
                } else {
                    $tmpPath  = (string) ($imageFile['tmp_name'] ?? '');
                    $fileSize = (int) ($imageFile['size'] ?? 0);

                    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
                        $imageError = 'Invalid image upload. Please try again.';
                    } elseif ($fileSize <= 0) {
                        $imageError = 'Image file is empty.';
                    } elseif ($fileSize > 2 * 1024 * 1024) {
                        $imageError = 'Image must be 2 MB or smaller.';
                    } else {
                        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                        $finfo        = new \finfo(FILEINFO_MIME_TYPE);
                        $mime         = $finfo->file($tmpPath);

                        if ($mime === false || !in_array($mime, $allowedMimes, true)) {
                            $imageError = 'Image must be JPEG, PNG, GIF, or WebP.';
                        } elseif (@getimagesize($tmpPath) === false) {
                            $imageError = 'The uploaded file is not a valid image.';
                        } else {
                            $extMap = [
                                'image/jpeg' => 'jpg',
                                'image/png'  => 'png',
                                'image/gif'  => 'gif',
                                'image/webp' => 'webp',
                            ];
                            $newFilename = 'fl_' . bin2hex(random_bytes(8)) . '.' . $extMap[$mime];
                        }
                    }
                }
            }

            // --- Previous Owners validation ---
            $previousOwnerNamesRaw = (array) $request->post('previous_owner_name', []);
            $previousOwnerUrlsRaw  = (array) $request->post('previous_owner_url', []);
            [$previousOwnerRows, $previousOwnerError] = self::validatePreviousOwnerRows($previousOwnerNamesRaw, $previousOwnerUrlsRaw);

            // --- Field validation ---
            $v = new Validator([
                'subject'         => $subject,
                'title'           => $title,
                'url'             => $url,
                'email'           => $email,
                'adopted_to_name' => (string) ($adoptedToName ?? ''),
                'adopted_to_url'  => (string) ($adoptedToUrl  ?? ''),
            ]);
            $v->field('subject')->required()->maxLength(255);
            $v->field('title')->maxLength(255);
            // URL is optional once a listing is archived (Closed/Adopted Out) — still
            // validated for format/length when present, just not required.
            $urlField = $v->field('url');
            if (!$isArchiving) {
                $urlField->required();
            }
            // 255, not 500 — matches the owned.url column width (install/index.php).
            $urlField->httpUrl()->maxLength(255);
            $v->field('email')->required()->email()->maxLength(255);
            $v->field('adopted_to_name')->maxLength(255);
            $v->field('adopted_to_url')->httpUrl()->maxLength(500);

            if ($imageError !== '' || $previousOwnerError !== '' || $archivedAtError !== '' || $v->fails()) {
                $messages = $v->fails() ? $v->allErrors() : [];
                if ($imageError !== '') {
                    array_unshift($messages, $imageError);
                }
                $fieldErrors = $v->errors();
                if ($previousOwnerError !== '') {
                    $messages[] = $previousOwnerError;
                    $fieldErrors['previous_owners'] = [$previousOwnerError];
                }
                if ($archivedAtError !== '') {
                    $messages[] = $archivedAtError;
                    $fieldErrors['archived_at'] = [$archivedAtError];
                }

                $tflCatsErr     = $categoryRepo->getBySource('tfl');
                $taflCatsErr    = $categoryRepo->getBySource('tafl');
                $customNetsErr  = $categoryRepo->getCustomSources();
                $customCatsErr  = [];
                foreach ($customNetsErr as $cn) {
                    $customCatsErr[$cn] = $categoryRepo->getBySource($cn);
                }

                $baseEditViewData = $this->buildEditFormViewData($id, $listing, $request);
                $codeApprovalEmailOverride = $codeApprovalEmailRaw !== null
                    ? ($codeApprovalEmailRaw === '' ? null : ($codeApprovalEmailRaw === '1'))
                    : ($baseEditViewData['code_approval_email'] ?? null);

                return $this->adminLayout('admin/listings/edit.php', 'Edit Listing', 'listings', array_merge(
                    $baseEditViewData,
                    [
                        'listing'          => $listing,
                        'success'             => '',
                        'error'               => implode(' ', $messages),
                        'field_errors'        => $fieldErrors,
                        'previous_owners'     => $previousOwners,
                        'stats_template_has_previous_owners_token' => $statsTemplateHasPreviousOwnersToken,
                        'archived_at_original' => $archivedAtOriginalVal,
                        'archived_at_unknown_original' => $archivedAtUnknownOriginal,
                        'old'                 => [
                            'subject'            => $subject,
                            'title'              => $title,
                            'url'                => $url,
                            'email'              => $email,
                            'status'             => $status,
                            'opened'             => $openedRaw,
                            'adopted_to_name'    => $adoptedToName ?? '',
                            'adopted_to_url'     => $adoptedToUrl  ?? '',
                            'archive_note'       => $archiveNote   ?? '',
                            'archived_at'        => $archivedAtInput ?? '',
                            // Explicit either way, so a box the owner just unticked
                            // stays unticked even when the saved date is unknown.
                            'archived_at_unknown' => $archivedAtUnknown ? '1' : '0',
                            'cat_network'        => (array) $request->post('cat_network', []),
                            'cat_name'           => (array) $request->post('cat_name', []),
                            'cat_network_other'  => (array) $request->post('cat_network_other', []),
                            'previous_owner_name' => $previousOwnerNamesRaw,
                            'previous_owner_url'  => $previousOwnerUrlsRaw,
                        ],
                        'lifecycle_meta'          => $lifecycleRow,
                        // Overrides for the fields that were actually part of this
                        // failed submission — show what was just typed, not stale
                        // DB values buildEditFormViewData() would otherwise supply.
                        'tfl_cats'                => $tflCatsErr,
                        'tafl_cats'               => $taflCatsErr,
                        'custom_networks'         => $customNetsErr,
                        'custom_cats'             => $customCatsErr,
                        'listing_type'            => $listingType,
                        'contact_enabled'         => $contactEnabled,
                        'contact_recipient'       => $contactRecipient,
                        'queue_updates'           => $queueUpdates,
                        'description'             => $description,
                        'fl_last_updated'         => $lastUpdatedInputRaw,
                        'approved_by'             => $approvedByInput,
                        'approval_url'            => $approvalUrlInputRaw,
                        'for_adoption'            => $forAdoptionInput,
                        'affiliate_apply_enabled' => $affiliateApplyEnabled,
                        'embed_settings'          => array_merge(
                            $baseEditViewData['embed_settings'] ?? [],
                            ['codes_submit_enabled' => $codesSubmitEnabledInput],
                        ),
                        'code_approval_email'     => $codeApprovalEmailOverride,
                    ],
                ), '', [
                    $this->crumb('Owned Listings', $this->adminUrl('/admin/listings')),
                    $this->crumb($listing->subject),
                ]);
            }

            // --- All valid: persist ---
            $catids    = self::createCategories($categoryRepo, $catPicks);
            $catidStr  = $catids ? '|' . implode('|', $catids) . '|' : '';

            // Lifecycle row: upsert when archiving (snapshotting the PRE-save upcoming/
            // trouble so a future Reopen restores correctly), delete otherwise (no-op
            // when the listing wasn't archived). $listing here is the row fetched before
            // this POST was processed (with lifecycle already applied above), so its
            // upcoming/trouble/lifecycle reflect the prior state.
            $wasArchived = $listing->lifecycle !== null;
            if ($isArchiving) {
                // Preserve the original pre-archive snapshot when changing between archived
                // statuses (e.g. Closed → Adopted Out). If we re-snapshot from the current
                // registry flags they are already inactive (upcoming=false, trouble=true),
                // which would overwrite the true original state and break Reopen.
                $snapshotUpcoming = $wasArchived ? (bool) ($lifecycleRow['legacy_upcoming'] ?? false) : $listing->upcoming;
                $snapshotTrouble  = $wasArchived ? (bool) ($lifecycleRow['legacy_trouble']  ?? false) : $listing->trouble;
                $lifecycleRepo->upsert($id, $status, $snapshotUpcoming, $snapshotTrouble, $adoptedToName, $adoptedToUrl, $archiveNote, $archivedAtValue);
                $activityRepo->log(
                    (int) $session->get('lstr_admin_id'),
                    'listing.archive',
                    "Archived listing #{$id} ({$title}) as " . ($status === 'adopted_out' ? 'Adopted Out' : 'Closed'),
                    $request->ip(),
                );
            } else {
                $lifecycleRepo->delete($id);
                if ($wasArchived) {
                    $activityRepo->log(
                        (int) $session->get('lstr_admin_id'),
                        'listing.reopen',
                        "Reopened listing #{$id} ({$title})",
                        $request->ip(),
                    );
                }
            }

            $upcoming = $isArchiving ? false : ($status === 'upcoming');
            $trouble  = $isArchiving ? true  : ($status === 'pending');

            $lastUpdatedInput = trim((string) $request->post('fl_last_updated', ''));
            // Validate: must be empty or a parseable date in YYYY-MM-DD format
            if ($lastUpdatedInput !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $lastUpdatedInput)) {
                $lastUpdatedInput = '';
            }

            $listingRepo->updateMeta($id, $title, $url, $email, $upcoming, $trouble);
            $listingRepo->update($id, !empty($catids) ? $catids[0] : null, $subject, $opened, $catidStr, $catids);
            $settingsRepo->saveListingType($id, $listingType);
            $settingsRepo->saveContactSettings($id, $contactEnabled, $contactRecipient !== '' ? $contactRecipient : null);
            $existingEmbed = $settingsRepo->getEmbedSettings($id);
            $settingsRepo->saveEmbedSettings(
                $id,
                $existingEmbed['form_url_join'],
                $existingEmbed['form_url_update'],
                $existingEmbed['form_url_reset'],
                $existingEmbed['form_url_codes_submit'],
                $codesSubmitEnabledInput,
                $existingEmbed['db_host'],
                $existingEmbed['db_name'],
                $existingEmbed['db_user'],
                $existingEmbed['db_pass'],
            );
            // Read the current network + URL before overwriting so we can detect changes.
            $prevApprovedBy   = $settingsRepo->getApprovedBy($id);
            $prevApprovalUrl  = (string) ($settingsRepo->getApprovalUrl($id) ?? '');
            $settingsRepo->saveApprovedBy($id, $approvedByInput);
            $approvalUrlInput = trim((string) $request->post('approval_url', ''));
            // Clear a leftover approval URL when the network changes, so a stale TFL
            // URL is never shown next to a freshly selected TAFL badge — but only when
            // the submitted URL still matches what was already stored, i.e. the admin
            // didn't actually enter/look up a fresh one for the new network in this
            // same submission. Otherwise a same-submission network change + new URL
            // (e.g. via the lookup button) would silently discard the URL just typed.
            if ($prevApprovedBy !== $approvedByInput && $approvalUrlInput === $prevApprovalUrl) {
                $approvalUrlInput = '';
            }
            // Reject non-http(s) schemes to prevent javascript:/data: hrefs reaching public templates.
            if ($approvalUrlInput !== '' && !preg_match('#^https?://#i', $approvalUrlInput)) {
                $approvalUrlInput = '';
            }
            $settingsRepo->saveApprovalUrl($id, $approvalUrlInput !== '' ? $approvalUrlInput : null);
            $settingsRepo->saveDescription($id, $description);
            if ($this->app->isAddonInstalled('Adoption')) {
                $settingsRepo->saveForAdoption($id, $forAdoptionInput);
            }
            $settingsRepo->saveAffiliateApplySettings($id, $affiliateApplyEnabled, $affiliateApplyFormUrl, $affiliateApplyRedirectUrl);
            $postData = $request->postAll();

            // code_approval_email: null=inherit global, true=on, false=off
            // Only save when the dropdown was present in the form (codes_submit was enabled at page load)
            if (array_key_exists('code_approval_email', $postData)) {
                $codeEmailPost = $request->post('code_approval_email', '');
                $codeEmailVal  = $codeEmailPost === '' ? null : ($codeEmailPost === '1');
                $settingsRepo->setCodeApprovalEmail($id, $codeEmailVal);
            }
            $settingsRepo->setQueueUpdates($id, $queueUpdates);
            if ($lastUpdatedInput !== '' || $request->post('fl_last_updated') !== null) {
                $settingsRepo->setLastUpdated($id, $lastUpdatedInput !== '' ? $lastUpdatedInput : null);
            }

            if ($newFilename !== null) {
                $uploadDir = $this->ensureUploadDirectory();
                $destPath  = $uploadDir . '/' . $newFilename;

                if (!move_uploaded_file($tmpPath, $destPath)) {
                    $session->set('lstr_flash_error', 'Could not save the uploaded image. Please try again.');
                    return Response::redirect($baseUrl . '/admin/listings/' . $id . '/edit');
                }

                $oldFilename = $settingsRepo->getImage($id);
                if ($oldFilename !== null && $oldFilename !== '') {
                    $oldPath = $uploadDir . '/' . $oldFilename;
                    if (is_file($oldPath)) {
                        unlink($oldPath);
                    }
                }

                $settingsRepo->saveImage($id, $newFilename);
            } elseif ($deleteImage) {
                $oldFilename = $settingsRepo->getImage($id);
                if ($oldFilename !== null && $oldFilename !== '') {
                    $uploadDir = $this->ensureUploadDirectory();
                    $oldPath   = $uploadDir . '/' . $oldFilename;
                    if (is_file($oldPath)) {
                        unlink($oldPath);
                    }
                    $settingsRepo->saveImage($id, '');
                }
            }

            $previousOwnersRepo->replaceForListing($id, $previousOwnerRows);

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
        $success            = (string) $session->get('lstr_flash_success', '');
        $error              = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->adminLayout('admin/listings/edit.php', 'Edit Listing', 'listings', array_merge(
            $this->buildEditFormViewData($id, $listing, $request),
            [
                'listing'          => $listing,
                'success'             => $success,
                'error'               => $error,
                'old'                 => [],
                'previous_owners'     => $previousOwners,
                'stats_template_has_previous_owners_token' => $statsTemplateHasPreviousOwnersToken,
                'archived_at_original' => $archivedAtOriginalVal,
                'archived_at_unknown_original' => $archivedAtUnknownOriginal,
                'lifecycle_meta'      => $lifecycleRow,
            ],
        ), '', [
            $this->crumb('Owned Listings', $this->adminUrl('/admin/listings')),
            $this->crumb($listing->subject),
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/{id}/delete
    // -----------------------------------------------------------------------

    /**
     * Deletes a listing and all associated data: member table, settings,
     * affiliates, pending updates, email templates, codes (with category map),
     * add-on data (Updates, KIM), and the listing image file.
     */
    public function delete(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $listingRepo  = new ListingRepo($db, $config);
        $activityRepo = new ActivityRepo($db);

        $listing = $listingRepo->getById($id);

        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        $prefix  = $listing->dbprefix;
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();
        $name    = $listing->title;

        $this->purgeListingData($id, $prefix);

        // Delete the listing registry row
        $listingRepo->delete($id);

        // Log (fanlisting row is gone but session identity is intact)
        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'listing.delete',
            "Deleted listing #{$id} ({$name})",
            $request->ip(),
        );

        $session->set('lstr_flash_success', "Listing \"{$name}\" deleted successfully.");
        return Response::redirect($baseUrl . '/admin/listings');
    }

    /**
     * Deletes every Luster-owned row and uploaded file that references a listing:
     * member table, settings/lifecycle/affiliates/pending-updates/email-templates,
     * listing-category links, codes (with category map), external import refs,
     * add-on data (News Feed, KIM, Adoption applications, Join Rules, template
     * revisions), and the listing's cover image. Does not delete the listing
     * registry row itself —
     * callers do that afterward via ListingRepo::delete(). Shared by delete() and
     * the bulk-delete branch of bulkAction() so both stay in sync.
     *
     * The image filename is fetched before lstr_fanlisting_settings is deleted —
     * ListingSettingsRepo::getImage() reads from that row, so deleting it first
     * would make every image lookup return null and leave the file on disk forever.
     */
    private function purgeListingData(int $id, string $prefix): void
    {
        $db           = $this->app->getDatabase();
        $settingsRepo = new ListingSettingsRepo($db);

        // Drop the member table (only if a table name exists and is safe)
        if ($prefix !== '' && preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) {
            $db->prepare("DROP TABLE IF EXISTS `{$prefix}`")->execute();
        }

        $imageFilename = $settingsRepo->getImage($id);

        // Delete Luster rows that reference this listing
        $db->prepare('DELETE FROM lstr_fanlisting_settings WHERE fanlisting_id = :id')
           ->execute(['id' => $id]);
        // Lifecycle table may not exist yet on an install that hasn't run
        // Migration 93 — deleting a listing must not fail over this one
        // optional cleanup table.
        try {
            $db->prepare('DELETE FROM lstr_listing_lifecycle WHERE fanlisting_id = :id')
               ->execute(['id' => $id]);
        } catch (\Throwable) {}
        $db->prepare('DELETE FROM lstr_affiliates WHERE fanlisting_id = :id')
           ->execute(['id' => $id]);
        $db->prepare('DELETE FROM lstr_pending_updates WHERE fanlisting_id = :id')
           ->execute(['id' => $id]);
        $db->prepare('DELETE FROM lstr_email_templates WHERE fanlisting_id = :id')
           ->execute(['id' => $id]);
        $db->prepare('DELETE FROM lstr_listing_external_refs WHERE listing_id = :id')
           ->execute(['id' => $id]);
        // Previous Owners table may not exist yet on an install that hasn't run
        // Migration 102 — same "silent if not present" treatment as the addon
        // tables below, not a hard requirement for deletion to succeed.
        try {
            $db->prepare('DELETE FROM lstr_listing_previous_owners WHERE fanlisting_id = :id')
               ->execute(['id' => $id]);
        } catch (\Throwable) {}
        try {
            $db->prepare('DELETE FROM lstr_listing_categories WHERE fanlisting_id = :id')
               ->execute(['id' => $id]);
        } catch (\Throwable) {}

        // Codes cascade: map → codes → categories/sizes/donors
        $db->prepare('DELETE lstr_code_category_map FROM lstr_code_category_map
                      INNER JOIN lstr_codes ON lstr_code_category_map.code_id = lstr_codes.id
                      WHERE lstr_codes.fanlisting_id = :id')
           ->execute(['id' => $id]);
        $db->prepare('DELETE FROM lstr_codes WHERE fanlisting_id = :id')
           ->execute(['id' => $id]);
        $db->prepare('DELETE FROM lstr_code_categories WHERE fanlisting_id = :id')
           ->execute(['id' => $id]);
        $db->prepare('DELETE FROM lstr_code_sizes WHERE fanlisting_id = :id')
           ->execute(['id' => $id]);
        $db->prepare('DELETE FROM lstr_code_donors WHERE fanlisting_id = :id')
           ->execute(['id' => $id]);

        // News Feed addon — silent if not installed
        try {
            $db->prepare('DELETE FROM lstr_newsfeed WHERE fanlisting_id = :id')
               ->execute(['id' => $id]);
        } catch (\Throwable) {}

        // KIM addon — silent if not installed
        try {
            $db->prepare('DELETE FROM lstr_kim_password_resets WHERE kim_member_id IN (SELECT id FROM lstr_kim_members WHERE fanlisting_id = :id)')
               ->execute(['id' => $id]);
            $db->prepare('DELETE FROM lstr_kim_members WHERE fanlisting_id = :id')
               ->execute(['id' => $id]);
        } catch (\Throwable) {}

        // Adoption addon — silent if not installed
        try {
            $db->prepare('DELETE FROM lstr_adoption_applications WHERE fanlisting_id = :id')
               ->execute(['id' => $id]);
        } catch (\Throwable) {}

        // Join Rules addon — silent if not installed
        try {
            $db->prepare('DELETE FROM lstr_rules WHERE fanlisting_id = :id')
               ->execute(['id' => $id]);
        } catch (\Throwable) {}

        // Template revisions — silent if not installed
        (new \Luster\Repositories\TemplateRevisionRepo($db))->deleteForListing($id);

        // Delete the image file if one exists
        if ($imageFilename !== null) {
            $imagePath = $this->ensureUploadDirectory() . '/' . $imageFilename;
            if (is_file($imagePath)) {
                unlink($imagePath);
            }
        }
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/{id}/delete-image
    // -----------------------------------------------------------------------

    /**
     * Immediately deletes the listing's image from disk and clears the DB
     * reference. Returns JSON {ok:true} on success or {ok:false,error:...}.
     */
    public function deleteImage(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $db           = $this->app->getDatabase();
        $config       = $this->app->getConfig();
        $session      = $this->app->getSession();
        $listingRepo  = new ListingRepo($db, $config);
        $settingsRepo = new ListingSettingsRepo($db);
        $activityRepo = new ActivityRepo($db);

        $listing = $listingRepo->getById($id);
        if ($listing === null) {
            return Response::json(['ok' => false, 'error' => 'Listing not found.']);
        }

        $filename = $settingsRepo->getImage($id);
        if ($filename === null || $filename === '') {
            return Response::json(['ok' => false, 'error' => 'No image to delete.']);
        }

        $uploadDir = $this->ensureUploadDirectory();
        $path      = $uploadDir . '/' . $filename;
        if (is_file($path)) {
            unlink($path);
        }

        $settingsRepo->saveImage($id, '');

        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'listing.image_delete',
            "Deleted image for listing #{$id} ({$listing->title})",
            $request->ip(),
        );

        return Response::json(['ok' => true]);
    }

    // -----------------------------------------------------------------------
    // GET + POST /admin/listings/{id}/edit-prefix
    // -----------------------------------------------------------------------

    /**
     * Renders the change-table-prefix form (GET) or renames the member table
     * and updates the registry row atomically (POST). Validates that the new
     * prefix is unique across all existing tables and listing registries.
     */
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
        return $this->ensureUploadSubdirectory('listings');
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/{id}/templates
    // -----------------------------------------------------------------------

    /**
     * Saves all per-listing template overrides. A template is stored as NULL
     * when its value matches the built-in default or the current global default,
     * so the cascade ("follow global → built-in") works correctly.
     */
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

        // Null if empty, matches the built-in default, or matches the current
        // global default — so NULL in the DB means "follow cascade" rather than
        // storing a literal copy of a higher-level value.
        $builtIns = [
            'stats_template'                => ListingSettingsRepo::defaultStatsTemplate(),
            'members_template'              => ListingSettingsRepo::defaultMembersTemplate(),
            'affiliates_section_template'   => ListingSettingsRepo::defaultAffiliatesSectionTemplate(),
            'newsfeed_template'              => ListingSettingsRepo::defaultNewsfeedTemplate(),
            'codes_submit_form_template'    => ListingSettingsRepo::defaultCodesSubmitFormTemplate(),
            'join_form_template'            => ListingSettingsRepo::defaultJoinFormTemplate(),
            'affiliate_apply_form_template' => ListingSettingsRepo::defaultAffiliateApplyFormTemplate(),
            'contact_form_template'         => ListingSettingsRepo::defaultContactFormTemplate(),
            'codes_template'                => ListingSettingsRepo::defaultCodesTemplate(),
            'update_form_template'          => ListingSettingsRepo::defaultUpdateFormTemplate(),
            'reset_form_template'           => ListingSettingsRepo::defaultResetFormTemplate(),
        ];
        $globalMap = [
            'stats_template'                => 'listing_default_stats_template',
            'members_template'              => 'listing_default_members_template',
            'affiliates_section_template'   => 'listing_default_affiliates_section_template',
            'newsfeed_template'              => 'listing_default_newsfeed_template',
            'codes_submit_form_template'    => 'listing_default_codes_submit_form_template',
            'join_form_template'            => 'listing_default_join_form_template',
            'affiliate_apply_form_template' => 'listing_default_affiliate_apply_form_template',
            'contact_form_template'         => 'listing_default_contact_form_template',
            'codes_template'                => 'listing_default_codes_template',
            'update_form_template'          => 'listing_default_update_form_template',
            'reset_form_template'           => 'listing_default_reset_form_template',
        ];
        $globalDefaults = (new SiteSettingsRepo($db))->getGlobalTemplates();
        $normalize = static fn(string $v): string => trim(str_replace("\r\n", "\n", $v));
        $nil = static function (string $raw, string $key = '') use ($builtIns, $globalMap, $globalDefaults, $normalize): ?string {
            $n = $normalize($raw);
            if ($n === '') {
                return null;
            }
            if (isset($builtIns[$key]) && $n === $normalize($builtIns[$key])) {
                return null;
            }
            if (isset($globalMap[$key])) {
                $globalVal = $globalDefaults[$globalMap[$key]] ?? null;
                if ($globalVal !== null && $n === $normalize($globalVal)) {
                    return null;
                }
            }
            return $raw;
        };

        $statsTemplate             = $nil((string) $request->post('stats_template',               ''), 'stats_template');
        $membersTemplate           = $nil((string) $request->post('members_template',             ''), 'members_template');
        $affiliatesSectionTemplate = $nil((string) $request->post('affiliates_section_template',  ''), 'affiliates_section_template');
        $updatesTemplate           = $nil((string) $request->post('newsfeed_template',             ''), 'newsfeed_template');
        $codesSubmitFormTemplate    = $nil((string) $request->post('codes_submit_form_template',    ''), 'codes_submit_form_template');
        $joinFormTemplate           = $nil((string) $request->post('join_form_template',              ''), 'join_form_template');
        $affiliateApplyFormTemplate = $nil((string) $request->post('affiliate_apply_form_template',   ''), 'affiliate_apply_form_template');
        $contactFormTemplate        = $nil((string) $request->post('contact_form_template',           ''), 'contact_form_template');
        $updateFormTemplate         = $nil((string) $request->post('update_form_template',            ''), 'update_form_template');
        $resetFormTemplate          = $nil((string) $request->post('reset_form_template',             ''), 'reset_form_template');
        $codesTemplate              = $nil((string) $request->post('codes_template',                  ''), 'codes_template');

        // Blank means "use the global default" — distinct from an explicit
        // choice, same as the template-nulling above. Must NOT default to 25
        // here: that would silently save an explicit per-listing override on
        // every save, permanently shadowing the global "Members Per Page"
        // default even when the admin never touched this field.
        $membersPerPageRaw = trim((string) $request->post('members_per_page', ''));
        $membersPerPage    = $membersPerPageRaw !== '' ? max(10, min(100, (int) $membersPerPageRaw)) : null;

        // null = inherit the global default; explicit 0 ("Show all") or 5
        // ("Truncate after 5") overrides it for this listing only.
        $statNewmembersLimitRaw = trim((string) $request->post('stat_newmembers_limit', ''));
        $statNewmembersLimit    = $statNewmembersLimitRaw !== '' ? ((int) $statNewmembersLimitRaw === 0 ? 0 : 5) : null;

        $affiliateApplyStylePost = trim((string) $request->post('affiliate_apply_style', ''));
        $affiliateApplyStyle     = in_array($affiliateApplyStylePost, ['link', 'button'], true) ? $affiliateApplyStylePost : null;

        $codesSubmitStylePost = trim((string) $request->post('codes_submit_style', ''));
        $codesSubmitStyle     = in_array($codesSubmitStylePost, ['link', 'button'], true) ? $codesSubmitStylePost : null;

        $settingsRepo = new ListingSettingsRepo($db);

        // affiliate_apply_style/toggle_label and codes_submit_style/toggle_label
        // only render on this form when the feature is enabled (edit.php wraps
        // them in a server-side conditional) — that enabled flag is itself
        // saved via the General tab's separate form, so it must be read fresh
        // from the DB here rather than from this request's POST data.
        $postData              = $request->postAll();
        $affiliateApplyEnabled = $settingsRepo->getAffiliateApplySettings($id)['enabled'];
        $codesSubmitEnabled    = $settingsRepo->getEmbedSettings($id)['codes_submit_enabled'];

        // Preserve templates not editable via this form so they are not cleared on save.
        // adopt_form_template is managed by the Adoption addon.
        $existingTemplates  = $settingsRepo->getTemplates($id);
        $adoptFormTemplate  = $existingTemplates['adopt_form_template'];
        $existingCodesTemplate = $settingsRepo->getCodesTemplate($id);

        // Record revisions for any templates about to change.
        $incoming = [
            'stats_template'                => $statsTemplate,
            'members_template'              => $membersTemplate,
            'affiliates_section_template'   => $affiliatesSectionTemplate,
            'newsfeed_template'              => $updatesTemplate,
            'codes_submit_form_template'    => $codesSubmitFormTemplate,
            'join_form_template'            => $joinFormTemplate,
            'affiliate_apply_form_template' => $affiliateApplyFormTemplate,
            'contact_form_template'         => $contactFormTemplate,
            'update_form_template'          => $updateFormTemplate,
            'reset_form_template'           => $resetFormTemplate,
            'codes_template'                => $codesTemplate,
        ];
        $oldValues = array_merge($existingTemplates, ['codes_template' => $existingCodesTemplate]);
        $adminId   = (int) $this->app->getSession()->get('lstr_admin_id');
        (new \Luster\Repositories\TemplateRevisionRepo($db))->recordChanged($id, $oldValues, $incoming, $adminId);

        $settingsRepo->saveTemplates(
            $id, $statsTemplate, $membersTemplate, $affiliatesSectionTemplate,
            $updatesTemplate, $codesSubmitFormTemplate,
            $joinFormTemplate, $affiliateApplyFormTemplate,
            $adoptFormTemplate, $contactFormTemplate,
            $updateFormTemplate, $resetFormTemplate,
        );
        $settingsRepo->saveCodesTemplate($id, $codesTemplate);
        $settingsRepo->saveMembersPerPage($id, $membersPerPage);
        $settingsRepo->setStatNewmembersLimit($id, $statNewmembersLimit);
        if ($affiliateApplyEnabled && array_key_exists('affiliate_apply_style', $postData)) {
            $settingsRepo->setAffiliateApplyStyle($id, $affiliateApplyStyle);
        }
        if ($affiliateApplyEnabled && array_key_exists('affiliate_apply_toggle_label', $postData)) {
            $affLabel = trim((string) $request->post('affiliate_apply_toggle_label', ''));
            $settingsRepo->setAffiliateApplyToggleLabel($id, $affLabel !== '' ? $affLabel : null);
        }
        if ($codesSubmitEnabled && array_key_exists('codes_submit_style', $postData)) {
            $settingsRepo->setCodesSubmitToggleStyle($id, $codesSubmitStyle);
        }
        if ($codesSubmitEnabled && array_key_exists('codes_submit_toggle_label', $postData)) {
            $csLabel = trim((string) $request->post('codes_submit_toggle_label', ''));
            $settingsRepo->setCodesSubmitToggleLabel($id, $csLabel !== '' ? $csLabel : null);
        }

        $this->app->getSession()->set('lstr_flash_success', 'Templates saved successfully.');

        $returnGroup = (string) $request->post('return_group', 'all');
        $validGroups = ['all', 'members', 'join-form', 'update-form', 'reset-form', 'statistics', 'codes', 'codes-form', 'affiliates', 'affiliate-form', 'newsfeed', 'contact-form'];
        if (!in_array($returnGroup, $validGroups, true)) {
            $returnGroup = 'all';
        }
        $groupSuffix = $returnGroup !== 'all' ? '?group=' . $returnGroup : '';
        return Response::redirect($this->app->getBaseUrl() . '/admin/listings/' . $id . '/edit' . $groupSuffix . '#tab-templates');
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/{id}/templates/reset-all
    // -----------------------------------------------------------------------

    /**
     * Clears all per-listing template overrides to NULL, effectively reverting
     * to global defaults (or built-in defaults when no global is set).
     */
    public function resetAllTemplates(Request $request, array $params): Response
    {
        $id      = (int) ($params['id'] ?? 0);
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $listing = (new ListingRepo($db, $config))->getById($id);
        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        $settingsRepo = new ListingSettingsRepo($db);
        $settingsRepo->saveTemplates(
            $id, null, null, null, null, null, null, null, null, null, null, null,
        );
        $settingsRepo->saveCodesTemplate($id, null);

        $session->set('lstr_flash_success', 'All templates reset to global defaults.');
        return Response::redirect($baseUrl . '/admin/listings/' . $id . '/edit#tab-templates');
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/{id}/templates/preview
    // -----------------------------------------------------------------------

    /**
     * Renders a live template preview for a per-listing template type (members,
     * affiliates_section, stats, updates, codes, rules, join_form, etc.) using
     * real listing data where available, falling back to sample data. Returns a
     * minimal standalone HTML page styled with the public stylesheet.
     */
    public function templatesPreview(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $this->app->getCSRF()->validate($request->post('_csrf', ''));

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();
        $appUrl = rtrim((string) $config->get('app_url', ''), '/');

        $type = (string) $request->post('template_type', '');
        $tpl  = (string) $request->post('template_html', '');

        $listingRepo = new ListingRepo($db, $config);
        $listing     = $listingRepo->getById($id);

        if ($listing === null) {
            return $this->previewWrap('<p style="color:#c00">Listing not found.</p>', $appUrl);
        }

        $allowed = ['members', 'affiliates_section', 'stats', 'newsfeed', 'codes', 'codes_submit_form', 'rules', 'join_form', 'affiliate_apply_form', 'contact_form', 'update_form', 'reset_form'];
        if (!in_array($type, $allowed, true)) {
            return $this->previewWrap('<p style="color:#c00">Unknown template type.</p>', $appUrl);
        }
        if ($tpl === '') {
            return $this->previewWrap('<p style="color:#c00">Template is empty.</p>', $appUrl);
        }

        try {
            $uploadsBase = $appUrl . '/uploads';
            $html = match ($type) {
                'members'            => $this->ltplPreviewMembers($tpl, $listing, $db, $config, $uploadsBase . '/members'),
                'affiliates_section' => $this->ltplPreviewAffiliates($tpl, $listing, $db, $uploadsBase . '/affiliates', $appUrl, $request),
                'stats'              => $this->ltplPreviewStats($tpl, $listing, $db, $config, $request),
                'newsfeed'           => $this->ltplPreviewUpdates($tpl, $listing, $db),
                'codes'              => $this->ltplPreviewCodes($tpl, $listing, $db, $request),
                'rules'              => $this->ltplPreviewRules(
                    $tpl,
                    $listing,
                    trim((string) $request->post('gate_label', '')),
                    (string) $request->post('gate_enabled', '1') !== '0',
                    trim((string) $request->post('gate_type', '')),
                ),
                'join_form'            => $this->ltplPreviewJoinForm($tpl, $listing),
                'codes_submit_form'    => $this->ltplPreviewCodesSubmitForm($tpl, $listing),
                'affiliate_apply_form' => $this->ltplPreviewAffiliateApplyForm($tpl, $listing),
                'contact_form'         => $this->ltplPreviewContactForm($tpl, $listing),
                'update_form'          => $this->ltplPreviewUpdateForm($tpl, $listing),
                'reset_form'           => $this->ltplPreviewResetForm($tpl, $listing),
                default                => throw new \LogicException("Unhandled preview type: {$type}"),
            };
        } catch (\Throwable $e) {
            $html = '<p style="color:#c00;font-family:monospace">Preview error: '
                  . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }

        return $this->previewWrap($html, $appUrl);
    }

    /**
     * Wraps a preview fragment in a minimal standalone HTML document, loading
     * the public default stylesheet so previews look like the real public page.
     * Shared by templatesPreview() (per-listing) and globalTemplatesPreview().
     */
    private function previewWrap(string $content, string $appUrl): Response
    {
        $cssUrl = htmlspecialchars(
            $appUrl . '/assets/css/default/style.css',
            ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8',
        );
        $jsUrl = htmlspecialchars(
            $appUrl . '/assets/js/fl-embed.js',
            ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8',
        );
        return Response::html(
            '<!DOCTYPE html><html lang="en"><head>'
            . '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<link rel="stylesheet" href="' . $cssUrl . '?v=' . filemtime(
                rtrim(LUSTER_BASE, '/') . '/public/assets/css/default/style.css'
            ) . '">'
            . '<style>body{padding:1rem;background:#fff;font-family:sans-serif;font-size:14px}</style>'
            . '</head><body>' . $content . '<script src="' . $jsUrl . '"></script></body></html>'
        );
    }

    /**
     * Renders a small section divider used by globalTemplatesPreview() to
     * separate "Sample Data" and "Empty State" renders within one preview.
     */
    private function previewSectionLabel(string $label): string
    {
        return '<div style="margin:1.5rem 0 .5rem;padding:.35rem .6rem;background:#eef1f8;'
            . 'border-left:3px solid #889bd6;font:600 .75rem sans-serif;color:#445;'
            . 'text-transform:uppercase;letter-spacing:.04em">'
            . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</div>';
    }

    private function ltplPreviewMembers(string $tpl, object $listing, \Luster\Core\Database $db, \Luster\Core\Config $config, string $uploadsUrl): string
    {
        $prefix = (string) ($listing->dbprefix ?? '');
        if ($prefix === '') {
            return '<p style="font-style:italic;color:#888">No member table configured for this listing.</p>';
        }

        $fieldRepo = new FieldRepo($db, $config);
        $fields    = $fieldRepo->getFieldsForListing((int) $listing->id);

        $memberRepo = new MemberRepo($db, $config);
        $page       = $memberRepo->paginateByStatus($prefix, 'approved', 1, 10);
        $members    = $page['members'];
        $total      = $page['total'];
        // Empty string, not the admin panel's own date_format setting — this
        // preview must match what a host page sees with no $luster_date_format
        // override (MembersTemplateRenderer falls back to the ordinal style).
        $dateFormat = '';

        $result = MembersTemplateRenderer::render(
            $tpl,
            $members,
            $fields,
            (string) ($listing->url ?? '#'),
            $total,
            $total . ' member(s)',
            '<a class="fl-member-sort-link" href="#">Date Joined</a>',
            '<a class="fl-member-sort-link" href="#">Name</a>',
            '<strong aria-current="page" class="fl-member-sort-link">Country</strong>',
            true,
            $dateFormat,
            false, // Country is the real embed's default sort, not Date Joined — keep the preview consistent
        );

        if (trim($result) === '' && empty($members)) {
            return '<p style="font-style:italic;color:#888">No approved members for this listing yet.</p>';
        }

        // Also show what the {if_sort_date}/{fan_added} join-date block looks like
        // when a fan actually sorts by Date Joined, since the main preview above
        // (matching the embed's real default sort) never renders it. Fetches its
        // own slice via paginate(..., 'added') — paginateByStatus() above doesn't
        // support date ordering, so reusing $members would show these rows in
        // whatever order the default sort left them in, not actually by join date.
        $dateSortedPage = $memberRepo->paginate($prefix, 1, 10, true, 'added');

        $dateSorted = MembersTemplateRenderer::render(
            $tpl,
            $dateSortedPage['members'],
            $fields,
            (string) ($listing->url ?? '#'),
            $dateSortedPage['total'],
            $dateSortedPage['total'] . ' member(s)',
            '<strong aria-current="page" class="fl-member-sort-link">Date Joined</strong>',
            '<a class="fl-member-sort-link" href="#">Name</a>',
            '<a class="fl-member-sort-link" href="#">Country</a>',
            true,
            $dateFormat,
            true,
        );

        if (trim($dateSorted) !== '') {
            $result .= $this->previewSectionLabel('Sorted by Date Joined') . $dateSorted;
        }

        return $result;
    }

    private function ltplPreviewAffiliates(string $tpl, object $listing, \Luster\Core\Database $db, string $uploadsUrl, string $appUrl, ?\Luster\Core\Request $request = null): string
    {
        $affiliateRepo    = new AffiliateRepo($db);
        $affiliates       = $affiliateRepo->getByListing((int) $listing->id)['items'];
        $affiliateApplyUrl = $appUrl . '/affiliate-apply/' . (int) $listing->id;
        $settingsRepo = new ListingSettingsRepo($db);
        $siteRepoAff  = new SiteSettingsRepo($db);

        // null = key absent from POST (not a live preview); '' = sent as empty ("Use global default")
        $previewStyle = $request ? $request->post('toggle_style', null) : null;
        $previewLabel = $request ? $request->post('toggle_label', null) : null;
        $applyStyle   = $previewStyle !== null
            ? (in_array(trim($previewStyle), ['link', 'button'], true) ? trim($previewStyle) : ($siteRepoAff->get('global_affiliate_apply_style') ?? 'button'))
            : ($settingsRepo->getAffiliateApplyStyle((int) $listing->id)
                ?? $siteRepoAff->get('global_affiliate_apply_style')
                ?? 'button');

        // Check if affiliate applications are enabled for this listing (matches production behaviour)
        $applyEnabled  = $settingsRepo->getAffiliateApplySettings((int) $listing->id)['enabled'] ?? false;
        $applyFormHtml = '';
        if ($applyEnabled && (str_contains($tpl, '{affiliate_apply_toggle}') || str_contains($tpl, '{affiliate_apply_form}'))) {
            $affApplyTpl = $settingsRepo->getAffiliateApplyFormTemplate((int) $listing->id);
            $globalTpls  = (new SiteSettingsRepo($db))->getGlobalTemplates();
            $resolved    = ListingTemplateResolver::resolve(
                $affApplyTpl,
                $globalTpls['listing_default_affiliate_apply_form_template'] ?? null,
                ListingSettingsRepo::defaultAffiliateApplyFormTemplate(),
            );
            $applyFormHtml = $this->renderAffiliateApplyFormPreview($resolved['value'], $listing->title, $listing->url, (string) $listing->subject);
        }

        $applyLabel = $previewLabel !== null
            ? (trim($previewLabel) !== '' ? trim($previewLabel) : ($siteRepoAff->get('global_affiliate_apply_toggle_label') ?? 'Apply to become an affiliate?'))
            : ($settingsRepo->getAffiliateApplyToggleLabel((int) $listing->id)
                ?? $siteRepoAff->get('global_affiliate_apply_toggle_label')
                ?? 'Apply to become an affiliate?');

        $sample = self::renderAffiliatesSection($tpl, $affiliates, $uploadsUrl, $affiliateApplyUrl, $applyStyle, $applyFormHtml, $applyLabel, 'active');
        $empty  = self::renderAffiliatesSection($tpl, [], $uploadsUrl, $affiliateApplyUrl, $applyStyle, $applyFormHtml, $applyLabel, 'empty');

        if (empty($affiliates)) {
            return $this->previewSectionLabel('Empty State') . ($empty ?: '<p style="font-style:italic;color:#888">No affiliates for this listing yet.</p>');
        }
        return $this->previewSectionLabel('Active Data') . $sample
            . $this->previewSectionLabel('Empty State') . ($empty ?: '<p style="font-style:italic;color:#888">No affiliates for this listing yet.</p>');
    }

    /**
     * Renders the affiliates_section_template against a set of affiliates.
     * Shared by ltplPreviewAffiliates() (real listing data) and
     * gtplPreviewAffiliates() (sample/empty data, listing-independent).
     *
     * @param \Luster\Models\Affiliate[] $affiliates
     */
    private static function renderAffiliatesSection(string $tpl, array $affiliates, string $uploadsUrl, string $affiliateApplyUrl = '', string $applyStyle = 'link', string $applyFormHtml = '', string $applyLabel = 'Apply to become an affiliate?', string $idSuffix = 'preview'): string
    {
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Handle {each_affiliate}...{/each_affiliate} inline loop
        if (preg_match('/\{each_affiliate\}(.*?)\{\/each_affiliate\}/s', $tpl, $m)) {
            $rendered = AffiliateRowRenderer::renderRows($m[1], $affiliates, $uploadsUrl);
            $tpl = (string) preg_replace(
                '/\{each_affiliate\}.*?\{\/each_affiliate\}/s',
                $rendered,
                $tpl,
                1,
            );
        }

        $count = count($affiliates);

        if ($count > 0) {
            $tpl = (string) (preg_replace('/\{if_no_affiliates\}.*?\{\/if_no_affiliates\}/s', '', $tpl) ?? $tpl);
            $tpl = str_replace(['{if_affiliates}', '{/if_affiliates}'], '', $tpl);
        } else {
            $tpl = (string) (preg_replace('/\{if_affiliates\}.*?\{\/if_affiliates\}/s', '', $tpl) ?? $tpl);
            $tpl = str_replace(['{if_no_affiliates}', '{/if_no_affiliates}'], '', $tpl);
        }

        $toggleHtml = '';
        $formTokenHtml = '';
        if ($applyFormHtml !== '') {
            $formId = 'fl-affiliate-apply-form-' . $e($idSuffix);
            $tag    = $applyStyle === 'link' ? 'a' : 'button';
            $attrs  = $applyStyle === 'link' ? ' role="button" tabindex="0"' : ' type="button"';
            $toggleHtml = '<' . $tag . ' class="fl-affiliate-apply-toggle fl-affiliate-apply-toggle--' . $e($applyStyle) . '"'
                . $attrs
                . ' data-fl-toggle="' . $formId . '" aria-expanded="false" aria-controls="' . $formId . '">'
                . $e($applyLabel)
                . '</' . $tag . '>';
            $formTokenHtml = '<div class="fl-affiliate-apply-form-wrap" id="' . $formId . '" hidden>'
                . $applyFormHtml
                . '</div>';
        }
        $tpl = str_replace(
            ['{affiliate_apply_toggle}', '{affiliate_apply_form}'],
            [$toggleHtml, $formTokenHtml],
            $tpl,
        );

        return str_replace(
            ['{affiliates_count}', '{affiliate_apply_url}'],
            [(string) $count, $e($affiliateApplyUrl)],
            $tpl,
        );
    }

    private function ltplPreviewStats(string $tpl, object $listing, \Luster\Core\Database $db, \Luster\Core\Config $config, ?\Luster\Core\Request $request = null): string
    {
        $settingsRepo = new ListingSettingsRepo($db);
        $approvedBy   = (string) ($settingsRepo->getApprovedBy((int) $listing->id) ?? '');
        $approvalUrl  = (string) ($settingsRepo->getApprovalUrl((int) $listing->id) ?? '');

        // {if_tfl} / {if_tafl} / {network_name} are category-derived — same
        // rule as the live embed (public/embed/stats.php) — independent of
        // "Approved By". A listing can carry categories from both networks.
        $listingRepoForCats = new ListingRepo($db, $config);
        $categoryRepo       = new CategoryRepo($db);
        $hasTflCategory      = false;
        $hasTaflCategory     = false;
        foreach ($listingRepoForCats->getCategoryIdsForListing((int) $listing->id) as $catId) {
            $cat = $categoryRepo->getById($catId);
            if ($cat === null) {
                continue;
            }
            if ($cat->source === 'tfl')  { $hasTflCategory  = true; }
            if ($cat->source === 'tafl') { $hasTaflCategory = true; }
        }

        // null = key absent (not live preview); '' = "Use global default"; '0'/'5' = explicit choice
        $limitRaw = $request ? $request->post('stat_newmembers_limit', null) : null;
        if ($limitRaw !== null) {
            if (trim($limitRaw) === '') {
                $globalVal   = (new SiteSettingsRepo($db))->get('stat_newmembers_limit');
                $newestLimit = ($globalVal === '5') ? 5 : 0;
            } else {
                $newestLimit = trim($limitRaw) === '0' ? 0 : 5;
            }
        } else {
            $siteRepo    = new SiteSettingsRepo($db);
            $savedLimit  = $settingsRepo->getStatNewmembersLimit((int) $listing->id);
            $newestLimit = $savedLimit ?? (($siteRepo->get('stat_newmembers_limit') === '5') ? 5 : 0);
        }

        $prefix     = (string) ($listing->dbprefix ?? '');
        $memberRepo = new MemberRepo($db, $config);
        $approved   = $prefix !== '' ? $memberRepo->countApproved($prefix) : 0;
        $pending    = $prefix !== '' ? $memberRepo->countPending($prefix) : 0;
        $countries  = $prefix !== '' ? $memberRepo->countUniqueCountries($prefix) : 0;
        $newest     = $prefix !== '' ? $memberRepo->getNewestMembers($prefix, $newestLimit) : [];
        $memberDate = $prefix !== '' ? $memberRepo->getMostRecentApprovedDate($prefix) : null;

        $newestFormatted = self::formatNewestMembers($newest, $approved, $newestLimit);

        $adminDate   = $settingsRepo->getLastUpdated((int) $listing->id);
        $updatedDate = DateFormatter::mostRecent($memberDate, $adminDate);
        $updatedStr  = $updatedDate ?? 'N/A';

        $values = [
            'stat_opened'     => $listing->opened ?? '',
            'stat_updated'    => $updatedStr,
            'stat_approved'   => (string) $approved,
            'stat_pending'    => (string) $pending,
            'stat_countries'  => (string) $countries,
            'stat_newmembers' => $newestFormatted,
            'stat_growth'     => '0.00 members/day',
            'listing_title'   => $listing->title,
            'listing_subject' => $listing->subject ?? $listing->title,
            'listing_url'     => $listing->url,
        ];
        $conditions = [
            'approved_by'  => $approvedBy !== '',
            'tfl'          => $hasTflCategory,
            'tafl'         => $hasTaflCategory,
            'stat_pending' => $pending > 0,
        ];
        $previousOwners = (new PreviousOwnersRepo($db))->getForListing((int) $listing->id);
        return self::renderStatsTemplate($tpl, $values, $conditions, $approvedBy, $hasTflCategory, $hasTaflCategory, $approvalUrl, $previousOwners);
    }

    /**
     * Renders the stats_template against a set of token values, conditions, the
     * raw approved-by key, category-derived network membership, and the saved
     * Approval Page URL. Shared by ltplPreviewStats() (real listing data) and
     * gtplPreviewStats() (sample/empty data, listing-independent).
     *
     * {approved_by} still reflects $approvedBy (the "Approved By" dropdown).
     * {network_name} reflects $hasTflCategory/$hasTaflCategory instead — same
     * split as the live embed (public/embed/stats.php) — since a listing can be
     * categorized under a network without (yet) being marked approved by it.
     * {approval_url}/{tfl_url}/{tafl_url} match the live embed's own fallback
     * rule exactly: {approval_url} is the raw saved value (or empty); {tfl_url}/
     * {tafl_url} use it only when $approvedBy matches that network, otherwise
     * falling back to the network's homepage.
     *
     * @param array<string, string> $values
     * @param array<string, bool>   $conditions
     */
    /**
     * @param array<int, array{owner_name: string, owner_url: string}> $previousOwners
     */
    private static function renderStatsTemplate(string $tpl, array $values, array $conditions, string $approvedBy, bool $hasTflCategory = false, bool $hasTaflCategory = false, string $approvalUrl = '', array $previousOwners = []): string
    {
        $network = strtoupper($approvedBy);

        $networkNames = [];
        if ($hasTflCategory)  { $networkNames[] = 'The Fanlistings Network'; }
        if ($hasTaflCategory) { $networkNames[] = 'The Anime Fanlistings Network'; }
        $networkName = implode(' & ', $networkNames);

        $tflUrl  = ($approvedBy === 'tfl'  && $approvalUrl !== '') ? $approvalUrl : 'https://thefanlistings.org/';
        $taflUrl = ($approvedBy === 'tafl' && $approvalUrl !== '') ? $approvalUrl : 'https://www.animefanlistings.com/';

        $rawValues = [];
        if (isset($values['stat_newmembers'])) {
            $rawValues['stat_newmembers'] = $values['stat_newmembers'];
            unset($values['stat_newmembers']);
        }

        // Previous Owners tokens must be shielded before TemplateEngine::render()
        // runs and resolved only after every other substitution below has
        // finished — see PreviousOwnersRenderer's docblock for why.
        $tpl = PreviousOwnersRenderer::shield($tpl);

        $rendered = TemplateEngine::render($tpl, escaped: $values, raw: $rawValues, conditions: $conditions);
        $rendered = str_replace(
            ['{approved_by}', '{network_name}', '{tfl_url}', '{tafl_url}', '{approval_url}'],
            [
                $network,
                $networkName,
                htmlspecialchars($tflUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($taflUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($approvalUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            ],
            $rendered,
        );

        return PreviousOwnersRenderer::resolve($rendered, $previousOwners);
    }

    /**
     * Everything the Edit Listing view needs beyond the request-specific bits
     * (success/error/old/field_errors, and the previous-owners/lifecycle data
     * already shared between both edit() branches) — members/affiliates/fields/
     * templates/codes/rules/adoption/embed-settings, all of it. Shared by both
     * the GET render and the POST-validation-failure re-render so they can
     * never drift out of sync with each other: a failure re-render used to
     * supply only a hand-picked subset of these keys, which 500'd the moment
     * a General-tab validation error (e.g. a bad title, or an invalid
     * Closed/Adopted On date) was hit, since the template unconditionally
     * uses many of them regardless of which tab is active.
     *
     * @return array<string, mixed>
     */
    private function buildEditFormViewData(int $id, $listing, Request $request): array
    {
        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $listingRepo    = new ListingRepo($db, $config);
        $settingsRepo   = new ListingSettingsRepo($db);
        $memberRepo     = new MemberRepo($db, $config);
        $affiliateRepo  = new AffiliateRepo($db);
        $categoryRepo   = new CategoryRepo($db);
        $fieldRepo      = new FieldRepo($db, $config);
        $codesRepo      = new CodesRepo($db);
        $codeCatsRepo   = new CodeCategoriesRepo($db);
        $codeDonorsRepo = new CodeDonorsRepo($db);

        $currentImage       = $settingsRepo->getImage($id);
        $listingType        = $settingsRepo->getListingType($id);
        // Nullable, not getMembersPerPage()'s defaulted-to-25 value: the edit
        // form needs to show blank (not a defaulted 25) when no per-listing
        // override is actually stored, matching stat_newmembers_limit below.
        $membersPerPage     = $settingsRepo->getMembersPerPageOrNull($id);
        $flLastUpdated      = $settingsRepo->getLastUpdated($id);
        $embedSettings      = $settingsRepo->getEmbedSettings($id);
        $contactSettings    = $settingsRepo->getContactSettings($id);
        $approvedBy         = $settingsRepo->getApprovedBy($id);
        $approvalUrl        = $settingsRepo->getApprovalUrl($id);
        $description        = $settingsRepo->getDescription($id);
        $affiliateApplySettings = $settingsRepo->getAffiliateApplySettings($id);
        $codeApprovalEmail  = $settingsRepo->getCodeApprovalEmail($id);
        $queueUpdates           = $settingsRepo->getQueueUpdates($id);
        $adoptionAddonInstalled = $this->app->isAddonInstalled('Adoption');
        $updatesAddonInstalled  = $this->app->isAddonInstalled('Newsfeed');
        $rulesAddonInstalled    = $this->app->isAddonInstalled('Rules');
        $rulesSet               = null;
        if ($rulesAddonInstalled) {
            try {
                $rulesSet = (new \Luster\Addons\Rules\RulesRepo($db))->getForListing($id);
            } catch (\Throwable) {
                $rulesSet = null;
            }
        }
        $rulesEnabledForListing = $rulesSet !== null && $rulesSet->enabled && trim($rulesSet->body) !== '';
        $forAdoption            = $adoptionAddonInstalled && $settingsRepo->getForAdoption($id);
        $adoptionCount          = $adoptionAddonInstalled
            ? (new \Luster\Addons\Adoption\AdoptionRepo($db))->countForListing($id, 'all')
            : 0;
        $approved           = $listing->dbprefix !== '' ? $memberRepo->countApproved($listing->dbprefix) : 0;
        $pending            = $listing->dbprefix !== '' ? $memberRepo->countPending($listing->dbprefix) : 0;
        $countries          = $listing->dbprefix !== '' ? $memberRepo->countUniqueCountries($listing->dbprefix) : 0;
        $lastUpdated        = $listing->dbprefix !== '' ? $memberRepo->getLastUpdated($listing->dbprefix) : null;

        $newestMember = null;
        if ($listing->dbprefix !== '' && $approved > 0) {
            $newest = $memberRepo->getNewestMembers($listing->dbprefix, 1);
            $newestMember = $newest[0] ?? null;
        }

        $topCountries = [];
        if ($listing->dbprefix !== '' && $approved > 0) {
            $countrySummary = $memberRepo->getCountrySummary($listing->dbprefix);
            usort($countrySummary, static fn(array $a, array $b): int => $b['fan_count'] <=> $a['fan_count']);
            $topCountries = array_slice($countrySummary, 0, 3);
        }

        $tflCats            = $categoryRepo->getBySource('tfl');
        $taflCats           = $categoryRepo->getBySource('tafl');
        $customNetworks     = $categoryRepo->getCustomSources();
        $customCats         = [];
        foreach ($customNetworks as $cn) {
            $customCats[$cn] = $categoryRepo->getBySource($cn);
        }
        $affiliates           = $affiliateRepo->getByListing($id)['items'];
        $fields               = $fieldRepo->getFieldsForListing($id);
        $templates            = $settingsRepo->getTemplates($id);
        $templates['codes_template'] = $settingsRepo->getCodesTemplate($id);
        $siteSettingsRepo     = new SiteSettingsRepo($db);
        $globalTemplates      = $siteSettingsRepo->getGlobalTemplates();
        $globalFormUrls       = $siteSettingsRepo->getGlobalFormUrls();
        $tplBuiltIns = [
            'stats_template'                => ListingSettingsRepo::defaultStatsTemplate(),
            'members_template'              => ListingSettingsRepo::defaultMembersTemplate(),
            'affiliates_section_template'   => ListingSettingsRepo::defaultAffiliatesSectionTemplate(),
            'newsfeed_template'              => ListingSettingsRepo::defaultNewsfeedTemplate(),
            'join_form_template'            => ListingSettingsRepo::defaultJoinFormTemplate(),
            'codes_submit_form_template'    => ListingSettingsRepo::defaultCodesSubmitFormTemplate(),
            'affiliate_apply_form_template' => ListingSettingsRepo::defaultAffiliateApplyFormTemplate(),
            'contact_form_template'         => ListingSettingsRepo::defaultContactFormTemplate(),
            'codes_template'                => ListingSettingsRepo::defaultCodesTemplate(),
            'update_form_template'          => ListingSettingsRepo::defaultUpdateFormTemplate(),
            'reset_form_template'           => ListingSettingsRepo::defaultResetFormTemplate(),
        ];
        $tplGlobalKeyMap = [
            'stats_template'                => 'listing_default_stats_template',
            'members_template'              => 'listing_default_members_template',
            'affiliates_section_template'   => 'listing_default_affiliates_section_template',
            'newsfeed_template'              => 'listing_default_newsfeed_template',
            'join_form_template'            => 'listing_default_join_form_template',
            'codes_submit_form_template'    => 'listing_default_codes_submit_form_template',
            'affiliate_apply_form_template' => 'listing_default_affiliate_apply_form_template',
            'contact_form_template'         => 'listing_default_contact_form_template',
            'codes_template'                => 'listing_default_codes_template',
            'update_form_template'          => 'listing_default_update_form_template',
            'reset_form_template'           => 'listing_default_reset_form_template',
        ];
        $templateSources = [];
        foreach ($tplBuiltIns as $tplKey => $builtIn) {
            $globalKey       = $tplGlobalKeyMap[$tplKey];
            $perListingValue = $templates[$tplKey] ?? null;
            // Mirrors $tplMembersVal's own check just below: a per-listing value
            // that's really just an old built-in default counts as absent here
            // too, so this badge can never disagree with what the textarea shows.
            if ($tplKey === 'members_template' && ListingSettingsRepo::isLegacyMembersTemplate($perListingValue)) {
                $perListingValue = null;
            }
            $resolved = ListingTemplateResolver::resolve(
                $perListingValue,
                $globalTemplates[$globalKey] ?? null,
                $builtIn,
            );
            $templateSources[$tplKey] = $resolved['source'];
        }
        $categories           = $categoryRepo->getAll();
        $currentCategoryId    = $listing->categoryId ?? null;
        $currentCategoryIds   = $listingRepo->getCategoryIdsForListing($id);

        // Codes tab data
        $statusFilterRaw  = (string) $request->query('status_filter', '');
        $codeStatusFilter = in_array($statusFilterRaw, CodesRepo::STATUSES, true) ? $statusFilterRaw : 'approved';
        $codeEditCatId   = (int) $request->query('edit_cat',   '0');
        $codeEditDonorId = (int) $request->query('edit_donor', '0');
        $allCodes        = $codesRepo->getByListing($id);
        $codeStatusCounts = ['approved' => 0, 'pending' => 0, 'rejected' => 0];
        foreach ($allCodes as $c) {
            $codeStatusCounts[$c->status] = ($codeStatusCounts[$c->status] ?? 0) + 1;
        }
        $codes       = $allCodes;
        $codeCats         = $codeCatsRepo->getByListing($id);
        $codeDonors       = $codeDonorsRepo->getByListing($id);
        $codeSubmitterNames = $codesRepo->getSubmitterNamesForListing($id);
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

        $lusterBase = defined('LUSTER_BASE') ? \LUSTER_BASE : dirname(__DIR__, 3);
        $resolvedEmbedPath = realpath($lusterBase . '/public/embed');
        $embedPath = $resolvedEmbedPath !== false
            ? rtrim(str_replace('\\', '/', $resolvedEmbedPath), '/')
            : rtrim(str_replace('\\', '/', $lusterBase), '/') . '/public/embed';

        return [
            'current_image'       => $currentImage,
            'cfg'                 => $config->all(),
            'embed_path'          => $embedPath,
            'approved'            => $approved,
            'pending'             => $pending,
            'countries'           => $countries,
            'newest_member'       => $newestMember,
            'top_countries'       => $topCountries,
            'last_updated'        => $lastUpdated,
            'fl_last_updated'     => $flLastUpdated,
            'tfl_cats'            => $tflCats,
            'tafl_cats'           => $taflCats,
            'custom_networks'     => $customNetworks,
            'custom_cats'         => $customCats,
            'affiliates'          => $affiliates,
            'fields'              => $fields,
            'unsavable_fields'    => $fieldRepo->fieldsWithoutColumn($listing->dbprefix, $fields),
            'templates'           => $templates,
            'global_templates'    => $globalTemplates,
            'global_form_urls'    => $globalFormUrls,
            'template_sources'    => $templateSources,
            'categories'          => $categories,
            'current_category_id'  => $currentCategoryId,
            'current_category_ids' => $currentCategoryIds,
            'listing_type'        => $listingType,
            'members_per_page'    => $membersPerPage,
            'embed_settings'      => $embedSettings,
            'contact_enabled'     => $contactSettings['enabled'],
            'contact_recipient'   => $contactSettings['recipient'] ?? '',
            'form_url_contact'    => $contactSettings['form_url_contact'] ?? '',
            'approved_by'         => $approvedBy,
            'approval_url'        => $approvalUrl,
            'description'         => $description,
            'adoption_addon_installed'       => $adoptionAddonInstalled,
            'newsfeed_addon_installed'        => $updatesAddonInstalled,
            'rules_addon_installed'          => $rulesAddonInstalled,
            'rules_enabled_for_listing'      => $rulesEnabledForListing,
            'rules'                           => $rulesSet,
            'for_adoption'                   => $forAdoption,
            'adoption_count'                 => $adoptionCount,
            'affiliate_apply_enabled'        => $affiliateApplySettings['enabled'],
            // '' (not null) is the "Use global default" sentinel the <select>
            // in edit.php compares against - passing null here left it
            // falling through to a hardcoded 'button' that ignored whatever
            // was actually configured on the Global Templates page, and
            // could get silently persisted as a real per-listing override
            // the next time the tab was saved without touching this field.
            'affiliate_apply_style'          => $settingsRepo->getAffiliateApplyStyle($id) ?? '',
            'codes_submit_style'             => $settingsRepo->getCodesSubmitToggleStyle($id) ?? '',
            'affiliate_apply_toggle_label'   => $settingsRepo->getAffiliateApplyToggleLabel($id) ?? '',
            'codes_submit_toggle_label'      => $settingsRepo->getCodesSubmitToggleLabel($id) ?? '',
            // Placeholders only (not the field value) - shows what will
            // actually be used if the admin leaves the label blank, instead
            // of a hardcoded string that ignores the configured global label.
            'affiliate_apply_toggle_label_placeholder' => $siteSettingsRepo->get('global_affiliate_apply_toggle_label') ?? 'Apply to become an affiliate?',
            'codes_submit_toggle_label_placeholder'    => $siteSettingsRepo->get('global_codes_submit_toggle_label') ?? 'Donate a code?',
            'form_url_affiliate_apply'       => $affiliateApplySettings['form_url'],
            'redirect_url_affiliate_apply'   => $affiliateApplySettings['redirect_url'],
            'stat_newmembers_limit'          => $settingsRepo->getStatNewmembersLimit($id),
            'code_approval_email'            => $codeApprovalEmail,
            'queue_updates'                  => $queueUpdates,
            'codes'               => $codes,
            'code_cats'           => $codeCats,
            'code_donors'          => $codeDonors,
            'code_submitter_names' => $codeSubmitterNames,
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
        ];
    }

    /**
     * The network/category rows the Add/Edit category widget posted, as
     * [network, name] pairs ("other" replaced by the network typed beside it),
     * skipping incomplete rows. Nothing is written here: createCategories() makes
     * them once the save has passed validation.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function categoryPicks(Request $request): array
    {
        $networks = array_values((array) $request->post('cat_network', []));
        $names    = array_values((array) $request->post('cat_name', []));
        $others   = array_values((array) $request->post('cat_network_other', []));

        $picks = [];
        foreach ($networks as $i => $rawNet) {
            $net = trim((string) $rawNet);
            if ($net === 'other') {
                $net = trim((string) ($others[$i] ?? ''));
            }
            $name = trim((string) ($names[$i] ?? ''));
            if ($net !== '' && $name !== '') {
                $picks[] = [$net, $name];
            }
        }
        return $picks;
    }

    /**
     * Finds or creates each picked category, in order.
     *
     * @param list<array{0: string, 1: string}> $picks From categoryPicks()
     * @return list<int>
     */
    private static function createCategories(CategoryRepo $categoryRepo, array $picks): array
    {
        return array_map(static fn(array $pick): int => $categoryRepo->findOrCreate($pick[0], $pick[1]), $picks);
    }

    /**
     * What to save as a listing's "Closed On"/"Adopted On" date while it's being
     * archived, from the date field, the "Date unknown" box, and the lifecycle
     * row already stored (null when the listing isn't archived yet). The value
     * goes to ListingLifecycleRepo::upsert():
     *  - box ticked: null, an unknown date. The date field is ignored (it's
     *    disabled, so the browser doesn't send it anyway). Storing null needs
     *    Migration 108 ($canStoreUnknown); without it this is an error, found
     *    before anything is saved or uploaded.
     *  - a date: that date, once validateArchivedAt() accepts it.
     *  - blank: '', keep what's stored (today on a first archive). When the
     *    stored date is unknown, though, a blank field with the box unticked is
     *    an error, rather than a silent switch to today or a save that changes
     *    nothing.
     * Reopening never gets here, so it ignores the box.
     *
     * @param array<string,mixed>|null $lifecycleRow
     * @return array{0: string|null, 1: string} the value to save, and an error ('' when fine)
     */
    private static function resolveArchivedAt(string $dateInput, bool $unknownTicked, ?array $lifecycleRow, bool $canStoreUnknown): array
    {
        if ($unknownTicked) {
            return $canStoreUnknown
                ? [null, '']
                : ['', 'Luster needs a database update before a date can be marked unknown. Run the migration page first (Luster needs Migration 108), then save again.'];
        }

        $error = self::validateArchivedAt($dateInput);
        if ($error !== '') {
            return ['', $error];
        }
        if ($dateInput === '' && $lifecycleRow !== null && $lifecycleRow['archived_at'] === null) {
            return ['', 'Enter the date, or tick Date unknown.'];
        }

        return [$dateInput, ''];
    }

    /**
     * Validates the "Closed On"/"Adopted On" date field (archived_at). Blank
     * is valid here — resolveArchivedAt() decides what a blank field means —
     * but a non-blank value has to be a real calendar date, so a bad value is
     * rejected rather than silently dropped the way fl_last_updated's looser
     * regex-only check allows (a known date is stored exactly as typed).
     * checkdate() alone still accepts years like 0001-0999, which MySQL's
     * strict mode can also reject, so the year is bounds-checked explicitly
     * too.
     *
     * @return string Empty when valid, an error message otherwise.
     */
    private static function validateArchivedAt(?string $archivedAtInput): string
    {
        if ($archivedAtInput === null || $archivedAtInput === '') {
            return '';
        }

        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $archivedAtInput, $m)) {
            return 'Please enter a valid date (YYYY-MM-DD).';
        }

        $y = (int) $m[1];
        $mo = (int) $m[2];
        $d = (int) $m[3];

        if ($y < 1000 || $y > 9999 || !checkdate($mo, $d, $y)) {
            return 'Please enter a valid date (YYYY-MM-DD).';
        }

        return '';
    }

    /**
     * Validates and cleans a POSTed set of Previous Owners rows (parallel
     * previous_owner_name[]/previous_owner_url[] arrays). A variable-length
     * array of rows, so this is validated by hand (mirroring
     * ValidatorField::httpUrl()'s own checks) rather than through the
     * single-field Validator class. Length limits mirror the DB column widths
     * (VARCHAR(255)/VARCHAR(500)) and are checked here server-side since the
     * form's maxlength attributes only guard against normal browser
     * submission, not a crafted POST. Rows where both fields are blank are
     * dropped (PreviousOwnersRepo::replaceForListing() would drop them too,
     * but skipping them here means they never reach a length/URL check).
     *
     * @param array<int, mixed> $namesRaw
     * @param array<int, mixed> $urlsRaw
     * @return array{0: array<int, array{owner_name: string, owner_url: string}>, 1: string} [rows, error]
     */
    private static function validatePreviousOwnerRows(array $namesRaw, array $urlsRaw): array
    {
        $rows  = [];
        $error = '';

        foreach ($namesRaw as $index => $rawName) {
            $name = trim((string) $rawName);
            $url  = trim((string) ($urlsRaw[$index] ?? ''));

            if ($name === '' && $url === '') {
                continue;
            }

            if (mb_strlen($name) > 255) {
                $error = 'A previous owner name is too long (255 characters max).';
                break;
            }

            if ($url !== '') {
                $url = self::httpsify($url);

                if (mb_strlen($url) > 500) {
                    $error = 'A previous owner URL is too long (500 characters max).';
                    break;
                }

                // Schemes are case-insensitive: HTTPS://example.com is kept as typed.
                if (filter_var($url, FILTER_VALIDATE_URL) === false
                    || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                    $error = 'Please enter a valid previous owner URL (e.g. https://example.com).';
                    break;
                }
            }

            $rows[] = ['owner_name' => $name, 'owner_url' => $url];
        }

        return [$rows, $error];
    }

    /**
     * @param array<int, array{name: string, url: string}> $members
     */
    private static function formatNewestMembers(array $members, int $totalApproved, int $limit): string
    {
        if ($members === []) {
            return '';
        }

        $formatted = array_map(static function (array $m): string {
            $name = htmlspecialchars($m['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if ($m['url'] !== '') {
                $url = htmlspecialchars($m['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                return '<a href="' . $url . '">' . $name . '</a>';
            }
            return $name;
        }, $members);

        // "and more…" only makes sense in Truncate mode — "Show all" already shows
        // every member matching its own criteria (most recent join date), so there's
        // nothing being cut off within that group.
        $hasMore = $limit > 0 && $totalApproved > count($members);

        if ($hasMore) {
            return implode(', ', $formatted) . ', and more&hellip;';
        }

        return match (count($formatted)) {
            1       => $formatted[0],
            2       => $formatted[0] . ' and ' . $formatted[1],
            default => implode(', ', array_slice($formatted, 0, -1)) . ', and ' . end($formatted),
        };
    }

    private function ltplPreviewUpdates(string $tpl, object $listing, \Luster\Core\Database $db): string
    {
        try {
            $updateRepo = new \Luster\Addons\Newsfeed\NewsfeedRepo($db);
            $updates    = $updateRepo->getForFanlisting((int) $listing->id, 1, 3);
        } catch (\Throwable) {
            return '<p style="font-style:italic;color:#888">Updates add-on is not installed.</p>';
        }

        $tplResolved = str_replace(
            ['{listing_title}', '{listing_subject}'],
            [
                htmlspecialchars((string) $listing->title,   ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars((string) ($listing->subject ?? $listing->title), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            ],
            $tpl,
        );

        $sampleUpdates = !empty($updates) ? $updates : SampleTemplateData::updates();
        $sample = \Luster\Support\NewsfeedTemplateRenderer::render($sampleUpdates, $tplResolved);
        $empty  = \Luster\Support\NewsfeedTemplateRenderer::render([], $tplResolved);
        if (trim($empty) === '') {
            $empty = '<p style="font-style:italic;color:#888">No updates posted for this listing yet.</p>';
        }

        return $this->previewSectionLabel(empty($updates) ? 'Sample Data' : 'Active Data')
            . $sample
            . $this->previewSectionLabel('Empty State')
            . $empty;
    }

    private function ltplPreviewCodes(string $tpl, object $listing, \Luster\Core\Database $db, ?\Luster\Core\Request $request = null): string
    {
        $listingId = (int) $listing->id;
        $appUrl     = rtrim((string) $this->app->getConfig()->get('app_url', ''), '/');
        $uploadsUrl = $appUrl . '/uploads/codes';

        $codesRepo      = new CodesRepo($db);
        $codeDonorsRepo = new CodeDonorsRepo($db);
        $catsRepo       = new CodeCategoriesRepo($db);

        $codes      = $codesRepo->getByListing($listingId, 'approved');
        $donors     = $codeDonorsRepo->getByListing($listingId);
        $categories = $catsRepo->getByListing($listingId);
        $catMap     = $catsRepo->getCategoryMapForListing($listingId);

        $donorMap = [];
        foreach ($donors as $donor) {
            $donorMap[$donor->id] = $donor;
        }

        $categoryMap = [];
        foreach ($categories as $cat) {
            $categoryMap[$cat->id] = $cat;
        }

        $grouped = [];
        foreach ($codes as $code) {
            if ($code->width > 0 && $code->height > 0) {
                $key   = (int) $code->width . 'x' . (int) $code->height;
                $label = (int) $code->width . '×' . (int) $code->height . 'px';
            } else {
                $key   = 'unknown';
                $label = 'Other';
            }
            if (!isset($grouped[$key])) {
                $grouped[$key] = ['label' => $label, 'codes' => []];
            }
            $grouped[$key]['codes'][] = $code;
        }
        uksort($grouped, function (string $a, string $b): int {
            if ($a === 'unknown') return 1;
            if ($b === 'unknown') return -1;
            [$aw, $ah] = explode('x', $a);
            [$bw, $bh] = explode('x', $b);
            return $aw !== $bw ? (int) $aw - (int) $bw : (int) $ah - (int) $bh;
        });

        $csResult          = $this->renderCodesSubmitTogglePreview($tpl, $listing, $db, $request);
        $csTogglePlaceholder = '<!--lstr-cs-toggle-preview-->';
        $csFormPlaceholder   = '<!--lstr-cs-form-preview-->';
        $tpl = str_replace(
            ['{codes_submit_toggle}', '{codes_submit_form}'],
            [$csTogglePlaceholder, $csFormPlaceholder],
            $tpl,
        );

        $html = CodesTemplateRenderer::render(
            $tpl, $codes, $grouped, $donorMap, $uploadsUrl,
            $catMap, $categoryMap, $listing->title ?? '',
        );

        if ($html === null) {
            return '<p style="color:#c00">Template must include an {each_code} or {each_size_group} block.</p>';
        }

        $empty = CodesTemplateRenderer::render(
            $tpl, [], [], [], $uploadsUrl, [], [], $listing->title ?? '',
        );

        // The Empty State pass reuses the same {codes_submit_toggle}/{codes_submit_form}
        // content as the main pass above, but with the wrap div's id (and the toggle's
        // matching data-fl-toggle/aria-controls) remapped to a distinct value — both
        // sections can render on the same preview page, and without this they'd share
        // one DOM id, breaking the toggle's JS wiring for whichever one isn't first.
        $csToggleEmpty = str_replace('fl-codes-submit-form-preview', 'fl-codes-submit-form-preview-empty', $csResult['toggle']);
        $csFormEmpty   = str_replace('fl-codes-submit-form-preview', 'fl-codes-submit-form-preview-empty', $csResult['form']);

        $html  = str_replace([$csTogglePlaceholder, $csFormPlaceholder], [$csResult['toggle'], $csResult['form']], (string) $html);
        $empty = str_replace([$csTogglePlaceholder, $csFormPlaceholder], [$csToggleEmpty, $csFormEmpty], (string) $empty);

        return $this->previewSectionLabel(empty($codes) ? 'Empty State' : 'Active Data') . ($html ?: '<p style="font-style:italic;color:#888">No approved codes for this listing yet.</p>')
            . (empty($codes) ? '' : $this->previewSectionLabel('Empty State') . ($empty ?: '<p style="font-style:italic;color:#888">No approved codes for this listing yet.</p>'));
    }

    /**
     * @return array{toggle: string, form: string}
     */
    private function renderCodesSubmitTogglePreview(string $tpl, object $listing, \Luster\Core\Database $db, ?\Luster\Core\Request $request = null): array
    {
        if (!str_contains($tpl, '{codes_submit_toggle}') && !str_contains($tpl, '{codes_submit_form}')) {
            return ['toggle' => '', 'form' => ''];
        }

        $settingsRepo = new ListingSettingsRepo($db);
        $embedCfg     = $settingsRepo->getResolvedEmbedSettings((int) $listing->id);
        if (!($embedCfg['codes_submit_enabled'] ?? true)) {
            return ['toggle' => '', 'form' => ''];
        }

        $siteRepo     = new SiteSettingsRepo($db);
        $previewCsStyle = $request ? $request->post('toggle_style', null) : null;
        $previewCsLabel = $request ? $request->post('toggle_label', null) : null;
        $csStyle = $previewCsStyle !== null
            ? (in_array(trim($previewCsStyle), ['link', 'button'], true) ? trim($previewCsStyle) : ($siteRepo->get('global_codes_submit_toggle_style') ?? 'button'))
            : ($settingsRepo->getCodesSubmitToggleStyle((int) $listing->id)
                ?? $siteRepo->get('global_codes_submit_toggle_style')
                ?? 'button');
        $csLabel = $previewCsLabel !== null
            ? (trim($previewCsLabel) !== '' ? trim($previewCsLabel) : ($siteRepo->get('global_codes_submit_toggle_label') ?? 'Donate a code?'))
            : ($settingsRepo->getCodesSubmitToggleLabel((int) $listing->id)
                ?? $siteRepo->get('global_codes_submit_toggle_label')
                ?? 'Donate a code?');
        $csFormTpl    = $settingsRepo->getCodesSubmitFormTemplate((int) $listing->id);
        $globalTpls   = $siteRepo->getGlobalTemplates();
        $resolved     = ListingTemplateResolver::resolve(
            $csFormTpl,
            $globalTpls['listing_default_codes_submit_form_template'] ?? null,
            ListingSettingsRepo::defaultCodesSubmitFormTemplate(),
        );
        $formHtml = $this->renderCodesSubmitFormPreview($resolved['value'], $listing->title, $listing->url, (string) $listing->subject);

        if ($formHtml === '') {
            return ['toggle' => '', 'form' => ''];
        }

        $e    = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $tag   = $csStyle === 'link' ? 'a' : 'button';
        $attrs = $csStyle === 'link' ? ' role="button" tabindex="0"' : ' type="button"';
        $toggleHtml = '<' . $tag . ' class="fl-codes-submit-toggle fl-codes-submit-toggle--' . $e($csStyle) . '"'
            . $attrs
            . ' data-fl-toggle="fl-codes-submit-form-preview" aria-expanded="false" aria-controls="fl-codes-submit-form-preview">' . $e($csLabel) . '</' . $tag . '>';
        $formTokenHtml = '<div class="fl-codes-submit-form-wrap" id="fl-codes-submit-form-preview" hidden>'
            . $formHtml
            . '</div>';

        return ['toggle' => $toggleHtml, 'form' => $formTokenHtml];
    }

    private function ltplPreviewRules(string $tpl, object $listing, string $gateLabel = '', bool $gateEnabled = true, string $gateType = ''): string
    {
        $appUrl = rtrim((string) $this->app->getConfig()->get('app_url', ''), '/');

        return RulesBodyRenderer::renderPreview($tpl, [
            'subject'       => $listing->subject ?? '',
            'listing_title' => $listing->title,
            'listing_url'   => $listing->url,
            'join_url'      => $appUrl . '/join/' . $listing->id,
            'update_url'    => $appUrl . '/update/' . $listing->id,
        ], $gateLabel, $gateEnabled, $gateType);
    }

    private function ltplPreviewJoinForm(string $tpl, object $listing): string
    {
        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $settingsRepo        = new ListingSettingsRepo($db);
        $countryFieldEnabled = (bool) $settingsRepo->getEmbedSettings((int) $listing->id)['country_field_enabled'];

        $fieldRepo = new FieldRepo($db, $config);
        $fields    = $fieldRepo->getFieldsForListing((int) $listing->id);

        return $this->renderJoinFormPreview($tpl, $listing->title, $listing->url, (string) $listing->subject, $countryFieldEnabled, $fields);
    }

    /**
     * Renders the join_form_template against sample form field markup.
     * Shared by ltplPreviewJoinForm() (real listing data) and
     * gtplPreviewJoinForm() (sample data, listing-independent).
     *
     * @param \Luster\Models\AdditionalField[] $fields
     */
    private function renderJoinFormPreview(string $tpl, string $listingTitle, string $listingUrl, string $listingSubject, bool $countryFieldEnabled, array $fields): string
    {
        $captchaQuestion = Captcha::getQuestion('join');
        $countrySelect   = Countries::buildSelect('');
        $fb = $this->app->getFormFieldBuilder();

        $descriptors = [
            ['type' => 'text',     'name' => 'name',             'id' => 'join-name',             'label' => 'Name',             'required' => true,  'value' => '', 'attrs' => ['maxlength' => '100', 'autocomplete' => 'name']],
            ['type' => 'email',    'name' => 'email',            'id' => 'join-email',            'label' => 'Email',            'required' => true,  'value' => '', 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
            ['type' => 'url',      'name' => 'url',              'id' => 'join-url',              'label' => 'Website URL',      'required' => false, 'value' => '', 'attrs' => ['maxlength' => '500', 'placeholder' => 'https://', 'autocomplete' => 'url'], 'hint' => 'Optional'],
            ['type' => 'text',     'name' => 'country',          'id' => '',                      'label' => 'Country',          'required' => $countryFieldEnabled, 'raw_input' => $countrySelect],
            ...$fb->descriptorsFromAdditionalFields($fields, []),
            ['type' => 'password', 'name' => 'password',         'id' => 'join-password',         'label' => 'Password',         'required' => false, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password'], 'hint' => 'Minimum 12 characters. If left blank, one will be generated for you.'],
            ['type' => 'password', 'name' => 'password_confirm', 'id' => 'join-password-confirm', 'label' => 'Confirm Password', 'required' => false, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password']],
            ['type' => 'text',     'name' => 'captcha_answer',   'id' => 'join-captcha',          'label' => 'Captcha: ' . $captchaQuestion, 'required' => true, 'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
        ];
        $f = $fb->build($descriptors, '');

        $additionalFieldsHtml      = implode("\n", array_map(
            fn($field) => $f['field_' . $field->name] ?? '', $fields,
        ));
        $additionalFieldsInputHtml = implode("\n", array_map(
            fn($field) => $f['field_' . $field->name . '_input'] ?? '', $fields,
        ));

        $tokens = [
            '{listing_title}'                 => View::e($listingTitle),
            '{listing_url}'                   => View::e($listingUrl),
            '{listing_subject}'               => View::e($listingSubject),
            '{field_name}'                     => $f['name'],
            '{field_name_input}'               => $f['name_input'],
            '{field_email}'                    => $f['email'],
            '{field_email_input}'              => $f['email_input'],
            '{field_url}'                      => $f['url'],
            '{field_url_input}'                => $f['url_input'],
            '{field_country}'                  => $countryFieldEnabled ? $f['country'] : '',
            '{field_country_input}'            => $countryFieldEnabled ? $f['country_input'] : '',
            '{additional_fields}'              => $additionalFieldsHtml,
            '{additional_fields_input}'        => $additionalFieldsInputHtml,
            '{field_password}'                 => $f['password'],
            '{field_password_input}'           => $f['password_input'],
            '{field_password_confirm}'         => $f['password_confirm'],
            '{field_password_confirm_input}'   => $f['password_confirm_input'],
            '{captcha_block}'                  => $f['captcha_answer'],
            '{captcha_block_input}'            => $f['captcha_answer_input'],
            '{captcha_question}'               => View::e($captchaQuestion),
            '{submit_button}'                  => '<button type="submit" class="fl-submit">Join Listing</button>',
            '{clear_button}'                   => '<button type="reset" class="fl-btn-secondary">Clear Form</button>',
        ];

        $inner = FormTemplateRenderer::expandTokens($tpl, $tokens);
        return FormTemplateRenderer::wrapForm($inner, '#', $this->app->getCSRF()->token(), false, preview: true);
    }

    private function ltplPreviewCodesSubmitForm(string $tpl, object $listing): string
    {
        return $this->renderCodesSubmitFormPreview($tpl, $listing->title, $listing->url, (string) $listing->subject);
    }

    /**
     * Renders the codes_submit_form_template against sample form field markup.
     * Shared by ltplPreviewCodesSubmitForm() (real listing data) and
     * gtplPreviewCodesSubmitForm() (sample data, listing-independent).
     */
    private function renderCodesSubmitFormPreview(string $tpl, string $listingTitle, string $listingUrl, string $listingSubject = ''): string
    {
        $captchaQuestion = Captcha::getQuestion('code_submit');
        $fb = $this->app->getFormFieldBuilder();

        $descriptors = [
            ['type' => 'file',  'name' => 'code_image',      'id' => 'code-image',  'label' => 'Upload Code',       'required' => true,  'attrs' => ['accept' => 'image/jpeg,image/png,image/gif,image/webp'], 'hint' => 'JPEG, PNG, GIF, or WebP. Max 500 KB.'],
            ['type' => 'text',  'name' => 'submitter_name',  'id' => 'sub-name',    'label' => 'Name',    'required' => true,  'value' => '', 'attrs' => ['maxlength' => '150', 'autocomplete' => 'name']],
            ['type' => 'email', 'name' => 'submitter_email', 'id' => 'sub-email',   'label' => 'Email',             'required' => true,  'value' => '', 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email'], 'hint' => 'Used only to notify you when your code is approved.'],
            ['type' => 'url',   'name' => 'submitter_url',   'id' => 'sub-url',     'label' => 'Website (optional)', 'required' => false, 'value' => '', 'attrs' => ['maxlength' => '255', 'placeholder' => 'https://', 'autocomplete' => 'url'], 'hint' => 'Displayed as a link on your donated code.'],
            ['type' => 'text',  'name' => 'captcha',         'id' => 'captcha',     'label' => 'Captcha: ' . $captchaQuestion, 'required' => true, 'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
        ];
        $f = $fb->build($descriptors, '');

        $tokens = [
            '{listing_title}'               => View::e($listingTitle),
            '{listing_url}'                 => View::e($listingUrl),
            '{listing_subject}'             => View::e($listingSubject),
            '{field_code_image}'            => $f['code_image'],
            '{field_code_image_input}'      => $f['code_image_input'],
            '{field_submitter_name}'        => $f['submitter_name'],
            '{field_submitter_name_input}'  => $f['submitter_name_input'],
            '{field_submitter_email}'       => $f['submitter_email'],
            '{field_submitter_email_input}' => $f['submitter_email_input'],
            '{field_submitter_url}'         => $f['submitter_url'],
            '{field_submitter_url_input}'   => $f['submitter_url_input'],
            '{captcha_block}'               => $f['captcha'],
            '{captcha_block_input}'         => $f['captcha_input'],
            '{captcha_question}'            => View::e($captchaQuestion),
            '{submit_button}'               => '<button type="submit" class="fl-submit">Submit Code</button>',
            '{clear_button}'                => '<button type="reset" class="fl-btn-secondary">Clear</button>',
        ];

        $inner = FormTemplateRenderer::expandTokens($tpl, $tokens);
        return FormTemplateRenderer::wrapForm($inner, '#', $this->app->getCSRF()->token(), true, preview: true);
    }

    private function ltplPreviewAffiliateApplyForm(string $tpl, object $listing): string
    {
        return $this->renderAffiliateApplyFormPreview($tpl, $listing->title, $listing->url, (string) $listing->subject);
    }

    /**
     * Renders the affiliate_apply_form_template against sample form field markup.
     * Shared by ltplPreviewAffiliateApplyForm() (real listing data) and
     * gtplPreviewAffiliateApplyForm() (sample data, listing-independent).
     */
    private function renderAffiliateApplyFormPreview(string $tpl, string $listingTitle, string $listingUrl, string $listingSubject = ''): string
    {
        $captchaQuestion = Captcha::getQuestion('affiliate_apply');
        $fb = $this->app->getFormFieldBuilder();

        $descriptors = [
            ['type' => 'text',     'name' => 'name',           'id' => 'aff-name',    'label' => 'Site Name',                      'required' => true,  'value' => '', 'attrs' => ['maxlength' => '200', 'autocomplete' => 'organization']],
            ['type' => 'url',      'name' => 'url',            'id' => 'aff-url',     'label' => 'Website URL',                       'required' => true,  'value' => '', 'attrs' => ['maxlength' => '500', 'placeholder' => 'https://', 'autocomplete' => 'url']],
            ['type' => 'email',    'name' => 'email',          'id' => 'aff-email',   'label' => 'Email',           'required' => true, 'value' => '', 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
            ['type' => 'file',     'name' => 'image',          'id' => 'aff-image',   'label' => 'Site Button / Image (optional)', 'required' => false, 'attrs' => ['accept' => 'image/jpeg,image/png,image/gif,image/webp'], 'hint' => 'JPEG, PNG, GIF, or WebP. Max 500 KB.'],
            ['type' => 'textarea', 'name' => 'message',        'id' => 'aff-message', 'label' => 'Message (optional)',             'required' => false, 'value' => '', 'attrs' => ['maxlength' => '2000', 'rows' => '4']],
            ['type' => 'text',     'name' => 'captcha_answer', 'id' => 'aff-captcha', 'label' => 'Captcha: ' . $captchaQuestion,   'required' => true,  'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
        ];
        $f = $fb->build($descriptors, '');

        $tokens = [
            '{listing_title}'         => View::e($listingTitle),
            '{listing_url}'           => View::e($listingUrl),
            '{listing_subject}'       => View::e($listingSubject),
            '{field_name}'            => $f['name'],
            '{field_name_input}'      => $f['name_input'],
            '{field_url}'             => $f['url'],
            '{field_url_input}'       => $f['url_input'],
            '{field_email}'           => $f['email'],
            '{field_email_input}'     => $f['email_input'],
            '{field_image}'           => $f['image'],
            '{field_image_input}'     => $f['image_input'],
            '{field_message}'         => $f['message'],
            '{field_message_input}'   => $f['message_input'],
            '{captcha_block}'         => $f['captcha_answer'],
            '{captcha_block_input}'   => $f['captcha_answer_input'],
            '{captcha_question}'      => View::e($captchaQuestion),
            '{submit_button}'         => '<button type="submit" class="fl-submit">Submit Application</button>',
            '{clear_button}'          => '<button type="reset" class="fl-btn-secondary">Clear</button>',
        ];

        $inner = FormTemplateRenderer::expandTokens($tpl, $tokens);
        return FormTemplateRenderer::wrapForm($inner, '#', $this->app->getCSRF()->token(), true, preview: true);
    }

    private function ltplPreviewContactForm(string $tpl, object $listing): string
    {
        return $this->renderContactFormPreview($tpl, $listing->title, $listing->url, $listing->subject);
    }

    private function ltplPreviewContactSent(string $tpl, object $listing): string
    {
        return SuccessRenderer::render($tpl, 'contact-sent', [
            'sender_name'   => 'Alice',
            'sender_email'  => 'alice@example.com',
            'listing_title' => $listing->title,
            'listing_url'   => $listing->url,
            'site_name'     => (string) ($this->app->getConfig()->get('site_name', '') ?: 'My Site'),
            'owner_name'    => (string) ($this->app->getConfig()->get('owner_name', '') ?: 'Site Owner'),
            'owner_email'   => (string) ($this->app->getConfig()->get('owner_email', '') ?: 'owner@example.com'),
        ]);
    }

    /**
     * Previews only the sign-in step — the only step this template controls.
     * The actual edit-info step always uses the built-in hardcoded markup
     * (see public/embed/update.php), so there is nothing else to preview.
     */
    private function ltplPreviewUpdateForm(string $tpl, object $listing): string
    {
        $fb = $this->app->getFormFieldBuilder();

        $signInDescriptors = [
            ['type' => 'email',    'name' => 'email',    'id' => 'upd-email',    'label' => 'Email Address', 'required' => true,  'value' => '', 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
            ['type' => 'password', 'name' => 'password', 'id' => 'upd-password', 'label' => 'Password',      'required' => true,  'attrs' => ['maxlength' => '255', 'autocomplete' => 'current-password']],
        ];
        $fSignIn = $fb->build($signInDescriptors, '');

        $tokens = [
            '{listing_title}'         => View::e((string) $listing->title),
            '{listing_url}'           => View::e((string) $listing->url),
            '{listing_subject}'       => View::e((string) ($listing->subject ?? $listing->title)),
            '{field_signin_email}'    => $fSignIn['email'],
            '{field_signin_password}' => $fSignIn['password'],
            '{submit_button}'         => '<button type="submit" class="fl-submit">Sign In</button>',
            '{clear_button}'          => '<button type="reset" class="fl-btn-secondary">Clear</button>',
            '{lost_password_url}'     => '#',
        ];

        // Templates saved before Step 2 was locked down may still physically
        // contain an {if_signed_in}...{/if_signed_in} block; strip it here so
        // the preview matches what actually renders now instead of showing
        // stale editable-step markup.
        $processed = TemplateEngine::conditionals($tpl, [
            'not_signed_in' => true,
            'signed_in'     => false,
        ]);

        $inner = FormTemplateRenderer::expandTokens($processed, $tokens);
        return FormTemplateRenderer::wrapForm($inner, '#', $this->app->getCSRF()->token(), false, preview: true);
    }

    /**
     * Previews only the request step — the only step this template controls.
     * The actual "set new password" step always uses the built-in hardcoded
     * markup (see public/embed/reset.php), so there is nothing else to preview.
     */
    private function ltplPreviewResetForm(string $tpl, object $listing): string
    {
        $fb = $this->app->getFormFieldBuilder();

        $emailDescriptors = [
            ['type' => 'email', 'name' => 'email',           'id' => 'reset-email',  'label' => 'Email Address', 'required' => true, 'value' => '', 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
        ];
        $fEmail = $fb->build($emailDescriptors, '');

        $tokens = [
            '{listing_title}'     => View::e((string) $listing->title),
            '{listing_url}'       => View::e((string) $listing->url),
            '{listing_subject}'   => View::e((string) ($listing->subject ?? $listing->title)),
            '{field_email}'       => $fEmail['email'],
            '{field_email_input}' => $fEmail['email_input'],
            '{captcha_block}'       => '',
            '{captcha_block_input}' => '',
            '{captcha_question}'    => '',
            '{reset_request_url}' => '#',
            '{submit_button}'     => '<button type="submit" class="fl-submit">Send Reset Link</button>',
            '{clear_button}'      => '<button type="reset" class="fl-btn-secondary">Clear</button>',
        ];

        // Templates saved before Step 2 was locked down may still physically
        // contain an {if_step_confirm}...{/if_step_confirm} block; strip it
        // here so the preview matches what actually renders now.
        $processed = TemplateEngine::conditionals($tpl, [
            'step_email'    => true,
            'step_confirm'  => false,
            'token_valid'   => false,
            'token_invalid' => false,
        ]);

        $inner = FormTemplateRenderer::expandTokens($processed, $tokens);
        return FormTemplateRenderer::wrapForm($inner, '#', $this->app->getCSRF()->token(), false, preview: true);
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/{id}/embed
    // -----------------------------------------------------------------------

    /**
     * Saves embed page URL settings (join, update, reset, codes-submit, contact,
     * affiliate apply) and optional per-listing DB credentials.
     */
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

        // This route is shared by two separate forms on separate tabs (Embed:
        // the form_url_* fields; Advanced: the db_* credentials), and neither
        // of them carries codes_submit_enabled at all (that checkbox lives on
        // the General tab's own form). saveEmbedSettings() below is a single
        // combined upsert covering all of these columns, so every field this
        // specific request didn't actually submit must fall back to its
        // current stored value — otherwise saving one tab silently blanks out
        // the other tab's fields and always disables codes donations.
        $existing  = $settingsRepo->getEmbedSettings($id);
        $postData  = $request->postAll();

        $formUrlJoin        = array_key_exists('form_url_join', $postData)
            ? ltrim(trim((string) $postData['form_url_join']), '/') : $existing['form_url_join'];
        $formUrlUpdate      = array_key_exists('form_url_update', $postData)
            ? ltrim(trim((string) $postData['form_url_update']), '/') : $existing['form_url_update'];
        $formUrlReset       = array_key_exists('form_url_reset', $postData)
            ? ltrim(trim((string) $postData['form_url_reset']), '/') : $existing['form_url_reset'];
        $formUrlCodesSubmit = array_key_exists('form_url_codes_submit', $postData)
            ? ltrim(trim((string) $postData['form_url_codes_submit']), '/') : $existing['form_url_codes_submit'];

        $dbHost = array_key_exists('db_host', $postData) ? trim((string) $postData['db_host']) : $existing['db_host'];
        $dbName = array_key_exists('db_name', $postData) ? trim((string) $postData['db_name']) : $existing['db_name'];
        $dbUser = array_key_exists('db_user', $postData) ? trim((string) $postData['db_user']) : $existing['db_user'];

        // db_pass: only update if a new value was submitted; empty/absent = keep existing
        $newDbPass = array_key_exists('db_pass', $postData) ? (string) $postData['db_pass'] : '';
        $dbPass    = $newDbPass !== '' ? $newDbPass : $existing['db_pass'];

        $settingsRepo->saveEmbedSettings(
            $id,
            $formUrlJoin,
            $formUrlUpdate,
            $formUrlReset,
            $formUrlCodesSubmit,
            $existing['codes_submit_enabled'],
            $dbHost,
            $dbName,
            $dbUser,
            $dbPass,
        );

        // Same fallback logic as above: the Advanced tab's form doesn't carry
        // either of these two fields, so saving it must not blank them out.
        $existingAffApply = $settingsRepo->getAffiliateApplySettings($id);

        $formUrlContact = array_key_exists('form_url_contact', $postData)
            ? ltrim(trim((string) $postData['form_url_contact']), '/')
            : $settingsRepo->getContactSettings($id)['form_url_contact'];
        $formUrlAffiliateApply = array_key_exists('form_url_affiliate_apply', $postData)
            ? ltrim(trim((string) $postData['form_url_affiliate_apply']), '/')
            : $existingAffApply['form_url'];
        $settingsRepo->saveFormUrlContact($id, $formUrlContact);

        $settingsRepo->saveAffiliateApplySettings(
            $id,
            $existingAffApply['enabled'],
            $formUrlAffiliateApply,
            $existingAffApply['redirect_url'],
        );

        $activityRepo = new ActivityRepo($db);
        $session      = $this->app->getSession();
        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'listing.embed.save',
            "Saved embed settings for listing #{$id} ({$listing->title})",
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Embed settings saved successfully.');

        return Response::redirect($this->app->getBaseUrl() . '/admin/listings/' . $id . '/edit');
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/{id}/update
    // -----------------------------------------------------------------------

    /**
     * Quick single-field category update (used by the inline category picker
     * in the listing index). Saves the primary category ID to the listing row.
     */
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

        $allCatIds = $categoryId !== null ? [$categoryId] : [];
        $listingRepo->update($id, $categoryId, $listing->subject, $listing->joined, (string) ($listing->catid ?? ''), $allCatIds);

        $this->app->getSession()->set('lstr_flash_success', 'Category updated.');

        return Response::redirect($this->app->getBaseUrl() . '/admin/listings/' . $id);
    }

    // -----------------------------------------------------------------------
    // GET /admin/listings/global-templates
    // -----------------------------------------------------------------------

    /**
     * Renders the global listing templates page where site-wide defaults for
     * member row, stats, affiliates section, updates, and all form templates
     * can be customized. Per-listing overrides cascade on top of these.
     */
    public function globalTemplates(Request $request, array $params): Response
    {
        $db       = $this->app->getDatabase();
        $session  = $this->app->getSession();
        $success  = (string) $session->get('lstr_flash_success', '');
        $error    = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        $siteRepo  = new SiteSettingsRepo($db);
        $globals   = $siteRepo->getGlobalTemplates();

        $tplBuiltIns = [
            'stats_template'                => ListingSettingsRepo::defaultStatsTemplate(),
            'members_template'              => ListingSettingsRepo::defaultMembersTemplate(),
            'affiliates_section_template'   => ListingSettingsRepo::defaultAffiliatesSectionTemplate(),
            'newsfeed_template'              => ListingSettingsRepo::defaultNewsfeedTemplate(),
            'join_form_template'            => ListingSettingsRepo::defaultJoinFormTemplate(),
            'codes_submit_form_template'    => ListingSettingsRepo::defaultCodesSubmitFormTemplate(),
            'affiliate_apply_form_template' => ListingSettingsRepo::defaultAffiliateApplyFormTemplate(),
            'contact_form_template'         => ListingSettingsRepo::defaultContactFormTemplate(),
            'codes_template'                => ListingSettingsRepo::defaultCodesTemplate(),
            'update_form_template'          => ListingSettingsRepo::defaultUpdateFormTemplate(),
            'reset_form_template'           => ListingSettingsRepo::defaultResetFormTemplate(),
        ];
        $tplGlobalKeyMap = [
            'stats_template'                => 'listing_default_stats_template',
            'members_template'              => 'listing_default_members_template',
            'affiliates_section_template'   => 'listing_default_affiliates_section_template',
            'newsfeed_template'              => 'listing_default_newsfeed_template',
            'join_form_template'            => 'listing_default_join_form_template',
            'codes_submit_form_template'    => 'listing_default_codes_submit_form_template',
            'affiliate_apply_form_template' => 'listing_default_affiliate_apply_form_template',
            'contact_form_template'         => 'listing_default_contact_form_template',
            'codes_template'                => 'listing_default_codes_template',
            'update_form_template'          => 'listing_default_update_form_template',
            'reset_form_template'           => 'listing_default_reset_form_template',
        ];
        $templateSources = [];
        foreach ($tplBuiltIns as $tplKey => $builtIn) {
            $globalKey   = $tplGlobalKeyMap[$tplKey];
            $globalValue = $globals[$globalKey] ?? null;
            // Mirrors $val()'s own check just below: a stored global value
            // that's really just an old built-in default counts as absent
            // here too, so this badge can never disagree with the textarea.
            if ($tplKey === 'members_template' && ListingSettingsRepo::isLegacyMembersTemplate($globalValue)) {
                $globalValue = null;
            }
            $resolved = ListingTemplateResolver::resolve(null, $globalValue, $builtIn);
            // From this page's perspective, a set global value simply is the
            // custom override -- there's no further per-listing tier above it.
            $templateSources[$tplKey] = $resolved['source'] === 'built_in' ? 'built_in' : 'custom';
        }

        $rawGlobalMpp = $siteRepo->get('listing_default_members_per_page');
        return $this->adminLayout('admin/listings/global-templates.php', 'Global Templates', 'listings', [
            'success'                    => $success,
            'error'                      => $error,
            'globals'                    => $globals,
            'template_sources'           => $templateSources,
            'stat_newmembers_limit'      => $siteRepo->get('stat_newmembers_limit'),
            'global_members_per_page'    => $rawGlobalMpp !== null ? (int) $rawGlobalMpp : null,
            'affiliate_apply_style'          => $siteRepo->get('global_affiliate_apply_style') ?? 'button',
            'codes_submit_toggle_style'      => $siteRepo->get('global_codes_submit_toggle_style') ?? 'button',
            'affiliate_apply_toggle_label'   => $siteRepo->get('global_affiliate_apply_toggle_label'),
            'codes_submit_toggle_label'      => $siteRepo->get('global_codes_submit_toggle_label'),
        ], '', [
            $this->crumb('Owned Listings', $this->adminUrl('/admin/listings')),
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/global-templates
    // -----------------------------------------------------------------------

    /**
     * Saves global listing template overrides to lstr_site_settings. A template
     * is stored as NULL when it matches the built-in default, so the cascade works
     * correctly: per-listing override → global default → built-in default.
     */
    public function saveGlobalTemplates(Request $request, array $params): Response
    {
        $db       = $this->app->getDatabase();
        $session  = $this->app->getSession();
        $baseUrl  = $this->app->getBaseUrl();

        $types = [
            'listing_default_members_template'              => ListingSettingsRepo::defaultMembersTemplate(),
            'listing_default_stats_template'                => ListingSettingsRepo::defaultStatsTemplate(),
            'listing_default_affiliates_section_template'   => ListingSettingsRepo::defaultAffiliatesSectionTemplate(),
            'listing_default_newsfeed_template'              => ListingSettingsRepo::defaultNewsfeedTemplate(),
            'listing_default_join_form_template'            => ListingSettingsRepo::defaultJoinFormTemplate(),
            'listing_default_codes_submit_form_template'    => ListingSettingsRepo::defaultCodesSubmitFormTemplate(),
            'listing_default_affiliate_apply_form_template' => ListingSettingsRepo::defaultAffiliateApplyFormTemplate(),
            'listing_default_update_form_template'          => ListingSettingsRepo::defaultUpdateFormTemplate(),
            'listing_default_reset_form_template'           => ListingSettingsRepo::defaultResetFormTemplate(),
            'listing_default_codes_template'                => ListingSettingsRepo::defaultCodesTemplate(),
            'listing_default_contact_form_template'         => ListingSettingsRepo::defaultContactFormTemplate(),
        ];

        $siteRepo = new SiteSettingsRepo($db);
        $normalize = static fn(string $v): string => trim(str_replace("\r\n", "\n", $v));

        $oldValues = $siteRepo->getMany(array_keys($types));
        $newValues = [];
        foreach ($types as $key => $builtIn) {
            $submitted        = $normalize((string) $request->post($key, ''));
            $newValues[$key]  = ($submitted === '' || $submitted === $normalize($builtIn)) ? null : $submitted;
        }

        $adminId = (int) $session->get('lstr_admin_id');
        (new \Luster\Repositories\TemplateRevisionRepo($db))
            ->recordChanged(\Luster\Repositories\TemplateRevisionRepo::GLOBAL_DEFAULTS_ID, $oldValues, $newValues, $adminId);

        foreach ($newValues as $key => $value) {
            $siteRepo->set($key, $value);
        }

        $snlRaw = trim((string) $request->post('stat_newmembers_limit', ''));
        $snlVal = $snlRaw !== '' ? (int) $snlRaw : null;
        $siteRepo->set('stat_newmembers_limit', $snlVal !== null ? ($snlVal === 0 ? '0' : '5') : null);

        $glMppRaw = trim((string) $request->post('listing_default_members_per_page', ''));
        $glMppVal = $glMppRaw !== '' ? max(10, min(100, (int) $glMppRaw)) : null;
        $siteRepo->set('listing_default_members_per_page', $glMppVal !== null ? (string) $glMppVal : null);

        $affStyle = (string) $request->post('affiliate_apply_style', 'button');
        $siteRepo->set('global_affiliate_apply_style', in_array($affStyle, ['link', 'button'], true) ? $affStyle : 'button');

        $csStyle = (string) $request->post('codes_submit_toggle_style', 'button');
        $siteRepo->set('global_codes_submit_toggle_style', in_array($csStyle, ['link', 'button'], true) ? $csStyle : 'button');

        $gAffLabel = trim((string) $request->post('affiliate_apply_toggle_label', ''));
        $siteRepo->set('global_affiliate_apply_toggle_label', $gAffLabel !== '' ? $gAffLabel : null);
        $gCsLabel = trim((string) $request->post('codes_submit_toggle_label', ''));
        $siteRepo->set('global_codes_submit_toggle_label', $gCsLabel !== '' ? $gCsLabel : null);

        $session->set('lstr_flash_success', 'Global listing templates saved.');
        $returnGroup = (string) $request->post('return_group', '');
        $groupSuffix = ($returnGroup !== '' && $returnGroup !== 'all') ? '?group=' . urlencode($returnGroup) : '';
        return Response::redirect($baseUrl . '/admin/listings/global-templates' . $groupSuffix);
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/global-templates/reset-all
    // -----------------------------------------------------------------------

    /**
     * Clears all global listing template overrides (sets them to NULL in
     * lstr_site_settings), reverting every listing to built-in defaults.
     */
    public function resetAllGlobalTemplates(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $siteRepo = new SiteSettingsRepo($db);
        $keys = [
            'listing_default_members_template',
            'listing_default_stats_template',
            'listing_default_affiliates_section_template',
            'listing_default_newsfeed_template',
            'listing_default_join_form_template',
            'listing_default_codes_submit_form_template',
            'listing_default_affiliate_apply_form_template',
            'listing_default_update_form_template',
            'listing_default_reset_form_template',
            'listing_default_codes_template',
            'listing_default_contact_form_template',
            'listing_default_contact_sent_template',
        ];

        // listing_default_contact_sent_template is a stale setting with no
        // matching entry in TemplatePackController::GLOBAL_KEY_MAP, so a
        // revision recorded for it could never be listed, restored, or
        // flushed through the history endpoints. Still cleared below, just
        // excluded from revision recording.
        $trackedKeys = array_values(array_diff($keys, ['listing_default_contact_sent_template']));

        $oldValues = $siteRepo->getMany($trackedKeys);
        $newValues = array_fill_keys($trackedKeys, null);
        $adminId   = (int) $session->get('lstr_admin_id');
        (new \Luster\Repositories\TemplateRevisionRepo($db))
            ->recordChanged(\Luster\Repositories\TemplateRevisionRepo::GLOBAL_DEFAULTS_ID, $oldValues, $newValues, $adminId);

        foreach ($keys as $key) {
            $siteRepo->set($key, null);
        }
        $siteRepo->set('stat_newmembers_limit', null);

        $session->set('lstr_flash_success', 'All global templates reset to Luster defaults.');
        return Response::redirect($baseUrl . '/admin/listings/global-templates');
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/global-templates/preview
    // -----------------------------------------------------------------------

    /**
     * Renders a live preview for a global listing template type using generic
     * sample data (no specific listing required). Returns a minimal standalone
     * HTML page styled with the public stylesheet.
     */
    public function globalTemplatesPreview(Request $request, array $params): Response
    {
        $this->app->getCSRF()->validate($request->post('_csrf', ''));

        $config = $this->app->getConfig();
        $appUrl = rtrim((string) $config->get('app_url', ''), '/');

        $type = (string) $request->post('template_type', '');
        $tpl  = (string) $request->post('template_html', '');

        $allowed = ['members', 'affiliates_section', 'stats', 'newsfeed', 'join_form', 'update_form', 'reset_form', 'codes', 'codes_submit_form', 'affiliate_apply_form', 'contact_form'];
        if (!in_array($type, $allowed, true)) {
            return $this->previewWrap('<p style="color:#c00">Unknown template type.</p>', $appUrl);
        }
        if ($tpl === '') {
            return $this->previewWrap('<p style="color:#c00">Template is empty.</p>', $appUrl);
        }

        try {
            $html = match ($type) {
                'members'              => $this->gtplPreviewMembers($tpl),
                'affiliates_section'   => $this->gtplPreviewAffiliates($tpl, $request),
                'stats'                => $this->gtplPreviewStats($tpl, $request),
                'newsfeed'             => $this->gtplPreviewUpdates($tpl),
                'join_form'            => $this->gtplPreviewJoinForm($tpl),
                'codes_submit_form'    => $this->gtplPreviewCodesSubmitForm($tpl),
                'affiliate_apply_form' => $this->gtplPreviewAffiliateApplyForm($tpl),
                'update_form'          => $this->gtplPreviewUpdateForm($tpl),
                'reset_form'           => $this->gtplPreviewResetForm($tpl),
                'codes'                => $this->gtplPreviewCodes($tpl, $request),
                'contact_form'         => $this->gtplPreviewContactForm($tpl),
            };
        } catch (\Throwable $e) {
            $html = '<p style="color:#c00;font-family:monospace">Preview error: '
                  . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }

        return $this->previewWrap($html, $appUrl);
    }

    /**
     * Members List preview using generic sample members, plus an empty-state
     * render with zero members.
     */
    private function gtplPreviewMembers(string $tpl): string
    {
        $fields     = SampleTemplateData::additionalFields();
        $members    = SampleTemplateData::members();
        // Empty string, not the admin panel's own date_format setting — this
        // preview must match what a host page sees with no $luster_date_format
        // override (MembersTemplateRenderer falls back to the ordinal style).
        $dateFormat = '';

        $sample = MembersTemplateRenderer::render(
            $tpl,
            $members,
            $fields,
            '#',
            count($members),
            count($members) . ' member(s)',
            '<a class="fl-member-sort-link" href="#">Date Joined</a>',
            '<a class="fl-member-sort-link" href="#">Name</a>',
            '<strong aria-current="page" class="fl-member-sort-link">Country</strong>',
            true,
            $dateFormat,
            false, // Country is the real embed's default sort, not Date Joined — keep the preview consistent
        );

        // Also show what the {if_sort_date}/{fan_added} join-date block looks like
        // when a fan actually sorts by Date Joined, since the main sample above
        // (matching the embed's real default sort) never renders it. Sorts its
        // own copy by added so this stays correct even if SampleTemplateData's
        // row order changes later — $members itself is left in its original order.
        $membersByDate = $members;
        usort($membersByDate, static fn($a, $b) => strcmp($a->added ?? '', $b->added ?? ''));

        $dateSorted = MembersTemplateRenderer::render(
            $tpl,
            $membersByDate,
            $fields,
            '#',
            count($membersByDate),
            count($membersByDate) . ' member(s)',
            '<strong aria-current="page" class="fl-member-sort-link">Date Joined</strong>',
            '<a class="fl-member-sort-link" href="#">Name</a>',
            '<a class="fl-member-sort-link" href="#">Country</a>',
            true,
            $dateFormat,
            true,
        );

        $empty = MembersTemplateRenderer::render(
            $tpl,
            [],
            $fields,
            '#',
            0,
            '0 member(s)',
            '<a class="fl-member-sort-link" href="#">Date Joined</a>',
            '<a class="fl-member-sort-link" href="#">Name</a>',
            '<strong aria-current="page" class="fl-member-sort-link">Country</strong>',
            true,
            $dateFormat,
            false, // Country is the real embed's default sort, not Date Joined — keep the preview consistent
        );

        if (trim($empty) === '') {
            $empty = '<p style="font-style:italic;color:#888">No approved members for this listing yet.</p>';
        }

        $result = $this->previewSectionLabel('Sample Data') . $sample;
        if (trim($dateSorted) !== '') {
            $result .= $this->previewSectionLabel('Sorted by Date Joined') . $dateSorted;
        }

        return $result . $this->previewSectionLabel('Empty State') . $empty;
    }

    /**
     * Affiliates Section preview using generic sample affiliates, plus an
     * empty-state render with zero affiliates.
     */
    private function gtplPreviewAffiliates(string $tpl, ?\Luster\Core\Request $request = null): string
    {
        $appUrl          = rtrim((string) $this->app->getConfig()->get('app_url', ''), '/');
        $uploadsUrl      = $appUrl . '/uploads/affiliates';
        $sampleImagesUrl = $appUrl . '/assets/images';

        // Per-listing affiliate apply URL, mirroring ltplPreviewAffiliates() —
        // this is the global default for per-listing affiliates_section_template,
        // not the collective template, so it must not use the collective apply URL.
        $affiliateApplyUrl = $appUrl . '/affiliate-apply/1';

        $applyFormHtml = '';
        if (str_contains($tpl, '{affiliate_apply_toggle}') || str_contains($tpl, '{affiliate_apply_form}')) {
            $globalTpls  = (new SiteSettingsRepo($this->app->getDatabase()))->getGlobalTemplates();
            $resolved    = ListingTemplateResolver::resolve(
                null,
                $globalTpls['listing_default_affiliate_apply_form_template'] ?? null,
                ListingSettingsRepo::defaultAffiliateApplyFormTemplate(),
            );
            $applyFormHtml = $this->renderAffiliateApplyFormPreview($resolved['value'], SampleTemplateData::LISTING_TITLE, SampleTemplateData::LISTING_URL, SampleTemplateData::LISTING_SUBJECT);
        }

        $siteRepo = new SiteSettingsRepo($this->app->getDatabase());
        $previewStyle = $request ? $request->post('toggle_style', null) : null;
        $previewLabel = $request ? $request->post('toggle_label', null) : null;
        $gAffStyle = $previewStyle !== null
            ? (in_array(trim($previewStyle), ['link', 'button'], true) ? trim($previewStyle) : ($siteRepo->get('global_affiliate_apply_style') ?? 'button'))
            : ($siteRepo->get('global_affiliate_apply_style') ?? 'button');
        $gAffLabel = $previewLabel !== null
            ? (trim($previewLabel) !== '' ? trim($previewLabel) : 'Apply to become an affiliate?')
            : ($siteRepo->get('global_affiliate_apply_toggle_label') ?? 'Apply to become an affiliate?');
        $sample = self::renderAffiliatesSection($tpl, SampleTemplateData::affiliates(), $sampleImagesUrl, $affiliateApplyUrl, $gAffStyle, $applyFormHtml, $gAffLabel, 'gsample');
        $empty  = self::renderAffiliatesSection($tpl, [], $uploadsUrl, $affiliateApplyUrl, $gAffStyle, $applyFormHtml, $gAffLabel, 'gempty');

        if (trim($empty) === '') {
            $empty = '<p style="font-style:italic;color:#888">No affiliates for this listing yet.</p>';
        }

        return $this->previewSectionLabel('Sample Data') . $sample
            . $this->previewSectionLabel('Empty State') . $empty;
    }

    /**
     * Statistics preview using generic sample stats, plus an empty-state
     * render representing a brand-new listing with no members yet.
     */
    private function gtplPreviewStats(string $tpl, ?\Luster\Core\Request $request = null): string
    {
        $limitRaw    = $request ? $request->post('stat_newmembers_limit', null) : null;
        $newestLimit = $limitRaw !== null ? (trim($limitRaw) === '0' ? 0 : 5) : 0;

        $sampleMembers = array_map(
            fn($m) => ['name' => $m->name, 'url' => $m->showurl ? $m->url : ''],
            SampleTemplateData::members(),
        );
        $sampleApprovedCount = 42;
        // "Show all" means every member who joined on the most recent join
        // date, not literally every approved member — demo that with a small
        // slice (3 of the 5 sample members), same as "Truncate after 5" does.
        $sliced = $newestLimit === 0 ? array_slice($sampleMembers, 0, 3) : array_slice($sampleMembers, 0, $newestLimit);
        // "…and more" only applies to Truncate mode — "Show all" never appends it,
        // regardless of how much higher the approved count is.
        $sampleNewest = self::formatNewestMembers($sliced, $sampleApprovedCount, $newestLimit);

        $sampleValues = [
            'stat_opened'     => '2020-01-15',
            'stat_updated'    => '2026-06-10',
            'stat_approved'   => (string) $sampleApprovedCount,
            'stat_pending'    => '3',
            'stat_countries'  => '12',
            'stat_newmembers' => $sampleNewest,
            'stat_growth'     => '0.71 members/day',
            'listing_title'   => SampleTemplateData::LISTING_TITLE,
            'listing_subject' => SampleTemplateData::LISTING_SUBJECT,
            'listing_url'     => SampleTemplateData::LISTING_URL,
        ];
        $sampleConditions = [
            'approved_by'  => true,
            'tfl'          => true,
            'tafl'         => false,
            'stat_pending' => true,
        ];
        $emptyValues = [
            'stat_opened'     => '',
            'stat_updated'    => 'N/A',
            'stat_approved'   => '0',
            'stat_pending'    => '0',
            'stat_countries'  => '0',
            'stat_newmembers' => '',
            'stat_growth'     => '0.00 members/day',
            'listing_title'   => SampleTemplateData::LISTING_TITLE,
            'listing_subject' => SampleTemplateData::LISTING_SUBJECT,
            'listing_url'     => SampleTemplateData::LISTING_URL,
        ];
        $emptyConditions = [
            'approved_by'  => false,
            'tfl'          => false,
            'tafl'         => false,
            'stat_pending' => false,
        ];

        // Two sample owners — one with a URL, one without — so the preview also
        // demonstrates the name-only-row fallback in {previous_owners}.
        $samplePreviousOwners = [
            ['owner_name' => 'Alex Rivera', 'owner_url' => 'https://example.com/'],
            ['owner_name' => 'Jordan Lee', 'owner_url' => ''],
        ];

        $sample = self::renderStatsTemplate($tpl, $sampleValues, $sampleConditions, 'tfl', hasTflCategory: true, hasTaflCategory: false, previousOwners: $samplePreviousOwners);
        $empty  = self::renderStatsTemplate($tpl, $emptyValues, $emptyConditions, '', hasTflCategory: false, hasTaflCategory: false, previousOwners: []);

        return $this->previewSectionLabel('Sample Data') . $sample
            . $this->previewSectionLabel('Empty State') . $empty;
    }

    /**
     * News Feed preview using generic sample updates, plus an empty-state
     * render with zero updates.
     */
    private function gtplPreviewUpdates(string $tpl): string
    {
        $tplResolved = str_replace(
            ['{listing_title}', '{listing_subject}'],
            [SampleTemplateData::LISTING_TITLE, SampleTemplateData::LISTING_SUBJECT],
            $tpl,
        );

        $sample = \Luster\Support\NewsfeedTemplateRenderer::render(SampleTemplateData::updates(), $tplResolved);
        $empty  = \Luster\Support\NewsfeedTemplateRenderer::render([], $tplResolved);

        if (trim($empty) === '') {
            $empty = '<p style="font-style:italic;color:#888">No updates posted yet.</p>';
        }

        return $this->previewSectionLabel('Sample Data') . $sample
            . $this->previewSectionLabel('Empty State') . $empty;
    }

    /**
     * Join Form preview using generic sample form field markup. Forms have no
     * empty-state concept, so this renders a single "Sample Data" section.
     */
    private function gtplPreviewJoinForm(string $tpl): string
    {
        return $this->renderJoinFormPreview(
            $tpl,
            SampleTemplateData::LISTING_TITLE,
            SampleTemplateData::LISTING_URL,
            SampleTemplateData::LISTING_SUBJECT,
            true,
            SampleTemplateData::additionalFields(),
        );
    }

    /**
     * Code Donation Form preview using generic sample form field markup.
     */
    private function gtplPreviewCodesSubmitForm(string $tpl): string
    {
        return $this->renderCodesSubmitFormPreview($tpl, SampleTemplateData::LISTING_TITLE, SampleTemplateData::LISTING_URL, SampleTemplateData::LISTING_SUBJECT);
    }

    /**
     * Affiliate Application Form preview using generic sample form field markup.
     */
    private function gtplPreviewAffiliateApplyForm(string $tpl): string
    {
        return $this->renderAffiliateApplyFormPreview($tpl, SampleTemplateData::LISTING_TITLE, SampleTemplateData::LISTING_URL, SampleTemplateData::LISTING_SUBJECT);
    }

    /**
     * Previews only the sign-in step — the only step this template controls.
     * The actual edit-info step always uses the built-in hardcoded markup
     * (see public/embed/update.php), so there is nothing else to preview.
     */
    private function gtplPreviewUpdateForm(string $tpl): string
    {
        $fb = $this->app->getFormFieldBuilder();

        $signInDescriptors = [
            ['type' => 'email',    'name' => 'email',    'id' => 'upd-email',    'label' => 'Email Address', 'required' => true,  'value' => '', 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
            ['type' => 'password', 'name' => 'password', 'id' => 'upd-password', 'label' => 'Password',      'required' => true,  'attrs' => ['maxlength' => '255', 'autocomplete' => 'current-password']],
        ];
        $fSignIn = $fb->build($signInDescriptors, '');

        $tokens = [
            '{listing_title}'         => SampleTemplateData::LISTING_TITLE,
            '{listing_url}'           => SampleTemplateData::LISTING_URL,
            '{listing_subject}'       => SampleTemplateData::LISTING_SUBJECT,
            '{field_signin_email}'    => $fSignIn['email'],
            '{field_signin_password}' => $fSignIn['password'],
            '{submit_button}'         => '<button type="submit" class="fl-submit">Sign In</button>',
            '{clear_button}'          => '<button type="reset" class="fl-btn-secondary">Clear</button>',
            '{lost_password_url}'     => '#',
        ];

        // Templates saved before Step 2 was locked down may still physically
        // contain an {if_signed_in}...{/if_signed_in} block; strip it here so
        // the preview matches what actually renders now.
        $processed = TemplateEngine::conditionals($tpl, [
            'not_signed_in' => true,
            'signed_in'     => false,
        ]);

        $inner = FormTemplateRenderer::expandTokens($processed, $tokens);
        return FormTemplateRenderer::wrapForm($inner, '#', $this->app->getCSRF()->token(), false, preview: true);
    }

    /**
     * Previews only the request step — the only step this template controls.
     * The actual "set new password" step always uses the built-in hardcoded
     * markup (see public/embed/reset.php), so there is nothing else to preview.
     */
    private function gtplPreviewResetForm(string $tpl): string
    {
        $fb = $this->app->getFormFieldBuilder();

        $fEmail = $fb->build([
            ['type' => 'email', 'name' => 'email',          'id' => 'reset-email',  'label' => 'Email Address', 'required' => true, 'value' => '', 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
        ], '');

        $tokens = [
            '{listing_title}'     => SampleTemplateData::LISTING_TITLE,
            '{listing_url}'       => SampleTemplateData::LISTING_URL,
            '{listing_subject}'   => SampleTemplateData::LISTING_SUBJECT,
            '{field_email}'       => $fEmail['email'],
            '{field_email_input}' => $fEmail['email_input'],
            '{captcha_block}'       => '',
            '{captcha_block_input}' => '',
            '{captcha_question}'    => '',
            '{reset_request_url}' => '#',
            '{submit_button}'     => '<button type="submit" class="fl-submit">Send Reset Link</button>',
            '{clear_button}'      => '<button type="reset" class="fl-btn-secondary">Clear</button>',
        ];

        // Templates saved before Step 2 was locked down may still physically
        // contain an {if_step_confirm}...{/if_step_confirm} block; strip it
        // here so the preview matches what actually renders now.
        $processed = TemplateEngine::conditionals($tpl, [
            'step_email'    => true,
            'step_confirm'  => false,
            'token_valid'   => false,
            'token_invalid' => false,
        ]);

        $inner = FormTemplateRenderer::expandTokens($processed, $tokens);
        return FormTemplateRenderer::wrapForm($inner, '#', $this->app->getCSRF()->token(), false, preview: true);
    }

    private function gtplPreviewCodes(string $tpl, ?\Luster\Core\Request $request = null): string
    {
        $appUrl     = rtrim((string) $this->app->getConfig()->get('app_url', ''), '/');
        $uploadsUrl = $appUrl . '/uploads/codes';
        $db         = $this->app->getDatabase();

        $codesRepo      = new CodesRepo($db);
        $codeDonorsRepo = new CodeDonorsRepo($db);
        $catsRepo       = new CodeCategoriesRepo($db);

        $codes      = $codesRepo->getByListing(null, 'approved');
        $donors     = $codeDonorsRepo->getByListing(null);
        $categories = $catsRepo->getByListing(null);
        $catMap     = $catsRepo->getCategoryMapForListing(null);

        $donorMap = [];
        foreach ($donors as $donor) { $donorMap[$donor->id] = $donor; }
        $categoryMap = [];
        foreach ($categories as $cat) { $categoryMap[$cat->id] = $cat; }

        $grouped = [];
        foreach ($codes as $code) {
            if ($code->width > 0 && $code->height > 0) {
                $key   = (int) $code->width . 'x' . (int) $code->height;
                $label = (int) $code->width . "\xC3\x97" . (int) $code->height . 'px';
            } else {
                $key   = 'unknown';
                $label = 'Other';
            }
            if (!isset($grouped[$key])) { $grouped[$key] = ['label' => $label, 'codes' => []]; }
            $grouped[$key]['codes'][] = $code;
        }
        uksort($grouped, function (string $a, string $b): int {
            if ($a === 'unknown') return 1;
            if ($b === 'unknown') return -1;
            [$aw, $ah] = explode('x', $a);
            [$bw, $bh] = explode('x', $b);
            return $aw !== $bw ? (int) $aw - (int) $bw : (int) $ah - (int) $bh;
        });

        $csToggle            = '';
        $csFormTokenHtml     = '';
        $csTogglePlaceholder = '<!--lstr-cs-toggle-gpreview-->';
        $csFormPlaceholder   = '<!--lstr-cs-form-gpreview-->';
        if (str_contains($tpl, '{codes_submit_toggle}') || str_contains($tpl, '{codes_submit_form}')) {
            $siteRepo   = new SiteSettingsRepo($db);
            $globalTpls = $siteRepo->getGlobalTemplates();
            $csFormTpl  = $globalTpls['listing_default_codes_submit_form_template'] ?? null;
            $resolved   = ListingTemplateResolver::resolve(
                null,
                $csFormTpl,
                ListingSettingsRepo::defaultCodesSubmitFormTemplate(),
            );
            $formHtml     = $this->renderCodesSubmitFormPreview($resolved['value'], SampleTemplateData::LISTING_TITLE, SampleTemplateData::LISTING_URL, SampleTemplateData::LISTING_SUBJECT);
            $previewCsStyle = $request ? $request->post('toggle_style', null) : null;
            $previewCsLabel = $request ? $request->post('toggle_label', null) : null;
            $csGStyle = $previewCsStyle !== null
                ? (in_array(trim($previewCsStyle), ['link', 'button'], true) ? trim($previewCsStyle) : ($siteRepo->get('global_codes_submit_toggle_style') ?? 'button'))
                : ($siteRepo->get('global_codes_submit_toggle_style') ?? 'button');
            $csGLabel = $previewCsLabel !== null
                ? (trim($previewCsLabel) !== '' ? trim($previewCsLabel) : 'Donate a code?')
                : ($siteRepo->get('global_codes_submit_toggle_label') ?? 'Donate a code?');
            $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if ($formHtml !== '') {
                $tag   = $csGStyle === 'link' ? 'a' : 'button';
                $attrs = $csGStyle === 'link' ? ' role="button" tabindex="0"' : ' type="button"';
                $csToggle = '<' . $tag . ' class="fl-codes-submit-toggle fl-codes-submit-toggle--' . $e($csGStyle) . '"'
                    . $attrs
                    . ' data-fl-toggle="fl-codes-submit-form-gpreview" aria-expanded="false" aria-controls="fl-codes-submit-form-gpreview">' . $e($csGLabel) . '</' . $tag . '>';
                $csFormTokenHtml = '<div class="fl-codes-submit-form-wrap" id="fl-codes-submit-form-gpreview" hidden>'
                    . $formHtml
                    . '</div>';
            }
        }
        $tpl = str_replace(
            ['{codes_submit_toggle}', '{codes_submit_form}'],
            [$csTogglePlaceholder, $csFormPlaceholder],
            $tpl,
        );

        $html = CodesTemplateRenderer::render(
            $tpl, $codes, $grouped, $donorMap, $uploadsUrl,
            $catMap, $categoryMap, SampleTemplateData::LISTING_TITLE,
        );

        if ($html === null) {
            return '<p style="color:#c00">Template must include an {each_code} or {each_size_group} block.</p>';
        }

        $empty = CodesTemplateRenderer::render(
            $tpl, [], [], [], $uploadsUrl, [], [], SampleTemplateData::LISTING_TITLE,
        );

        // See the matching comment in ltplPreviewCodes() -- the Empty State pass reuses
        // the same toggle/form content but with the id remapped so both sections can
        // coexist on the preview page without a duplicate-DOM-id JS wiring conflict.
        $csToggleEmpty = str_replace('fl-codes-submit-form-gpreview', 'fl-codes-submit-form-gpreview-empty', $csToggle);
        $csFormEmpty   = str_replace('fl-codes-submit-form-gpreview', 'fl-codes-submit-form-gpreview-empty', $csFormTokenHtml);

        $html  = str_replace([$csTogglePlaceholder, $csFormPlaceholder], [$csToggle, $csFormTokenHtml], (string) $html);
        $empty = str_replace([$csTogglePlaceholder, $csFormPlaceholder], [$csToggleEmpty, $csFormEmpty], (string) $empty);

        return $this->previewSectionLabel(empty($codes) ? 'Empty State' : 'Sample Data') . ($html ?: '<p style="font-style:italic;color:#888">No approved codes to preview with.</p>')
            . (empty($codes) ? '' : $this->previewSectionLabel('Empty State') . ($empty ?: '<p style="font-style:italic;color:#888">No approved codes to preview with.</p>'));
    }

    /**
     * Contact Form preview using generic sample form field markup.
     */
    private function gtplPreviewContactForm(string $tpl): string
    {
        return $this->renderContactFormPreview($tpl, SampleTemplateData::LISTING_TITLE, SampleTemplateData::LISTING_URL, SampleTemplateData::LISTING_SUBJECT);
    }

    /**
     * Renders the contact_form_template against sample form field markup.
     * Shared by ltplPreviewContactForm() (real listing data) and
     * gtplPreviewContactForm() (sample data, listing-independent).
     */
    private function renderContactFormPreview(string $tpl, string $listingTitle, string $listingUrl, string $listingSubject = ''): string
    {
        $captchaQuestion = Captcha::getQuestion('contact_listing');
        $fb = $this->app->getFormFieldBuilder();

        $subjectHtml = '<select id="contact-subject" name="subject" required>'
                     . '<option value="" disabled selected>Choose &mdash;</option>'
                     . '<option value="Comments">Comments</option>'
                     . '<option value="Questions/Concerns">Questions/Concerns</option>'
                     . '<option value="Contact/Other">Contact/Other</option>'
                     . '</select>';

        $descriptors = [
            ['type' => 'text',     'name' => 'name',           'id' => 'contact-name',    'label' => 'Name',        'required' => true,  'value' => '', 'attrs' => ['maxlength' => '100', 'autocomplete' => 'name']],
            ['type' => 'email',    'name' => 'email',          'id' => 'contact-email',   'label' => 'Email',       'required' => true,  'value' => '', 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
            ['type' => 'url',      'name' => 'url',            'id' => 'contact-url',     'label' => 'Website URL', 'required' => false, 'value' => '', 'attrs' => ['maxlength' => '255', 'autocomplete' => 'url']],
            ['type' => 'select',   'name' => 'subject',        'id' => 'contact-subject', 'label' => 'Subject',     'required' => true,  'raw_input' => $subjectHtml],
            ['type' => 'textarea', 'name' => 'message',        'id' => 'contact-message', 'label' => 'Message',     'required' => true,  'value' => '', 'attrs' => ['maxlength' => '5000', 'rows' => '6']],
            ['type' => 'text',     'name' => 'captcha_answer', 'id' => 'contact-captcha', 'label' => 'Captcha: ' . $captchaQuestion, 'required' => true, 'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
        ];
        $f = $fb->build($descriptors, '');

        $tokens = [
            '{listing_title}'        => View::e($listingTitle),
            '{listing_subject}'      => View::e($listingSubject),
            '{listing_url}'          => View::e($listingUrl),
            '{field_name}'           => $f['name'],
            '{field_name_input}'     => $f['name_input'],
            '{field_email}'          => $f['email'],
            '{field_email_input}'    => $f['email_input'],
            '{field_url}'            => $f['url'],
            '{field_url_input}'      => $f['url_input'],
            '{field_subject}'        => $f['subject'],
            '{field_subject_input}'  => $f['subject_input'],
            '{field_message}'        => $f['message'],
            '{field_message_input}'  => $f['message_input'],
            '{captcha_block}'        => $f['captcha_answer'],
            '{captcha_block_input}'  => $f['captcha_answer_input'],
            '{captcha_question}'     => View::e($captchaQuestion),
            '{submit_button}'        => '<button type="submit" class="fl-submit">Send Message</button>',
            '{clear_button}'         => '<button type="reset" class="fl-btn-secondary">Clear</button>',
        ];

        $inner = FormTemplateRenderer::expandTokens($tpl, $tokens);
        return FormTemplateRenderer::wrapForm($inner, '#', $this->app->getCSRF()->token(), true, preview: true);
    }

    private function gtplPreviewContactSent(string $tpl): string
    {
        return SuccessRenderer::render($tpl, 'contact-sent', [
            'sender_name'   => 'Alice',
            'sender_email'  => 'alice@example.com',
            'listing_title' => SampleTemplateData::LISTING_TITLE,
            'listing_url'   => SampleTemplateData::LISTING_URL,
            'site_name'     => (string) ($this->app->getConfig()->get('site_name', '') ?: 'My Site'),
            'owner_name'    => (string) ($this->app->getConfig()->get('owner_name', '') ?: 'Site Owner'),
            'owner_email'   => (string) ($this->app->getConfig()->get('owner_email', '') ?: 'owner@example.com'),
        ]);
    }

    // -----------------------------------------------------------------------
    // GET /admin/listings/settings
    // -----------------------------------------------------------------------

    /**
     * Renders the Owned Listings Settings page (default sort, per-page count,
     * image preview size, overdue notification threshold).
     */
    public function indexSettings(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        $siteRepo = new SiteSettingsRepo($this->app->getDatabase());

        return $this->adminLayout('admin/listings/settings.php', 'Owned Listings Settings', 'listings', [
            'cfg'       => $this->app->getConfig()->all(),
            'form_urls' => $siteRepo->getGlobalFormUrls(),
            'success'   => $success,
            'error'     => $error,
        ], '', [
            $this->crumb('Owned Listings', $this->adminUrl('/admin/listings')),
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/settings
    // -----------------------------------------------------------------------

    /**
     * Saves the Owned Listings display settings (per-page count) to
     * config/app.php and logs the change.
     */
    public function saveSettings(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $perPage = max(5, min(200, (int) $request->post('owned_per_page', 10)));

        $previewSize = (string) $request->post('image_preview_size', 'small');
        if (!in_array($previewSize, ['small', 'medium', 'large'], true)) {
            $previewSize = 'small';
        }

        // $updated starts as a copy of the full current config, so any key with
        // no corresponding field on this form is preserved as-is rather than
        // silently reset to some hardcoded default on every save.
        $updated                       = $this->app->getConfig()->all();
        $updated['owned_per_page']     = $perPage;
        $updated['image_preview_size'] = $previewSize;

        try {
            $this->writeConfig($updated);
        } catch (\RuntimeException $e) {
            $session->set('lstr_flash_error', 'Could not save settings: ' . $e->getMessage());
            return Response::redirect($baseUrl . '/admin/settings#panel-notifications');
        }

        $db       = $this->app->getDatabase();
        $siteRepo = new SiteSettingsRepo($db);
        $urlKeys  = SiteSettingsRepo::GLOBAL_FORM_URL_KEYS;
        foreach ($urlKeys as $key => $_default) {
            $submitted = ltrim(trim((string) $request->post($key, '')), '/');
            $siteRepo->set($key, $submitted !== '' ? $submitted : null);
        }

        $activityRepo = new ActivityRepo($db);
        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'listings.settings.save',
            'Owned listing settings updated',
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Settings saved successfully.');
        return Response::redirect($baseUrl . '/admin/listings/settings');
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/settings/send-overdue-now
    // -----------------------------------------------------------------------

    /**
     * Sends the overdue-listings notification email immediately (on demand),
     * using the configured overdue_days threshold. Always resets the 24-hour
     * rate-limit window. No email is sent when no listings are overdue.
     */
    public function sendOverdueNow(Request $request, array $params): Response
    {
        $session  = $this->app->getSession();
        $baseUrl  = $this->app->getBaseUrl();
        $config   = $this->app->getConfig();
        $db       = $this->app->getDatabase();

        $ownerEmail = (string) ($config->get('owner_email', '') ?? '');
        if ($ownerEmail === '') {
            $session->set('lstr_flash_error', 'No owner email is configured. Add one under Settings → General before sending notifications.');
            return Response::redirect($baseUrl . '/admin/settings#panel-notifications');
        }

        $siteRepo = new SiteSettingsRepo($db);
        $days     = max(1, (int) ($siteRepo->get('overdue_days', '55') ?? '55'));

        $stale = (new ListingRepo($db, $config))->getStaleListings($days);

        // Always update the timestamp — resets the 24-hour window.
        $siteRepo->set('overdue_notify_last_sent', (string) time());

        if (empty($stale)) {
            $session->set('lstr_flash_success', 'No overdue listings found — no email was sent. The 24-hour window has been reset.');
            return Response::redirect($baseUrl . '/admin/settings#panel-notifications');
        }

        $siteName = (string) ($config->get('site_name', 'your collective') ?? '');
        $adminUrl = rtrim((string) ($config->get('app_url', '') ?? ''), '/') . '/admin';

        $lines = array_map(
            static fn($l) => '- ' . $l->title . ' (' . $l->url . ')',
            $stale,
        );

        $tplPath = LUSTER_BASE . '/templates/email/admin-overdue-listings.txt';
        if (is_file($tplPath)) {
            $body = str_replace(
                ['{listings}', '{collective_name}', '{admin_url}', '{days}'],
                [implode("\n", $lines), $siteName, $adminUrl, (string) $days],
                (string) file_get_contents($tplPath),
            );
        } else {
            $body = "The following fanlistings on {$siteName} haven't been updated in {$days}+ days:\n\n"
                  . implode("\n", $lines)
                  . "\n\nLog in to mark them updated: {$adminUrl}";
        }

        try {
            $mailer = new Mailer($config);
            $mailer->send($ownerEmail, '', '[' . $siteName . '] Overdue fanlistings', $body);

            (new ActivityRepo($db))->log(
                (int) $session->get('lstr_admin_id'),
                'overdue_notification',
                'Overdue notification sent manually for ' . count($stale) . ' listing(s)',
                $request->ip(),
            );

            $session->set('lstr_flash_success', 'Overdue notification sent to ' . $ownerEmail . ' (' . count($stale) . ' listing(s) listed).');
        } catch (\Throwable $e) {
            $session->set('lstr_flash_error', 'Failed to send email: ' . $e->getMessage());
        }

        return Response::redirect($baseUrl . '/admin/listings/settings');
    }

    // -----------------------------------------------------------------------
    // GET|POST /admin/listings/{id}/email-templates  → unified page
    // -----------------------------------------------------------------------

    /**
     * Redirects the old per-listing email-templates URL to the unified Emails
     * page with the appropriate tab and listing pre-selected.
     */
    public function emailTemplatesRedirect(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        return Response::redirect($this->app->getBaseUrl() . '/admin/emails?tab=templates&listing=' . $id);
    }

    // -----------------------------------------------------------------------
    // GET /admin/listings/{id}/email-templates  (kept for reference; no longer routed)
    // -----------------------------------------------------------------------

    /**
     * @deprecated No longer routed — kept for backwards reference only.
     *             Use emailTemplatesRedirect() → Emails page instead.
     */
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

    /**
     * Saves all per-listing email template overrides (join, approve, update,
     * remove, reset, notify_join, affiliate_applied, affiliate_approved,
     * affiliate_rejected) with their enabled flags.
     */
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

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($configPath, true);
        }
    }
}
