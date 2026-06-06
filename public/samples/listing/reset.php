<?php
/**
 * Sample listing — password reset.
 *
 * Handles both steps of the reset flow automatically:
 *   Step 1 — Visitor enters their email. Luster emails them a link back to this page.
 *   Step 2 — Visitor arrives via reset.php?token=… and sets a new password.
 *
 * SETUP
 *   In Admin → Listings → Edit → Embed, set:
 *     Form URL — Reset:  https://yourdomain.com/fanlisting/reset.php
 */

$page_title = 'Reset Password';
require 'header.php';
?>

  <h1>Reset Password</h1>

  <?php
  $luster_fanlisting_id = 1;
  $luster_reset_step    = isset($_GET['token']) ? 'confirm' : 'request';
  require $luster_root . '/public/embed/reset.php';
  ?>

<?php require 'footer.php'; ?>
