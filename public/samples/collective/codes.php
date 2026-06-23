<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Sample codes page for a fanlisting collective. Displays fan-made graphics
 (buttons, banners, etc.) for the collective as a whole. Links to the code
 donation page so fans can submit their own designs. Customize the HTML to
 match your collective's design.
*/

$page_title = 'Codes';
require 'header.php';
?>

  <h1>Codes</h1>

  <p>Collective codes made by fans. Right-click to save, then link back to
  <?= htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8') ?>.</p>

  <?php
  $luster_fanlisting_id = 0; // 0 = collective codes (fanlisting_id IS NULL); set to a listing ID for per-listing codes
  require $luster_root . '/public/embed/codes.php';
  ?>

  <p style="margin-top:1.25rem">
    <a class="back-link" href="codes-submit.php">Donate a code &rarr;</a>
  </p>

<?php require 'footer.php'; ?>
