<?php
/**
 * Sample listing — join form.
 *
 * REDIRECT SETUP
 *   In Admin → Listing → Edit → Embed, set:
 *     Form URL — Join:     https://yourdomain.com/fanlisting/join.php
 *     Redirect after Join: https://yourdomain.com/fanlisting/join.php?joined=1
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

$_siteName   = $_sampleL?->name ?? 'Listing Name';
$_ownerName  = (string) ($_sampleApp->getConfig()->get('owner_name',  '') ?: 'Your Name');
$_ownerEmail = (string) ($_sampleApp->getConfig()->get('owner_email', '') ?: 'you@example.com');
$_navActive  = 'join';
$_pageTitle  = 'Join';

require 'header.php';
?>

  <h1>Join</h1>

  <?php if (isset($_GET['joined'])): ?>
    <div class="notice notice--success">
      Thank you for joining! Your membership may need to be approved before it appears in the member list.
    </div>
    <a class="back-link" href="index.php">&larr; Back to home</a>
  <?php else: ?>
    <?php
    $luster_fanlisting_id = LISTING_ID;
    $luster_return        = true;
    echo require $_embedPath . '/join.php';
    ?>
  <?php endif; ?>

<?php require 'footer.php'; ?>
