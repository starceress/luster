<?php
$page_title = 'Affiliates';
require 'header.php';
?>

  <h1>Affiliates</h1>

  <p>Friends of the collective — click to visit.</p>

  <?php
  $luster_fanlisting_id   = 0;   // 0 = collective/site-wide affiliates
  $luster_affiliates_base = strtok($_SERVER['REQUEST_URI'], '?');
  require $luster_root . '/public/embed/affiliates.php';
  ?>

<?php require 'footer.php'; ?>
