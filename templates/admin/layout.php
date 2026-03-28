<?php
use Luster\Core\View;
$b = rtrim(View::e($base_url), '/');
// Treat 'blacklist' as part of Settings for nav highlighting
$s = match ($current_section ?? '') {
    'blacklist'       => 'settings',
    'sysinfo'         => 'settings',
    'email-templates' => 'emails',
    default           => ($current_section ?? ''),
};
// Section identity attribute - shared by neb-card borders and active nav colour
$_uri = $_SERVER['REQUEST_URI'] ?? '';
$pageSection = '';
if (preg_match('#/admin/(listings|joined|members|affiliates|categories|pending-updates)(?:/|$|\?)#', $_uri, $_m)) {
    $pageSection = $_m[1];
}

if (!function_exists('navActive')) {
    function navActive(string $section, string $current): string {
        return $section === $current ? ' active' : '';
    }
}

?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Luster &#10022; Fanlisting Management System  &mdash; <?= View::e($page_title) ?></title>
  <link rel="stylesheet" href="<?= $b ?>/assets/css/admin.css">
  <script>(function(){var t=localStorage.getItem('lstr_theme');if(t==='dark'||t==='light')document.documentElement.setAttribute('data-theme',t);})();</script>
</head>
<body<?= $pageSection !== '' ? ' data-section="' . $pageSection . '"' : '' ?>>

<!--  SIDEBAR  -->
<aside class="sidebar">
  <div class="sidebar-dots" aria-hidden="true"></div>

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
      <a href="<?= $b ?>/admin" class="nav-item<?= navActive('dashboard', $s) ?>"><span class="nav-icon"><?= View::icon('home') ?></span> Dashboard</a>
    </div>

    <div class="sidebar-section">
      <div class="sidebar-label">Manage</div>
      <a href="<?= $b ?>/admin/listings" class="nav-item<?= navActive('listings', $s) ?>"><span class="nav-icon"><?= View::icon('star') ?></span> Owned</a>

      <a href="<?= $b ?>/admin/joined" class="nav-item<?= navActive('joined', $s) ?>"><span class="nav-icon"><?= View::icon('heart') ?></span> Joined</a>

      <a href="<?= $b ?>/admin/members" class="nav-item<?= navActive('members', $s) ?>"><span class="nav-icon"><?= View::icon('members') ?></span> Members
        <?php if (($nav_pending_members ?? 0) > 0): ?>
          <span class="nav-pending"><?= (int) $nav_pending_members ?></span>
        <?php endif; ?>
      </a>

      <a href="<?= $b ?>/admin/affiliates" class="nav-item<?= navActive('affiliates', $s) ?>"><span class="nav-icon"><?= View::icon('link') ?></span> Affiliates</a>

      <a href="<?= $b ?>/admin/categories" class="nav-item<?= navActive('categories', $s) ?>"><span class="nav-icon"><?= View::icon('tag') ?></span> Categories</a>
      
      <a href="<?= $b ?>/admin/embed-codes" class="nav-item<?= navActive('embed-codes', $s) ?>"><span class="nav-icon"><?= View::icon('embed') ?></span> Embed Codes</a>

      <a href="<?= $b ?>/admin/emails" class="nav-item<?= navActive('emails', $s) ?>"><span class="nav-icon"><?= View::icon('email') ?></span> Emails</a>

      <?php $_navAddons = $nav_installed_addons ?? [];
      if (in_array('Kim', $_navAddons, true)): ?>
      <a href="<?= $b ?>/admin/kim" class="nav-item<?= navActive('kim', $s) ?>"><span class="nav-icon"><?= View::icon('kim') ?></span> Keep In Mind</a>
      <?php endif; ?>

      <?php if (in_array('Updates', $_navAddons, true)): ?>
      <a href="<?= $b ?>/admin/updates" class="nav-item<?= navActive('updates', $s) ?>"><span class="nav-icon"><?= View::icon('updates') ?></span> Updates Feed</a>
      <?php endif; ?>
    </div>

    <div class="sidebar-section">
      <div class="sidebar-label">System</div>
      <a href="<?= $b ?>/admin/addons" class="nav-item<?= navActive('addons', $s) ?>"><span class="nav-icon"><?= View::icon('addon') ?></span> Add-Ons</a>
      
      <a href="<?= $b ?>/admin/settings" class="nav-item<?= navActive('settings', $s) ?>"><span class="nav-icon"><?= View::icon('gear') ?></span> Settings</a>

      <?php $logsOpen = in_array($s, ['activitylog', 'errorlog']); ?>
      <div class="nav-group">
        <button class="nav-group-toggle"
                aria-expanded="<?= $logsOpen ? 'true' : 'false' ?>"
                data-group="logs">
          <span class="nav-icon"><?= View::icon('logs') ?></span> Logs
          <span class="nav-icon nav-group-chevron"><?= View::icon('chevrondown') ?></span>
        </button>
        <div class="nav-group-panel<?= $logsOpen ? ' open' : '' ?>" id="nav-group-logs">
          <a href="<?= $b ?>/admin/errorlog" class="nav-item nav-sub-item<?= navActive('errorlog', $s) ?>"><span class="nav-icon"><?= View::icon('caution') ?></span> Error Log</a>

          <a href="<?= $b ?>/admin/activitylog" class="nav-item nav-sub-item<?= navActive('activitylog', $s) ?>"><span class="nav-icon"><?= View::icon('notes') ?></span> Activity Log</a>
        </div>
      </div>
    </div>

  </nav>

  <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="user-star">&#10022;</div>
      <div class="user-info">
        <span class="user-name"><?= View::e($admin_username) ?></span>
        <span class="user-role"><?= View::e($site_name) ?></span>
      </div>
    </div>
    <form method="POST" action="<?= $b ?>/admin/logout" style="margin-top:12px">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
      <button type="submit" class="btn-signout"><?= View::icon('signout') ?> Sign Out</button>
    </form>
  </div>
