<?php
/**
 * Sample collective — owned listings.
 *
 * Options (set before the embed require):
 *   $luster_status             = 'current';  // current | upcoming | pending | all
 *   $luster_category_id        = 0;          // 0 = reads $_GET['cat'] automatically
 *   $luster_hide_dropdown      = false;      // suppress the category selector
 *   $luster_show_list          = false;      // categories as link list vs dropdown
 *   $luster_browse_by_category = true;       // show category index first, then filter
 */

$page_title = 'Owned';
require 'header.php';
?>

  <h1>Owned listings</h1>

  <?php
  $luster_status             = 'current';
  $luster_browse_by_category = true;
  require $luster_root . '/public/embed/owned.php';
  ?>

<?php require 'footer.php'; ?>
