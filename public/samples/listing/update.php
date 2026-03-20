<?php
/**
 * Sample listing — update membership form.
 * See index.php for setup instructions.
 *
 * REDIRECT SETUP
 *   In Admin → Listing → Edit → Embed, set:
 *     Form URL — Update:      https://yourdomain.com/fanlisting/update.php
 *     Redirect after Update:  https://yourdomain.com/fanlisting/update.php?updated=1
 */

const LISTING_ID = 1;   // ← your listing ID

$_embedPath  = dirname(__DIR__, 3) . '/public/embed';
$_lusterRoot = dirname(__DIR__, 3);

// ── Load listing name before HTML output ───────────────────────────────────
defined('LUSTER_BASE') || define('LUSTER_BASE', $_lusterRoot);
require_once $_lusterRoot . '/vendor/autoload.php';

$_sampleApp = require $_lusterRoot . '/bootstrap.php';
$_sampleL  = (new \Luster\Repositories\ListingRepo(
    $_sampleApp->getDatabase(),
    $_sampleApp->getConfig(),
))->getById(LISTING_ID);

$_siteName  = $_sampleL?->name ?? 'Listing Name';
$_navActive = 'update';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($_siteName, ENT_QUOTES, 'UTF-8') ?> &mdash; Update</title>
  <link rel="stylesheet" href="../../assets/default/style.css">
</head>
<body>

<header class="fl-site-header">
  <div class="fl-header-inner">
    <p class="fl-site-title"><a href="index.php"><?= htmlspecialchars($_siteName, ENT_QUOTES, 'UTF-8') ?></a></p>
    <nav class="fl-nav" aria-label="Listing navigation">
      <a href="index.php">Home</a>
      <a href="join.php">Join</a>
      <a href="update.php" class="active">Update</a>
      <a href="remove.php">Remove</a>
      <a href="members.php">Members</a>
      <a href="codes.php">Codes</a>
      <a href="updates.php">Updates</a>
      <a href="affiliates.php">Affiliates</a>
    </nav>
  </div>
</header>

<main class="fl-site-main">
<?php
if (isset($_GET['updated'])) {
    echo '<div class="fl-wrap"><div class="fl-success">Your membership details have been updated.</div></div>';
} else {
    $luster_fanlisting_id = LISTING_ID;
    $luster_return        = true;
    echo require $_embedPath . '/update.php';
}
?>
</main>

<footer class="fl-site-footer">
  <p>Powered by <a href="https://github.com/sjlabels/luster">Luster</a></p>
</footer>

</body>
</html>
