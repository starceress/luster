<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Forgot Password &mdash; <?= \Luster\Core\View::e($app_name) ?></title>
  <link rel="stylesheet" href="<?= rtrim(\Luster\Core\View::e($base_url ?? ''), '/') ?>/assets/css/admin.css">
  <script>(function(){var t=localStorage.getItem('lstr_theme');if(t==='dark'||t==='light')document.documentElement.setAttribute('data-theme',t);})();</script>
</head>
<body class="login-page">

<canvas id="login-constellation"></canvas>

<div class="login-theme-corner">
  <button class="theme-toggle" aria-label="Toggle theme">
    <span class="theme-toggle-icon">☀️</span>
    <div class="toggle-track"><div class="toggle-thumb"></div></div>
    <span class="theme-toggle-icon">🌙</span>
  </button>
</div>

<div class="login-wrapper">
  <div class="login-card">

    <div class="login-brand">
      <div class="brand-gem-wrap login-gem-wrap">
        <div class="brand-gem"></div>
        <div class="brand-gem-glow"></div>
      </div>
      <span class="brand-name login-brand-name"><?= \Luster\Core\View::e($app_name) ?></span>
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
