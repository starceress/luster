<?php
/**
 * Sample listing — home page.
 *
 * SETUP
 *   1. Set LISTING_ID to your listing's numeric ID
 *      (visible in the admin panel URL: /admin/listings/1/edit → ID is 1).
 *   2. Copy this directory to your listing's web root.
 *      Update $_embedPath and $_lusterRoot to match your server paths.
 *
 * HOW FORM REDIRECTS WORK
 *   The join/update/remove forms POST to Luster's own routes.
 *   Configure "Form URL" and "Redirect URL" in Admin → Listing → Edit → Embed.
 *   Example — Form URL (Join): https://yourdomain.com/fanlisting/join.php
 */

const LISTING_ID = 1;   // ← your listing ID

$_embedPath  = dirname(__DIR__, 3) . '/public/embed';
$_lusterRoot = dirname(__DIR__, 3);

defined('LUSTER_BASE') || define('LUSTER_BASE', $_lusterRoot);
require_once $_lusterRoot . '/vendor/autoload.php';

$_sampleApp = require $_lusterRoot . '/bootstrap.php';
$_sampleL   = (new \Luster\Repositories\ListingRepo(
    $_sampleApp->getDatabase(),
    $_sampleApp->getConfig(),
))->getById(LISTING_ID);

$_siteName   = $_sampleL?->name    ?? 'Listing Name';
$_flSubject  = $_sampleL?->subject ?? 'Subject Name';
$_flUrl      = $_sampleL?->url     ?? '#';
$_ownerName  = (string) ($_sampleApp->getConfig()->get('owner_name',  '') ?: 'Your Name');
$_ownerEmail = (string) ($_sampleApp->getConfig()->get('owner_email', '') ?: 'you@example.com');
$_navActive  = 'home';
$_pageTitle  = 'Home';

require 'header.php';
?>

  <h1>Welcome</h1>

  <p>Welcome to <b><?= $_siteName ?></b>, a fanlisting dedicated to <u><?= htmlspecialchars($_flSubject, ENT_QUOTES, 'UTF-8') ?></u>.</p>
  
  <p>Edit this text to introduce your listing and its subject.

  <?php
  // Stats — configure the template in Admin → Listing → Edit → Templates
  $luster_fanlisting_id = LISTING_ID;
  $luster_return        = true;
  $statsHtml = require $_embedPath . '/stats.php';
  if ($statsHtml) echo $statsHtml;
  ?>

  <?php
  // Updates feed — requires the Updates add-on (blank when not installed)
  $luster_fanlisting_id = LISTING_ID;
  $luster_show_cats     = true;
  $luster_return        = true;
  $updatesHtml = require $_embedPath . '/updates.php';
  if ($updatesHtml): ?>
    <h1>Updates</h1>
    <?= $updatesHtml ?>
  <?php endif; ?>

<?php require 'footer.php'; ?>
