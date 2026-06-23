<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Sample news feed page for a fanlisting collective. Displays all collective-wide
 news and update entries, with optional category tags on each post. Requires the
 News Feed add-on to be installed. Customize the HTML to match your design.
*/

$page_title = 'Updates';
require 'header.php';
?>

  <h1>Updates</h1>

  <?php
  $luster_show_cats = true;
  require $luster_root . '/public/embed/collective-updates.php';
  ?>

  <p style="margin-top:1.25rem">
    <a class="back-link" href="index.php">&larr; Back to home</a>
  </p>

<?php require 'footer.php'; ?>
