<?php
/**
 * Sample collective — owned listings (legacy filename; use owned.php instead).
 *
 * Options (set before the embed require):
 *   $luster_status          = 'current';  // current | upcoming | trouble | all
 *   $luster_category_id     = 0;          // 0 = reads $_GET['cat'] automatically
 *   $luster_hide_dropdown   = false;      // suppress the category selector
 *   $luster_show_list       = false;      // categories as link list vs dropdown
 *   $luster_exclude_trouble = true;       // hide trouble listings when status=all
 */

$_embedPath = dirname(__DIR__, 3) . '/public/embed';

$_siteName    = 'Collective Name';
$_siteTagline = 'A fanlisting collective';
$_ownerName   = 'Your Name';
$_ownerEmail  = 'you@example.com';
$_navActive   = 'owned';
$_pageTitle   = 'Owned';

require 'header.php';
?>

  <h1 class="page-heading">Owned listings</h1>

  <?php
  $luster_status = 'current';
  $luster_return = true;
  echo require $_embedPath . '/owned.php';
  ?>

<?php require 'footer.php'; ?>
