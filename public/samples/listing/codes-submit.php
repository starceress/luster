<?php
/**
 * Sample listing — fan code submission.
 */

const LISTING_ID = 1;           // ← your listing ID
$_lusterRoot = dirname(__DIR__, 3);
$_navActive  = 'codes';
$_pageTitle  = 'Donate a Code';
require __DIR__ . '/_setup.php';
require 'header.php';
?>

  <h1>Donate a Code</h1>
  <?php
  $luster_fanlisting_id = LISTING_ID;
  $luster_return        = true;
  echo require $_embedPath . '/codes-submit.php';
  ?>

<?php require 'footer.php'; ?>
