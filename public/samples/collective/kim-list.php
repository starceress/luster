<?php
/**
 * Sample collective — KIM List overview.
 * Requires the KIM List add-on to be installed.
 */

$page_title = 'Keep In Mind — List';
require 'header.php';
?>

  <h1>KIM List</h1>

  <p>People interested in adopting these fanlistings if they become available.</p>

  <?php
  $luster_kim_signup_url = 'kim.php';
  require $luster_root . '/public/embed/kim-list.php';
  ?>

<?php require 'footer.php'; ?>
