<?php
$page_title = 'Affiliates';
require 'header.php';
?>

  <h1>Affiliates</h1>

  <?php
  $luster_fanlisting_id   = 1;
  require $luster_root . '/public/embed/affiliates.php';
  ?>

  <p><a href="affiliate-apply.php">Want to affiliate? Apply here.</a></p>

<?php require 'footer.php'; ?>
j