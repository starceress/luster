<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login &mdash; <?= \Luster\Core\View::e($app_name) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600&family=DM+Mono&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= rtrim(\Luster\Core\View::e($base_url ?? ''), '/') ?>/assets/css/admin.css">
</head>
<body class="login-page">

<div class="login-wrapper">
  <div class="login-card">

    <div class="login-logo">
      <h1><?= \Luster\Core\View::e($app_name) ?></h1>
      <p>Admin Panel</p>
    </div>

    <?php if ($error !== '') : ?>
      <div class="alert alert-error" role="alert">
        <?= \Luster\Core\View::e($error) ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="" novalidate>
      <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token) ?>">

      <div class="form-group">
        <label class="form-label" for="username">Username</label>
        <input
          type="text"
          id="username"
          name="username"
          class="form-input"
          value="<?= \Luster\Core\View::e($username) ?>"
          autocomplete="username"
          autofocus
          required
        >
      </div>

      <div class="form-group">
        <label class="form-label" for="password">Password</label>
        <input
          type="password"
          id="password"
          name="password"
          class="form-input"
          autocomplete="current-password"
          required
        >
      </div>

      <button type="submit" class="btn btn-primary btn-full">Sign in</button>
    </form>

  </div>

  <p class="login-footer"><?= \Luster\Core\View::e($app_name) ?> &mdash; Listing Management</p>
</div>

</body>
</html>
