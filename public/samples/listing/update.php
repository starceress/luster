<?php
/**
 * Sample listing — update membership form.
 *
 * REDIRECT SETUP
 *   In Admin → Listing → Edit → Embed, set:
 *     Form URL — Update:     https://yourdomain.com/fanlisting/update.php
 *     Redirect after Update: https://yourdomain.com/fanlisting/update.php?updated=1
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
$_navActive  = 'update';
$_pageTitle  = 'Update';

require 'header.php';
?>

  <h1>Update</h1>

  <?php if (isset($_GET['updated'])): ?>
    <div class="notice notice--success">
      Your membership details have been updated successfully.
    </div>
    <a class="back-link" href="index.php">&larr; Back to home</a>
  <?php else: ?>
    <?php
    $luster_fanlisting_id    = LISTING_ID;
    $luster_lost_password_url = 'reset.php';
    $luster_return            = true;
    echo require $_embedPath . '/update.php';
    ?>
  <?php endif; ?>

<?php require 'footer.php'; ?>
