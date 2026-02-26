<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login &mdash; <?= \Luster\Core\View::e($app_name) ?></title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
      background: #f0f2f5;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #1a1a2e;
    }

    .login-wrapper {
      width: 100%;
      max-width: 400px;
      padding: 1rem;
    }

    .login-card {
      background: #fff;
      border-radius: 10px;
      box-shadow: 0 4px 24px rgba(0,0,0,.10);
      padding: 2.5rem 2rem;
    }

    .login-logo {
      text-align: center;
      margin-bottom: 2rem;
    }

    .login-logo h1 {
      font-size: 1.75rem;
      font-weight: 700;
      color: #4f46e5;
      letter-spacing: -0.5px;
    }

    .login-logo p {
      color: #6b7280;
      font-size: 0.875rem;
      margin-top: 0.25rem;
    }

    .alert-error {
      background: #fef2f2;
      border: 1px solid #fecaca;
      border-radius: 6px;
      color: #b91c1c;
      font-size: 0.875rem;
      padding: 0.75rem 1rem;
      margin-bottom: 1.25rem;
    }

    .form-group {
      margin-bottom: 1.25rem;
    }

    label {
      display: block;
      font-size: 0.875rem;
      font-weight: 500;
      color: #374151;
      margin-bottom: 0.375rem;
    }

    input[type="text"],
    input[type="password"] {
      width: 100%;
      padding: 0.625rem 0.875rem;
      border: 1px solid #d1d5db;
      border-radius: 6px;
      font-size: 0.9375rem;
      color: #111827;
      background: #fff;
      transition: border-color 0.15s, box-shadow 0.15s;
      outline: none;
    }

    input[type="text"]:focus,
    input[type="password"]:focus {
      border-color: #4f46e5;
      box-shadow: 0 0 0 3px rgba(79,70,229,0.15);
    }

    .btn-submit {
      width: 100%;
      padding: 0.7rem 1rem;
      background: #4f46e5;
      color: #fff;
      border: none;
      border-radius: 6px;
      font-size: 0.9375rem;
      font-weight: 600;
      cursor: pointer;
      transition: background 0.15s;
      margin-top: 0.5rem;
    }

    .btn-submit:hover { background: #4338ca; }
    .btn-submit:active { background: #3730a3; }

    .login-footer {
      text-align: center;
      font-size: 0.8125rem;
      color: #9ca3af;
      margin-top: 1.75rem;
    }
  </style>
</head>
<body>

<div class="login-wrapper">
  <div class="login-card">

    <div class="login-logo">
      <h1><?= \Luster\Core\View::e($app_name) ?></h1>
      <p>Admin Panel</p>
    </div>

    <?php if ($error !== '') : ?>
      <div class="alert-error" role="alert">
        <?= \Luster\Core\View::e($error) ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="" novalidate>
      <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token) ?>">

      <div class="form-group">
        <label for="username">Username</label>
        <input
          type="text"
          id="username"
          name="username"
          value="<?= \Luster\Core\View::e($username) ?>"
          autocomplete="username"
          autofocus
          required
        >
      </div>

      <div class="form-group">
        <label for="password">Password</label>
        <input
          type="password"
          id="password"
          name="password"
          autocomplete="current-password"
          required
        >
      </div>

      <button type="submit" class="btn-submit">Sign in</button>
    </form>

  </div>

  <p class="login-footer"><?= \Luster\Core\View::e($app_name) ?> &mdash; Fanlisting Management</p>
</div>

</body>
</html>
