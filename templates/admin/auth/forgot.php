<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Forgot Password &mdash; <?= \Luster\Core\View::e($site_name) ?></title>
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
      <span class="brand-sub login-brand-sub">Password Reset</span>
    </div>

    <?php if ($success !== '') : ?>
      <div class="alert alert-success" role="alert"><?= \Luster\Core\View::e($success) ?></div>
    <?php endif; ?>
    <?php if ($error !== '') : ?>
      <div class="alert alert-error" role="alert"><?= \Luster\Core\View::e($error) ?></div>
    <?php endif; ?>

    <?php if ($success === '') : ?>
    <p style="font-size:.875rem;color:var(--text-muted);margin-bottom:1.25rem;line-height:1.5">
      Enter your username or email address and we&rsquo;ll send you a link to reset your password.
    </p>

    <form method="POST" action="" novalidate>
      <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token) ?>">

      <div class="form-group" style="margin-bottom:0">
        <label class="form-label" for="identifier">Username or Email</label>
        <input type="text" id="identifier" name="identifier" class="form-input"
               autocomplete="username email" autofocus required>
      </div>

      <button type="submit" class="btn btn-primary login-submit">Send Reset Link</button>
    </form>
    <?php endif; ?>

  </div>

  <p class="login-footer">
    <a href="<?= rtrim(\Luster\Core\View::e($base_url ?? ''), '/') ?>/admin/login" class="login-forgot">&larr; Back to sign in</a>
  </p>
</div>

<script src="<?= rtrim(\Luster\Core\View::e($base_url ?? ''), '/') ?>/assets/js/admin.js"></script>
</body>
</html>
