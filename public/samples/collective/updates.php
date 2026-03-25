<?php
/**
 * Sample collective — updates feed.
 * Requires the Updates add-on to be installed.
 */

$_embedPath = dirname(__DIR__, 3) . '/public/embed';

$_siteName    = 'Collective Name';
$_siteTagline = 'A fanlisting collective';
$_ownerName   = 'Your Name';
$_ownerEmail  = 'you@example.com';
$_navActive   = 'home';
$_pageTitle   = 'Updates';

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
