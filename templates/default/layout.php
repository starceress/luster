<?php

declare(strict_types=1);

/**
 * Luster — Public layout template.
 *
 * Edit this file to customise the look of your listing pages.
 * This file wraps every fan-facing page (join, members, update, remove, etc.).
 *
 * Available variables:
 *   $listing      — Listing model object (->name, ->url, ->subject, ->email)
 *                      May be null on error pages — always use ?? fallbacks.
 *   $listing_name — string, listing name (pre-sanitised)
 *   $listing_url  — string, listing URL
 *   $page_title      — string, page-specific title (e.g. "Join", "Members")
 *   $base_url        — string, Luster base URL (no trailing slash)
 *   $theme_path      — string, web-accessible path to this theme's assets, trailing slash
 *                      e.g. http://localhost/luster/public/assets/css/default/
 *   $content         — string, rendered page content — output with <?= $content ?>
 *                      This is trusted server-rendered HTML; do NOT escape it.
 *
 * CSS classes available (from style.css):
 *   .fl-field, .fl-label, .fl-input, .fl-select, .fl-btn, .fl-btn-secondary,
 *   .fl-errors, .fl-form-actions, .fl-member-list, .fl-member-item,
 *   .fl-pagination-nav, .fl-page-link, .fl-page-current, .fl-empty
 */

use Luster\Core\View;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= View::e($page_title . ' — ' . ($listing->title ?? $listing_name ?? '')) ?></title>
  <link rel="icon" type="image/svg+xml" href="<?= View::e(rtrim($base_url ?? '', '/')) ?>/assets/images/logo16.svg">
  <link rel="stylesheet" href="<?= View::e($theme_path) ?>style.css">
</head>
<body>

<header>
  <h1>
    <?php if (!empty($listing->url ?? '')): ?>
      <a href="<?= View::e($listing->url) ?>"><?= View::e($listing->title ?? $listing_name ?? '') ?></a>
    <?php else: ?>
      <?= View::e($listing->title ?? $listing_name ?? '') ?>
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
