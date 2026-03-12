<?php
/**
 * Sample fanlisting — home page.
 *
 * SETUP
 *   1. Set FANLISTING_ID to your fanlisting's numeric ID
 *      (visible in the admin panel URL: /admin/fanlistings/1/edit → ID is 1).
 *   2. Fill in the config constants below.
 *   3. Copy this directory to your fanlisting's web root (any location you like).
 *      Then update LUSTER_EMBED to the absolute server path of Luster's
 *      public/embed/ directory.
 *
 * HOW FORM REDIRECTS WORK
 *   The join/update/remove forms POST to Luster's own routes.
 *   Configure the "Form URL" and "Redirect URL" fields in the fanlisting's
 *   Embed Settings (admin → fanlisting → Edit → Embed) so that Luster sends
 *   visitors back to these sample pages on success or validation error.
 *   Example — Form URL (Join): https://yourdomain.com/fanlisting/join.php
 */

const FANLISTING_ID = 1;                         // ← your fanlisting ID

// Path to Luster's embed/ directory. Adjust if you move this file.
$_embedPath = dirname(__DIR__, 3) . '/public/embed';   // 3 levels up: samples/fanlisting → samples → public → luster root, then /public/embed

// ── Page identity ─────────────────────────────────────────────────────────────
$_siteName  = 'Fanlisting Name';     // ← shown in <title> and the site header
$_flSubject = 'Subject Name';        // ← what the fanlisting is about
$_flUrl     = '#';                   // ← link to the subject (film, artist, etc.)
$_navActive = 'home';

// ── Helper: escape for HTML output ───────────────────────────────────────────
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
    <nav class="fl-nav" aria-label="Fanlisting navigation">
      <a href="index.php"   class="active">Home</a>
      <a href="members.php">Members</a>
      <a href="join.php">Join</a>
      <a href="update.php">Update</a>
      <a href="remove.php">Remove</a>
    </nav>
  </div>
</header>

<main class="fl-site-main">
  <div class="fl-wrap">

    <h2 class="fl-title">Welcome</h2>

    <p>
      Welcome! This is a fanlisting for
      <a href="<?= _e($_flUrl) ?>"><?= _e($_flSubject) ?></a>.
      Edit this text to introduce your fanlisting and its subject.
    </p>

    <?php
    // Stats — configure the stats template in Admin → Fanlisting → Edit → Templates
    $luster_fanlisting_id = FANLISTING_ID;
    $luster_return        = true;
    $statsHtml = require $_embedPath . '/stats.php';
    if ($statsHtml) {
        echo $statsHtml;
    }
    ?>

    <p style="margin-top:1.5rem">
      <a class="fl-btn" href="join.php">Join this fanlisting</a>
      &nbsp;
      <a class="fl-link" href="members.php" style="font-size:.9rem">View all members &rarr;</a>
    </p>

  </div>
</main>

<footer class="fl-site-footer">
  <p>Powered by <a href="https://github.com/sjlabels/luster">Luster</a></p>
</footer>

</body>
</html>
