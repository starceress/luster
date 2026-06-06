<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

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
final class CodesController extends AdminController
{
    /** Allowed MIME types for uploaded code images. */
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** Maximum upload size per file (500 KB). */
    private const MAX_SIZE = 512_000;

    /**
     * Redirect after a codes action. When the request came from the standalone
     * Codes page (_from=codes), send the user back there; otherwise return to
     * the listing edit Codes tab.
     */
    private function afterActionRedirect(Request $request, string $baseUrl, int $listingId, string $editSuffix = ''): Response
    {
        if ($request->post('_from', '') === 'codes') {
            return Response::redirect($baseUrl . '/admin/codes?listing_id=' . $listingId);
        }
        return Response::redirect($baseUrl . '/admin/listings/' . $listingId . '/edit?tab=codes' . $editSuffix);
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

        // Resolve donor from POST.
        // Value may be a numeric donor_id (formal registry) or "s:NAME" (past submitter).
        $postedDonorVal      = (string) $request->post('donor_id', '');
        $donorId             = null;
        $uploadSubmitterName = null;

        if (str_starts_with($postedDonorVal, 's:')) {
            $rawName = trim(substr($postedDonorVal, 2));
            if ($rawName !== '') {
                $uploadSubmitterName = $rawName;
            }
        } elseif ($postedDonorVal !== '' && (int) $postedDonorVal > 0) {
            $donor = $donorsRepo->getById((int) $postedDonorVal);
            if ($donor !== null && $donor->listingId === $listingId) {
                $donorId = (int) $postedDonorVal;
            }
        }

        // Inline new-donor creation: if no donor selected and new_donor_name is filled,
        // create the donor and use the resulting ID for all uploaded codes in this batch.
        if ($donorId === null && $uploadSubmitterName === null) {
            $newDonorName = trim((string) $request->post('new_donor_name', ''));
            if ($newDonorName !== '') {
                $newDonorEmail = trim((string) $request->post('new_donor_email', ''));
                $newDonorUrl   = trim((string) $request->post('new_donor_url',   ''));
                if ($newDonorEmail !== '' && filter_var($newDonorEmail, FILTER_VALIDATE_EMAIL) === false) {
                    $newDonorEmail = '';
                }
                if ($newDonorUrl !== '' && (!filter_var($newDonorUrl, FILTER_VALIDATE_URL) || !in_array(parse_url($newDonorUrl, PHP_URL_SCHEME), ['http', 'https'], true))) {
                    $newDonorUrl = '';
                }
                $donorId = $donorsRepo->insert(
                    $listingId,
                    $newDonorName,
                    $newDonorEmail !== '' ? $newDonorEmail : null,
                    $newDonorUrl   !== '' ? $newDonorUrl   : null,
                );
                (new ActivityRepo($db))->log(
                    (int) $session->get('lstr_admin_id'),
                    'codes.donor.add',
                    "Added code donor \"{$newDonorName}\" for listing #{$listingId} (created inline during upload)",
                    $request->ip(),
                );
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
            return $this->afterActionRedirect($request, $baseUrl, $listingId);
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

            $newCodeId = $codesRepo->insert($listingId, $newFilename, $width, $height, ++$sortOffset, $sizeId, $donorId, $uploadStatus, null, $uploadSubmitterName);
            if (!empty($validCatIds)) {
                $catsRepo->setCategories($newCodeId, $validCatIds);
            }
            $uploaded++;
        }

        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'codes.upload',
            "Uploaded {$uploaded} code(s) for listing #{$listingId} ({$listing->title})",
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

        return $this->afterActionRedirect($request, $baseUrl, $listingId);
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
            return $this->afterActionRedirect($request, $baseUrl, $listingId);
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
        return $this->afterActionRedirect($request, $baseUrl, $listingId);
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
            return $this->afterActionRedirect($request, $baseUrl, $listingId);
        }

        $label  = trim((string) $request->post('label', ''));
        $wRaw   = (string) $request->post('width', '');
        $hRaw   = (string) $request->post('height', '');
        $width  = ($wRaw !== '' && (int) $wRaw > 0) ? (int) $wRaw : null;
        $height = ($hRaw !== '' && (int) $hRaw > 0) ? (int) $hRaw : null;

        if ($label === '') {
            $session->set('lstr_flash_error', 'Size label is required.');
            return $this->afterActionRedirect($request, $baseUrl, $listingId, '&edit_size=' . $sizeId);
        }

        $sizesRepo->update($sizeId, $label, $width, $height);

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'codes.size.edit',
            "Updated code size #{$sizeId} to \"{$label}\" for listing #{$listingId}",
            $request->ip(),
        );

