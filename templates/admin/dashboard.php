<?php
/**
 * @var string        $admin_username
 * @var string        $app_name
 * @var int           $total_listings
 * @var int           $fan_current
 * @var int           $fan_pending
 * @var int           $fan_upcoming
 * @var int           $total_joined
 * @var int           $total_joined_active
 * @var int           $total_joined_inactive
 * @var int           $total_joined_categories
 * @var int           $total_members
 * @var int           $total_pending
 * @var int           $total_affiliates
 * @var int           $total_affiliates_collective
 * @var int           $total_affiliates_per_fl
 * @var int           $total_categories
 * @var string|null   $newest_owned_name
 * @var string|null   $newest_owned_opened
 * @var string|null   $newest_joined_name
 * @var string|null   $newest_joined_date
 * @var array[]       $recent_members   [{member: Member, listing_name: string}]
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

<!-- ── Stat row ────────────────────────────────────────────────── -->
<div class="stats-grid stats-grid-row">

  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-emoji">💎</span>
      <span class="stat-label">Owned</span>
      <span class="stat-sparkle">✦</span>
    </div>
    <div class="stat-value"><?= (int) $total_listings ?></div>
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

  <div class="stat-card stat-card-green">
    <div class="stat-header">
      <span class="stat-emoji">👥</span>
      <span class="stat-label">Total Members</span>
      <span class="stat-sparkle">✦</span>
    </div>
    <div class="stat-value">
      <div><?= number_format((int) $total_members) ?> total</div>
      <?php if ($total_pending > 0): ?>
        <a href="<?= $b ?>/admin/members" class="stat-pending-link"><?= (int) $total_pending ?> pending</a>
      <?php else: ?>
        <div class="stat-no-pending">No pending</div>
      <?php endif; ?>
    </div>
    <div class="stat-pills"></div>
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

</div><!-- /.stats-grid -->

<!-- ── Newest card ───────────────────────────────────────────── -->
<div class="newest-card">
  <div class="newest-row">
    <span class="newest-star">✦</span>
    <span class="newest-label">Newest owned listing:</span>
    <?php if ($newest_owned_name !== null): ?>
      <a href="<?= $b ?>/admin/listings" class="newest-link"><?= \Luster\Core\View::e($newest_owned_name) ?></a>
    <?php else: ?>
      <span class="text-dim">None yet</span>
    <?php endif; ?>
  </div>
  <div class="newest-row">
    <span class="newest-star">✦</span>
    <span class="newest-label">Newest joined listing:</span>
    <?php if ($newest_joined_name !== null): ?>
      <a href="<?= $b ?>/admin/joined" class="newest-link"><?= \Luster\Core\View::e($newest_joined_name) ?></a>
    <?php else: ?>
      <span class="text-dim">None yet</span>
    <?php endif; ?>
  </div>
</div>

<!-- ── Staleness warning ──────────────────────────────────────── -->
<?php if (!empty($stale_listings)): ?>
<div class="neb-card" style="margin-bottom:1.5rem;border-left:3px solid var(--warn,#f59e0b)">
  <div style="display:flex;justify-content:space-between;align-items:center;padding:0.85rem 1.25rem;border-bottom:1px solid var(--border)">
    <div>
      <span style="font-weight:700;color:var(--text)">⚠️ Maintenance Needed</span>
      <span style="font-size:0.8125rem;color:var(--text-dim);margin-left:0.5rem">
        <?= count($stale_listings) ?> listing<?= count($stale_listings) !== 1 ? 's have' : ' has' ?>
        not been updated in over <?= (int) $inactive_threshold_days ?> days
      </span>
    </div>
    <form method="POST" action="<?= $b ?>/admin/maintenance/touch-all-updated"
          data-confirm="Mark all listings as updated today?">
      <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
      <button type="submit" class="btn btn-secondary btn-sm">
        Touch All
      </button>
    </form>
  </div>
  <div style="padding:0.5rem 0">
    <?php foreach ($stale_listings as $stalefl): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;padding:0.5rem 1.25rem">
      <a href="<?= $b ?>/admin/listings/<?= (int) $stalefl->id ?>/edit"
         class="neb-link" style="font-size:0.875rem"><?= \Luster\Core\View::e($stalefl->name) ?></a>
      <span style="font-size:0.75rem;color:var(--text-dim)">Never set or overdue</span>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

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
      <div class="panel-empty">No members yet.</div>
    <?php else: ?>
      <?php foreach ($recent_members as $rm):
        $m       = $rm['member'];
        $flName  = (string) $rm['listing_name'];
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

  <!-- Collective Statistics -->
  <div class="panel">
    <div class="panel-header">
      <div class="panel-title">
        <span class="panel-icon">🌐</span> Collective Statistics
      </div>
    </div>

    <div style="padding:1rem 1.25rem 0.75rem;display:flex;flex-direction:column;gap:10px">
      <div class="newest-row">
        <span class="newest-star">✦</span>
        <span class="newest-label">Categories:</span>
        <span><?= (int) $total_categories ?></span>
      </div>
      <div class="newest-row">
        <span class="newest-star">✦</span>
        <span class="newest-label">Joined Listings:</span>
        <span><?= (int) $total_joined_active ?> approved (<?= (int) $total_joined_inactive ?> pending)</span>
      </div>
      <div class="newest-row">
        <span class="newest-star">✦</span>
        <span class="newest-label">Owned Listings:</span>
        <span><?= (int) $fan_current ?> current, <?= (int) $fan_upcoming ?> upcoming, <?= (int) $fan_pending ?> pending</span>
      </div>
      <div class="newest-row">
        <span class="newest-star">✦</span>
        <span class="newest-label">Affiliates:</span>
        <span><?= (int) $total_affiliates ?> affiliates</span>
      </div>
    </div>

    <div style="padding:0.75rem 1.25rem 1rem;border-top:1px solid var(--border);display:flex;flex-direction:column;gap:10px">
      <div class="newest-row">
        <span class="newest-star">✦</span>
        <span class="newest-label">Newest joined:</span>
        <?php if ($newest_joined_name !== null): ?>
          <a href="<?= $b ?>/admin/joined" class="newest-link"><?= \Luster\Core\View::e($newest_joined_name) ?></a>
          <?php if (!empty($newest_joined_date)): ?>
            <span class="text-dim">on <?= \Luster\Core\View::e(date('F j, Y', strtotime($newest_joined_date))) ?></span>
          <?php endif; ?>
        <?php else: ?>
          <span class="text-dim">None yet</span>
        <?php endif; ?>
      </div>
      <div class="newest-row">
        <span class="newest-star">✦</span>
        <span class="newest-label">Newest owned:</span>
        <?php if ($newest_owned_name !== null): ?>
          <a href="<?= $b ?>/admin/listings" class="newest-link"><?= \Luster\Core\View::e($newest_owned_name) ?></a>
          <?php if (!empty($newest_owned_opened)): ?>
            <span class="text-dim">on <?= \Luster\Core\View::e(date('F j, Y', strtotime($newest_owned_opened))) ?></span>
          <?php endif; ?>
        <?php else: ?>
          <span class="text-dim">None yet</span>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div><!-- /.two-col -->

