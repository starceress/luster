<?php
/**
 * Sample collective — KIM List update entry.
 * Requires the KIM List add-on to be installed.
 */

$page_title = 'Keep In Mind — Update';
require 'header.php';
?>

  <h1>Update Your Entry</h1>

  <p>Update your name, URL, or email address on the KIM list.</p>

  <?php
  $luster_kim_reset_url = 'kim-reset.php';
  $luster_kim_list_url  = 'kim-list.php';
  require $luster_root . '/public/embed/kim-update.php';
  ?>

<?php require 'footer.php'; ?>
