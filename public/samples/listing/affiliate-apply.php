<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Sample affiliate application page for a fanlisting. Embeds the Luster affiliate
 application form so other site owners can request to exchange links. Enable
 affiliate applications and set the Form URL in Admin before using this page.
 Customize the HTML to match your design.
*/

$page_title = 'Affiliate Application';
require 'header.php';
?>

  <h1>Affiliate Application</h1>

  <?php
  $luster_fanlisting_id = $listing_id;
  require $luster_root . '/public/embed/affiliate-apply.php';
  ?>

<?php require 'footer.php'; ?>
