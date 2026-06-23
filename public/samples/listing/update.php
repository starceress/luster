<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Sample update page for a fanlisting. Embeds the Luster update form so existing
 members can change their name, URL, country, or other details. Includes a link
 to the password reset page. Set the Form URL and Redirect URL for the update
 form in Admin before using this page. Customize the HTML to match your design.
*/

$page_title = 'Update';
require 'header.php';
?>

  <h1>Update</h1>

  <?php
  $luster_fanlisting_id     = 2;
  $luster_lost_password_url = 'reset.php';
  require $luster_root . '/public/embed/update.php';
  ?>

<?php require 'footer.php'; ?>
