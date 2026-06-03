<?php
/**
 * Sample collective — joined listings.
 *
 * Options (set before the embed require):
 *   $luster_category            = '';    // '' = dropdown, or a category name string
 *   $luster_show_list           = false; // categories as link list vs dropdown
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
