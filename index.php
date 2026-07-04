<?php
// No declare(strict_types=1) here — this file must parse and run on ANY PHP
// version so the PHP-version check can display before loading modern code.

// PHP version gate — show a friendly error rather than a blank 500.
if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    http_response_code(503);
    ?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PHP 8.1 Required &#10022; Luster</title>
<link rel="icon" type="image/svg+xml" href="public/assets/images/logo16.svg">
<link rel="stylesheet" href="public/assets/css/tokens.css">
<link rel="stylesheet" href="public/assets/css/admin.css">
<link rel="stylesheet" href="public/assets/css/skins.css">
<link rel="stylesheet" href="install/install.css">
<script>(function(){var t=localStorage.getItem('lstr_theme');if(t==='dark'||t==='light')document.documentElement.setAttribute('data-theme',t);})();</script>
</head>
<body class="install-page">

<div class="login-theme-corner">
  <button type="button" class="theme-toggle" aria-label="Toggle theme">
    <span class="theme-toggle-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="5"/><path d="M12 1v3M12 20v3M4.22 4.22l2.12 2.12M17.66 17.66l2.12 2.12M1 12h3M20 12h3M4.22 19.78l2.12-2.12M17.66 6.34l2.12-2.12"/></svg></span>
    <div class="toggle-track"><div class="toggle-thumb"></div></div>
    <span class="theme-toggle-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg></span>
  </button>
</div>

<div class="install-wrap">

  <div class="install-brand">
    <picture>
      <source media="(max-width: 640px)" srcset="public/assets/images/logo50.svg">
      <img src="public/assets/images/logo.svg" alt="" class="install-logo">
    </picture>
    <h1 class="install-wordmark"><span class="install-brand-plain">Welcome to </span><span class="install-brand-gradient">Luster</span></h1>
    <p class="install-luster-sub">Where fandoms can <em>shine</em> &#10022;</p>
  </div>

<div class="main-card">
  <div class="panel-header">
    <div class="panel-header-icon" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>
    <div class="panel-header-meta">
      <h2 class="panel-header-title">PHP Upgrade Needed</h2>
      <div class="panel-header-sub">Luster requires PHP 8.1 or newer to run.</div>
    </div>
  </div>
  <div class="card-body">
    <div class="alert alert-error" style="margin-bottom: 1.25rem;">
      <span class="results-icon results-icon-err" aria-label="Needs attention">&#10005;</span> &nbsp;
      Your server is running <code>PHP <?php echo htmlspecialchars(PHP_VERSION); ?></code>, which is too old to run Luster.
    </div>
    <p class="form-hint" style="font-size:.875rem;margin-bottom:.85rem; color: var(--text-muted);">
      In your hosting control panel, look for a <strong>PHP version selector</strong>
      (often labelled &ldquo;Select PHP Version&rdquo; or &ldquo;MultiPHP Manager&rdquo;)
      and switch this directory to <strong>PHP 8.1</strong> or newer.
    </p>
    <p class="form-hint" style="font-size:.875rem;margin-bottom:0; color: var(--text-muted);">
      If you are unsure how to do this, contact your hosting provider &mdash;
      switching PHP versions is a routine request on any modern shared host.
      This page will automatically show the setup wizard once your server meets the requirement.
    </p>
  </div>
</div>

</div>

<p class="login-footer"><a href="https://luster.nu/" target="_blank" title="Luster" class="link"><b>Luster</b></a> &copy; <a href="https://dreamesque.net/" target="_blank" title="Star" class="link">Star</a>, <?php echo date('Y'); ?></p>

<script src="public/assets/js/admin.js"></script>
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
    header('Location: install/');
}
exit;
