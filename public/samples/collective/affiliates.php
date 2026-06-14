<?php
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
