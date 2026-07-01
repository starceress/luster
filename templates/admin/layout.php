<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Master layout wrapper for all authenticated admin pages. Outputs the full HTML
 document structure including the sidebar navigation, topbar, theme toggle, mobile
 menu, flash messages, and the content area where page-specific templates are injected.
 Filter-panel FOUC prevention and admin CSS/JS assets are loaded here.
*/
use Luster\Core\View;
use Luster\Core\SkinCatalog;
require_once __DIR__ . '/_css_assets.php';
$b = rtrim(View::e($base_url), '/');

$s = match ($current_section ?? '') {
    'blacklist'       => 'settings',
    'sysinfo'         => 'settings',
    'email-templates' => 'emails',
    'import'          => 'import',
    'import-members'  => 'import',
    'adoptions'       => 'adoptions',
    default           => ($current_section ?? ''),
};
if (!function_exists('navActive')) {
    function navActive(string $section, string $current): string {
        return $section === $current ? ' active' : '';
    }
}

$filterBootPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
$filterBootMap = [
    ['~/admin/(?:listings|affiliates|joined|categories)(?:/|$)~', 'filter-panel', 'fp-fl'],
    ['~/admin/members(?:/|$)~', 'mem-filter-panel', 'fp-mem'],
    ['~/admin/link-checker(?:/|$)~', 'lc-filter-panel', 'fp-lc'],
    ['~/admin/wishlist(?:/|$)~', 'wl-filter-panel', 'fp-wl'],
    ['~/admin/kim(?:/|$)~', 'kim-filter-panel', 'fp-kim'],
    ['~/admin/adoptions(?:/|$)~', 'adopt-filter-panel', 'fp-adopt'],
    ['~/admin/updates(?:/|$)~', 'upd-filter-panel', 'fp-upd'],
    ['~/admin/rules(?:/|$)~',   'rules-filter-panel', 'fp-rules'],
];
$filterBootStates = json_decode((string) ($_COOKIE['lstr_fp'] ?? ''), true);
if (!is_array($filterBootStates)) {
    $filterBootStates = json_decode(rawurldecode((string) ($_COOKIE['lstr_fp'] ?? '')), true);
}
if (!is_array($filterBootStates)) {
    $filterBootStates = [];
}
$htmlClasses = [];
foreach ($filterBootMap as [$pattern, $panelId, $className]) {
    if (preg_match($pattern, $filterBootPath) !== 1) {
        continue;
    }
    $stateKey = 'fp:' . $filterBootPath . ':' . $panelId;
    if (($filterBootStates[$stateKey] ?? null) !== '0') {
        $htmlClasses[] = $className;
    }
}
$htmlClassAttr = $htmlClasses === [] ? '' : ' class="' . View::e(implode(' ', $htmlClasses)) . '"';

?>
<!DOCTYPE html>
<html lang="en"<?= $htmlClassAttr ?> data-theme="<?= in_array($admin_default_theme ?? 'dark', ['dark', 'light'], true) ? View::e($admin_default_theme) : 'dark' ?>"<?= ($selected_skin ?? '') !== '' ? ' data-skin="' . View::e($selected_skin) . '"' : '' ?>>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= View::e($page_title) ?> &#10022; Luster</title>
  <script>(function(){var t=localStorage.getItem('lstr_theme');if(t==='dark'||t==='light')document.documentElement.setAttribute('data-theme',t);})();</script>
  <script>
    (function(){
      var h=document.documentElement;
      var p=window.location.pathname;
      var m=[
        [/\/admin\/(?:listings|affiliates|joined|categories)(?:\/|$)/,'filter-panel','fp-fl'],
        [/\/admin\/members(?:\/|$)/,'mem-filter-panel','fp-mem'],
        [/\/admin\/link-checker(?:\/|$)/,'lc-filter-panel','fp-lc'],
        [/\/admin\/wishlist(?:\/|$)/,'wl-filter-panel','fp-wl'],
        [/\/admin\/kim(?:\/|$)/,'kim-filter-panel','fp-kim'],
        [/\/admin\/adoptions(?:\/|$)/,'adopt-filter-panel','fp-adopt'],
        [/\/admin\/updates(?:\/|$)/,'upd-filter-panel','fp-upd'],
        [/\/admin\/rules(?:\/|$)/,'rules-filter-panel','fp-rules']
      ];
      function cookieMap(){var m=document.cookie.match(/(?:^|; )lstr_fp=([^;]*)/);if(!m)return {};try{return JSON.parse(decodeURIComponent(m[1]));}catch(e){return {};}}
      function writeCookie(map){document.cookie='lstr_fp='+encodeURIComponent(JSON.stringify(map))+'; path=/; SameSite=Lax';}
      var cm=cookieMap();
      var dirty=false;
      function state(id){var k='fp:'+p+':'+id;if(Object.prototype.hasOwnProperty.call(cm,k))return cm[k];try{var s=sessionStorage.getItem(k);if(s!==null){cm[k]=s;dirty=true;}return s;}catch(e){return null;}}
      for(var i=0;i<m.length;i++){if(!m[i][0].test(p))continue;var st=state(m[i][1]);h.classList.toggle(m[i][2],st!=='0');}
      if(dirty)writeCookie(cm);
    }());
  </script>
  <style>
    html.fp-fl #filter-panel,
    html.fp-mem #mem-filter-panel,
    html.fp-lc #lc-filter-panel,
    html.fp-wl #wl-filter-panel,
    html.fp-kim #kim-filter-panel,
    html.fp-adopt #adopt-filter-panel,
    html.fp-upd #upd-filter-panel,
    html.fp-rules #rules-filter-panel { display: block; }
  </style>
  <link rel="icon" type="image/svg+xml" href="<?= $b ?>/assets/images/logo16.svg">
  <?= lusterAdminCssLinks($b . '/assets/css', LUSTER_BASE . '/public/assets/css', $selected_skin ?? '') . PHP_EOL ?>
