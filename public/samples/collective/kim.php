<?php
/**
 * Sample collective — Keep In Mind (KIM) sign-up.
 * Requires the KIM List add-on to be installed.
 */

$page_title = 'Keep In Mind';
require 'header.php';
?>

  <h1>Keep In Mind</h1>

  <p>Interested in a fanlisting but not yet a member? Add yourself to the waiting
  list and you'll be notified when it opens.</p>

  <?php
  $luster_kim_update_url = 'kim-update.php';
  $luster_kim_reset_url  = 'kim-reset.php';
  $luster_kim_list_url   = 'kim-list.php';
  require $luster_root . '/public/embed/kim-signup.php';
  ?>

<?php require 'footer.php'; ?>
