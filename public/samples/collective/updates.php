<?php
/**
 * Sample collective — updates feed.
 * Requires the Updates add-on to be installed.
 */

$page_title = 'Updates';
require 'header.php';
?>

  <h1>Updates</h1>

  <?php
  $luster_show_cats = true;
  require $luster_root . '/public/embed/collective-updates.php';
  ?>

  <p style="margin-top:1.25rem">
    <a class="back-link" href="index.php">&larr; Back to home</a>
  </p>

<?php require 'footer.php'; ?>
