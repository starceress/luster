<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\CodeCategoriesRepo;
use Luster\Repositories\CodeDonorsRepo;
use Luster\Repositories\CodesRepo;
use Luster\Repositories\CodeSizesRepo;
use Luster\Repositories\ListingRepo;

/**
 * Admin CRUD for per-fanlisting codes and code sizes.
 *
 * Routes:
 *   GET  /admin/listings/{fid}/codes                           — list + upload form
 *   POST /admin/listings/{fid}/codes/upload                    — multi-file upload
 *   POST /admin/listings/{fid}/codes/sizes/add                 — add a size group
 *   POST /admin/listings/{fid}/codes/sizes/{sid}/edit          — update a size group
 *   POST /admin/listings/{fid}/codes/sizes/{sid}/delete        — delete a size group
 *   POST /admin/listings/{fid}/codes/categories/add            — add a category
 *   POST /admin/listings/{fid}/codes/categories/{cid}/edit     — update a category
 *   POST /admin/listings/{fid}/codes/categories/{cid}/delete   — delete a category
 *   POST /admin/listings/{fid}/codes/donors/add                — add a donor
 *   POST /admin/listings/{fid}/codes/donors/{did}/edit         — update a donor
 *   POST /admin/listings/{fid}/codes/donors/{did}/delete       — delete a donor
 *   POST /admin/listings/{fid}/codes/bulk                      — bulk delete / bulk reassign
 *   POST /admin/listings/{fid}/codes/{id}/delete               — single code delete
 *   POST /admin/listings/{fid}/codes/{id}/size                 — reassign single code to a size
 *   POST /admin/listings/{fid}/codes/{id}/categories           — set category assignments for a code
 *   POST /admin/listings/{fid}/codes/{id}/donor                — set donor for a code
 *   POST /admin/listings/{fid}/codes/{id}/status               — set approval status for a code
 *   POST /admin/listings/{fid}/codes/{id}/name                 — set or clear the per-code label
 *   POST /admin/listings/{fid}/codes/{id}/update               — consolidated update (name, size, categories, donor, status)
 *   GET  /admin/listings/{fid}/codes/export                    — ZIP download of all approved codes
 *   POST /admin/listings/{fid}/codes/reorder                   — drag-drop sort order save
 */
final class CodesController
{
    /** Allowed MIME types for uploaded code images. */
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** Maximum upload size per file (500 KB). */
    private const MAX_SIZE = 512_000;

    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /admin/listings/{fid}/codes
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $listingId   = (int) ($params['fid'] ?? 0);
        $editSizeId  = (int) ($request->query('edit_size',  '0'));
        $editCatId   = (int) ($request->query('edit_cat',   '0'));
        $editDonorId = (int) ($request->query('edit_donor', '0'));

        // Validate status filter against the allowed set
        $rawFilter    = (string) $request->query('status_filter', '');
        $statusFilter = in_array($rawFilter, array_merge([''], CodesRepo::STATUSES), true)
            ? $rawFilter
            : '';

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $listingRepo = new ListingRepo($db, $config);
        $listing     = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        $session    = $this->app->getSession();
        $codesRepo  = new CodesRepo($db);
        $catsRepo   = new CodeCategoriesRepo($db);
        $donorsRepo = new CodeDonorsRepo($db);

        // Load all codes for status counts, then optionally filter for display
        $allCodes     = $codesRepo->getByListing($listingId);
        $statusCounts = ['approved' => 0, 'pending' => 0, 'rejected' => 0];
        foreach ($allCodes as $c) {
            $statusCounts[$c->status] = ($statusCounts[$c->status] ?? 0) + 1;
        }

        $codes = $statusFilter !== ''
            ? array_values(array_filter($allCodes, fn($c) => $c->status === $statusFilter))
            : $allCodes;

        $categories = $catsRepo->getByListing($listingId);
        $donors     = $donorsRepo->getByListing($listingId);

        // code_id → [category_id, ...] — loaded in one query
        $codeCategories = $catsRepo->getCategoryMapForListing($listingId);

        // Quick-lookup maps
        $categoryMap = [];
        foreach ($categories as $cat) {
            $categoryMap[$cat->id] = $cat;
        }
        $donorMap = [];
        foreach ($donors as $donor) {
            $donorMap[$donor->id] = $donor;
        }

        // Auto-group display codes by detected dimensions (width × height).
        // Codes with no dimension data fall into an 'unknown' bucket shown last.
        $grouped = [];
        foreach ($codes as $code) {
            if ($code->width > 0 && $code->height > 0) {
                $key   = (int) $code->width . 'x' . (int) $code->height;
                $label = (int) $code->width . '×' . (int) $code->height . 'px';
            } else {
                $key   = 'unknown';
                $label = 'Unknown dimensions';
            }
            if (!isset($grouped[$key])) {
                $grouped[$key] = ['label' => $label, 'codes' => []];
            }
            $grouped[$key]['codes'][] = $code;
        }

