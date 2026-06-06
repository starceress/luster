<?php
$page_title = 'Donate a Code';
require 'header.php';
?>

  <h1>Donate a code</h1>

  <p>Made a button or banner for the collective? Submit it here and it may be added to the codes page.</p>

  <?php
  $luster_fanlisting_id = 0; // 0 = collective code donations; matches codes.php
  require $luster_root . '/public/embed/codes-submit.php';
  ?>

<?php require 'footer.php'; ?>
