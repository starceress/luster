<?php
$page_title = 'Codes';
require 'header.php';
?>

  <h1>Codes</h1>

  <p>Collective codes made by fans. Right-click to save, then link back to
  <?= htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8') ?>.</p>

  <?php
  require('C:/xampp/htdocs/luster/public/embed/codes.php');
  ?>

  <p style="margin-top:1.25rem">
    <a class="back-link" href="codes-submit.php">Donate a code &rarr;</a>
  </p>

<?php require 'footer.php'; ?>
