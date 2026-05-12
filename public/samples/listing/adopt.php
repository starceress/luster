<?php
/**
 * Sample listing — adoption application form.
 *
 * SETUP
 *   Enable "Available for adoption" on the listing's edit page in Admin → Listings → Edit.
 *   This page will show an error message if the listing does not have adoption enabled.
 */

const LISTING_ID = 1;           // ← your listing ID
$_lusterRoot = dirname(__DIR__, 3);
$_navActive  = '';
$_pageTitle  = 'Adopt This Fanlisting';
require __DIR__ . '/_setup.php';
require 'header.php';
?>

  <h1>Adopt This Fanlisting</h1>

  <p>
    This fanlisting is available for adoption. If you are interested in taking over
    ownership, please fill in the form below.
  </p>

  <?php
  $luster_fanlisting_id = LISTING_ID;
  $luster_return        = true;
  echo require $_embedPath . '/adopt.php';
  ?>

<?php require 'footer.php'; ?>