        // Sort groups: smallest width first, then height; unknown last
        uksort($grouped, function (string $a, string $b): int {
            if ($a === 'unknown') return 1;
            if ($b === 'unknown') return -1;
            [$aw, $ah] = explode('x', $a);
            [$bw, $bh] = explode('x', $b);
            return $aw !== $bw ? (int) $aw - (int) $bw : (int) $ah - (int) $bh;
        });

        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->layout('admin/listings/codes.php', [
            'listing'         => $listing,
            'codes'           => $codes,
            'categories'      => $categories,
            'donors'          => $donors,
            'grouped'         => $grouped,
            'code_categories' => $codeCategories,
            'category_map'    => $categoryMap,
            'donor_map'       => $donorMap,
            'edit_cat_id'     => $editCatId,
            'edit_donor_id'   => $editDonorId,
            'status_filter'   => $statusFilter,
            'status_counts'   => $statusCounts,
            'total_codes'     => count($allCodes),
            'uploads_url'     => $this->app->getBaseUrl() . '/uploads/codes',
            'success'         => $success,
            'error'           => $error,
        ], 'Codes', 'listings', 'Owned › ' . $listing->name);
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/{fid}/codes/upload
    // -----------------------------------------------------------------------

    public function upload(Request $request, array $params): Response
    {
        $listingId = (int) ($params['fid'] ?? 0);

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $listingRepo = new ListingRepo($db, $config);
        $listing     = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        $codesRepo    = new CodesRepo($db);
        $sizesRepo    = new CodeSizesRepo($db);
        $catsRepo     = new CodeCategoriesRepo($db);
        $donorsRepo   = new CodeDonorsRepo($db);
        $activityRepo = new ActivityRepo($db);

        // Resolve size_id from POST — validate it belongs to this listing
        $postedSizeId = $request->post('size_id', '') !== '' ? (int) $request->post('size_id', 0) : null;
        $sizeId       = null;
        if ($postedSizeId !== null && $postedSizeId > 0) {
            $size = $sizesRepo->getById($postedSizeId);
            if ($size !== null && $size->listingId === $listingId) {
                $sizeId = $postedSizeId;
            }
        }

        // Resolve donor_id from POST — validate it belongs to this listing
        $postedDonorId = $request->post('donor_id', '') !== '' ? (int) $request->post('donor_id', 0) : null;
        $donorId       = null;
        if ($postedDonorId !== null && $postedDonorId > 0) {
            $donor = $donorsRepo->getById($postedDonorId);
            if ($donor !== null && $donor->listingId === $listingId) {
                $donorId = $postedDonorId;
            }
        }

        // Upload status — default approved; pending allowed for draft workflow
        $uploadStatus = (string) $request->post('upload_status', 'approved');
        if (!in_array($uploadStatus, CodesRepo::STATUSES, true)) {
            $uploadStatus = 'approved';
        }

        // Resolve and validate posted category IDs
        $postedCatIds = array_map('intval', (array) ($request->post('cat_ids') ?? []));
        $validCatIds  = [];
        foreach ($postedCatIds as $catId) {
            if ($catId <= 0) continue;
            $cat = $catsRepo->getById($catId);
            if ($cat !== null && $cat->listingId === $listingId) {
                $validCatIds[] = $catId;
            }
        }

        // Ensure upload directory exists
        $uploadDir = realpath(__DIR__ . '/../../../public/uploads') . DIRECTORY_SEPARATOR . 'codes';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Retrieve $_FILES['codes'] — supports both single and multiple files
        $fileData = $request->file('codes');

        if ($fileData === null || (is_array($fileData['name']) ? empty(array_filter($fileData['name'])) : $fileData['name'] === '')) {
            $session->set('lstr_flash_error', 'No files selected for upload.');
            return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
        }

        // Normalise PHP's grouped multi-file array into a flat list of file structs
        $names    = is_array($fileData['name'])     ? $fileData['name']     : [$fileData['name']];
        $tmpNames = is_array($fileData['tmp_name']) ? $fileData['tmp_name'] : [$fileData['tmp_name']];
        $errors   = is_array($fileData['error'])    ? $fileData['error']    : [$fileData['error']];
        $sizes    = is_array($fileData['size'])      ? $fileData['size']     : [$fileData['size']];

        $uploaded   = 0;
        $skipped    = [];
        $sortOffset = $codesRepo->getMaxSortOrder($listingId);
        $finfo      = new \finfo(FILEINFO_MIME_TYPE);

        foreach ($names as $i => $originalName) {
            if ($originalName === '' || $originalName === null) {
                continue;
            }

            if ((int) $errors[$i] !== UPLOAD_ERR_OK) {
                $skipped[] = htmlspecialchars((string) $originalName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' (upload error)';
                continue;
            }

            $tmpName  = (string) $tmpNames[$i];
            $fileSize = (int)    $sizes[$i];

            if ($fileSize > self::MAX_SIZE) {
                $skipped[] = htmlspecialchars((string) $originalName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' (exceeds 500 KB)';
                continue;
            }

            $mime = $finfo->file($tmpName);

            if (!in_array($mime, self::ALLOWED_MIME, true)) {
                $skipped[] = htmlspecialchars((string) $originalName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' (not a supported image)';
                continue;
            }

            $ext = match ($mime) {
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/gif'  => 'gif',
                'image/webp' => 'webp',
                default      => 'bin',
            };

            $imgInfo = getimagesize($tmpName);
            $width   = $imgInfo !== false ? (int) $imgInfo[0] : 0;
            $height  = $imgInfo !== false ? (int) $imgInfo[1] : 0;

            $newFilename = 'c_' . uniqid('', true) . bin2hex(random_bytes(4)) . '.' . $ext;
            $dest        = $uploadDir . DIRECTORY_SEPARATOR . $newFilename;

            if (!move_uploaded_file($tmpName, $dest)) {
                $skipped[] = htmlspecialchars((string) $originalName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' (move failed)';
                continue;
            }

            $newCodeId = $codesRepo->insert($listingId, $newFilename, $width, $height, ++$sortOffset, $sizeId, $donorId, $uploadStatus);
            if (!empty($validCatIds)) {
                $catsRepo->setCategories($newCodeId, $validCatIds);
            }
            $uploaded++;
        }

        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'codes.upload',
            "Uploaded {$uploaded} code(s) for listing #{$listingId} ({$listing->name})",
            $request->ip(),
        );

        if ($uploaded > 0) {
            $msg = 'Uploaded ' . $uploaded . ' code' . ($uploaded !== 1 ? 's' : '') . ' successfully.';
            if (!empty($skipped)) {
                $msg .= ' Skipped: ' . implode(', ', $skipped);
            }
            $session->set('lstr_flash_success', $msg);
        } else {
            $msg = 'No codes uploaded.';
            if (!empty($skipped)) {
                $msg .= ' Issues: ' . implode(', ', $skipped);
            }
            $session->set('lstr_flash_error', $msg);
        }

        return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/{fid}/codes/sizes/add
    // -----------------------------------------------------------------------

    public function addSize(Request $request, array $params): Response
    {
        $listingId = (int) ($params['fid'] ?? 0);

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $listingRepo = new ListingRepo($db, $config);
        if ($listingRepo->getById($listingId) === null) {
            return Response::notFound('Listing not found.');
        }

        $label  = trim((string) $request->post('label', ''));
        $wRaw   = (string) $request->post('width', '');
        $hRaw   = (string) $request->post('height', '');
        $width  = ($wRaw !== '' && (int) $wRaw > 0) ? (int) $wRaw : null;
        $height = ($hRaw !== '' && (int) $hRaw > 0) ? (int) $hRaw : null;

        if ($label === '') {
            $session->set('lstr_flash_error', 'Size label is required.');
            return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
        }

        $sizesRepo = new CodeSizesRepo($db);
        $sortOrder = $sizesRepo->getMaxSortOrder($listingId) + 1;
        $sizesRepo->insert($listingId, $label, $width, $height, $sortOrder);

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'codes.size.add',
            "Added code size \"{$label}\" for listing #{$listingId}",
            $request->ip(),
        );

        $session->set('lstr_flash_success', "Size \"{$label}\" added.");
        return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/{fid}/codes/sizes/{sid}/edit
    // -----------------------------------------------------------------------

    public function editSize(Request $request, array $params): Response
    {
        $listingId = (int) ($params['fid'] ?? 0);
        $sizeId    = (int) ($params['sid'] ?? 0);

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $listingRepo = new ListingRepo($db, $config);
        if ($listingRepo->getById($listingId) === null) {
            return Response::notFound('Listing not found.');
        }

        $sizesRepo = new CodeSizesRepo($db);
        $size      = $sizesRepo->getById($sizeId);

        if ($size === null || $size->listingId !== $listingId) {
            $session->set('lstr_flash_error', 'Size not found.');
            return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
        }

        $label  = trim((string) $request->post('label', ''));
        $wRaw   = (string) $request->post('width', '');
        $hRaw   = (string) $request->post('height', '');
        $width  = ($wRaw !== '' && (int) $wRaw > 0) ? (int) $wRaw : null;
        $height = ($hRaw !== '' && (int) $hRaw > 0) ? (int) $hRaw : null;

        if ($label === '') {
            $session->set('lstr_flash_error', 'Size label is required.');
            return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes?edit_size=' . $sizeId);
        }

        $sizesRepo->update($sizeId, $label, $width, $height);

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'codes.size.edit',
            "Updated code size #{$sizeId} to \"{$label}\" for listing #{$listingId}",
            $request->ip(),
        );

        $session->set('lstr_flash_success', "Size \"{$label}\" updated.");
        return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/{fid}/codes/categories/add
    // -----------------------------------------------------------------------

    public function addCategory(Request $request, array $params): Response
    {
        $listingId = (int) ($params['fid'] ?? 0);

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $listingRepo = new ListingRepo($db, $config);
        if ($listingRepo->getById($listingId) === null) {
            return Response::notFound('Listing not found.');
        }

        $name     = trim((string) $request->post('name', ''));
        $pidRaw   = $request->post('parent_id', '');
        $parentId = ($pidRaw !== '' && (int) $pidRaw > 0) ? (int) $pidRaw : null;

        if ($name === '') {
            $session->set('lstr_flash_error', 'Category name is required.');
            return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
        }

        // Validate parent belongs to this listing
        $catsRepo = new CodeCategoriesRepo($db);
        if ($parentId !== null) {
            $parent = $catsRepo->getById($parentId);
            if ($parent === null || $parent->listingId !== $listingId) {
                $parentId = null;
            }
        }

        $sortOrder = $catsRepo->getMaxSortOrder($listingId) + 1;
        $catsRepo->insert($listingId, $name, $parentId, $sortOrder);

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'codes.category.add',
            "Added code category \"{$name}\" for listing #{$listingId}",
            $request->ip(),
        );

        $session->set('lstr_flash_success', "Category \"{$name}\" added.");
        return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/{fid}/codes/categories/{cid}/edit
    // -----------------------------------------------------------------------

    public function editCategory(Request $request, array $params): Response
    {
        $listingId  = (int) ($params['fid'] ?? 0);
        $categoryId = (int) ($params['cid'] ?? 0);

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $listingRepo = new ListingRepo($db, $config);
        if ($listingRepo->getById($listingId) === null) {
            return Response::notFound('Listing not found.');
        }

        $catsRepo = new CodeCategoriesRepo($db);
        $cat      = $catsRepo->getById($categoryId);

        if ($cat === null || $cat->listingId !== $listingId) {
            $session->set('lstr_flash_error', 'Category not found.');
            return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
        }

        $name     = trim((string) $request->post('name', ''));
        $pidRaw   = $request->post('parent_id', '');
        $parentId = ($pidRaw !== '' && (int) $pidRaw > 0) ? (int) $pidRaw : null;

        if ($name === '') {
            $session->set('lstr_flash_error', 'Category name is required.');
            return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes?edit_cat=' . $categoryId);
        }

        // Prevent a category from being its own parent
        if ($parentId === $categoryId) {
            $parentId = null;
        }

        // Validate parent belongs to this listing
        if ($parentId !== null) {
            $parent = $catsRepo->getById($parentId);
            if ($parent === null || $parent->listingId !== $listingId) {
                $parentId = null;
            }
        }

        $catsRepo->update($categoryId, $name, $parentId);

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'codes.category.edit',
            "Updated code category #{$categoryId} to \"{$name}\" for listing #{$listingId}",
            $request->ip(),
        );

        $session->set('lstr_flash_success', "Category \"{$name}\" updated.");
        return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/{fid}/codes/categories/{cid}/delete
    // -----------------------------------------------------------------------

    public function deleteCategory(Request $request, array $params): Response
    {
        $listingId  = (int) ($params['fid'] ?? 0);
        $categoryId = (int) ($params['cid'] ?? 0);

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $listingRepo = new ListingRepo($db, $config);
        if ($listingRepo->getById($listingId) === null) {
            return Response::notFound('Listing not found.');
        }

        $catsRepo = new CodeCategoriesRepo($db);
        $cat      = $catsRepo->getById($categoryId);

        if ($cat === null || $cat->listingId !== $listingId) {
            $session->set('lstr_flash_error', 'Category not found.');
            return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
        }

        $codeCount = $catsRepo->countCodesForCategory($categoryId);
        $catsRepo->delete($categoryId);

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'codes.category.delete',
            "Deleted code category \"{$cat->name}\" (#{$categoryId}) from listing #{$listingId}; {$codeCount} assignment(s) removed",
            $request->ip(),
        );

        $msg = "Category \"{$cat->name}\" deleted.";
        if ($codeCount > 0) {
            $msg .= " Removed from {$codeCount} code" . ($codeCount !== 1 ? 's' : '') . '.';
        }
        $session->set('lstr_flash_success', $msg);
        return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/{fid}/codes/donors/add
    // -----------------------------------------------------------------------

    public function addDonor(Request $request, array $params): Response
    {
        $listingId = (int) ($params['fid'] ?? 0);

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $listingRepo = new ListingRepo($db, $config);
        if ($listingRepo->getById($listingId) === null) {
            return Response::notFound('Listing not found.');
        }

        $name  = trim((string) $request->post('name',  ''));
        $email = trim((string) $request->post('email', ''));
        $url   = trim((string) $request->post('url',   ''));

        if ($name === '') {
            $session->set('lstr_flash_error', 'Donor name is required.');
            return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
        }

        (new CodeDonorsRepo($db))->insert(
            $listingId,
            $name,
            $email !== '' ? $email : null,
            $url   !== '' ? $url   : null,
        );

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'codes.donor.add',
            "Added code donor \"{$name}\" for listing #{$listingId}",
            $request->ip(),
        );

        $session->set('lstr_flash_success', "Donor \"{$name}\" added.");
        return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/{fid}/codes/donors/{did}/edit
    // -----------------------------------------------------------------------

    public function editDonor(Request $request, array $params): Response
    {
        $listingId = (int) ($params['fid'] ?? 0);
        $donorId   = (int) ($params['did'] ?? 0);

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $listingRepo = new ListingRepo($db, $config);
        if ($listingRepo->getById($listingId) === null) {
            return Response::notFound('Listing not found.');
        }

        $donorsRepo = new CodeDonorsRepo($db);
        $donor      = $donorsRepo->getById($donorId);

        if ($donor === null || $donor->listingId !== $listingId) {
            $session->set('lstr_flash_error', 'Donor not found.');
            return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
        }

        $name  = trim((string) $request->post('name',  ''));
        $email = trim((string) $request->post('email', ''));
        $url   = trim((string) $request->post('url',   ''));

        if ($name === '') {
            $session->set('lstr_flash_error', 'Donor name is required.');
            return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes?edit_donor=' . $donorId);
        }

        $donorsRepo->update(
            $donorId,
            $name,
            $email !== '' ? $email : null,
            $url   !== '' ? $url   : null,
        );

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'codes.donor.edit',
            "Updated code donor #{$donorId} to \"{$name}\" for listing #{$listingId}",
            $request->ip(),
        );

        $session->set('lstr_flash_success', "Donor \"{$name}\" updated.");
        return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/{fid}/codes/donors/{did}/delete
    // -----------------------------------------------------------------------

    public function deleteDonor(Request $request, array $params): Response
    {
        $listingId = (int) ($params['fid'] ?? 0);
        $donorId   = (int) ($params['did'] ?? 0);

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $listingRepo = new ListingRepo($db, $config);
        if ($listingRepo->getById($listingId) === null) {
            return Response::notFound('Listing not found.');
        }

        $donorsRepo = new CodeDonorsRepo($db);
        $donor      = $donorsRepo->getById($donorId);

        if ($donor === null || $donor->listingId !== $listingId) {
            $session->set('lstr_flash_error', 'Donor not found.');
            return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
        }

        $codeCount = $donorsRepo->countCodesForDonor($donorId);
        $donorsRepo->delete($donorId);

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'codes.donor.delete',
            "Deleted code donor \"{$donor->name}\" (#{$donorId}) from listing #{$listingId}; {$codeCount} code(s) unattributed",
            $request->ip(),
        );

        $msg = "Donor \"{$donor->name}\" deleted.";
        if ($codeCount > 0) {
            $msg .= " {$codeCount} code" . ($codeCount !== 1 ? 's were' : ' was') . ' unattributed.';
        }
        $session->set('lstr_flash_success', $msg);
        return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/{fid}/codes/{id}/categories
    // -----------------------------------------------------------------------

    public function assignCategories(Request $request, array $params): Response
    {
        $listingId = (int) ($params['fid'] ?? 0);
        $codeId    = (int) ($params['id']  ?? 0);

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $listingRepo = new ListingRepo($db, $config);
        if ($listingRepo->getById($listingId) === null) {
            return Response::notFound('Listing not found.');
        }

        $codesRepo = new CodesRepo($db);
        $code      = $codesRepo->getById($codeId);

        if ($code === null || $code->listingId !== $listingId) {
            $session->set('lstr_flash_error', 'Code not found.');
            return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
        }

        // Validate each submitted category ID belongs to this listing
        $catsRepo    = new CodeCategoriesRepo($db);
        $postedIds   = array_map('intval', (array) ($request->post('cat_ids') ?? []));
        $validCatIds = [];
        foreach ($postedIds as $catId) {
            if ($catId <= 0) continue;
            $cat = $catsRepo->getById($catId);
            if ($cat !== null && $cat->listingId === $listingId) {
                $validCatIds[] = $catId;
            }
        }

        $catsRepo->setCategories($codeId, $validCatIds);

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'codes.category.assign',
            "Set " . count($validCatIds) . " categor" . (count($validCatIds) !== 1 ? 'ies' : 'y') . " on code #{$codeId} (listing #{$listingId})",
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Categories updated.');
        return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/{fid}/codes/{id}/donor
    // -----------------------------------------------------------------------

    public function assignDonor(Request $request, array $params): Response
    {
        $listingId = (int) ($params['fid'] ?? 0);
        $codeId    = (int) ($params['id']  ?? 0);

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $listingRepo = new ListingRepo($db, $config);
        if ($listingRepo->getById($listingId) === null) {
            return Response::notFound('Listing not found.');
        }

        $codesRepo = new CodesRepo($db);
        $code      = $codesRepo->getById($codeId);

        if ($code === null || $code->listingId !== $listingId) {
            $session->set('lstr_flash_error', 'Code not found.');
            return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
        }

        $postedDonorId = $request->post('donor_id', '') !== '' ? (int) $request->post('donor_id', 0) : null;
        $donorId       = null;

        if ($postedDonorId !== null && $postedDonorId > 0) {
            $donor = (new CodeDonorsRepo($db))->getById($postedDonorId);
            if ($donor !== null && $donor->listingId === $listingId) {
                $donorId = $postedDonorId;
            }
        }

        $codesRepo->updateDonor($codeId, $donorId);

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'codes.donor.assign',
            "Set donor " . ($donorId !== null ? "#{$donorId}" : 'none') . " on code #{$codeId} (listing #{$listingId})",
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Donor updated.');
        return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/{fid}/codes/sizes/{sid}/delete
    // -----------------------------------------------------------------------

    public function deleteSize(Request $request, array $params): Response
    {
        $listingId = (int) ($params['fid'] ?? 0);
        $sizeId    = (int) ($params['sid'] ?? 0);

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $listingRepo = new ListingRepo($db, $config);
        if ($listingRepo->getById($listingId) === null) {
            return Response::notFound('Listing not found.');
        }

        $sizesRepo = new CodeSizesRepo($db);
        $size      = $sizesRepo->getById($sizeId);

        if ($size === null || $size->listingId !== $listingId) {
            $session->set('lstr_flash_error', 'Size not found.');
            return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
        }

        $codeCount = $sizesRepo->countCodesForSize($sizeId);
        $sizesRepo->delete($sizeId);
        // Codes referencing this size will have size_id set to NULL via FK ON DELETE SET NULL.

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'codes.size.delete',
            "Deleted code size \"{$size->label}\" (#{$sizeId}) from listing #{$listingId}; {$codeCount} code(s) unassigned",
            $request->ip(),
        );

        $msg = "Size \"{$size->label}\" deleted.";
        if ($codeCount > 0) {
            $msg .= " {$codeCount} code" . ($codeCount !== 1 ? 's were' : ' was') . ' moved to Unsized.';
        }
        $session->set('lstr_flash_success', $msg);
        return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/{fid}/codes/{id}/delete
    // -----------------------------------------------------------------------

    public function delete(Request $request, array $params): Response
    {
        $listingId = (int) ($params['fid'] ?? 0);
        $codeId    = (int) ($params['id']  ?? 0);

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $listingRepo = new ListingRepo($db, $config);
        $listing     = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        $codesRepo    = new CodesRepo($db);
        $activityRepo = new ActivityRepo($db);

        $code = $codesRepo->getById($codeId);

        if ($code === null || $code->listingId !== $listingId) {
            $session->set('lstr_flash_error', 'Code not found.');
            return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
        }

        $uploadDir = realpath(__DIR__ . '/../../../public/uploads/codes');
        if ($uploadDir !== false) {
            $filePath = $uploadDir . DIRECTORY_SEPARATOR . $code->filename;
            if (is_file($filePath)) {
                unlink($filePath);
            }
        }

        $codesRepo->delete($codeId);

        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'codes.delete',
            "Deleted code #{$codeId} ({$code->filename}) from listing #{$listingId}",
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Code deleted.');
        return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/{fid}/codes/{id}/size
    // -----------------------------------------------------------------------

    public function assignSize(Request $request, array $params): Response
    {
        $listingId = (int) ($params['fid'] ?? 0);
        $codeId    = (int) ($params['id']  ?? 0);

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $listingRepo = new ListingRepo($db, $config);
        if ($listingRepo->getById($listingId) === null) {
            return Response::notFound('Listing not found.');
        }

        $codesRepo = new CodesRepo($db);
        $code      = $codesRepo->getById($codeId);

        if ($code === null || $code->listingId !== $listingId) {
            $session->set('lstr_flash_error', 'Code not found.');
            return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
        }

        // Resolve and validate the target size
        $postedSizeId = $request->post('size_id', '') !== '' ? (int) $request->post('size_id', 0) : null;
        $sizeId       = null;

        if ($postedSizeId !== null && $postedSizeId > 0) {
            $size = (new CodeSizesRepo($db))->getById($postedSizeId);
            if ($size !== null && $size->listingId === $listingId) {
                $sizeId = $postedSizeId;
            }
        }

        $codesRepo->updateSize($codeId, $sizeId);

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'codes.size.assign',
            "Moved code #{$codeId} to size " . ($sizeId !== null ? "#{$sizeId}" : 'Unsized') . " (listing #{$listingId})",
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Code moved.');
        return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/{fid}/codes/bulk
    // -----------------------------------------------------------------------

    public function bulk(Request $request, array $params): Response
    {
        $listingId = (int) ($params['fid'] ?? 0);

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $listingRepo = new ListingRepo($db, $config);
        $listing     = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        $action      = (string) $request->post('bulk_action', '');
        $selectedIds = array_map('intval', (array) ($request->post('selected') ?? []));

        if (empty($selectedIds)) {
            $session->set('lstr_flash_error', 'No codes selected.');
            return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
        }

        $codesRepo    = new CodesRepo($db);
        $activityRepo = new ActivityRepo($db);
        $uploadDir    = realpath(__DIR__ . '/../../../public/uploads/codes');

        if ($action === 'delete') {
            $deleted = 0;

            foreach ($selectedIds as $codeId) {
                $code = $codesRepo->getById($codeId);
                if ($code === null || $code->listingId !== $listingId) {
                    continue;
                }

                if ($uploadDir !== false) {
                    $filePath = $uploadDir . DIRECTORY_SEPARATOR . $code->filename;
                    if (is_file($filePath)) {
                        unlink($filePath);
                    }
                }

                $codesRepo->delete($codeId);
                $deleted++;
            }

            $activityRepo->log(
                (int) $session->get('lstr_admin_id'),
                'codes.bulk_delete',
                "Bulk-deleted {$deleted} code(s) from listing #{$listingId} ({$listing->name})",
                $request->ip(),
            );

            $session->set(
                'lstr_flash_success',
                'Deleted ' . $deleted . ' code' . ($deleted !== 1 ? 's' : '') . '.',
            );

        } elseif ($action === 'reassign') {
            // Resolve and validate target size
            $postedSizeId = $request->post('bulk_size_id', '') !== '' ? (int) $request->post('bulk_size_id', 0) : null;
            $targetSizeId = null;

            if ($postedSizeId !== null && $postedSizeId > 0) {
                $size = (new CodeSizesRepo($db))->getById($postedSizeId);
                if ($size !== null && $size->listingId === $listingId) {
                    $targetSizeId = $postedSizeId;
                }
            }

            $moved = 0;
            foreach ($selectedIds as $codeId) {
                $code = $codesRepo->getById($codeId);
                if ($code === null || $code->listingId !== $listingId) {
                    continue;
                }
                $codesRepo->updateSize($codeId, $targetSizeId);
                $moved++;
            }

            $activityRepo->log(
                (int) $session->get('lstr_admin_id'),
                'codes.bulk_reassign',
                "Bulk-moved {$moved} code(s) to size " . ($targetSizeId !== null ? "#{$targetSizeId}" : 'Unsized') . " (listing #{$listingId})",
                $request->ip(),
            );

            $session->set(
                'lstr_flash_success',
                'Moved ' . $moved . ' code' . ($moved !== 1 ? 's' : '') . '.',
            );

        } elseif ($action === 'approve' || $action === 'reject') {
            $newStatus = $action === 'approve' ? 'approved' : 'rejected';
            $changed   = 0;

            foreach ($selectedIds as $codeId) {
                $code = $codesRepo->getById($codeId);
                if ($code === null || $code->listingId !== $listingId) {
                    continue;
                }
                $codesRepo->updateStatus($codeId, $newStatus);
                $changed++;
            }

            $activityRepo->log(
                (int) $session->get('lstr_admin_id'),
                'codes.bulk_status',
                "Bulk-set {$changed} code(s) to {$newStatus} (listing #{$listingId})",
                $request->ip(),
            );

            $session->set(
                'lstr_flash_success',
                ucfirst($newStatus) . ' ' . $changed . ' code' . ($changed !== 1 ? 's' : '') . '.',
            );

        } elseif ($action === 'categorize') {
            // Resolve posted category IDs and validate they belong to this listing
            $catsRepo    = new CodeCategoriesRepo($db);
            $postedCatIds = array_map('intval', (array) ($request->post('bulk_cat_ids') ?? []));
            $validCatIds  = [];
            foreach ($postedCatIds as $catId) {
                if ($catId <= 0) {
                    continue;
                }
                $cat = $catsRepo->getById($catId);
                if ($cat !== null && $cat->listingId === $listingId) {
                    $validCatIds[] = $catId;
                }
            }

            if (empty($validCatIds)) {
                $session->set('lstr_flash_error', 'No valid categories selected.');
                return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
            }

            $categorized = 0;
            foreach ($selectedIds as $codeId) {
                $code = $codesRepo->getById($codeId);
                if ($code === null || $code->listingId !== $listingId) {
                    continue;
                }
                $catsRepo->addCategories($codeId, $validCatIds);
                $categorized++;
            }

            $activityRepo->log(
                (int) $session->get('lstr_admin_id'),
                'codes.bulk_categorize',
                "Bulk-added " . count($validCatIds) . " categor" . (count($validCatIds) !== 1 ? 'ies' : 'y') . " to {$categorized} code(s) (listing #{$listingId})",
                $request->ip(),
            );

            $session->set(
                'lstr_flash_success',
                'Added categories to ' . $categorized . ' code' . ($categorized !== 1 ? 's' : '') . '.',
            );

        } else {
            $session->set('lstr_flash_error', 'Unknown bulk action.');
        }

        return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/{fid}/codes/{id}/name
    // -----------------------------------------------------------------------

    public function assignName(Request $request, array $params): Response
    {
        $listingId = (int) ($params['fid'] ?? 0);
        $codeId    = (int) ($params['id']  ?? 0);

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $listingRepo = new ListingRepo($db, $config);
        if ($listingRepo->getById($listingId) === null) {
            return Response::notFound('Listing not found.');
        }

        $codesRepo = new CodesRepo($db);
        $code      = $codesRepo->getById($codeId);

        if ($code === null || $code->listingId !== $listingId) {
            $session->set('lstr_flash_error', 'Code not found.');
            return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
        }

        // Empty string → null (clears the name)
        $raw  = trim((string) $request->post('name', ''));
        $name = $raw !== '' ? $raw : null;

        $codesRepo->updateName($codeId, $name);

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'codes.name',
            $name !== null
                ? "Set name \"{$name}\" on code #{$codeId} (listing #{$listingId})"
                : "Cleared name on code #{$codeId} (listing #{$listingId})",
            $request->ip(),
        );

        $session->set('lstr_flash_success', $name !== null ? "Label set to \"{$name}\"." : 'Label cleared.');
        return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/{fid}/codes/{id}/status
    // -----------------------------------------------------------------------

    public function assignStatus(Request $request, array $params): Response
    {
        $listingId = (int) ($params['fid'] ?? 0);
        $codeId    = (int) ($params['id']  ?? 0);

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $listingRepo = new ListingRepo($db, $config);
        if ($listingRepo->getById($listingId) === null) {
            return Response::notFound('Listing not found.');
        }

        $codesRepo = new CodesRepo($db);
        $code      = $codesRepo->getById($codeId);

        if ($code === null || $code->listingId !== $listingId) {
            $session->set('lstr_flash_error', 'Code not found.');
            return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
        }

        $status = (string) $request->post('status', 'approved');
        if (!in_array($status, CodesRepo::STATUSES, true)) {
            $status = 'approved';
        }

        $codesRepo->updateStatus($codeId, $status);

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'codes.status',
            "Set code #{$codeId} status to {$status} (listing #{$listingId})",
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Status updated to ' . $status . '.');
        return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/{fid}/codes/reorder
    // -----------------------------------------------------------------------

    public function reorder(Request $request, array $params): Response
    {
        $listingId = (int) ($params['fid'] ?? 0);

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $listingRepo = new ListingRepo($db, $config);
        if ($listingRepo->getById($listingId) === null) {
            return Response::notFound('Listing not found.');
        }

        // ordered_ids is a JSON-encoded array of code IDs in the desired order
        $rawIds     = (string) $request->post('ordered_ids', '');
        $orderedIds = [];

        if ($rawIds !== '') {
            $decoded = json_decode($rawIds, true);
            if (is_array($decoded)) {
                $orderedIds = array_map('intval', $decoded);
                $orderedIds = array_filter($orderedIds, fn($id) => $id > 0);
                $orderedIds = array_values($orderedIds);
            }
        }

        if (empty($orderedIds)) {
            $session->set('lstr_flash_error', 'No order data received.');
            return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
        }

        (new CodesRepo($db))->reorder($listingId, $orderedIds);

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'codes.reorder',
            "Reordered " . count($orderedIds) . " code(s) for listing #{$listingId}",
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Display order saved.');
        return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
    }

    // -----------------------------------------------------------------------
    // GET /admin/listings/{fid}/codes/export
    // -----------------------------------------------------------------------

    public function export(Request $request, array $params): Response
    {
        $listingId = (int) ($params['fid'] ?? 0);

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $listingRepo = new ListingRepo($db, $config);
        $listing     = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        $codesRepo = new CodesRepo($db);
        $codes     = $codesRepo->getByListing($listingId, 'approved');

        if (empty($codes)) {
            $session = $this->app->getSession();
            $session->set('lstr_flash_error', 'No approved codes to export.');
            $baseUrl = $this->app->getBaseUrl();
            return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
        }

        $uploadDir = realpath(__DIR__ . '/../../../public/uploads/codes');
        if ($uploadDir === false) {
            $session = $this->app->getSession();
            $session->set('lstr_flash_error', 'Upload directory not found.');
            $baseUrl = $this->app->getBaseUrl();
            return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'lstr_codes_');
        if ($tmpFile === false) {
            $session = $this->app->getSession();
            $session->set('lstr_flash_error', 'Could not create temporary file for export.');
            $baseUrl = $this->app->getBaseUrl();
            return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmpFile, \ZipArchive::OVERWRITE) !== true) {
            unlink($tmpFile);
            $session = $this->app->getSession();
            $session->set('lstr_flash_error', 'Could not create ZIP archive.');
            $baseUrl = $this->app->getBaseUrl();
            return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
        }

        $added = 0;
        foreach ($codes as $code) {
            $filePath = $uploadDir . DIRECTORY_SEPARATOR . $code->filename;
            if (is_file($filePath)) {
                $zip->addFile($filePath, $code->filename);
                $added++;
            }
        }

        $zip->close();

        if ($added === 0) {
            unlink($tmpFile);
            $session = $this->app->getSession();
            $session->set('lstr_flash_error', 'No code image files found on disk.');
            $baseUrl = $this->app->getBaseUrl();
            return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
        }

        $zipContent = (string) file_get_contents($tmpFile);
        unlink($tmpFile);

        $safeName = preg_replace('/[^a-zA-Z0-9_.\-]/', '_', $listing->name);
        $filename = 'codes_' . $safeName . '_' . date('Ymd') . '.zip';

        return Response::download($zipContent, $filename, 'application/zip');
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/{fid}/codes/{id}/update
    // -----------------------------------------------------------------------

    /**
     * Consolidated per-code update: name, size, categories, donor, status in one POST.
     */
    public function updateCode(Request $request, array $params): Response
    {
        $listingId = (int) ($params['fid'] ?? 0);
        $codeId    = (int) ($params['id']  ?? 0);

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $listingRepo = new ListingRepo($db, $config);
        if ($listingRepo->getById($listingId) === null) {
            return Response::notFound('Listing not found.');
        }

        $codesRepo = new CodesRepo($db);
        $code      = $codesRepo->getById($codeId);

        if ($code === null || $code->listingId !== $listingId) {
            $session->set('lstr_flash_error', 'Code not found.');
            return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
        }

        $adminId = (int) $session->get('lstr_admin_id');

        // Name (empty → null)
        $rawName = trim((string) $request->post('name', ''));
        $name    = $rawName !== '' ? $rawName : null;
        $codesRepo->updateName($codeId, $name);

        // Categories
        $catsRepo    = new CodeCategoriesRepo($db);
        $postedIds   = array_map('intval', (array) ($request->post('cat_ids') ?? []));
        $validCatIds = [];
        foreach ($postedIds as $catId) {
            if ($catId <= 0) continue;
            $cat = $catsRepo->getById($catId);
            if ($cat !== null && $cat->listingId === $listingId) {
                $validCatIds[] = $catId;
            }
        }
        $catsRepo->setCategories($codeId, $validCatIds);

        // Donor
        $donorRaw = $request->post('donor_id', '');
        $donorId  = null;
        if ($donorRaw !== '' && (int) $donorRaw > 0) {
            $donor = (new CodeDonorsRepo($db))->getById((int) $donorRaw);
            if ($donor !== null && $donor->listingId === $listingId) {
                $donorId = (int) $donorRaw;
            }
        }
        $codesRepo->updateDonor($codeId, $donorId);

        // Status
        $status = (string) $request->post('status', 'approved');
        if (!in_array($status, CodesRepo::STATUSES, true)) {
            $status = 'approved';
        }
        $codesRepo->updateStatus($codeId, $status);

        (new ActivityRepo($db))->log(
            $adminId,
            'codes.update',
            "Updated code #{$codeId} (listing #{$listingId}): status={$status}",
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Code updated.');
        return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/codes');
    }

    // -----------------------------------------------------------------------
    // Shared render helper
    // -----------------------------------------------------------------------

    private function layout(string $template, array $data, string $pageTitle, string $section, string $pageSubtitle = ''): Response
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
            'page_subtitle'   => $pageSubtitle,
            'site_name'       => (string) $config->get('site_name', 'Luster'),
            'admin_username'  => (string) $session->get('lstr_admin_username', ''),
            'csrf_token'      => $csrfToken,
            'current_section' => $section,
            'base_url'        => $baseUrl,
            'content'         => $inner,
        ], $this->app->getAdminNavData())));
    }
}
