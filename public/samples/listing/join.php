<?php
/**
 * Sample listing — join form.
 *
 * SETUP
 *   In Admin → Listings → Edit → Embed, set:
 *     Form URL — Join:     https://yourdomain.com/fanlisting/join.php
 *     Redirect after Join: https://yourdomain.com/fanlisting/join.php
 */

$page_title = 'Join';
require 'header.php';
?>

  <h1>Join</h1>

  <?php
  $luster_fanlisting_id = 2;
  require $luster_root . '/public/embed/join.php';
  ?>

<?php require 'footer.php'; ?>
