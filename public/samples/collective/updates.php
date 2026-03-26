<?php
/**
 * Sample collective — updates feed.
 * Requires the Updates add-on to be installed.
 */

$_lusterRoot  = dirname(__DIR__, 3);
$_siteTagline = 'A fanlisting collective';  // ← your tagline
$_navActive   = 'home';
$_pageTitle   = 'Updates';
require __DIR__ . '/_setup.php';
require 'header.php';
?>

  <h1 class="page-heading">Updates</h1>

  <?php
  /*
   * Renders the site-wide (collective) updates feed.
   *
   * Options (set before require):
   *   $luster_per_page  = 10;   // optional; default from config per_page
   *   $luster_show_cats = true; // optional bool; show category tags per entry
   */
  $luster_show_cats = true;
  $luster_return    = true;
  echo require $_embedPath . '/collective-updates.php';
  ?>

  <p style="margin-top: 1.25rem">
    <a class="back-link" href="index.php">&larr; Back to home</a>
  </p>

<?php require 'footer.php'; ?>
