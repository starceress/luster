<?php
/**
 * Sample collective — owned listings (legacy filename; use owned.php instead).
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
