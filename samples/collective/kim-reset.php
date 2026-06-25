<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Sample KIM List password reset page for a fanlisting collective. Lets KIM
 members request a password reset link via email. Requires the KIM List add-on
 to be installed. Customize the HTML to match your design.
*/

$page_title = 'Keep In Mind — Reset Password';
require 'header.php';
?>

  <h1>Reset Password</h1>

  <p>Forgot your KIM List password? Enter your email to receive a reset link.</p>

  <?php
  $luster_kim_update_url = 'kim-update.php';
  require $luster_root . '/public/embed/kim-reset.php';
  ?>

<?php require 'footer.php'; ?>
