<?php
use Luster\Core\View;
require_once __DIR__ . '/../_css_assets.php';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= in_array($admin_default_theme ?? 'dark', ['dark', 'light'], true) ? View::e($admin_default_theme) : 'dark' ?>"<?= ($selected_skin ?? '') !== '' ? ' data-skin="' . View::e($selected_skin) . '"' : '' ?>>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Luster &#10022; Fanlisting Management System &mdash; Sign in</title>
  <link rel="icon" type="image/svg+xml" href="<?= rtrim(View::e($base_url ?? ''), '/') ?>/assets/images/logo16.svg">
  <?= lusterAdminCssLinks(rtrim(View::e($base_url ?? ''), '/') . '/assets/css', LUSTER_BASE . '/public/assets/css', $selected_skin ?? '') . PHP_EOL ?>
  <script>(function(){var t=localStorage.getItem('lstr_theme');if(t==='dark'||t==='light')document.documentElement.setAttribute('data-theme',t);})();</script>
</head>
<body class="login-page">

<div class="login-dots" aria-hidden="true"></div>

<div class="login-theme-corner">
  <button class="theme-toggle" aria-label="Toggle theme">
    <span class="theme-toggle-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="5"/><path d="M12 1v3M12 20v3M4.22 4.22l2.12 2.12M17.66 17.66l2.12 2.12M1 12h3M20 12h3M4.22 19.78l2.12-2.12M17.66 6.34l2.12-2.12"/></svg></span>
    <div class="toggle-track"><div class="toggle-thumb"></div></div>
    <span class="theme-toggle-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg></span>
  </button>
</div>

<div class="login-wrapper">
  <div class="login-logo-wrap">
    <img src="<?= rtrim(View::e($base_url ?? ''), '/') ?>/assets/images/logo.svg" alt="Luster Logo">
  </div>
  <div class="login-card">

    <div class="login-brand">
      <span class="login-wordmark">Luster</span>
      <span class="login-luster-sub">Admin Panel</span>
    </div>

    <?php if ($error !== '') : ?>
      <div class="alert alert-error" role="alert"><?= View::e($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="" novalidate>
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

      <div class="field-group">
        <label class="field-label" for="username">Username</label>
        <input type="text" id="username" name="username" class="form"
               value="<?= View::e($username) ?>"
               autocomplete="username" autofocus required>
      </div>

      <div class="field-group field-group--compact">
        <label class="field-label" for="password">Password</label>
        <div class="input-icon-wrap">
          <input type="password" id="password" name="password" class="form"
                 autocomplete="current-password" required>
          <button type="button" data-pw-toggle aria-label="Show password" tabindex="-1" class="input-icon-btn">
            <?= View::icon('eye-on') ?>
            <?= View::icon('eye-off') ?>
          </button>
        </div>
      </div>

      <div class="login-between">
        <label class="login-remember">
          <input type="checkbox" name="remember_me" value="1">
          Remember me
        </label>
        <a href="<?= rtrim(View::e($base_url ?? ''), '/') ?>/admin/forgot" class="link">Forgot password?</a>
      </div>

      <button type="submit" class="btn btn-primary btn-block-center">Sign in</button>
    </form>

  </div>
  <p class="login-footer"><a href="https://luster.nu/" target="_blank" title="Luster ✦ Fanlisting Management Script" class="link"><b>Luster</b></a> &copy; <a href="https://dreamesque.net/" target="_blank" title="Star ✦ Dreamesque.Net" class="link">Star</a>, <?= date('Y') === '2026' ? '2026' : '2026&#x2013;' . date('Y') ?></p>
</div>

<?php $jsV = filemtime(LUSTER_BASE . '/public/assets/js/admin.js') ?: 0; ?>
<script src="<?= rtrim(View::e($base_url ?? ''), '/') ?>/assets/js/admin.js?v=<?= $jsV ?>"></script>

</body>
</html>
