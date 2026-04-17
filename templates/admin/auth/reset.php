<?php
use Luster\Core\View;
require_once __DIR__ . '/../_css_assets.php';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Luster &#10022; Fanlisting Management System &mdash; Reset Password</title>
  <?= lusterAdminCssLinks(rtrim(View::e($base_url ?? ''), '/') . '/assets/css/admin', LUSTER_BASE . '/public/assets/css/admin', null, true) . PHP_EOL ?>
  <script>(function(){var t=localStorage.getItem('lstr_theme');if(t==='dark'||t==='light')document.documentElement.setAttribute('data-theme',t);})();</script>
</head>
<body class="login-page">

<div class="login-dots" aria-hidden="true"></div>

<div class="login-theme-corner">
  <button class="theme-toggle" aria-label="Toggle theme">
    <span class="theme-toggle-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="5"/><path d="M12 1v3M12 20v3M4.22 4.22l2.12 2.12M17.66 17.66l2.12 2.12M1 12h3M20 12h3M4.22 19.78l2.12-2.12M17.66 6.34l2.12-2.12"/></svg></span>
    <div class="toggle-track"><div class="toggle-thumb"></div></div>
    <span class="theme-toggle-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg></span>
  </button>
</div>

<div class="login-wrapper">
  <div class="login-card">

    <div class="login-brand">
      <div class="brand-gem-wrap login-gem-wrap">
        <div class="brand-gem"></div>
        <div class="brand-gem-glow"></div>
      </div>
      <div class="brand-text">
        <span class="brand-name login-brand-name"><?= \Luster\Core\View::e($site_name) ?></span>
        <span class="brand-sub login-brand-sub">Set New Password</span>
      </div>
    </div>

    <?php if ($error !== '') : ?>
      <div class="alert alert-error" role="alert"><?= \Luster\Core\View::e($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="" novalidate>
      <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token) ?>">

      <div class="form-group">
        <label class="form-label" for="new_password">New Password <span>*</span></label>
        <div class="input-icon-wrap">
          <input type="password" id="new_password" name="new_password" class="form-input"
                 maxlength="255" required autocomplete="new-password" autofocus>
          <button type="button" data-pw-toggle aria-label="Show password" tabindex="-1" class="input-icon-btn">
            <?= View::icon('eye-on') ?>
            <?= View::icon('eye-off') ?>
          </button>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label" for="new_password_confirm">Confirm New Password <span>*</span></label>
        <div class="input-icon-wrap">
          <input type="password" id="new_password_confirm" name="new_password_confirm" class="form-input"
                 maxlength="255" required autocomplete="new-password">
          <button type="button" class="input-icon-btn" aria-label="Show password" tabindex="-1">
            <?= View::icon('eye-on') ?>
            <?= View::icon('eye-off') ?>
          </button>
        </div>
      </div>

      <button type="submit" class="btn btn-primary btn-block-center">Send Reset Link</button>
    </form>

  <p class="login-footer">
    <a href="<?= rtrim(\Luster\Core\View::e($base_url ?? ''), '/') ?>/admin/login" class="link">&larr; Back to Log In</a>
  </p>
  </div>

  <p class="login-footer"><b>Luster</b> &copy; <a href="https://dreamesque.net/contact/" target="_blank" title="Contact" class="link">Star</a>, <?= date('Y') === '2026' ? '2026' : '2026&#x2013;' . date('Y') ?>.
</div>

<?php $jsV = filemtime(LUSTER_BASE . '/public/assets/js/admin.js') ?: 0; ?>
<script src="<?= rtrim(\Luster\Core\View::e($base_url ?? ''), '/') ?>/assets/js/admin.js?v=<?= $jsV ?>"></script>
</body>
</html>
