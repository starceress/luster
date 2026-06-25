<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Sample KIM List update page for a fanlisting collective. Lets KIM members
 update their name, URL, or email address. Requires the KIM List add-on to be
 installed. Customize the HTML to match your design.
*/

$page_title = 'Keep In Mind — Update';
require 'header.php';
?>

  <h1>Update Your Entry</h1>

  <p>Update your name, URL, or email address on the KIM list.</p>

  <?php
  $luster_kim_reset_url = 'kim-reset.php';
  $luster_kim_list_url  = 'kim-list.php';
  require $luster_root . '/public/embed/kim-update.php';
  ?>

<?php require 'footer.php'; ?>
