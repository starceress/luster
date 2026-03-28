<?php
/**
 * Sample collective — Keep In Mind (KIM) list sign-up.
 *
 * Requires the KIM List add-on to be installed.
 */

$_lusterRoot = dirname(__DIR__, 3);
$_siteTagline = 'A fanlisting collective';  // ← your tagline
$_navActive   = 'kim';
$_pageTitle   = 'Keep In Mind';
require __DIR__ . '/_setup.php';
require 'header.php';
?>

  <h1 class="page-heading">Keep In Mind</h1>

  <p class="text-muted">Interested in a fanlisting but not yet a member? Add yourself to the waiting list and you'll be notified when you join.</p>

  <?php
  $luster_kim_update_url = 'kim-update.php';
  $luster_kim_reset_url  = 'kim-reset.php';
  $luster_kim_list_url   = 'kim-list.php';
  $luster_return         = true;
  echo require $_embedPath . '/kim-signup.php';
  ?>

<?php require 'footer.php'; ?>
