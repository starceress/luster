<?php
/**
 * Sample listing — adoption application form.
 *
 * SETUP
 *   Enable "Available for adoption" in Admin → Listings → Edit.
 */

$page_title = 'Adopt This Fanlisting';
require 'header.php';
?>

  <h1>Adopt This Fanlisting</h1>

  <p>This fanlisting is available for adoption. If you are interested in taking over
  ownership, please fill in the form below.</p>

  <?php
  $luster_fanlisting_id = $listing_id;
  require $luster_root . '/public/embed/adopt.php';
  ?>

<?php require 'footer.php'; ?>
