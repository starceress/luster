<?php
/**
 * Sample listing — affiliate application form.
 *
 * SETUP
 *   1. Enable affiliate applications in Admin → Listings → Edit → Affiliates.
 *   2. In the same section, set:
 *        Application form URL:    affiliate-apply.php
 *        After-submit redirect:   affiliate-apply.php?sent=1
 */

const LISTING_ID = 1;           // ← your listing ID
$_lusterRoot = dirname(__DIR__, 3);
$_navActive  = '';
$_pageTitle  = 'Affiliate Application';
require __DIR__ . '/_setup.php';
require 'header.php';
?>

  <h1>Affiliate Application</h1>

  <?php if (isset($_GET['sent'])): ?>
    <div class="notice notice--success">
      Thank you for your application! We will review it and be in touch.
    </div>
    <a class="back-link" href="index.php">&larr; Back to home</a>
  <?php else: ?>
    <?php
    $luster_fanlisting_id = LISTING_ID;
    $luster_return        = true;
    echo require $_embedPath . '/affiliate-apply.php';
    ?>
  <?php endif; ?>

<?php require 'footer.php'; ?>
