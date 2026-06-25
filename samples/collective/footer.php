<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Sample footer for a fanlisting collective. Closes the page layout opened by
 header.php and displays a copyright line with a Luster credit link. Every
 other sample collective page includes this file at the bottom. Customize the
 HTML to match your own design.
*/
?>
      </div>
    </main>
  </div>

  <footer class="sample-footer">
    <strong><?= $e($site_name) ?></strong> &copy;
    <a href="mailto:<?= $e($owner_email) ?>"><?= $e($owner_name) ?></a>.
    Powered by <a href="https://luster.nu/" rel="noopener noreferrer" title="Luster ✦ Fanlisting Management Script">Luster</a>
  </footer>
</div>

</body>
</html>
