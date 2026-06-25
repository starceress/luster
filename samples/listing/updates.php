<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Sample news feed page for a fanlisting. Displays the listing's news and update
 entries, with optional category tags on each post. Requires the News Feed
 add-on to be installed. Customize the HTML to match your design.
*/

$page_title = 'Updates';
require 'header.php';
?>

  <h1>Updates</h1>

  <?php
  $luster_fanlisting_id = 2;
  $luster_show_cats     = true;
  require $luster_root . '/public/embed/updates.php';
  ?>

<?php require 'footer.php'; ?>
