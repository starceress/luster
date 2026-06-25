<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Sample home page for a fanlisting site. This is the landing page visitors see
 when they arrive at the fanlisting. It displays a welcome message, the listing's
 live stats block, and an optional news feed. Copy this file into your fanlisting's
 web root and customize the HTML to match your own design.
*/

$page_title = 'Fanlisting Demo ✦ Luster';

$luster_return = true;
require 'header.php';
?>

  <h1>Welcome</h1>

<p>Welcome to <b><?= $e($site_name) ?></b>, a fanlisting
dedicated to <?= $e($site_subject) ?>.</p>

  <?php
  // Stats block — configure the template in Admin → Listings → Edit → Templates
  $luster_fanlisting_id = 12;
  require $luster_root . '/public/embed/stats.php';
  ?>

  <?php
  // Updates feed — only shows if the Updates add-on is installed
  $luster_fanlisting_id = 12;
  $luster_show_cats     = true;
  $luster_return        = true;
  $updates = require $luster_root . '/public/embed/updates.php';
  if ($updates): ?>
    <h2>Updates</h2>
    <?= $updates ?>
  <?php endif; ?>

<?php require 'footer.php'; ?>
