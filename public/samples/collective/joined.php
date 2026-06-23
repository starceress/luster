<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Sample joined listings page for a fanlisting collective. Shows the fanlistings
 you have joined on other sites, organized by category with a browse-by-category
 index. Customize the HTML to match your collective's design.
*/

$page_title = 'Joined';
require 'header.php';
?>

  <h1>Joined listings</h1>

  <?php
  $luster_browse_by_category = true;
  require $luster_root . '/public/embed/joined.php';
  ?>

<?php require 'footer.php'; ?>
