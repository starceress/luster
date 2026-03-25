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
 * @var int           $member_countries
 * @var int           $total_pending
 * @var int           $total_affiliates
 * @var int           $total_affiliates_collective
 * @var int           $total_affiliates_per_fl
 * @var int           $total_categories
 * @var string|null   $newest_owned_name
 * @var string|null   $newest_owned_opened
 * @var string|null   $newest_joined_name
 * @var string|null   $newest_joined_date
 * @var string|null   $most_active_listing_name
 * @var int|null      $most_active_listing_id
 * @var int           $most_active_listing_count
 * @var array         $top_countries        [name => count, ...]
 * @var int           $growth_this_month
 * @var int           $growth_last_month
 * @var int           $opened_this_year
 * @var int           $joined_this_year
 * @var int           $listings_up_to_date
 * @var array[]       $recent_members       [{member: Member, listing_name: string, listing_id: int}]
 * @var array[]       $recent_activity
 * @var array[]       $stale_listings
 * @var int           $inactive_threshold_days
 * @var string        $base_url
 * @var string        $csrf_token
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
}

$newestMember        = $recent_members[0] ?? null;
$growthDiff          = $growth_this_month - $growth_last_month;
?>

<!-- ── Welcome block ──────────────────────────────────────────────── -->
<div class="welcome-block">
  <div class="welcome-text">
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
  <div class="welcome-actions">
    <a href="<?= $b ?>/admin/listings/add" class="btn btn-primary btn-sm">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><path d="M8 3v10M3 8h10"/></svg>
      Add Listing
    </a>
    <a href="<?= $b ?>/admin/joined/add" class="btn btn-tertiary btn-sm">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><path d="M8 3v10M3 8h10"/></svg>
      Add Joined FL
    </a>
  </div>
</div>

<!-- ── Stat cards ──────────────────────────────────────────────────── -->
<div class="stats-grid stats-grid-row">

  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z"/></svg></span>
      <span class="stat-label">Owned</span>
    </div>
    <div class="stat-value"><?= (int) $total_listings ?></div>
    <div class="stat-pills">
      <?php if ($fan_pending > 0): ?>
        <span class="neb-badge badge-pending"><?= (int) $fan_pending ?> Pending</span>
      <?php endif; ?>
      <?php if ($fan_upcoming > 0): ?>
        <span class="neb-badge badge-upcoming"><?= (int) $fan_upcoming ?> Upcoming</span>
      <?php endif; ?>
      <?php if ($fan_pending === 0 && $fan_upcoming === 0): ?>
        <span class="neb-badge badge-current">All Current</span>
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
        <span class="neb-badge badge-info"><?= (int) $total_joined_categories ?> Categories</span>
      <?php endif; ?>
      <?php if ($total_joined_inactive > 0): ?>
        <span class="neb-badge badge-pending"><?= (int) $total_joined_inactive ?> Inactive</span>
      <?php endif; ?>
      <?php if ($total_joined === 0): ?>
        <span class="neb-badge badge-muted">None yet</span>
      <?php endif; ?>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
      <span class="stat-label">Total Members</span>
    </div>
    <div class="stat-value"><?= number_format((int) $total_members) ?></div>
    <div class="stat-pills">
      <?php if ($member_countries > 0): ?>
        <span class="neb-badge badge-muted">Across <?= (int) $member_countries ?> <?= $member_countries === 1 ? 'Country' : 'Countries' ?></span>
      <?php endif; ?>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg></span>
      <span class="stat-label">Affiliates</span>
    </div>
    <div class="stat-value"><?= (int) $total_affiliates ?></div>
    <div class="stat-pills">
      <?php if ($total_affiliates_collective > 0): ?>
        <span class="neb-badge badge-muted"><?= (int) $total_affiliates_collective ?> Collective</span>
      <?php endif; ?>
      <?php if ($total_affiliates_per_fl > 0): ?>
        <span class="neb-badge badge-muted"><?= (int) $total_affiliates_per_fl ?> Per-FL</span>
      <?php endif; ?>
      <?php if ($total_affiliates === 0): ?>
        <span class="neb-badge badge-muted">None yet</span>
      <?php endif; ?>
    </div>
  </div>

</div><!-- /.stats-grid -->

