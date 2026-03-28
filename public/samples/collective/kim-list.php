<?php
/**
 * Sample collective — KIM List overview.
 *
 * Requires the KIM List add-on to be installed.
 */

$_lusterRoot  = dirname(__DIR__, 3);
$_siteTagline = 'A fanlisting collective';  // ← your tagline
$_navActive   = 'kim';
$_pageTitle   = 'Keep In Mind — List';
require __DIR__ . '/_setup.php';
require 'header.php';
?>

  <h1 class="page-heading">KIM List</h1>

  <p class="text-muted">People interested in adopting these fanlistings if they ever become available.</p>

  <?php
  $luster_kim_signup_url = 'kim.php';
  $luster_return         = true;
  echo require $_embedPath . '/kim-list.php';
  ?>

<?php require 'footer.php'; ?>
