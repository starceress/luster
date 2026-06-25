<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Sample join page for a fanlisting. Embeds the Luster join form so new fans can
 sign up to the listing. Set the Form URL and Redirect URL for the join form in
 Admin before using this page. Copy it into your fanlisting's web root and
 customize the surrounding HTML to match your design.
*/

$page_title = 'Join';
require 'header.php';
?>

  <h1>Join</h1>

  <?php
  $luster_fanlisting_id = 2;
  require $luster_root . '/public/embed/join.php';
  ?>

<?php require 'footer.php'; ?>
