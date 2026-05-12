<?php
/**
 * Sample collective — wishlist.
 *
 * Options (set before the embed require):
 *   $luster_wishlist_type = 'all';   // 'all', 'wanted', 'top', 'granted', 'upcoming'
 */

$_lusterRoot  = dirname(__DIR__, 3);
$_siteTagline = 'A fanlisting collective';  // ← your tagline
$_navActive   = 'wishlist';
$_pageTitle   = 'Wishlist';
require __DIR__ . '/_setup.php';
require 'header.php';
?>

  <h1 class="page-heading">Wishlist</h1>
  <p class="page-sub">Fanlistings I would love to own one day.</p>

  <?php
  $luster_wishlist_type = 'all';
  $luster_return        = true;
  echo require $_embedPath . '/wishlist.php';
  ?>

<?php require 'footer.php'; ?>
