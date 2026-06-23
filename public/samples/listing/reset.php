<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Sample password reset page for a fanlisting. Handles both steps of the reset
 flow: the visitor enters their email to receive a reset link, then returns via
 that link to set a new password. Set the Form URL for the reset form in Admin
 before using this page. Customize the HTML to match your design.
*/

$page_title = 'Reset Password';
require 'header.php';
?>

  <h1>Reset Password</h1>

  <?php
  $luster_fanlisting_id = 2;
  require $luster_root . '/public/embed/reset.php';
  ?>

<?php require 'footer.php'; ?>
