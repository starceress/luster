<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Sample adoption application page for a fanlisting collective. Lets visitors
 apply to take over a fanlisting that the owner has marked as available for
 adoption. Requires the Adoption add-on to be installed and at least one listing
 flagged for adoption. Customize the HTML to match your design.
*/

$page_title = 'Adopt a Fanlisting';
require 'header.php';
?>

  <h1>Adopt a Fanlisting</h1>

  <p>Interested in taking over one of our fanlistings? Choose one from the list below
  and fill in the form.</p>

  <?php require $luster_root . '/public/embed/adopt.php'; ?>

<?php require 'footer.php'; ?>
