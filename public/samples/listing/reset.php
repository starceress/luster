<?php
/**
 * Sample listing — lost password / reset form.
 *
 * This page handles both steps of the password reset flow:
 *
 *   Step 1 — Email entry (default):
 *     Visitor lands on reset.php, enters their email, submits.
 *     Luster sends a reset email containing a link back to this page with ?token=…
 *
 *   Step 2 — New password form:
 *     Visitor arrives via reset.php?token=…, enters a new password, submits.
 *
 * SETUP
 *   In Admin → Listing → Edit → Embed, set:
 *     Form URL — Reset:  reset.php
 *     (This tells Luster where to redirect on validation errors and builds
 *      the reset link sent by email.)
 */

const LISTING_ID = 1;           // ← your listing ID
$_lusterRoot = dirname(__DIR__, 3);
$_navActive  = '';              // not a nav item — accessed via "Forgot your password?" link
$_pageTitle  = 'Reset Password';
require __DIR__ . '/_setup.php';
require 'header.php';
?>

  <h1>Reset Password</h1>

  <?php
  $luster_fanlisting_id = LISTING_ID;
  $luster_reset_step    = isset($_GET['token']) ? 'confirm' : 'request';
  $luster_return        = true;
  echo require $_embedPath . '/reset.php';
  ?>

<?php require 'footer.php'; ?>
