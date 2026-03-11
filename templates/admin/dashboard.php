<?php
/**
 * @var string        $admin_username
 * @var string        $app_name
 * @var int           $total_fanlistings
 * @var int           $fan_pending
 * @var int           $fan_upcoming
 * @var int           $total_joined
 * @var int           $total_joined_inactive
 * @var int           $total_joined_categories
 * @var int           $total_members
 * @var int           $total_pending
 * @var int           $total_affiliates
 * @var int           $total_affiliates_collective
 * @var int           $total_affiliates_per_fl
 * @var int           $total_categories
 * @var string|null   $newest_owned_name
 * @var string|null   $newest_joined_name
 * @var array[]       $recent_members   [{member: Member, fanlisting_name: string}]
 * @var array[]       $recent_activity
 * @var string        $base_url
 */
$b = rtrim(\Luster\Core\View::e($base_url), '/');

if (!function_exists('dashTimeAgo')) {
    function dashTimeAgo(string $dt): string
    {
        $diff = max(0, time() - (int) strtotime($dt));
        if ($diff < 60)    return 'just now';
        if ($diff < 3600)  return floor($diff / 60) . 'm ago';
        if ($diff < 86400) return floor($diff / 3600) . 'h ago';
        return floor($diff / 86400) . 'd ago';
    }

    function dashDotColor(string $action): string
    {
        $a = strtolower($action);
        if (str_contains($a, 'approve') || str_contains($a, 'add') || str_contains($a, 'creat')) return '#34d399';
        if (str_contains($a, 'delete')  || str_contains($a, 'reject') || str_contains($a, 'flush')) return '#f87171';
        if (str_contains($a, 'update')  || str_contains($a, 'save')   || str_contains($a, 'edit'))  return '#fbbf24';
        return '#a78bfa';
    }

}
?>

<!-- ── Welcome block ──────────────────────────────────────────── -->
<div class="welcome-block">
  <div class="welcome-datetime" id="live-datetime"></div>
  <div class="welcome-title">Welcome back, <?= \Luster\Core\View::e($admin_username) ?>! ✨</div>
  <div class="welcome-sub">
    Your collective, <strong><?= \Luster\Core\View::e($app_name) ?></strong>, is shining today &mdash; here&rsquo;s what&rsquo;s happening <span class="welcome-star">✦</span>
  </div>
</div>

<!-- ── 3×2 stat grid ─────────────────────────────────────────── -->
<div class="stats-grid">

  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-emoji">💎</span>
      <span class="stat-label">Owned</span>
      <span class="stat-sparkle">✦</span>
    </div>
    <div class="stat-value"><?= (int) $total_fanlistings ?></div>
    <div class="stat-pills">
      <?php if ($fan_pending > 0): ?>
        <span class="pill pill-warn"><?= (int) $fan_pending ?> Pending</span>
      <?php endif; ?>
      <?php if ($fan_upcoming > 0): ?>
        <span class="pill pill-muted"><?= (int) $fan_upcoming ?> Upcoming</span>
      <?php endif; ?>
      <?php if ($fan_pending === 0 && $fan_upcoming === 0): ?>
        <span class="pill pill-ok">All Current</span>
      <?php endif; ?>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-emoji">🌸</span>
      <span class="stat-label">Joined</span>
      <span class="stat-sparkle">✦</span>
    </div>
    <div class="stat-value"><?= (int) $total_joined ?></div>
    <div class="stat-pills">
      <?php if ($total_joined_categories > 0): ?>
        <span class="pill pill-info"><?= (int) $total_joined_categories ?> Categories</span>
      <?php endif; ?>
      <?php if ($total_joined_inactive > 0): ?>
        <span class="pill pill-muted"><?= (int) $total_joined_inactive ?> Inactive</span>
      <?php endif; ?>
      <?php if ($total_joined === 0): ?>
        <span class="pill pill-muted">None yet</span>
      <?php endif; ?>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-emoji">👥</span>
      <span class="stat-label">Total Members</span>
      <span class="stat-sparkle">✦</span>
    </div>
    <div class="stat-value"><?= number_format((int) $total_members) ?></div>
    <div class="stat-pills">
      <?php if ($total_pending > 0): ?>
        <span class="pill pill-warn"><?= (int) $total_pending ?> Pending</span>
      <?php else: ?>
        <span class="pill pill-ok">All Approved</span>
      <?php endif; ?>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-emoji">⭐</span>
      <span class="stat-label">Affiliates</span>
      <span class="stat-sparkle">✦</span>
    </div>
    <div class="stat-value"><?= (int) $total_affiliates ?></div>
    <div class="stat-pills">
      <?php if ($total_affiliates_collective > 0): ?>
        <span class="pill pill-info"><?= (int) $total_affiliates_collective ?> Collective</span>
      <?php endif; ?>
      <?php if ($total_affiliates_per_fl > 0): ?>
        <span class="pill pill-muted"><?= (int) $total_affiliates_per_fl ?> Per-FL</span>
      <?php endif; ?>
      <?php if ($total_affiliates === 0): ?>
        <span class="pill pill-muted">None yet</span>
      <?php endif; ?>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-emoji">🗂️</span>
      <span class="stat-label">Categories</span>
      <span class="stat-sparkle">✦</span>
    </div>
    <div class="stat-value"><?= (int) $total_categories ?></div>
    <div class="stat-pills">
      <span class="pill pill-ok">All Active</span>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-emoji">⏳</span>
      <span class="stat-label">Pending Approvals</span>
      <span class="stat-sparkle">✦</span>
    </div>
    <div class="stat-value"><?= (int) $total_pending ?></div>
    <div class="stat-pills">
      <?php if ($total_pending > 0): ?>
        <span class="pill pill-warn"><?= (int) $total_pending ?> Members</span>
      <?php else: ?>
        <span class="pill pill-ok">None Pending</span>
      <?php endif; ?>
    </div>
  </div>