        $session->set('lstr_flash_success', "Size \"{$label}\" updated.");
        return $this->afterActionRedirect($request, $baseUrl, $listingId);
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
            return $this->afterActionRedirect($request, $baseUrl, $listingId);
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
        return $this->afterActionRedirect($request, $baseUrl, $listingId);
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
            return $this->afterActionRedirect($request, $baseUrl, $listingId);
        }

        $name     = trim((string) $request->post('name', ''));
        $pidRaw   = $request->post('parent_id', '');
        $parentId = ($pidRaw !== '' && (int) $pidRaw > 0) ? (int) $pidRaw : null;

        if ($name === '') {
            $session->set('lstr_flash_error', 'Category name is required.');
            return $this->afterActionRedirect($request, $baseUrl, $listingId, '&edit_cat=' . $categoryId);
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
        return $this->afterActionRedirect($request, $baseUrl, $listingId);
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
            return $this->afterActionRedirect($request, $baseUrl, $listingId);
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
        return $this->afterActionRedirect($request, $baseUrl, $listingId);
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
            return $this->afterActionRedirect($request, $baseUrl, $listingId);
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
        return $this->afterActionRedirect($request, $baseUrl, $listingId);
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
            return $this->afterActionRedirect($request, $baseUrl, $listingId);
        }

        $name  = trim((string) $request->post('name',  ''));
        $email = trim((string) $request->post('email', ''));
        $url   = trim((string) $request->post('url',   ''));

        if ($name === '') {
            $session->set('lstr_flash_error', 'Donor name is required.');
            return $this->afterActionRedirect($request, $baseUrl, $listingId, '&edit_donor=' . $donorId);
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
        return $this->afterActionRedirect($request, $baseUrl, $listingId);
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
            return $this->afterActionRedirect($request, $baseUrl, $listingId);
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
        return $this->afterActionRedirect($request, $baseUrl, $listingId);
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
            return $this->afterActionRedirect($request, $baseUrl, $listingId);
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
        return $this->afterActionRedirect($request, $baseUrl, $listingId);
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
            return $this->afterActionRedirect($request, $baseUrl, $listingId);
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
        return $this->afterActionRedirect($request, $baseUrl, $listingId);
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
            return $this->afterActionRedirect($request, $baseUrl, $listingId);
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
        return $this->afterActionRedirect($request, $baseUrl, $listingId);
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
            return $this->afterActionRedirect($request, $baseUrl, $listingId);
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
        return $this->afterActionRedirect($request, $baseUrl, $listingId);
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
            return $this->afterActionRedirect($request, $baseUrl, $listingId);
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
        return $this->afterActionRedirect($request, $baseUrl, $listingId);
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
            return $this->afterActionRedirect($request, $baseUrl, $listingId);
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
                "Bulk-deleted {$deleted} code(s) from listing #{$listingId} ({$listing->title})",
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
                return $this->afterActionRedirect($request, $baseUrl, $listingId);
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

        return $this->afterActionRedirect($request, $baseUrl, $listingId);
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
            return $this->afterActionRedirect($request, $baseUrl, $listingId);
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
        return $this->afterActionRedirect($request, $baseUrl, $listingId);
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
            return $this->afterActionRedirect($request, $baseUrl, $listingId);
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
        return $this->afterActionRedirect($request, $baseUrl, $listingId);
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
            return $this->afterActionRedirect($request, $baseUrl, $listingId);
        }

        (new CodesRepo($db))->reorder($listingId, $orderedIds);

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'codes.reorder',
            "Reordered " . count($orderedIds) . " code(s) for listing #{$listingId}",
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Display order saved.');
        return $this->afterActionRedirect($request, $baseUrl, $listingId);
    }

    // -----------------------------------------------------------------------
    // GET /admin/listings/{fid}/codes/export
    // -----------------------------------------------------------------------

    public function export(Request $request, array $params): Response
    {
        if (!class_exists('ZipArchive')) {
            return Response::html('<p>The PHP <code>zip</code> extension is not enabled. Enable <code>extension=zip</code> in your <code>php.ini</code> and restart your web server.</p>', 500);
        }

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
            return $this->afterActionRedirect($request, $baseUrl, $listingId);
        }

        $uploadDir = realpath(__DIR__ . '/../../../public/uploads/codes');
        if ($uploadDir === false) {
            $session = $this->app->getSession();
            $session->set('lstr_flash_error', 'Upload directory not found.');
            $baseUrl = $this->app->getBaseUrl();
            return $this->afterActionRedirect($request, $baseUrl, $listingId);
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'lstr_codes_');
        if ($tmpFile === false) {
            $session = $this->app->getSession();
            $session->set('lstr_flash_error', 'Could not create temporary file for export.');
            $baseUrl = $this->app->getBaseUrl();
            return $this->afterActionRedirect($request, $baseUrl, $listingId);
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmpFile, \ZipArchive::OVERWRITE) !== true) {
            unlink($tmpFile);
            $session = $this->app->getSession();
            $session->set('lstr_flash_error', 'Could not create ZIP archive.');
            $baseUrl = $this->app->getBaseUrl();
            return $this->afterActionRedirect($request, $baseUrl, $listingId);
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
            return $this->afterActionRedirect($request, $baseUrl, $listingId);
        }

        $zipContent = (string) file_get_contents($tmpFile);
        unlink($tmpFile);

        $safeName = preg_replace('/[^a-zA-Z0-9_.\-]/', '_', $listing->title);
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
            return $this->afterActionRedirect($request, $baseUrl, $listingId);
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

        // Submitter name (empty string clears it)
        $rawSubmitterName = trim((string) $request->post('submitter_name', ''));
        $codesRepo->updateSubmitterName($codeId, $rawSubmitterName !== '' ? $rawSubmitterName : null);

        // Submitter email (empty string clears it)
        $rawSubmitterEmail = trim((string) $request->post('submitter_email', ''));
        if ($rawSubmitterEmail !== '' && filter_var($rawSubmitterEmail, FILTER_VALIDATE_EMAIL) === false) {
            $rawSubmitterEmail = '';
        }
        $codesRepo->updateSubmitterEmail($codeId, $rawSubmitterEmail !== '' ? $rawSubmitterEmail : null);

        // Submitter URL (empty string clears it)
        $rawSubmitterUrl = trim((string) $request->post('submitter_url', ''));
        if ($rawSubmitterUrl !== '' && (filter_var($rawSubmitterUrl, FILTER_VALIDATE_URL) === false || !in_array(parse_url($rawSubmitterUrl, PHP_URL_SCHEME), ['http', 'https'], true))) {
            $rawSubmitterUrl = '';
        }
        $codesRepo->updateSubmitterUrl($codeId, $rawSubmitterUrl !== '' ? $rawSubmitterUrl : null);

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
        return $this->afterActionRedirect($request, $baseUrl, $listingId);
    }

}
