<?php
/**
 * Sample collective — joined listings.
 *
 * Options (set before the embed require):
 *   $luster_category            = '';    // '' = selector, or a category name string
 *   $luster_show_list           = true;  // overview: true = link list (default), false = dropdown
 *   $luster_show_cat_switcher   = true;  // per-category: true = dropdown switcher (default), false = none
 *   $luster_browse_by_category  = true;  // show category index first, then filter
 */

$page_title = 'Joined';
require 'header.php';
?>

  <h1>Joined listings</h1>

  <?php
  $luster_browse_by_category = true;
  require $luster_root . '/public/embed/joined.php';
  ?>

<?php require 'footer.php'; ?>
