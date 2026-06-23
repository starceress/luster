<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Public layout template. This file wraps every fan-facing page rendered via
 View::renderPublic() — join, members, update, reset, codes, affiliates, and
 all other public content fragments. Site owners customise this file for their
 own branding. The inner page content is injected as trusted HTML via $content.
*/

declare(strict_types=1);

use Luster\Core\View;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= View::e($page_title . ' — ' . ($listing->title ?? $listing_title ?? '')) ?></title>
  <link rel="icon" type="image/svg+xml" href="<?= View::e(rtrim($base_url ?? '', '/')) ?>/assets/images/logo16.svg">
  <link rel="stylesheet" href="<?= View::e($theme_path) ?>style.css">
</head>
<body>

<header>
  <h1>
    <?php if (!empty($listing->url ?? '')): ?>
      <a href="<?= View::e($listing->url) ?>"><?= View::e($listing->title ?? $listing_title ?? '') ?></a>
    <?php else: ?>
      <?= View::e($listing->title ?? $listing_title ?? '') ?>
    <?php endif; ?>
  </h1>
</header>

<main>
  <?= $content ?>
</main>

<footer>
  <p class="fl-powered-by">Powered by <a href="https://luster.nu/" rel="noopener noreferrer" target="_blank" title="Luster ✦ Fanlisting Management Script">Luster</a></p>
</footer>

</body>
</html>
