<?php
/**
 * Sample collective — adoption application form.
 *
 * SETUP
 *   Enable "Available for adoption" on one or more listings in Admin → Listings → Edit.
 */

$page_title = 'Adopt a Fanlisting';
require 'header.php';
?>

  <h1>Adopt a Fanlisting</h1>

  <p>Interested in taking over one of our fanlistings? Choose one from the list below
  and fill in the form.</p>

  <?php require $luster_root . '/public/embed/adopt.php'; ?>

<?php require 'footer.php'; ?>
