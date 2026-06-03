<?php
// ── The only line you need to change ─────────────────────────────────────────
$site_tagline = 'A fanlisting collective';

// ── Everything else comes from your Luster installation automatically ─────────
$luster_root = dirname(__DIR__, 3);
$_cfg        = require $luster_root . '/config/app.php';
$site_name   = $_cfg['app_name']    ?? 'My Collective';
$owner_name  = $_cfg['owner_name']  ?? '';
$owner_email = $_cfg['owner_email'] ?? '';
unset($_cfg);

$e = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $e($site_name) ?><?= isset($page_title) ? ' &mdash; ' . $e($page_title) : '' ?></title>
  <link rel="stylesheet" href="style.css">
</head>
<body>

<header>
  <p class="title">
    <a href="index.php"><?= $e($site_name) ?></a>
  </p>
  <?php if (!empty($site_tagline)): ?>
    <p class="tagline"><?= $e($site_tagline) ?></p>
  <?php endif; ?>
  <nav aria-label="Collective navigation">
    <a href="index.php">Home</a>
    <a href="owned.php">Owned</a>
    <a href="joined.php">Joined</a>
    <a href="kim.php">Keep In Mind</a>
    <a href="codes.php">Codes</a>
    <a href="affiliates.php">Affiliates</a>
    <a href="contact.php">Contact</a>
  </nav>
  <hr class="divider">
</header>

<main>
