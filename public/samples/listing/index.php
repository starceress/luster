<?php
/**
 * Sample listing — home page.
 *
 * SETUP
 *   1. Set LISTING_ID to your listing's numeric ID
 *      (visible in the admin panel URL: /admin/listings/1/edit → ID is 1).
 *   2. Copy this directory to your listing's web root (any location you like).
 *      Then update $_embedPath to the absolute server path of Luster's
 *      public/embed/ directory.
 *
 * HOW FORM REDIRECTS WORK
 *   The join/update/remove forms POST to Luster's own routes.
 *   Configure the "Form URL" and "Redirect URL" fields in the listing's
 *   Embed Settings (admin → listing → Edit → Embed) so that Luster sends
 *   visitors back to these sample pages on success or validation error.
 *   Example — Form URL (Join): https://yourdomain.com/fanlisting/join.php
 */

const LISTING_ID = 1;   // ← your listing ID

$_embedPath  = dirname(__DIR__, 3) . '/public/embed';
$_lusterRoot = dirname(__DIR__, 3);

// ── Load listing data before any HTML output ───────────────────────────────
defined('LUSTER_BASE') || define('LUSTER_BASE', $_lusterRoot);
require_once $_lusterRoot . '/vendor/autoload.php';

$_sampleApp = require $_lusterRoot . '/bootstrap.php';
$_sampleL  = (new \Luster\Repositories\ListingRepo(
    $_sampleApp->getDatabase(),
    $_sampleApp->getConfig(),
))->getById(LISTING_ID);

// ── Site identity ─────────────────────────────────────────────────────────────
$_siteName  = $_sampleL?->name    ?? 'Listing Name';
$_flSubject = $_sampleL?->subject ?? 'Subject Name';
$_flUrl     = $_sampleL?->url     ?? '#';
$_navActive = 'home';

function _e(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= _e($_siteName) ?> &mdash; Home</title>
  <link rel="stylesheet" href="../../assets/default/style.css">
</head>
<body>

<header class="fl-site-header">
  <div class="fl-header-inner">
    <p class="fl-site-title"><a href="index.php"><?= _e($_siteName) ?></a></p>
    <nav class="fl-nav" aria-label="Listing navigation">
      <a href="index.php"   class="active">Home</a>
      <a href="join.php">Join</a>
      <a href="update.php">Update</a>
      <a href="remove.php">Remove</a>
      <a href="members.php">Members</a>
      <a href="codes.php">Codes</a>
      <a href="updates.php">Updates</a>
      <a href="affiliates.php">Affiliates</a>
    </nav>
  </div>
</header>

<main class="fl-site-main">
  <div class="fl-wrap">

    <h2 class="fl-title">Welcome</h2>

    <p>
      Welcome! This is a listing for
      <a href="<?= _e($_flUrl) ?>"><?= _e($_flSubject) ?></a>.
      Edit this text to introduce your listing and its subject.
    </p>

    <?php
    // Stats — configure the stats template in Admin → Listing → Edit → Templates
    $luster_fanlisting_id = LISTING_ID;
    $luster_return        = true;
    $statsHtml = require $_embedPath . '/stats.php';
    if ($statsHtml) {
        echo $statsHtml;
    }
    ?>

    <p style="margin-top:1.5rem">
      <a class="fl-btn" href="join.php">Join this listing</a>
      &nbsp;
      <a class="fl-link" href="members.php" style="font-size:.9rem">View all members &rarr;</a>
    </p>

  </div>
</main>

<footer class="fl-site-footer">
  <p>Powered by <a href="https://github.com/sjlabels/luster">Luster</a></p>
</footer>

</body>
</html>
