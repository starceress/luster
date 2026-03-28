<?php
/**
 * Sample collective — KIM List update entry.
 *
 * Requires the KIM List add-on to be installed.
 */

$_lusterRoot  = dirname(__DIR__, 3);
$_siteTagline = 'A fanlisting collective';  // ← your tagline
$_navActive   = 'kim';
$_pageTitle   = 'Keep In Mind — Update';
require __DIR__ . '/_setup.php';
require 'header.php';
?>

  <h1 class="page-heading">Update Your Entry</h1>

  <p class="text-muted">Update your name, URL, or email address on the KIM list.</p>

  <?php
  $luster_kim_reset_url = 'kim-reset.php';
  $luster_kim_list_url  = 'kim-list.php';
  $luster_return        = true;
  echo require $_embedPath . '/kim-update.php';
  ?>

<?php require 'footer.php'; ?>
