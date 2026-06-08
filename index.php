<?php
// No declare(strict_types=1) here — this file must parse and run on ANY PHP
// version so the PHP-version check can display before loading modern code.

// PHP version gate — show a friendly error rather than a blank 500.
if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    http_response_code(503);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PHP 8.1 Required — Luster</title>
<style>
body { font-family: system-ui, sans-serif; background: #1a1a2e; color: #e2d9f3; margin: 0; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
.box { background: #22223b; border: 1px solid #4a4a6a; border-radius: 12px; padding: 2.5rem; max-width: 480px; width: 90%; }
h1 { margin: 0 0 1rem; font-size: 1.4rem; color: #f0c8ff; }
p { margin: 0 0 0.75rem; line-height: 1.6; color: #c8b8e8; }
code { background: #2e2e4a; padding: 0.15em 0.4em; border-radius: 4px; font-size: 0.9em; }
.badge { display: inline-block; background: #6e3d8c; color: #f0c8ff; border-radius: 6px; padding: 0.2em 0.7em; font-size: 0.85rem; font-weight: 600; margin-bottom: 1.25rem; }
</style>
</head>
<body>
<div class="box">
  <div class="badge">PHP upgrade needed</div>
  <h1>Luster requires PHP 8.1 or newer</h1>
  <p>Your server is running <code>PHP <?php echo htmlspecialchars(PHP_VERSION); ?></code>, which is too old to run Luster.</p>
  <p>In your hosting control panel, look for a <strong>PHP version selector</strong> (often labelled &ldquo;Select PHP Version&rdquo; or &ldquo;MultiPHP Manager&rdquo;) and switch this directory to <strong>PHP 8.1</strong> or <strong>8.3</strong>.</p>
  <p>If you are unsure how to do this, contact your hosting provider — switching PHP versions is a routine request on any modern shared host.</p>
</div>
</body>
</html>
<?php
    exit;
}

// Send the user to the right place based on install state.
$_configPath   = __DIR__ . '/config/app.php';
$_sentinelPath = __DIR__ . '/storage/.installed';

if (is_file($_configPath) && is_file($_sentinelPath)) {
    // Already installed — go to the public site.
    header('Location: public/');
} else {
    // Not installed yet — go to the installer.
    header('Location: public/install/');
}
exit;
