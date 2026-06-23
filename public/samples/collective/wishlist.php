<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Sample wishlist page for a fanlisting collective. Displays fanlistings you
 would like to own someday, with an optional type filter (wanted, top priority,
 granted, upcoming). Requires the Wishlist add-on to be installed. Customize
 the HTML to match your collective's design.
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
