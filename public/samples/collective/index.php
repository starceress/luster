<?php
/**
 * Sample collective — home page with site-wide statistics.
 *
 * SETUP
 *   1. Fill in the config below.
 *   2. Copy this directory to your collective's web root (any location).
 *      Then update $_embedPath to the absolute server path of Luster's
 *      public/embed/ directory.
 */

$_embedPath = dirname(__DIR__, 3) . '/public/embed';   // adjust if you move this file

// ── Site identity ─────────────────────────────────────────────────────────────
$_siteName    = 'Collective Name';    // ← your collective / site name
$_siteTagline = 'A listing collective';  // ← shown below the site name
$_navActive   = 'home';

function _e(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= _e($_siteName) ?> &mdash; Home</title>
  <link rel="stylesheet" href="../../assets/default/style.css">
</head>
<body>

<header class="fl-site-header">
  <div class="fl-header-inner">
    <p class="fl-site-title"><a href="index.php"><?= _e($_siteName) ?></a></p>
    <nav class="fl-nav" aria-label="Collective navigation">
      <a href="index.php"       class="active">Home</a>
      <a href="listings.php">Listings</a>
      <a href="joined.php">Joined</a>
      <a href="affiliates.php">Affiliates</a>
    </nav>
  </div>
</header>

<main class="fl-site-main">
  <div class="fl-wrap">

    <h2 class="fl-title"><?= _e($_siteName) ?></h2>
    <p class="fl-muted" style="margin-top:-.75rem;margin-bottom:1.25rem"><?= _e($_siteTagline) ?></p>

    <p>
      Welcome! Edit this section to introduce your collective.
      Browse the owned listings, see which listings I've joined,
      or check out my affiliates.
    </p>

    <?php
    // Collective stats — $luster_return = true renders an HTML block.
    // You can also use the exposed $luster_* variables directly (see embed/collective-stats.php).
    $luster_cache_ttl = 300;   // cache stats for 5 minutes (0 = disabled)
    $luster_count     = 3;     // number of newest/random listings to fetch
    $luster_return    = true;
    $statsHtml = require $_embedPath . '/collective-stats.php';
    if ($statsHtml) {
        echo $statsHtml;
    }
    ?>

    <p style="margin-top:1.5rem">
      <a class="fl-btn" href="listings.php">Browse listings</a>
      &nbsp;
      <a class="fl-link" href="joined.php" style="font-size:.9rem">Joined listings &rarr;</a>
    </p>

  </div>
</main>

<footer class="fl-site-footer">
  <p>Powered by <a href="https://github.com/sjlabels/luster">Luster</a></p>
</footer>

</body>
</html>
