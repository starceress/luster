<?php

declare(strict_types=1);

use Luster\Repositories\CodesRepo;
use Luster\Repositories\FanlistingRepo;

/**
 * Luster embed — codes fragment.
 *
 * Usage in your own page:
 *   $luster_fanlisting_id = 1;     // required: fanlisting ID (int)
 *   $luster_return        = false;  // optional: true = return string, false = echo
 *   require '/absolute/path/to/luster/public/embed/codes.php';
 *
 * Renders the codes grid for the given fanlisting as a content fragment
 * with no layout wrapper — your own page provides the surrounding HTML.
 */

// ---------------------------------------------------------------------------
// 1. Validate required variable
// ---------------------------------------------------------------------------

$_lusterFanlistingId = (int) ($luster_fanlisting_id ?? 0);

if ($_lusterFanlistingId === 0) {
    $_lusterHtml = '<p class="fl-errors">Luster embed error: <code>$luster_fanlisting_id</code> is not set.</p>';
    if ($luster_return ?? false) {
        return $_lusterHtml;
    }
    echo $_lusterHtml;
    return;
}

// ---------------------------------------------------------------------------
// 2. Bootstrap Luster
// ---------------------------------------------------------------------------

$_lusterRoot = dirname(__DIR__, 2);

if (!is_file($_lusterRoot . '/vendor/autoload.php') || !is_file($_lusterRoot . '/config/app.php')) {
    $_lusterHtml = '<p class="fl-errors">Luster embed error: Luster is not installed correctly.</p>';
    if ($luster_return ?? false) {
        return $_lusterHtml;
    }
    echo $_lusterHtml;
    return;
}

defined('LUSTER_BASE') || define('LUSTER_BASE', $_lusterRoot);

/** @var \Luster\Core\Application $_lusterApp */
$_lusterApp = require $_lusterRoot . '/bootstrap.php';
$_lusterApp->getSession()->start();

// ---------------------------------------------------------------------------
// 3. Load data
// ---------------------------------------------------------------------------

$_lusterFanlistingRepo = new FanlistingRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig());
$_lusterFanlisting     = $_lusterFanlistingRepo->getById($_lusterFanlistingId);

if ($_lusterFanlisting === null) {
    $_lusterHtml = '<p class="fl-errors">Luster embed error: fanlisting #' . $_lusterFanlistingId . ' not found.</p>';
    if ($luster_return ?? false) {
        return $_lusterHtml;
    }
    echo $_lusterHtml;
    return;
}

$_lusterCodesRepo = new CodesRepo($_lusterApp->getDatabase());
$_lusterCodes     = $_lusterCodesRepo->getByFanlisting($_lusterFanlistingId);

// ---------------------------------------------------------------------------
// 4. Render
// ---------------------------------------------------------------------------

$_lusterBaseUrl    = rtrim($_lusterApp->getBaseUrl(), '/');
$_lusterUploadsUrl = $_lusterBaseUrl . '/uploads/codes';

$_lusterHtml = $_lusterApp->getView()->renderFragment('codes.php', [
    'fanlisting'      => $_lusterFanlisting,
    'fanlisting_name' => $_lusterFanlisting->name,
    'fanlisting_url'  => $_lusterFanlisting->url,
    'page_title'      => 'Codes',
    'codes'           => $_lusterCodes,
    'uploads_url'     => $_lusterUploadsUrl,
    'join_url'        => $_lusterBaseUrl . '/join/' . $_lusterFanlistingId,
    'base_url'        => $_lusterBaseUrl,
    'theme_path'      => $_lusterBaseUrl . '/assets/default/',
]);

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