<!-- ── Pending members banner ──────────────────────────────────────── -->
<?php if ($total_pending > 0): ?>
<div class="action-banner action-banner--danger">
  <div class="action-banner-head">
    <div class="action-banner-left">
      <span class="action-banner-icon action-banner-icon--danger">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="18" height="18"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
      </span>
      <div>
        <span class="action-banner-title action-banner-title--danger">Action Required!</span>
        <span class="action-banner-msg"><?= (int) $total_pending ?> member<?= $total_pending !== 1 ? 's are' : ' is' ?> awaiting approval</span>
      </div>
    </div>
    <a href="<?= $b ?>/admin/members" class="btn-ghost-sm">Review Now &rarr;</a>
  </div>
</div>
<?php endif; ?>

<!-- ── Maintenance warning ────────────────────────────────────────── -->
<?php if (!empty($stale_listings)): ?>
<div class="action-banner">
  <div class="action-banner-head">
    <div class="action-banner-left">
      <span class="action-banner-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="18" height="18"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><rect x="11" y="8.5" width="2" height="5.5" rx="1" fill="currentColor" stroke="none"/><circle cx="12" cy="16.5" r="1.1" fill="currentColor" stroke="none"/></svg>
      </span>
      <div>
        <span class="action-banner-title">Maintenance Needed!</span>
        <span class="action-banner-msg">
          <?= count($stale_listings) ?> listing<?= count($stale_listings) !== 1 ? 's have' : ' has' ?>
          not been updated in over <?= (int) $inactive_threshold_days ?> days
        </span>
      </div>
    </div>
    <form method="POST" action="<?= $b ?>/admin/maintenance/touch-all-updated"
          data-confirm="Mark all listings as updated today?">
      <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
      <button type="submit" class="btn-ghost-sm">Update Entire Collective &rarr;</button>
    </form>
  </div>
  <div class="banner-listings">
    <?php foreach ($stale_listings as $staleItem): ?>
    <div class="banner-listing-row">
      <a href="<?= $b ?>/admin/listings/<?= (int) $staleItem['listing']->id ?>/edit"
         class="listing-link"><?= \Luster\Core\View::e($staleItem['listing']->name) ?></a>
      <span class="listing-meta">
        <?php if ($staleItem['days_ago'] !== null): ?>
          (Last updated <?= (int) $staleItem['days_ago'] ?> day<?= $staleItem['days_ago'] !== 1 ? 's' : '' ?> ago)
        <?php else: ?>
          (Never updated)
        <?php endif; ?>
      </span>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- ── Collective at a Glance ──────────────────────────────────────── -->
