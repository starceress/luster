<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Sample KIM List page for a fanlisting collective. Handles two modes on a single
 page: the overview (all fanlistings with interest counts) and the per-fanlisting
 subject view (approved KIM members for one listing, via ?id=N). Requires the
 KIM List add-on to be installed. Customize the HTML to match your design.
*/

$page_title = 'Keep In Mind — List';
require 'header.php';
?>

  <h1>KIM List</h1>

  <?php
  // Keep per-fanlisting links on this page instead of routing through Luster.
  $_kimDir = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
      . '://' . $_SERVER['HTTP_HOST']
      . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
  $luster_kim_list_base  = $_kimDir . '/kim-list.php';
  $luster_kim_signup_url = $_kimDir . '/kim.php';

  // Switch to subject mode when a fanlisting ID is in the query string.
  if (isset($_GET['id']) && (int) $_GET['id'] > 0) {
      $luster_kim_subject_id = (int) $_GET['id'];
  }

  require $luster_root . '/public/embed/kim-list.php';
  ?>

<?php require 'footer.php'; ?>
