<?php
/**
 * Sample collective — joined listings.
 *
 * Options (set before the embed require):
 *   $luster_category            = '';    // '' = dropdown, 'all' = all grouped,
 *                                        // or a category name string
 *   $luster_show_list           = false; // categories as link list vs dropdown
 *   $luster_show_all_by_default = false; // show all grouped below selector
 *   $luster_browse_by_category  = true;  // show category index first, then filter
 */

$_lusterRoot  = dirname(__DIR__, 3);
$_siteTagline = 'A fanlisting collective';  // ← your tagline
$_navActive   = 'joined';
$_pageTitle   = 'Joined';
require __DIR__ . '/_setup.php';
require 'header.php';
?>

  <h1 class="page-heading">Joined listings</h1>

  <?php
  $luster_category            = '';
  $luster_browse_by_category  = true;
  $luster_return              = true;
  echo require $_embedPath . '/joined.php';
  ?>

<?php require 'footer.php'; ?>
