<?php
/**
 * Sample listing — updates feed.
 * Requires the Updates add-on to be installed.
 */

const LISTING_ID = 1;           // ← your listing ID
$_lusterRoot = dirname(__DIR__, 3);
$_navActive  = 'home';
$_pageTitle  = 'Updates';
require __DIR__ . '/_setup.php';
require 'header.php';
?>

  <?php
  $luster_fanlisting_id = LISTING_ID;
  $luster_show_cats     = true;
  $luster_return        = true;
  echo require $_embedPath . '/updates.php';
  ?>

<?php require 'footer.php'; ?>
