<?php
/**
 * Sample listing — codes.
 */

const LISTING_ID = 1;           // ← your listing ID
$_lusterRoot = dirname(__DIR__, 3);
$_navActive  = 'codes';
$_pageTitle  = 'Codes';
require __DIR__ . '/_setup.php';
require 'header.php';
?>

  <h1>Codes</h1>

  <p>Right-click to save, then link back to this listing. <a class="back-link" href="codes-submit.php">Click here if you'd like to donate a code!</a></p>

  <?php
  $luster_fanlisting_id = LISTING_ID;
  $luster_return        = true;
  echo require $_embedPath . '/codes.php';
  ?>

<?php require 'footer.php'; ?>
