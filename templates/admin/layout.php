<?php
$b = rtrim(\Luster\Core\View::e($base_url), '/');
// Treat 'blacklist' as part of Settings for nav highlighting
$s = match ($current_section ?? '') {
    'blacklist'       => 'settings',
    'email-templates' => 'mass-email',
    default           => ($current_section ?? ''),
};

if (!function_exists('navActive')) {
    function navActive(string $section, string $current): string {
        return $section === $current ? ' active' : '';
    }
}

$avatarChar = strtoupper(substr((string) ($admin_username ?? 'A'), 0, 1));
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= \Luster\Core\View::e($page_title) ?> &mdash; <?= \Luster\Core\View::e($site_name) ?></title>
  <link rel="stylesheet" href="<?= $b ?>/assets/css/admin.css">
  <script>(function(){var t=localStorage.getItem('lstr_theme');if(t==='dark'||t==='light')document.documentElement.setAttribute('data-theme',t);})();</script>
</head>
<body>

<!-- ═══════════ SIDEBAR ═══════════ -->
<aside class="sidebar">
  <canvas id="constellation"></canvas>

  <div class="sidebar-brand">
    <div class="brand-lockup">
      <div class="brand-gem-wrap">
        <div class="brand-gem"></div>
        <div class="brand-gem-glow"></div>
      </div>
      <div class="brand-text">
        <span class="brand-name">Luster</span>
        <span class="brand-sub">Admin Panel</span>
      </div>
    </div>
  </div>

  <nav class="sidebar-nav">

    <div class="sidebar-section">
      <div class="sidebar-label">Main</div>
      <a href="<?= $b ?>/admin" class="nav-item<?= navActive('dashboard', $s) ?>">
        <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>
        </svg>
        Dashboard
      </a>
    </div>

    <div class="sidebar-section">
      <div class="sidebar-label">Manage</div>
      <a href="<?= $b ?>/admin/listings" class="nav-item<?= navActive('listings', $s) ?>">
        <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z"/>
        </svg>
        Owned
      </a>
      <a href="<?= $b ?>/admin/joined" class="nav-item<?= navActive('joined', $s) ?>">
        <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/>
        </svg>
        Joined
      </a>
      <a href="<?= $b ?>/admin/members" class="nav-item<?= navActive('members', $s) ?>">
        <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
          <path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
        </svg>
        Members
      </a>

      <a href="<?= $b ?>/admin/affiliates" class="nav-item<?= navActive('affiliates', $s) ?>">
        <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
          <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
        </svg>
        Affiliates
      </a>
      <a href="<?= $b ?>/admin/categories" class="nav-item<?= navActive('categories', $s) ?>">
        <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/>
          <line x1="7" y1="7" x2="7.01" y2="7"/>
        </svg>
        Categories
      </a>
      <a href="<?= $b ?>/admin/embed-codes" class="nav-item<?= navActive('embed-codes', $s) ?>">
        <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>
        </svg>
        Embed Codes
      </a>
      <a href="<?= $b ?>/admin/mass-email" class="nav-item<?= navActive('mass-email', $s) ?>">
        <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
          <polyline points="22,6 12,13 2,6"/>
        </svg>
        Mass Email
      </a>
    </div>

    <div class="sidebar-section">
      <div class="sidebar-label">System</div>
      <a href="<?= $b ?>/admin/addons" class="nav-item<?= navActive('addons', $s) ?>">
        <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
        </svg>
        Add-ons
      </a>
      <a href="<?= $b ?>/admin/settings" class="nav-item<?= navActive('settings', $s) ?>">
        <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 4.93a10 10 0 0 0 0 14.14"/>
        </svg>
        Settings
      </a>
      <a href="<?= $b ?>/admin/activity" class="nav-item<?= navActive('activity', $s) ?>">
        <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
          <polyline points="14 2 14 8 20 8"/>
          <line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>
        </svg>
        Activity Log
      </a>
      <a href="<?= $b ?>/admin/errorlog" class="nav-item<?= navActive('errorlog', $s) ?>">
        <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <circle cx="12" cy="12" r="10"/>
          <line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        Error Log
      </a>
      <a href="<?= $b ?>/admin/sysinfo" class="nav-item<?= navActive('sysinfo', $s) ?>">
        <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/>
          <line x1="12" y1="8" x2="12.01" y2="8"/>
        </svg>
        System Info
      </a>
    </div>

  </nav>

  <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="user-avatar"><?= \Luster\Core\View::e($avatarChar) ?></div>
      <div class="user-info">
        <span class="user-name"><?= \Luster\Core\View::e($admin_username) ?></span>
        <span class="user-role">SUPERADMIN</span>
      </div>
    </div>
    <form method="POST" action="<?= $b ?>/admin/logout" style="margin-top:12px">
      <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token) ?>">
      <button type="submit" class="btn-signout">&#8592; Sign out</button>
    </form>
  </div>

</aside>

<!-- ═══════════ SIDEBAR OVERLAY (mobile) ═══════════ -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ═══════════ MAIN ═══════════ -->
<div class="main-wrap">

  <div class="topbar">
    <div class="topbar-left">
      <button class="hamburger" id="hamburger" aria-label="Open menu">
        <span></span><span></span><span></span>
      </button>
      <span class="topbar-page"><?= \Luster\Core\View::e($page_title) ?></span>
    </div>
    <div class="topbar-right">
      <button class="theme-toggle" aria-label="Toggle theme">
        <span class="theme-toggle-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg></span>
        <div class="toggle-track">
          <div class="toggle-thumb"></div>
        </div>
        <span class="theme-toggle-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg></span>
      </button>
    </div>
  </div>

  <div class="page-content">
    <?= $content ?>
  </div>

</div>

<script src="<?= $b ?>/assets/js/admin.js"></script>

</body>
</html>
