<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\CodesRepo;
use Luster\Repositories\FanlistingRepo;

/**
 * Admin CRUD for per-fanlisting codes.
 *
 * Routes:
 *   GET  /admin/fanlistings/{fid}/codes                — list + upload form
 *   POST /admin/fanlistings/{fid}/codes/upload         — multi-file upload
 *   POST /admin/fanlistings/{fid}/codes/{id}/delete    — single delete
 *   POST /admin/fanlistings/{fid}/codes/bulk           — bulk delete
 */
final class CodesController
{
    /** Allowed MIME types for uploaded code images. */
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** Maximum upload size per file (500 KB). */
    private const MAX_SIZE = 512_000;

    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /admin/fanlistings/{fid}/codes
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $fanlistingId = (int) ($params['fid'] ?? 0);

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $fanlistingRepo = new FanlistingRepo($db, $config);
        $fanlisting     = $fanlistingRepo->getById($fanlistingId);

        if ($fanlisting === null) {
            return Response::notFound('Fanlisting not found.');
        }

        $session   = $this->app->getSession();
        $codesRepo = new CodesRepo($db);
        $codes     = $codesRepo->getByFanlisting($fanlistingId);

        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->layout('admin/fanlistings/codes.php', [
            'fanlisting'  => $fanlisting,
            'codes'       => $codes,
            'uploads_url' => $this->app->getBaseUrl() . '/uploads/codes',
            'success'     => $success,
            'error'       => $error,
        ], 'Codes — ' . $fanlisting->name, 'fanlistings');
    }

    // -----------------------------------------------------------------------
    // POST /admin/fanlistings/{fid}/codes/upload
    // -----------------------------------------------------------------------

    public function upload(Request $request, array $params): Response
    {
        $fanlistingId = (int) ($params['fid'] ?? 0);

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $fanlistingRepo = new FanlistingRepo($db, $config);
        $fanlisting     = $fanlistingRepo->getById($fanlistingId);

        if ($fanlisting === null) {
            return Response::notFound('Fanlisting not found.');
        }

        $codesRepo    = new CodesRepo($db);
        $activityRepo = new ActivityRepo($db);

        // Ensure upload directory exists
        $uploadDir = realpath(__DIR__ . '/../../../public/uploads') . DIRECTORY_SEPARATOR . 'codes';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Retrieve $_FILES['codes'] — supports both single and multiple files
        $fileData = $request->file('codes');

        if ($fileData === null || (is_array($fileData['name']) ? empty(array_filter($fileData['name'])) : $fileData['name'] === '')) {
            $session->set('lstr_flash_error', 'No files selected for upload.');
            return Response::redirect($baseUrl . '/admin/fanlistings/' . $fanlistingId . '/codes');
        }

        // Normalise PHP's grouped multi-file array into a flat list of file structs
        $names    = is_array($fileData['name'])     ? $fileData['name']     : [$fileData['name']];
        $tmpNames = is_array($fileData['tmp_name']) ? $fileData['tmp_name'] : [$fileData['tmp_name']];
        $errors   = is_array($fileData['error'])    ? $fileData['error']    : [$fileData['error']];
        $sizes    = is_array($fileData['size'])      ? $fileData['size']     : [$fileData['size']];

        $uploaded   = 0;
        $skipped    = [];
        $sortOffset = $codesRepo->getMaxSortOrder($fanlistingId);
        $finfo      = new \finfo(FILEINFO_MIME_TYPE);

        foreach ($names as $i => $originalName) {
            if ($originalName === '' || $originalName === null) {
                continue;
            }

            if ((int) $errors[$i] !== UPLOAD_ERR_OK) {
                $skipped[] = htmlspecialchars((string) $originalName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' (upload error)';
                continue;
            }

            $tmpName = (string) $tmpNames[$i];
            $size    = (int)    $sizes[$i];

            if ($size > self::MAX_SIZE) {
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

            $newFilename = 'c_' . uniqid('', true) . bin2hex(random_bytes(4)) . '.' . $ext;
            $dest        = $uploadDir . DIRECTORY_SEPARATOR . $newFilename;

            if (!move_uploaded_file($tmpName, $dest)) {
                $skipped[] = htmlspecialchars((string) $originalName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' (move failed)';
                continue;
            }

            // Auto-detect dimensions
            $imgInfo = @getimagesize($dest);
            $width   = $imgInfo !== false ? (int) $imgInfo[0] : 0;
            $height  = $imgInfo !== false ? (int) $imgInfo[1] : 0;

            $codesRepo->insert($fanlistingId, $newFilename, $width, $height, ++$sortOffset);
            $uploaded++;
        }

        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'codes.upload',
            "Uploaded {$uploaded} code(s) for fanlisting #{$fanlistingId} ({$fanlisting->name})",
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

        return Response::redirect($baseUrl . '/admin/fanlistings/' . $fanlistingId . '/codes');
    }

    // -----------------------------------------------------------------------
    // POST /admin/fanlistings/{fid}/codes/{id}/delete
    // -----------------------------------------------------------------------

    public function delete(Request $request, array $params): Response
    {
        $fanlistingId = (int) ($params['fid'] ?? 0);
        $codeId       = (int) ($params['id']  ?? 0);

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $fanlistingRepo = new FanlistingRepo($db, $config);
        $fanlisting     = $fanlistingRepo->getById($fanlistingId);

        if ($fanlisting === null) {
            return Response::notFound('Fanlisting not found.');
        }

        $codesRepo    = new CodesRepo($db);
        $activityRepo = new ActivityRepo($db);

        $code = $codesRepo->getById($codeId);

        if ($code === null || $code->fanlistingId !== $fanlistingId) {
            $session->set('lstr_flash_error', 'Code not found.');
            return Response::redirect($baseUrl . '/admin/fanlistings/' . $fanlistingId . '/codes');
        }

        // Delete the file from disk
        $uploadDir = realpath(__DIR__ . '/../../../public/uploads/codes');
        if ($uploadDir !== false) {
            $filePath = $uploadDir . DIRECTORY_SEPARATOR . $code->filename;
            if (is_file($filePath)) {
                @unlink($filePath);
            }
        }

        $codesRepo->delete($codeId);

        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'codes.delete',
            "Deleted code #{$codeId} ({$code->filename}) from fanlisting #{$fanlistingId}",
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Code deleted.');
        return Response::redirect($baseUrl . '/admin/fanlistings/' . $fanlistingId . '/codes');
    }

    // -----------------------------------------------------------------------
    // POST /admin/fanlistings/{fid}/codes/bulk
    // -----------------------------------------------------------------------

    public function bulk(Request $request, array $params): Response
    {
        $fanlistingId = (int) ($params['fid'] ?? 0);

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $fanlistingRepo = new FanlistingRepo($db, $config);
        $fanlisting     = $fanlistingRepo->getById($fanlistingId);

        if ($fanlisting === null) {
            return Response::notFound('Fanlisting not found.');
        }

        $action      = (string) $request->post('bulk_action', '');
        $selectedIds = array_map('intval', (array) ($request->post('selected') ?? []));

        if ($action !== 'delete' || empty($selectedIds)) {
            $session->set('lstr_flash_error', 'No action or selection provided.');
            return Response::redirect($baseUrl . '/admin/fanlistings/' . $fanlistingId . '/codes');
        }

        $codesRepo    = new CodesRepo($db);
        $activityRepo = new ActivityRepo($db);
        $uploadDir    = realpath(__DIR__ . '/../../../public/uploads/codes');

        $deleted = 0;

        foreach ($selectedIds as $codeId) {
            $code = $codesRepo->getById($codeId);

            if ($code === null || $code->fanlistingId !== $fanlistingId) {
                continue;
            }

            if ($uploadDir !== false) {
                $filePath = $uploadDir . DIRECTORY_SEPARATOR . $code->filename;
                if (is_file($filePath)) {
                    @unlink($filePath);
                }
            }

            $codesRepo->delete($codeId);
            $deleted++;
        }

        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'codes.bulk_delete',
            "Bulk-deleted {$deleted} code(s) from fanlisting #{$fanlistingId} ({$fanlisting->name})",
            $request->ip(),
        );

        $session->set(
            'lstr_flash_success',
            'Deleted ' . $deleted . ' code' . ($deleted !== 1 ? 's' : '') . '.',
        );

        return Response::redirect($baseUrl . '/admin/fanlistings/' . $fanlistingId . '/codes');
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
