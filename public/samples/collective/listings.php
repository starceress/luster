<?php
/**
 * Sample collective — owned listings.
 * See index.php for setup instructions.
 */

$_embedPath = dirname(__DIR__, 3) . '/public/embed';

$_siteName  = 'Collective Name';
$_navActive = 'listings';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($_siteName, ENT_QUOTES, 'UTF-8') ?> &mdash; Listings</title>
  <link rel="stylesheet" href="../../assets/default/style.css">
</head>
<body>

<header class="fl-site-header">
  <div class="fl-header-inner">
    <p class="fl-site-title"><a href="index.php"><?= htmlspecialchars($_siteName, ENT_QUOTES, 'UTF-8') ?></a></p>
    <nav class="fl-nav" aria-label="Collective navigation">
      <a href="index.php">Home</a>
      <a href="listings.php" class="active">Listings</a>
      <a href="joined.php">Joined</a>
      <a href="affiliates.php">Affiliates</a>
    </nav>
  </div>
</header>

<main class="fl-site-main">
<?php
/*
 * Renders owned listings with an optional category filter dropdown.
 *
 * Options (set before require):
 *   $luster_status          = 'current';  // current | upcoming | trouble | all
 *   $luster_category_id     = 0;          // 0 = reads $_GET['cat'] automatically
 *   $luster_hide_dropdown   = false;      // suppress the category selector
 *   $luster_show_list       = false;      // render categories as link list vs dropdown
 *   $luster_exclude_trouble = true;       // hide trouble listings when status=all
 */
$luster_status  = 'current';
$luster_return  = true;
echo require $_embedPath . '/owned.php';
?>
</main>

<footer class="fl-site-footer">
  <p>Powered by <a href="https://github.com/sjlabels/luster">Luster</a></p>
</footer>

</body>
</html>
