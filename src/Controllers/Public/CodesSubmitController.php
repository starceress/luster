<?php

declare(strict_types=1);

namespace Luster\Controllers\Public;

use Luster\Core\Application;
use Luster\Core\Captcha;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\CodesRepo;
use Luster\Repositories\ListingRepo;

/**
 * Public fan code submission — allows fans to submit code images for review.
 *
 * Routes:
 *   GET  /codes/submit/{id}        — show submission form
 *   POST /codes/submit/{id}        — process submission (stores as pending)
 *   GET  /codes/submit/{id}/thanks — thank-you page after successful submission
 */
final class CodesSubmitController
{
    /** Allowed MIME types for submitted code images. */
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** Maximum upload size per file (200 KB — tighter limit for fan submissions). */
    private const MAX_SIZE = 204_800;

    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /codes/submit/{id}
    // -----------------------------------------------------------------------

    public function showForm(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $listingRepo = new ListingRepo($db, $config);
        $listing     = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $error = (string) $session->get('lstr_flash_error', '');
        $old   = (array)  $session->get('lstr_flash_old',   []);
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_old');

        $captchaQuestion = Captcha::getQuestion('code_submit');

        return Response::html($this->app->getView()->renderPublic('code-submit.php', [
            'listing'          => $listing,
            'listing_name'     => $listing->name,
            'listing_url'      => $listing->url ?? '',
            'page_title'       => 'Submit a Code — ' . $listing->name,
            'base_url'         => $baseUrl,
            'theme_path'       => $baseUrl . '/assets/default',
            'form_action'      => $baseUrl . '/codes/submit/' . $listingId,
            'csrf_token'       => $this->app->getCSRF()->token(),
            'errors'           => htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'old'              => $old,
            'captcha_question' => $captchaQuestion,
            'captcha_error'    => '',
            'submitted'        => false,
        ]));
    }

    // -----------------------------------------------------------------------
    // POST /codes/submit/{id}
    // -----------------------------------------------------------------------

    public function submit(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $listingRepo = new ListingRepo($db, $config);
        $listing     = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        $submitUrl = $baseUrl . '/codes/submit/' . $listingId;

        // Validate captcha
        $captchaAnswer = (string) $request->post('captcha', '');
        if (!Captcha::validate($captchaAnswer, 'code_submit')) {
            $session->set('lstr_flash_error', 'Incorrect answer to the anti-spam question. Please try again.');
            $session->set('lstr_flash_old', [
                'submitter_name'  => $request->post('submitter_name',  ''),
                'submitter_email' => $request->post('submitter_email', ''),
            ]);
            return Response::redirect($submitUrl);
        }

        // Collect optional submitter info
        $submitterName  = trim((string) $request->post('submitter_name',  ''));
        $submitterEmail = trim((string) $request->post('submitter_email', ''));
        $submitterName  = $submitterName  !== '' ? $submitterName  : null;
        $submitterEmail = $submitterEmail !== '' ? $submitterEmail : null;

        // Validate email if provided
        if ($submitterEmail !== null && filter_var($submitterEmail, FILTER_VALIDATE_EMAIL) === false) {
            $session->set('lstr_flash_error', 'Please enter a valid email address.');
            $session->set('lstr_flash_old', [
                'submitter_name'  => $submitterName  ?? '',
                'submitter_email' => $submitterEmail ?? '',
            ]);
            return Response::redirect($submitUrl);
        }

        // Validate file upload
        $fileData = $request->file('code_image');
        if ($fileData === null || (string) $fileData['name'] === '' || (int) $fileData['error'] === UPLOAD_ERR_NO_FILE) {
            $session->set('lstr_flash_error', 'Please select a code image to submit.');
            $session->set('lstr_flash_old', [
                'submitter_name'  => $submitterName  ?? '',
                'submitter_email' => $submitterEmail ?? '',
            ]);
            return Response::redirect($submitUrl);
        }

        if ((int) $fileData['error'] !== UPLOAD_ERR_OK) {
            $session->set('lstr_flash_error', 'File upload failed. Please try again.');
            $session->set('lstr_flash_old', [
                'submitter_name'  => $submitterName  ?? '',
                'submitter_email' => $submitterEmail ?? '',
            ]);
            return Response::redirect($submitUrl);
        }

        if ((int) $fileData['size'] > self::MAX_SIZE) {
            $session->set('lstr_flash_error', 'The file exceeds the 200 KB size limit.');
            $session->set('lstr_flash_old', [
                'submitter_name'  => $submitterName  ?? '',
                'submitter_email' => $submitterEmail ?? '',
            ]);
            return Response::redirect($submitUrl);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file((string) $fileData['tmp_name']);

        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            $session->set('lstr_flash_error', 'Only JPEG, PNG, GIF, and WebP images are accepted.');
            $session->set('lstr_flash_old', [
                'submitter_name'  => $submitterName  ?? '',
                'submitter_email' => $submitterEmail ?? '',
            ]);
            return Response::redirect($submitUrl);
        }

        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            default      => 'bin',
        };

        $imgInfo = getimagesize((string) $fileData['tmp_name']);
        $width   = $imgInfo !== false ? (int) $imgInfo[0] : 0;
        $height  = $imgInfo !== false ? (int) $imgInfo[1] : 0;

        // Ensure upload directory exists
        $uploadDir = realpath(__DIR__ . '/../../../public/uploads') . DIRECTORY_SEPARATOR . 'codes';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $newFilename = 'c_' . uniqid('', true) . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest        = $uploadDir . DIRECTORY_SEPARATOR . $newFilename;

        if (!move_uploaded_file((string) $fileData['tmp_name'], $dest)) {
            $session->set('lstr_flash_error', 'Could not save the uploaded file. Please try again.');
            $session->set('lstr_flash_old', [
                'submitter_name'  => $submitterName  ?? '',
                'submitter_email' => $submitterEmail ?? '',
            ]);
            return Response::redirect($submitUrl);
        }

        $codesRepo = new CodesRepo($db);
        $sortOrder = $codesRepo->getMaxSortOrder($listingId);

        $codesRepo->insert(
            $listingId,
            $newFilename,
            $width,
            $height,
            $sortOrder + 1,
            null,
            null,
            'pending',
            null,
            $submitterName,
            $submitterEmail,
        );

        return Response::redirect($baseUrl . '/codes/submit/' . $listingId . '/thanks');
    }

    // -----------------------------------------------------------------------
    // GET /codes/submit/{id}/thanks
    // -----------------------------------------------------------------------

    public function thanks(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $listingRepo = new ListingRepo($db, $config);
        $listing     = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        $baseUrl = $this->app->getBaseUrl();

        return Response::html($this->app->getView()->renderPublic('code-submit.php', [
            'listing'          => $listing,
            'listing_name'     => $listing->name,
            'listing_url'      => $listing->url ?? '',
            'page_title'       => 'Thanks! — ' . $listing->name,
            'base_url'         => $baseUrl,
            'theme_path'       => $baseUrl . '/assets/default',
            'form_action'      => $baseUrl . '/codes/submit/' . $listingId,
            'csrf_token'       => '',
            'errors'           => '',
            'old'              => [],
            'captcha_question' => '',
            'captcha_error'    => '',
            'submitted'        => true,
        ]));
    }
}
