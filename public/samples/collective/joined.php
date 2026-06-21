<?php
/**
 * Sample collective — joined listings.
 *
 * Options (set before the embed require):
 *   $luster_category            = '';    // '' = selector, 'all' = grouped, or exact category name
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
