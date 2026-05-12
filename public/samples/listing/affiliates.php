<?php
const LISTING_ID = 1;           // ← your listing ID
$_lusterRoot = dirname(__DIR__, 3);
$_navActive  = 'affiliates';
$_pageTitle  = 'Affiliates';
require __DIR__ . '/_setup.php';
require 'header.php';
?>

  <h1>Affiliates</h1>

  <?php
  $luster_fanlisting_id   = LISTING_ID;
  $luster_affiliates_base = strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: '';
  $luster_return          = true;
  echo require $_embedPath . '/affiliates.php';
  ?>

  <p><a href="affiliate-apply.php">Want to affiliate? Apply here.</a></p>

<?php require 'footer.php'; ?>
