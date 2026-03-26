<?php
/**
 * Sample collective — joined listings.
 *
 * Options (set before the embed require):
 *   $luster_category            = '';    // '' = dropdown, 'all' = all grouped,
 *                                        // or a category name string
 *   $luster_show_list           = false; // categories as link list vs dropdown
 *   $luster_show_all_by_default = true;  // show all grouped below selector
 */

$_lusterRoot  = dirname(__DIR__, 3);
$_siteTagline = 'A fanlisting collective';  // ← your tagline
$_navActive   = 'joined';
$_pageTitle   = 'Joined';
require __DIR__ . '/_setup.php';
require 'header.php';
?>

  <h1 class="page-heading">Joined listings</h1>

  <p class="text-muted">Fanlistings I'm a member of.</p>

  <?php
  $luster_category            = '';
  $luster_show_all_by_default = true;
  $luster_return              = true;
  echo require $_embedPath . '/joined.php';
  ?>

<?php require 'footer.php'; ?>
