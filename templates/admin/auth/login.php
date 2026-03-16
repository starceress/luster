<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign in &mdash; <?= \Luster\Core\View::e($site_name) ?></title>
  <link rel="stylesheet" href="<?= rtrim(\Luster\Core\View::e($base_url ?? ''), '/') ?>/assets/css/admin.css">
  <script>(function(){var t=localStorage.getItem('lstr_theme');if(t==='dark'||t==='light')document.documentElement.setAttribute('data-theme',t);})();</script>
</head>
<body class="login-page">

<canvas id="login-constellation"></canvas>

<div class="login-theme-corner">
  <button class="theme-toggle" aria-label="Toggle theme">
    <span class="theme-toggle-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg></span>
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
      <span class="brand-name login-brand-name"><?= \Luster\Core\View::e($site_name) ?></span>
      <span class="brand-sub login-brand-sub">Admin Panel</span>
    </div>

    <?php if ($error !== '') : ?>
      <div class="alert alert-error" role="alert"><?= \Luster\Core\View::e($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="" novalidate>
      <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token) ?>">

      <div class="form-group">
        <label class="form-label" for="username">Username</label>
        <input type="text" id="username" name="username" class="form-input"
               value="<?= \Luster\Core\View::e($username) ?>"
               autocomplete="username" autofocus required>
      </div>

      <div class="form-group" style="margin-bottom:0">
        <label class="form-label" for="password">Password</label>
        <div class="input-icon-wrap">
          <input type="password" id="password" name="password" class="form-input"
                 autocomplete="current-password" required>
          <button type="button" class="input-icon-btn" aria-label="Show password" tabindex="-1">
            <svg class="eye-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            <svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
          </button>
        </div>
      </div>

      <div class="login-between">
        <label class="login-remember">
          <input type="checkbox" name="remember_me" value="1">
          Remember me
        </label>
        <a href="<?= rtrim(\Luster\Core\View::e($base_url ?? ''), '/') ?>/admin/forgot" class="login-forgot">Forgot password?</a>
      </div>

      <button type="submit" class="btn btn-primary login-submit">Sign in</button>
    </form>

  </div>
  <p class="login-footer"><?= \Luster\Core\View::e($site_name) ?> &mdash; Listing Management</p>
</div>

<script src="<?= rtrim(\Luster\Core\View::e($base_url ?? ''), '/') ?>/assets/js/admin.js"></script>

</body>
</html>