</div><!-- /.stats-grid -->

<!-- ── Newest card ───────────────────────────────────────────── -->
<div class="newest-card">
  <div class="newest-row">
    <span class="newest-star">✦</span>
    <span class="newest-label">Newest owned fanlisting:</span>
    <?php if ($newest_owned_name !== null): ?>
      <a href="<?= $b ?>/admin/fanlistings" class="newest-link"><?= \Luster\Core\View::e($newest_owned_name) ?></a>
    <?php else: ?>
      <span style="color:var(--text-dim)">None yet</span>
    <?php endif; ?>
  </div>
  <div class="newest-row">
    <span class="newest-star">✦</span>
    <span class="newest-label">Newest joined fanlisting:</span>
    <?php if ($newest_joined_name !== null): ?>
      <a href="<?= $b ?>/admin/joined" class="newest-link"><?= \Luster\Core\View::e($newest_joined_name) ?></a>
    <?php else: ?>
      <span style="color:var(--text-dim)">None yet</span>
    <?php endif; ?>
  </div>
</div>

<!-- ── Two-column: recent members + recent activity ──────────── -->
<div class="two-col">

  <!-- Recent Members -->
  <div class="panel">
    <div class="panel-header">
      <div class="panel-title">
        <span class="panel-icon">👤</span> Recent Members
      </div>
      <a href="<?= $b ?>/admin/members" class="panel-link">View all &rarr;</a>
    </div>
    <?php if (empty($recent_members)): ?>
      <div style="padding:20px;font-size:.8rem;color:var(--text-muted)">No members yet.</div>
    <?php else: ?>
      <?php foreach ($recent_members as $rm):
        $m       = $rm['member'];
        $flName  = (string) $rm['fanlisting_name'];
      ?>
      <div class="member-row">
        <div class="member-info">
          <div class="member-name"><?= \Luster\Core\View::e($m->name) ?></div>
          <div class="member-fl"><?= \Luster\Core\View::e($flName) ?></div>
        </div>
        <span class="member-status <?= $m->approved ? 'status-approved' : 'status-pending' ?>">
          <?= $m->approved ? 'Approved' : 'Pending' ?>
        </span>
        <div class="member-time"><?= dashTimeAgo((string) $m->added) ?></div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Recent Activity -->
  <div class="panel">
    <div class="panel-header">
      <div class="panel-title">
        <span class="panel-icon">📋</span> Recent Activity
      </div>
      <a href="<?= $b ?>/admin/activity" class="panel-link">View all &rarr;</a>
    </div>
    <?php if (empty($recent_activity)): ?>
      <div style="padding:20px;font-size:.8rem;color:var(--text-muted)">No activity recorded yet.</div>
    <?php else: ?>
      <?php foreach (array_slice($recent_activity, 0, 5) as $entry): ?>
      <div class="activity-row">
        <div class="activity-dot" style="background:<?= dashDotColor((string) ($entry['action'] ?? '')) ?>"></div>
        <div class="activity-text">
          <strong><?= \Luster\Core\View::e((string) ($entry['username'] ?? 'system')) ?></strong>
          <?= \Luster\Core\View::e((string) ($entry['description'] ?? '')) ?>
        </div>
        <div class="activity-time"><?= dashTimeAgo((string) ($entry['created_at'] ?? '')) ?></div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

</div><!-- /.two-col -->

<script>
/* ── Live date/time ── */
(function updateTime() {
  const days   = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
  const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  const now    = new Date();
  const h      = now.getHours();
  const m      = now.getMinutes().toString().padStart(2, '0');
  const ampm   = h >= 12 ? 'PM' : 'AM';
  const h12    = h % 12 || 12;
  const el     = document.getElementById('live-datetime');
  if (el) {
    el.textContent = 'Today is ' + days[now.getDay()] + ', ' + months[now.getMonth()] + ' ' +
      now.getDate() + ', ' + now.getFullYear() + ' · ' + h12 + ':' + m + ' ' + ampm;
  }
  setTimeout(updateTime, 30000);
})();
</script>
