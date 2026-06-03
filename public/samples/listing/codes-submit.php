<?php
$page_title = 'Donate a Code';
require 'header.php';
?>

  <h1>Donate a Code</h1>

  <?php
  $luster_fanlisting_id = $listing_id;
  require $luster_root . '/public/embed/codes-submit.php';
  ?>

<?php require 'footer.php'; ?>
