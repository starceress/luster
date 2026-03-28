<?php
/**
 * Sample collective — KIM List password reset.
 *
 * Requires the KIM List add-on to be installed.
 */

$_lusterRoot  = dirname(__DIR__, 3);
$_siteTagline = 'A fanlisting collective';  // ← your tagline
$_navActive   = 'kim';
$_pageTitle   = 'Keep In Mind — Reset Password';
require __DIR__ . '/_setup.php';
require 'header.php';
?>

  <h1 class="page-heading">Reset Password</h1>

  <p class="text-muted">Forgot your KIM List password? Enter your email and select the fanlisting to receive a reset link.</p>

  <?php
  $luster_kim_update_url = 'kim-update.php';
  $luster_return         = true;
  echo require $_embedPath . '/kim-reset.php';
  ?>

<?php require 'footer.php'; ?>
