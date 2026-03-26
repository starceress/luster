<?php
/**
 * Sample collective — site-wide affiliates.
 *
 * $luster_fanlisting_id = 0 shows collective-wide affiliates
 * (those with no specific listing assigned).
 * Change to a real listing ID to show that listing's affiliates instead.
 */

$_lusterRoot  = dirname(__DIR__, 3);
$_siteTagline = 'A fanlisting collective';  // ← your tagline
$_navActive   = 'affiliates';
$_pageTitle   = 'Affiliates';
require __DIR__ . '/_setup.php';
require 'header.php';
?>

  <h1 class="page-heading">Affiliates</h1>

  <p class="text-muted">Friends of the collective — click to visit.</p>

  <?php
  $luster_fanlisting_id   = 0;   // 0 = collective/site-wide affiliates
  $luster_affiliates_base = strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: '';
  $luster_per_page        = 50;
  $luster_return          = true;
  echo require $_embedPath . '/affiliates.php';
  ?>

<?php require 'footer.php'; ?>
