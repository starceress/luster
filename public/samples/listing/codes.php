<?php
$page_title = 'Codes';
require 'header.php';
?>

  <h1>Codes</h1>

  <p>Right-click to save, then link back to this listing.
  <a class="back-link" href="codes-submit.php">Want to donate a code?</a></p>

  <?php
  $luster_fanlisting_id = $listing_id;
  require $luster_root . '/public/embed/codes.php';
  ?>

<?php require 'footer.php'; ?>
