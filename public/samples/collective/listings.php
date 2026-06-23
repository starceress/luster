<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Legacy filename for the owned listings page. This file is kept for backward
 compatibility with existing links. Use owned.php instead for new setups.
 Customize the HTML to match your collective's design.
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
