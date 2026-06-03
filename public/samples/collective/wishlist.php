<?php
/**
 * Sample collective — wishlist.
 * Requires the Wishlist add-on to be installed.
 *
 * Options:
 *   $luster_wishlist_type = 'all';  // all | wanted | top | granted | upcoming
 */

$page_title = 'Wishlist';
require 'header.php';
?>

  <h1>Wishlist</h1>
  <p>Fanlistings I would love to own one day.</p>

  <?php
  $luster_wishlist_type = 'all';
  require $luster_root . '/public/embed/wishlist.php';
  ?>

<?php require 'footer.php'; ?>
