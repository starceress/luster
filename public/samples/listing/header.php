<?php
// Edit this line to match your listing's ID (Admin → Listings).
$listing_id = 1;

// Everything else comes from your Luster installation automatically.
$luster_root = dirname(__DIR__, 3);
$_cfg        = require $luster_root . '/config/app.php';
$owner_name  = $_cfg['owner_name']  ?? '';
$owner_email = $_cfg['owner_email'] ?? '';
unset($_cfg);

// Load the listing's name and subject from the database.
defined('LUSTER_BASE') || define('LUSTER_BASE', $luster_root);
$_lusterApp     = require $luster_root . '/bootstrap.php';
$_lusterListing = (new \Luster\Repositories\ListingRepo(
    $_lusterApp->getDatabase(),
    $_lusterApp->getConfig()
))->getById($listing_id);
$site_name    = (string) ($_lusterListing?->title   ?? 'My Fanlisting');
$site_subject = (string) ($_lusterListing?->subject ?? 'Subject Name');
unset($_lusterApp, $_lusterListing);

$e = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$current_page = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
$nav_items = [
    'index.php'      => 'Home',
    'join.php'       => 'Join',
    'update.php'     => 'Update',
    'members.php'    => 'Members',
    'codes.php'      => 'Codes',
    'affiliates.php' => 'Affiliates',
    'contact.php'    => 'Contact',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $e($site_name) ?><?= isset($page_title) ? ' - ' . $e($page_title) : '' ?></title>
  <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="site-shell">
  <div class="sample-site">
    <header class="sample-masthead">
      <div class="sample-logo-glow" aria-hidden="true"></div>
      <p class="sample-title">
        <a href="index.php"><?= $e($site_name) ?></a>
      </p>
      <p class="sample-tagline">A sample fanlisting dedicated to <?= $e($site_subject) ?>.</p>
    </header>

    <nav class="sample-nav" aria-label="Listing navigation">
      <?php foreach ($nav_items as $href => $label): ?>
        <a href="<?= $e($href) ?>"<?= $current_page === $href ? ' class="active"' : '' ?>><?= $e($label) ?></a>
      <?php endforeach; ?>
    </nav>

    <main class="sample-card">
      <div class="sample-card-inner">
