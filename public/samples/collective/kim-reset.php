<?php
/**
 * Sample collective — KIM List password reset.
 * Requires the KIM List add-on to be installed.
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