</aside>

<!--  SIDEBAR OVERLAY (mobile)  -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<main>
  <div class="topbar">
    <div class="topbar-left">
      <button class="hamburger" id="hamburger" aria-label="Open menu">
        <span></span><span></span><span></span>
      </button>
      <div class="topbar-title-group">
        <span class="topbar-page"><?= View::e($page_title) ?></span>
        <?php if (!empty($page_subtitle)): ?>
          <span class="topbar-sub"><?= View::e($page_subtitle) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="topbar-right">
      <?php if (!empty($nav_site_url)): ?>
        <a href="<?= View::e($nav_site_url) ?>" target="_blank" rel="noopener noreferrer"
           class="topbar-view-site" aria-label="View public site">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <circle cx="12" cy="12" r="10"/>
            <path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
          </svg>
          <span>View Site</span>
        </a>
      <?php endif; ?>
      <button class="theme-toggle" aria-label="Toggle theme">
        <span class="theme-toggle-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="5"/><path d="M12 1v3M12 20v3M4.22 4.22l2.12 2.12M17.66 17.66l2.12 2.12M1 12h3M20 12h3M4.22 19.78l2.12-2.12M17.66 6.34l2.12-2.12"/></svg></span>
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

</main>

<!--  Global delete confirmation modal  -->
<div id="del-confirm-modal" class="modal-backdrop" style="display:none" role="dialog" aria-modal="true" aria-labelledby="del-modal-title">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title" id="del-modal-title">Confirm Deletion</h3>
    </div>
    <div class="modal-body">
      <p id="del-modal-desc" style="margin-bottom:.75rem"></p>
      <div class="modal-warning">
        <strong>Warning:</strong> <span id="del-modal-warning-text">This action cannot be undone.</span>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-danger" id="del-modal-confirm">Delete</button>
      <button type="button" class="btn btn-quaternary" id="del-modal-cancel">Cancel</button>
    </div>
  </div>
</div>

<script src="<?= $b ?>/assets/js/admin.js"></script>

</body>
</html>
