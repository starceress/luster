<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Sample home page for a fanlisting collective. Displays a welcome message,
 collective-wide stats, and an optional news feed. Copy this file into your
 collective's web root and customize the HTML to match your own design.
*/

$page_title = 'Home';
require 'header.php';
?>

  <h1>Welcome</h1>

  <p>Welcome to <?= htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8') ?>.
  Edit this text to introduce your collective.</p>

  <?php
  // Collective stats block
  require $luster_root . '/public/embed/collective-stats.php';
  ?>

  <p style="margin-top:1.25rem">
    <a class="btn" href="owned.php">Browse listings</a>
    <a class="back-link" href="joined.php">Joined listings &rarr;</a>
  </p>

  <?php
  // Collective updates feed — only shows if the Updates add-on is installed
  $luster_show_cats = true;
  $luster_return    = true;
  $updates = require $luster_root . '/public/embed/collective-updates.php';
  if ($updates): ?>
    <h2>Updates</h2>
    <?= $updates ?>
  <?php endif; ?>

<?php require 'footer.php'; ?>
