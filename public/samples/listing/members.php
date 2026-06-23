<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Sample members list page for a fanlisting. Embeds the Luster members list with
 sorting and pagination that link back to this page automatically. Customize the
 surrounding HTML to match your fanlisting's design.
*/

$page_title = 'Members';
require 'header.php';
?>

  <h1>Members</h1>

  <?php
  $luster_fanlisting_id = 2;
  require $luster_root . '/public/embed/members.php';
  ?>

<?php require 'footer.php'; ?>