<div class="neb-card">
  <div class="card-head">
    <div style="display:flex;align-items:center;gap:.5rem">
      <div class="card-head-icon">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="6"/><path d="M8 5v3l2 2"/></svg>
      </div>
      <span class="card-head-title">At a Glance</span>
    </div>
  </div>

  <!-- Recent Listings -->
  <div class="glance-section">
    <div class="glance-section-label">Recent Listings</div>
    <div class="glance-row">
      <span class="glance-label">Newest Joined</span>
      <?php if ($newest_joined_name !== null): ?>
        <a href="<?= $b ?>/admin/joined" class="glance-link"><?= \Luster\Core\View::e($newest_joined_name) ?></a>
      <?php else: ?>
        <span class="glance-val glance-val--dim">None yet</span>
      <?php endif; ?>
    </div>
    <div class="glance-row">
      <span class="glance-label">Newest Owned</span>
      <?php if ($newest_owned_name !== null): ?>
        <span>
          <a href="<?= $b ?>/admin/listings" class="glance-link"><?= \Luster\Core\View::e($newest_owned_name) ?></a>
          <?php if (!empty($newest_owned_opened)): ?>
            <span class="glance-sub">(Opened <?= \Luster\Core\View::e(date($admin_date_format, strtotime((string) $newest_owned_opened))) ?>)</span>
          <?php endif; ?>
        </span>
      <?php else: ?>
        <span class="glance-val glance-val--dim">None yet</span>
      <?php endif; ?>
    </div>
    <?php if ($most_active_listing_name !== null && $most_active_listing_count > 0): ?>
    <div class="glance-row">
      <span class="glance-label">Most Active Listing</span>
      <span>
        <a href="<?= $b ?>/admin/members/<?= (int) $most_active_listing_id ?>" class="glance-link glance-link--pink"><?= \Luster\Core\View::e($most_active_listing_name) ?></a>
        <span class="glance-sub">(<?= number_format($most_active_listing_count) ?> member<?= $most_active_listing_count !== 1 ? 's' : '' ?>)</span>
      </span>
    </div>
    <?php endif; ?>
  </div>

  <!-- Health -->
  <div class="glance-section">
    <div class="glance-section-label">Collective</div>
    <div class="glance-row">
      <span class="glance-label">Total Current Listings</span>
      <span class="glance-val"><?= (int) $fan_current ?></span>
    </div>
    <div class="glance-row">
      <span class="glance-label">Listings Up to Date</span>
      <?php $allUpToDate = $listings_up_to_date >= (int) $total_listings; ?>
      <span class="glance-val <?= $allUpToDate ? 'glance-val--success' : 'glance-val--warn' ?>">
        <?= (int) $listings_up_to_date ?> of <?= (int) $total_listings ?>
      </span>
    </div>
    <?php if ($fan_pending > 0): ?>
    <div class="glance-row">
      <span class="glance-label">Listings Pending Approval</span>
      <span class="glance-val glance-val--danger"><?= (int) $fan_pending ?></span>
    </div>
    <?php endif; ?>
    <?php if ($opened_this_year > 0): ?>
    <div class="glance-row">
      <span class="glance-label">Opened This Year</span>
      <span class="glance-val"><?= (int) $opened_this_year ?></span>
    </div>
    <?php endif; ?>
    <?php if ($joined_this_year > 0): ?>
    <div class="glance-row">
      <span class="glance-label">Joined This Year</span>
      <span class="glance-val"><?= (int) $joined_this_year ?></span>
    </div>
    <?php endif; ?>
  </div>

  <!-- Members -->
  <div class="glance-section">
    <div class="glance-section-label">Members</div>
    <div class="glance-row">
      <span class="glance-label">Total Members</span>
      <span class="glance-val"><?= number_format((int) $total_members) ?></span>
    </div>
    <?php if ($total_pending > 0): ?>
    <div class="glance-row">
      <span class="glance-label">Members Pending Review</span>
      <span class="glance-val glance-val--danger"><?= (int) $total_pending ?></span>
    </div>
    <?php endif; ?>
    <div class="glance-row">
      <span class="glance-label">Growth This Month</span>
      <div style="display:flex;align-items:center;gap:.4rem">
        <span class="glance-val"><?= (int) $growth_this_month ?></span>
        <?php if ($growthDiff > 0): ?>
        <span class="growth-pill growth-pill--up">
          <svg viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M2 7l3-4 3 4"/></svg>
          +<?= (int) $growthDiff ?> vs last month
        </span>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($newestMember !== null): ?>
    <div class="glance-row">
      <span class="glance-label">Newest Member</span>
      <span>
        <a href="<?= $b ?>/admin/members/<?= (int) $newestMember['listing_id'] ?>?search=<?= urlencode($newestMember['member']->email) ?>"
           class="glance-member-name"><?= \Luster\Core\View::e($newestMember['member']->name) ?></a>
        <span class="glance-member-fl">(<?= \Luster\Core\View::e($newestMember['listing_name']) ?>)</span>
        <?php if (!empty($newestMember['member']->added)): ?>
          <span class="glance-member-date">&mdash; <?= dashTimeAgo((string) $newestMember['member']->added) ?></span>
        <?php endif; ?>
      </span>
    </div>
    <?php endif; ?>
  </div>

  <!-- Top Countries -->
  <?php if (!empty($top_countries)): ?>
  <div class="glance-section">
    <div class="glance-section-label">Top <?= count($top_countries) ?> Countries</div>
    <?php $rank = 1; foreach ($top_countries as $country => $count): ?>
    <div class="country-row">
      <span class="country-rank"><?= $rank++ ?>.</span>
      <span class="country-name"><?= \Luster\Core\View::e($country) ?></span>
      <span class="country-count"><?= (int) $count ?><?php if ($total_members > 0): ?> <span style="opacity:.65">(<?= round($count / $total_members * 100) ?>%)</span><?php endif; ?></span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div><!-- /.neb-card -->
