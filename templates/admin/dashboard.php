<?php
/**
 * @var string        $admin_username
 * @var string        $site_name
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
  <div class="welcome-title">Hey there, <?= \Luster\Core\View::e($admin_username) ?>!</div>
  <div class="welcome-sub">
    <?php if ($total_pending > 0 || !empty($stale_listings)): ?>
      <?php
        $parts = [];
        if ($total_pending > 0) {
            $parts[] = (int) $total_pending . ' pending member' . ($total_pending !== 1 ? 's' : '');
        }
        if (!empty($stale_listings)) {
            $n = count($stale_listings);
            $parts[] = $n . ' neglected listing' . ($n !== 1 ? 's' : '');
        }
      ?>
      You have <?= implode(' and ', $parts) ?><?= count($parts) === 1 ? ' that needs' : ' that need' ?> attention.
    <?php else: ?>
      Everything is up to date &mdash; <?= number_format((int) $total_members) ?> member<?= $total_members !== 1 ? 's' : '' ?> across <?= (int) $total_listings ?> listing<?= $total_listings !== 1 ? 's' : '' ?>.
    <?php endif; ?>
  </div>
</div>

<!-- ── Quick actions ───────────────────────────────────────────── -->
<div class="quick-actions">
  <span class="quick-actions-label">Quick Actions</span>
  <a href="<?= $b ?>/admin/listings/create" class="quick-action-btn">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    Add Listing
  </a>
  <a href="<?= $b ?>/admin/joined/create" class="quick-action-btn">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    Add Joined FL
  </a>
</div>

<!-- ── Stat row ────────────────────────────────────────────────── -->
<div class="stats-grid stats-grid-row">

  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z"/></svg></span>
      <span class="stat-label">Owned</span>
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
      <span class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg></span>
      <span class="stat-label">Joined</span>
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
      <span class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
      <span class="stat-label">Total Members</span>
    </div>
    <div class="stat-value"><?= number_format((int) $total_members) ?></div>
    <div class="stat-pills"></div>
  </div>

  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg></span>
      <span class="stat-label">Affiliates</span>
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

<!-- ── Pending members banner ──────────────────────────────────── -->
<?php if ($total_pending > 0): ?>
<div class="pending-banner">
  <div class="pending-banner-left">
    <span class="pending-banner-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="20" height="20"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
    </span>
    <div>
      <span class="pending-banner-title">Action required</span>
      <span class="pending-banner-msg"><?= (int) $total_pending ?> member<?= $total_pending !== 1 ? 's are' : ' is' ?> awaiting approval</span>
    </div>
  </div>
  <a href="<?= $b ?>/admin/members" class="btn btn-primary btn-sm">Review now &rarr;</a>
</div>
<?php endif; ?>

<!-- ── Staleness warning ──────────────────────────────────────── -->
<?php if (!empty($stale_listings)): ?>
<div class="stale-banner">
  <div class="stale-banner-head">
    <div class="pending-banner-left">
      <span class="stale-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="20" height="20"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
      </span>
      <div>
        <span class="pending-banner-title">Maintenance Needed</span>
        <span class="pending-banner-msg">
          <?= count($stale_listings) ?> listing<?= count($stale_listings) !== 1 ? 's have' : ' has' ?>
          not been updated in over <?= (int) $inactive_threshold_days ?> days
        </span>
      </div>
    </div>
    <form method="POST" action="<?= $b ?>/admin/maintenance/touch-all-updated"
          data-confirm="Mark all listings as updated today?">
      <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
      <button type="submit" class="btn btn-warn btn-sm">Update Entire Collective &rarr;</button>
    </form>
  </div>
  <div class="stale-rows">
    <?php foreach ($stale_listings as $staleItem): ?>
    <div class="stale-row">
      <a href="<?= $b ?>/admin/listings/<?= (int) $staleItem['listing']->id ?>/edit"
         class="neb-link"><?= \Luster\Core\View::e($staleItem['listing']->name) ?></a>
      <span class="stale-row-age">
        <?php if ($staleItem['days_ago'] !== null): ?>
          Last updated <?= (int) $staleItem['days_ago'] ?> day<?= $staleItem['days_ago'] !== 1 ? 's' : '' ?> ago
        <?php else: ?>
          Never updated
        <?php endif; ?>
      </span>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- ── Recent Listings (full-width) ──────────────────────────── -->
<div class="panel">
  <div class="panel-header">
    <div class="panel-title">
      <svg class="panel-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z"/></svg> Recent Listings
    </div>
  </div>

  <div style="padding:1rem 1.25rem 0.75rem;display:flex;flex-direction:column;gap:.5rem">
    <div class="newest-row">
      <span class="newest-star">✦</span>
      <span class="newest-label">Newest Joined:</span>
      <?php if ($newest_joined_name !== null): ?>
        <a href="<?= $b ?>/admin/joined" class="newest-link"><?= \Luster\Core\View::e($newest_joined_name) ?></a>
        <?php if (!empty($newest_joined_date)): ?>
          <span class="newest-date"><?= \Luster\Core\View::e(date('j M Y', strtotime($newest_joined_date))) ?></span>
        <?php endif; ?>
      <?php else: ?>
        <span class="text-dim">None yet</span>
      <?php endif; ?>
    </div>
    <div class="newest-row">
      <span class="newest-star">✦</span>
      <span class="newest-label">Newest Owned Listing:</span>
      <?php if ($newest_owned_name !== null): ?>
        <a href="<?= $b ?>/admin/listings" class="newest-link"><?= \Luster\Core\View::e($newest_owned_name) ?></a>
        <?php if (!empty($newest_owned_opened)): ?>
          <span class="newest-date"><?= \Luster\Core\View::e(date('j M Y', strtotime($newest_owned_opened))) ?></span>
        <?php endif; ?>
      <?php else: ?>
        <span class="text-dim">None yet</span>
      <?php endif; ?>
    </div>
  </div>
</div>

