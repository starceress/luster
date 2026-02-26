<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= \Luster\Core\View::e($page_title) ?> &mdash; <?= \Luster\Core\View::e($app_name) ?></title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --sidebar-width: 220px;
      --header-height: 56px;
      --accent:  #4f46e5;
      --accent2: #4338ca;
      --bg:      #f0f2f5;
      --sidebar-bg: #1e1b4b;
      --sidebar-fg: #c7d2fe;
      --sidebar-hover: #312e81;
      --sidebar-active: #4f46e5;
    }

    body {
      font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
      background: var(--bg);
      color: #1a1a2e;
      min-height: 100vh;
      display: flex;
    }

    /* ------------------------------------------------------------------ */
    /* Sidebar                                                              */
    /* ------------------------------------------------------------------ */
    .sidebar {
      width: var(--sidebar-width);
      flex-shrink: 0;
      background: var(--sidebar-bg);
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      position: fixed;
      top: 0;
      left: 0;
      bottom: 0;
      overflow-y: auto;
    }

    .sidebar-brand {
      padding: 1.25rem 1.25rem 1rem;
      border-bottom: 1px solid rgba(255,255,255,0.08);
    }

    .sidebar-brand a {
      font-size: 1.125rem;
      font-weight: 700;
      color: #fff;
      text-decoration: none;
      letter-spacing: -0.25px;
    }

    .sidebar-brand span {
      display: block;
      font-size: 0.7rem;
      font-weight: 400;
      color: var(--sidebar-fg);
      opacity: 0.7;
      margin-top: 2px;
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }

    .sidebar-nav {
      flex: 1;
      padding: 1rem 0;
    }

    .sidebar-section {
      padding: 0.375rem 1rem 0.125rem;
      font-size: 0.65rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: var(--sidebar-fg);
      opacity: 0.5;
      margin-top: 0.75rem;
    }

    .sidebar-nav a {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.55rem 1.25rem;
      color: var(--sidebar-fg);
      text-decoration: none;
      font-size: 0.875rem;
      border-left: 3px solid transparent;
      transition: background 0.12s, color 0.12s;
    }

    .sidebar-nav a:hover {
      background: var(--sidebar-hover);
      color: #fff;
    }

    .sidebar-nav a.active {
      background: rgba(79,70,229,0.25);
      border-left-color: var(--accent);
      color: #fff;
    }

    .sidebar-footer {
      padding: 1rem 1.25rem;
      border-top: 1px solid rgba(255,255,255,0.08);
      font-size: 0.8125rem;
      color: var(--sidebar-fg);
      opacity: 0.8;
    }

    .sidebar-footer strong {
      display: block;
      color: #fff;
      margin-bottom: 0.5rem;
      font-size: 0.875rem;
    }

    .btn-logout {
      display: inline-block;
      background: rgba(239,68,68,0.15);
      color: #fca5a5;
      border: 1px solid rgba(239,68,68,0.3);
      border-radius: 5px;
      padding: 0.35rem 0.75rem;
      font-size: 0.8125rem;
      cursor: pointer;
      transition: background 0.12s;
      width: 100%;
      margin-top: 0.375rem;
    }

    .btn-logout:hover {
      background: rgba(239,68,68,0.3);
      color: #fff;
    }

    /* ------------------------------------------------------------------ */
    /* Main content area                                                    */
    /* ------------------------------------------------------------------ */
    .main-wrap {
      margin-left: var(--sidebar-width);
      flex: 1;
      display: flex;
      flex-direction: column;
      min-height: 100vh;
    }

    .topbar {
      height: var(--header-height);
      background: #fff;
      border-bottom: 1px solid #e5e7eb;
      display: flex;
      align-items: center;
      padding: 0 1.75rem;
      position: sticky;
      top: 0;
      z-index: 10;
      box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    }

    .topbar-title {
      font-size: 1rem;
      font-weight: 600;
      color: #111827;
    }

    .main-content {
      padding: 1.75rem;
      flex: 1;
    }
  </style>
</head>
<body>

<!-- -------------------------------------------------------------------- -->
<!-- Sidebar                                                                -->
<!-- -------------------------------------------------------------------- -->
<aside class="sidebar">
  <div class="sidebar-brand">
    <a href="<?= \Luster\Core\View::e($app_name) ?>"><?= \Luster\Core\View::e($app_name) ?></a>
    <span>Fanlisting Admin</span>
  </div>

  <nav class="sidebar-nav">
    <div class="sidebar-section">Main</div>

    <a href="./admin" <?= ($page_title === 'Dashboard' ? 'class="active"' : '') ?>>
      &#9632; Dashboard
    </a>

    <div class="sidebar-section">Manage</div>

    <a href="./admin/fanlistings">&#9632; Fanlistings</a>
    <a href="./admin/members">&#9632; Members</a>
    <a href="./admin/affiliates">&#9632; Affiliates</a>

    <div class="sidebar-section">System</div>

    <a href="./admin/settings">&#9632; Settings</a>
  </nav>

  <div class="sidebar-footer">
    <strong><?= \Luster\Core\View::e($admin_username) ?></strong>

    <form method="POST" action="./admin/logout">
      <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token) ?>">
      <button type="submit" class="btn-logout">Sign out</button>
    </form>
  </div>
</aside>

<!-- -------------------------------------------------------------------- -->
<!-- Main content                                                           -->
<!-- -------------------------------------------------------------------- -->
<div class="main-wrap">
  <header class="topbar">
    <h1 class="topbar-title"><?= \Luster\Core\View::e($page_title) ?></h1>
  </header>

  <main class="main-content">
    <?= $content ?>
  </main>
</div>

</body>
</html>
