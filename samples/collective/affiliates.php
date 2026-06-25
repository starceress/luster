<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Sample affiliates page for a fanlisting collective. Displays the collective's
 site-wide affiliates and links to the affiliate application form. Customize
 the HTML to match your collective's design.
*/

$page_title = 'Affiliates';
require 'header.php';
?>

  <h1>Affiliates</h1>

  <p>Friends of the collective — click to visit.</p>

  <?php
  $luster_fanlisting_id = 0;   // 0 = collective/site-wide affiliates
  require $luster_root . '/public/embed/affiliates.php';
  ?>

  <p><a href="affiliate-apply.php">Want to affiliate with the collective? Apply here.</a></p>

<?php require 'footer.php'; ?>
