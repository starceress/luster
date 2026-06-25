<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Sample affiliates page for a fanlisting. Displays the listing's approved
 affiliates and links to the affiliate application form. Customize the HTML
 to match your fanlisting's design.
*/

$page_title = 'Affiliates';
require 'header.php';
?>

  <h1>Affiliates</h1>

  <?php
  $luster_fanlisting_id   = 1;
  require $luster_root . '/public/embed/affiliates.php';
  ?>

  <p><a href="affiliate-apply.php">Want to affiliate? Apply here.</a></p>

<?php require 'footer.php'; ?>
