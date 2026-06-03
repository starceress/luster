<?php
$page_title = 'Contact';
require 'header.php';
?>

  <h1>Contact</h1>

  <?php
  $luster_fanlisting_id = $listing_id;
  require $luster_root . '/public/embed/contact.php';
  ?>

<?php require 'footer.php'; ?>
