<?php

declare(strict_types=1);

/**
 * Auto link-check cron endpoint.
 *
 * Call this URL from your hosting cron manager once per day. The script
 * checks all affiliate and joined-listing URLs and only runs when 30 days
 * have elapsed since the last run (or immediately when ?force=1 is set).
 *
 * Authentication: secret token passed as ?token=<value>
 * The token is stored in lstr_site_settings under key 'auto_link_check_token'.
 * Generate or rotate it via Admin → Link Checker → Auto Link Check.
 */

set_time_limit(300);
header('Content-Type: application/json');

defined('LUSTER_BASE') || define('LUSTER_BASE', dirname(__DIR__, 2));

/** @var \Luster\Core\Application $app */
$app = require LUSTER_BASE . '/bootstrap.php';

$db       = $app->getDatabase();
$settings = new \Luster\Repositories\SiteSettingsRepo($db);

// --- Token auth

$expected  = (string) ($settings->get('auto_link_check_token', '') ?? '');
$submitted = (string) ($_GET['token'] ?? '');

if ($expected === '' || !hash_equals($expected, $submitted)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit();
}

// --- Enabled check

$enabled = ($settings->get('auto_link_check_enabled', '0') ?? '0') === '1';
if (!$enabled) {
    echo json_encode(['skipped' => true, 'reason' => 'Auto-check is disabled']);
    exit();
}

// --- 30-day interval check

$force   = isset($_GET['force']) && $_GET['force'] === '1';
$lastRun = (string) ($settings->get('auto_link_check_last_run', '') ?? '');

if (!$force && $lastRun !== '') {
    $lastTs = strtotime($lastRun);
    if ($lastTs !== false && (time() - $lastTs) < 30 * 24 * 3600) {
        $nextRun = date('Y-m-d H:i:s', $lastTs + 30 * 24 * 3600);
        echo json_encode(['skipped' => true, 'reason' => 'Not due yet', 'next_run' => $nextRun]);
        exit();
    }
}

// --- Run checks

$affRepo    = new \Luster\Repositories\AffiliateRepo($db);
$joinedRepo = new \Luster\Repositories\JoinedListingRepo($db);

$cfg        = $app->getConfig();
$reciprocal = trim((string) $cfg->get('reciprocal_url', ''));
$ownerUrl   = rtrim($reciprocal !== '' ? $reciprocal : (string) $cfg->get('app_url', ''), '/');

$affiliates = $affRepo->getAllForLinkCheck();
$joined     = $joinedRepo->getAllForLinkCheck();

$affChecked    = 0;
$joinedChecked = 0;
$errors        = [];

foreach ($affiliates as $aff) {
    try {
        $result = \Luster\Core\LinkChecker::check($aff->url);
        $affRepo->updateLinkStatus($aff->id, $result['status'], $result['redirect_url']);
        if ($result['status'] !== 'dead') {
            $recipStatus = \Luster\Core\LinkChecker::checkReciprocal($aff->url, $ownerUrl);
            $affRepo->updateReciprocalStatus($aff->id, $recipStatus);
        }
        $affChecked++;
    } catch (\Throwable $e) {
        $errors[] = 'Affiliate #' . $aff->id . ': ' . $e->getMessage();
    }
}

foreach ($joined as $jl) {
    try {
        $result = \Luster\Core\LinkChecker::check($jl->url);
        $joinedRepo->updateLinkStatus($jl->id, $result['status'], $result['redirect_url']);
        $joinedChecked++;
    } catch (\Throwable $e) {
        $errors[] = 'Joined #' . $jl->id . ': ' . $e->getMessage();
    }
}

// --- Record completion

$checkedAt = date('Y-m-d H:i:s');
$settings->set('auto_link_check_last_run', $checkedAt);

(new \Luster\Repositories\ActivityRepo($db))->log(
    null,
    'link-checker',
    sprintf('Auto-check completed: %d affiliates, %d joined listings checked', $affChecked, $joinedChecked),
);

echo json_encode([
    'ok'                 => true,
    'affiliates_checked' => $affChecked,
    'joined_checked'     => $joinedChecked,
    'checked_at'         => $checkedAt,
    'errors'             => $errors,
]);
exit();