</head>
<body>

<!-- ──── SIDEBAR ────────────────────────────────────────────────────── -->
<aside class="sidebar">
  <div class="sidebar-dots" aria-hidden="true"></div>

  <div class="sidebar-brand">
    <a href="<?= $b ?>/admin" class="luster-glow">
      <img src="<?= $b ?>/assets/images/logo50.svg" alt="" class="luster-logo">
      <div class="luster-text">
        <span class="wordmark">Luster</span>
        <span class="luster-sub">Admin Panel</span>
      </div>
    </a>
  </div>

  <div class="nav-search-wrap">
    <?= View::icon('search', ['class' => 'nav-search-icon', 'aria-hidden' => 'true']) ?>
    <input type="search" id="nav-search-input" class="nav-search-input"
           placeholder="Search&hellip;" autocomplete="off" spellcheck="false" aria-label="Search navigation"
           data-base="<?= $b ?>">
    <button type="button" class="nav-search-clear" id="nav-search-clear" aria-label="Clear search">&times;</button>
  </div>

  <nav class="sidebar-nav">
    <p class="nav-search-no-results" id="nav-search-no-results">No pages found.</p>
    <div id="nav-search-extras"></div>

    <div class="sidebar-section">
      <a href="<?= $b ?>/admin" class="nav-item<?= navActive('dashboard', $s) ?>"><span class="nav-icon"><?= View::icon('home') ?></span> Dashboard</a>
    </div>

    <div class="sidebar-section">
      <div class="sidebar-label">Manage</div>
      <a href="<?= $b ?>/admin/collective" class="nav-item<?= navActive('collective', $s) ?>"><span class="nav-icon"><?= View::icon('collective') ?></span> Collective</a>

      <a href="<?= $b ?>/admin/listings" class="nav-item<?= navActive('listings', $s) ?>"><span class="nav-icon"><?= View::icon('star') ?></span> Owned</a>

      <a href="<?= $b ?>/admin/joined" class="nav-item<?= navActive('joined', $s) ?>"><span class="nav-icon"><?= View::icon('heart') ?></span> Joined</a>

      <a href="<?= $b ?>/admin/members" class="nav-item<?= navActive('members', $s) ?>"><span class="nav-icon"><?= View::icon('members') ?></span> Members
        <?php if (($nav_pending_members ?? 0) > 0): ?>
          <span class="badge badge-pending" style="margin-left: auto;"><?= (int) $nav_pending_members ?></span>
        <?php endif; ?>
      </a>

      <a href="<?= $b ?>/admin/affiliates" class="nav-item<?= navActive('affiliates', $s) ?>"><span class="nav-icon"><?= View::icon('link') ?></span> Affiliates<?php if (($nav_pending_affiliates ?? 0) > 0): ?><span class="badge badge-pending" style="margin-left: auto;"><?= (int) $nav_pending_affiliates ?></span><?php endif; ?></a>

      <a href="<?= $b ?>/admin/codes" class="nav-item<?= navActive('codes', $s) ?>">
        <span class="nav-icon"><?= View::icon('image') ?></span> Codes
        <?php if (($nav_pending_codes ?? 0) > 0): ?>
          <span class="badge badge-pending" style="margin-left: auto;"><?= (int) $nav_pending_codes ?></span>
        <?php endif; ?>
      </a>

      <a href="<?= $b ?>/admin/categories" class="nav-item<?= navActive('categories', $s) ?>"><span class="nav-icon"><?= View::icon('tag') ?></span> Categories</a>

      <a href="<?= $b ?>/admin/emails" class="nav-item<?= navActive('emails', $s) ?>"><span class="nav-icon"><?= View::icon('email') ?></span> Emails</a>
    </div>
      
    <div class="sidebar-section">
      <div class="sidebar-label">Tools</div>
      <?php $_navAddons = $nav_installed_addons ?? [];
      if (in_array('Adoption', $_navAddons, true)): ?>
      <a href="<?= $b ?>/admin/adoptions" class="nav-item<?= navActive('adoptions', $s) ?>"><span class="nav-icon"><?= View::icon('adoptions') ?></span> Adoptions<?php if (($nav_pending_adoptions ?? 0) > 0): ?><span class="badge badge-pending" style="margin-left: auto;"><?= (int) $nav_pending_adoptions ?></span><?php endif; ?></a>
      <?php endif; ?>

      <?php if (in_array('Rules', $_navAddons, true)): ?>
      <a href="<?= $b ?>/admin/rules" class="nav-item<?= navActive('rules', $s) ?>"><span class="nav-icon"><?= View::icon('rules') ?></span> Join Rules</a>
      <?php endif; ?>

      <?php if (in_array('Kim', $_navAddons, true)): ?>
      <a href="<?= $b ?>/admin/kim" class="nav-item<?= navActive('kim', $s) ?>"><span class="nav-icon"><?= View::icon('kim') ?></span> Keep In Mind<?php if (($nav_pending_kim ?? 0) > 0): ?><span class="badge badge-pending" style="margin-left: auto;"><?= (int) $nav_pending_kim ?></span><?php endif; ?></a>
      <?php endif; ?>

      <?php if (in_array('Updates', $_navAddons, true)): ?>
      <a href="<?= $b ?>/admin/updates" class="nav-item<?= navActive('updates', $s) ?>"><span class="nav-icon"><?= View::icon('updates') ?></span> News Feed</a>
      <?php endif; ?>

      <?php if (in_array('Wishlist', $_navAddons, true)): ?>
      <a href="<?= $b ?>/admin/wishlist" class="nav-item<?= navActive('wishlist', $s) ?>"><span class="nav-icon"><?= View::icon('wishlist') ?></span> Wishlist</a>
      <?php endif; ?>

      <a href="<?= $b ?>/admin/embed-codes" class="nav-item<?= navActive('embed-codes', $s) ?>"><span class="nav-icon"><?= View::icon('embed') ?></span> Embed Wizard</a>

      <a href="<?= $b ?>/admin/link-checker" class="nav-item<?= navActive('link-checker', $s) ?>"><span class="nav-icon"><?= View::icon('linkcheck') ?></span> Link Checker<?php if (($nav_lc_dead ?? 0) > 0): ?><span class="badge badge-pending" style="margin-left: auto;"><?= (int) ($nav_lc_dead ?? 0) ?></span><?php endif; ?></a>

      <a href="<?= $b ?>/admin/addons" class="nav-item<?= navActive('addons', $s) ?>"><span class="nav-icon"><?= View::icon('addon') ?></span> Manage Add-Ons</a>
    </div>

    <div class="sidebar-section">
      <div class="sidebar-label">Admin</div>
      
      <a href="<?= $b ?>/admin/settings" class="nav-item<?= navActive('settings', $s) ?>"><span class="nav-icon"><?= View::icon('gear') ?></span> Settings</a>

      <a href="<?= $b ?>/admin/import" class="nav-item<?= navActive('import', $s) ?>"><span class="nav-icon"><?= View::icon('import') ?></span> Import Data</a>

      <?php $logsOpen = in_array($s, ['activitylog', 'errorlog']); ?>
      <div class="nav-group">
        <button class="nav-group-toggle<?= $logsOpen ? ' active' : '' ?>"
                aria-expanded="<?= $logsOpen ? 'true' : 'false' ?>"
                data-group="logs">
          <span class="nav-icon"><?= View::icon('logs') ?></span> Logs
          <span class="nav-icon nav-group-chevron"><?= View::icon('chevrondown') ?></span>
        </button>
        <div class="nav-group-panel<?= $logsOpen ? ' open' : '' ?>" id="nav-group-logs">
          <a href="<?= $b ?>/admin/errorlog" class="nav-item<?= navActive('errorlog', $s) ?>" style="padding-left: 2rem;"><span class="nav-icon"><?= View::icon('caution') ?></span> Error Log</a>

          <a href="<?= $b ?>/admin/activitylog" class="nav-item<?= navActive('activitylog', $s) ?>" style="padding-left: 2rem;"><span class="nav-icon"><?= View::icon('notes') ?></span> Activity Log</a>
        </div>
      </div>

      <a href="<?= $b ?>/admin/feedback" class="nav-item<?= navActive('feedback', $s) ?>"><span class="nav-icon"><?= View::icon('report') ?></span> Send a Report</a>
    </div>

  </nav>

  <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="user-star">&#10022;</div>
      <div class="user-info">
        <span class="user-name"><?= View::e(($nav_display_name ?? '') !== '' ? $nav_display_name : $admin_username) ?></span>
        <span class="user-site"><?= View::e($site_name) ?></span>
      </div>
    </div>
    <form method="POST" action="<?= $b ?>/admin/logout" style="margin-top: 12px;">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
      <button type="submit" class="btn btn-danger btn-sm btn-block-center"><?= View::icon('signout') ?> Sign Out</button>
    </form>
  </div>
