<?php
/**
 * Sample listing — home page.
 *
 * SETUP
 *   1. Edit _config.php — set $luster_root, $listing_id, and your listing details.
 *   2. Copy this folder to your listing's web root.
 */

$page_title = 'Home';
require 'header.php';
?>

  <h1>Welcome</h1>

  <p>Welcome to <b><?= htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8') ?></b>, a fanlisting
  dedicated to <?= htmlspecialchars($site_subject, ENT_QUOTES, 'UTF-8') ?>.</p>

  <p>Edit this text to introduce your listing and its subject.</p>

  <?php
  // Stats block — configure the template in Admin → Listings → Edit → Templates
  $luster_fanlisting_id = $listing_id;
  require $luster_root . '/public/embed/stats.php';
  ?>

  <?php
  // Updates feed — only shows if the Updates add-on is installed
  $luster_fanlisting_id = $listing_id;
  $luster_show_cats     = true;
  $luster_return        = true;
  $updates = require $luster_root . '/public/embed/updates.php';
  if ($updates): ?>
    <h2>Updates</h2>
    <?= $updates ?>
  <?php endif; ?>

<?php require 'footer.php'; ?>
