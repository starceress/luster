<?php
/**
 * Sample listing — updates feed.
 * Requires the Updates add-on to be installed.
 */

$page_title = 'Updates';
require 'header.php';
?>

  <h1>Updates</h1>

  <?php
  $luster_fanlisting_id = 2;
  $luster_show_cats     = true;
  require $luster_root . '/public/embed/updates.php';
  ?>

<?php require 'footer.php'; ?>
