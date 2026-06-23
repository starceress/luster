<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Sample owned listings page for a fanlisting collective. Shows the fanlistings
 you own, organized by category with a browse-by-category index. Several
 display options can be set before the embed (status filter, category browsing
 mode, dropdown vs. list). Customize the HTML to match your collective's design.
*/

$page_title = 'Owned';
require 'header.php';
?>

  <h1>Owned listings</h1>

  <?php
  $luster_status             = 'current';
  $luster_browse_by_category = true;
  require $luster_root . '/public/embed/owned.php';
  ?>

<?php require 'footer.php'; ?>