</aside>

<!-- ──── SIDEBAR OVERLAY (MOBILE) ───────────────────────────────────── -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<main>
  <div class="topbar">
    <div class="topbar-left">
      <button class="hamburger" id="hamburger" aria-label="Open menu">
        <span></span><span></span><span></span>
      </button>
      <div class="topbar-title-group">
        <span class="topbar-page"><?= View::e($page_title) ?></span>
        <?php if (!empty($page_breadcrumbs)): ?>
          <nav class="topbar-breadcrumbs" aria-label="Breadcrumb">
            <?php $crumbCount = count($page_breadcrumbs); ?>
            <?php foreach ($page_breadcrumbs as $i => $crumb): ?>
              <?php $isLast = $i === $crumbCount - 1; ?>
              <?php if ((!$isLast || $crumbCount === 1) && !empty($crumb['url'])): ?>
                <a href="<?= View::e($crumb['url']) ?>" class="topbar-crumb"><?= View::e($crumb['label']) ?></a>
              <?php else: ?>
                <span class="topbar-crumb<?= $isLast ? ' is-current' : '' ?>"><?= View::e($crumb['label']) ?></span>
              <?php endif; ?>
              <?php if (!$isLast): ?>
                <span class="topbar-crumb-sep" aria-hidden="true">&#10022;</span>
              <?php endif; ?>
            <?php endforeach; ?>
          </nav>
        <?php endif; ?>
      </div>
    </div>
    <div class="topbar-right">
      <?php if (!empty($nav_site_url)): ?>
        <a href="<?= View::e($nav_site_url) ?>" target="_blank" rel="noopener noreferrer"
           class="btn btn-quaternary btn-sm" aria-label="View public site"><?= View::icon('www') ?>
          <span>View <?= View::e($site_name) ?></span>
        </a>
      <?php endif; ?>
      <button class="theme-toggle" aria-label="Toggle theme">
        <span class="theme-toggle-icon"><?= View::icon('sun') ?></span>
        <div class="toggle-track">
          <div class="toggle-thumb"></div>
        </div>
        <span class="theme-toggle-icon"><?= View::icon('moon') ?></span>
      </button>
    </div>
  </div>

  <div class="page-content">
    <?= $content ?>
  </div>
</main>

<!-- ──── GLOBAL DELETE CONFIRMATION MODAL ───────────────────────────── -->
<div id="del-confirm-modal" class="modal-backdrop is-hidden" role="dialog" aria-modal="true" aria-labelledby="del-modal-title">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title" id="del-modal-title">Confirm Deletion</h3>
      <button type="button" class="modal-close" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <p id="del-modal-desc" class="modal-desc"></p>
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

<button type="button" id="scroll-to-top" class="scroll-to-top" aria-label="Scroll to top" title="Scroll to top">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><polyline points="18 15 12 9 6 15"/></svg>
</button>

  <?php $jsV = filemtime(LUSTER_BASE . '/public/assets/js/admin.js') ?: 0; ?>
<script src="<?= $b ?>/assets/js/admin.js?v=<?= $jsV ?>"></script>

</body>
</html>
