<?php
/**
 * Sample collective — KIM List overview and per-fanlisting subject view.
 * Requires the KIM List add-on to be installed.
 *
 * This single page handles both modes:
 *   kim-list.php         — overview: all fanlistings with interest counts
 *   kim-list.php?id=N    — subject: approved KIM members for fanlisting N
 */

$page_title = 'Keep In Mind — List';
require 'header.php';
?>

  <h1>KIM List</h1>

  <?php
  // Keep per-fanlisting links on this page instead of routing through Luster.
  $luster_kim_list_base  = $setup['collective_url'] . '/kim-list.php';
  $luster_kim_signup_url = $setup['collective_url'] . '/kim.php';

  // Switch to subject mode when a fanlisting ID is in the query string.
  if (isset($_GET['id']) && (int) $_GET['id'] > 0) {
      $luster_kim_subject_id = (int) $_GET['id'];
  }

  require $luster_root . '/public/embed/kim-list.php';
  ?>

<?php require 'footer.php'; ?>
