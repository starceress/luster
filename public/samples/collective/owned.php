<?php
/**
 * Sample collective — owned listings.
 *
 * Options (set before the embed require):
 *   $luster_status          = 'current';  // current | upcoming | trouble | all
 *   $luster_category_id     = 0;          // 0 = reads $_GET['cat'] automatically
 *   $luster_hide_dropdown   = false;      // suppress the category selector
 *   $luster_show_list       = false;      // categories as link list vs dropdown
 *   $luster_exclude_trouble = true;       // hide trouble listings when status=all
 */

$_lusterRoot  = dirname(__DIR__, 3);
$_siteTagline = 'A fanlisting collective';  // ← your tagline
$_navActive   = 'owned';
$_pageTitle   = 'Owned';
require __DIR__ . '/_setup.php';
require 'header.php';
?>

  <h1 class="page-heading">Owned listings</h1>

  <?php
  $luster_status = 'current';
  $luster_return = true;
  echo require $_embedPath . '/owned.php';
  ?>

<?php require 'footer.php'; ?>
