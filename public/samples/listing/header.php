<?php
// ── Edit these three lines ────────────────────────────────────────────────────
$listing_id   = 1;               // your listing's ID (Admin → Listings)
$site_name    = 'My Fanlisting'; // your listing's name
$site_subject = 'Subject Name';  // what your fanlisting is about

// ── Everything else comes from your Luster installation automatically ─────────
$luster_root = dirname(__DIR__, 3);
$_cfg        = require $luster_root . '/config/app.php';
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
  <nav aria-label="Listing navigation">
    <a href="index.php">Home</a>
    <a href="join.php">Join</a>
    <a href="update.php">Update</a>
    <a href="remove.php">Remove</a>
    <a href="members.php">Members</a>
    <a href="codes.php">Codes</a>
    <a href="affiliates.php">Affiliates</a>
    <a href="contact.php">Contact</a>
  </nav>
  <hr class="divider">
</header>

<main class="main">
