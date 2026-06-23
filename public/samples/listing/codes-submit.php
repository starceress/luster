<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Sample code donation page for a fanlisting. Embeds the Luster code submission
 form so fans can upload a button or banner for the listing. Submitted codes go
 to a pending queue for admin approval. Customize the HTML to match your design.
*/

$page_title = 'Donate a Code';
require 'header.php';
?>

  <h1>Donate a Code</h1>

  <?php
  $luster_fanlisting_id = $listing_id;
  require $luster_root . '/public/embed/codes-submit.php';
  ?>

<?php require 'footer.php'; ?>
