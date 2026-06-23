<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Sample contact page for a fanlisting. Embeds the Luster contact form so
 visitors can send a message to the listing owner. Customize the HTML to match
 your fanlisting's design.
*/

$page_title = 'Contact';
require 'header.php';
?>

  <h1>Contact</h1>

  <?php
  $luster_fanlisting_id = 1;
  require $luster_root . '/public/embed/contact.php';
  ?>

<?php require 'footer.php'; ?>
