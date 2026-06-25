<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Sample affiliate application page for a fanlisting collective. Embeds the
 Luster collective affiliate application form so other site owners can request
 to exchange links with the collective. Enable collective affiliate applications
 in Admin before using this page. Customize the HTML to match your design.
*/

$page_title = 'Affiliate Application';
require 'header.php';
?>

  <h1>Affiliate Application</h1>

  <?php
  $luster_fanlisting_id = 0;   // 0 = collective affiliate application
  require $luster_root . '/public/embed/affiliate-apply.php';
  ?>

<?php require 'footer.php'; ?>
