<?php
use Luster\Core\View;
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
$b = rtrim(View::e($base_url), '/');

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
<div class="gradient-block">
  <div>
    <div class="gradient-sub" id="live-datetime"></div>
    <div class="gradient-title">Hey there, <?= View::e($admin_username) ?>!</div>
    <div class="gradient-sub">
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
  <div class="gradient-actions">
    <a href="<?= $b ?>/admin/listings/add" class="btn btn-primary btn-sm">
      <?= View::icon('add') ?> Add Owned
    </a>
    <a href="<?= $b ?>/admin/joined/add" class="btn btn-secondary btn-sm">
     <?= View::icon('add') ?> Add Joined
    </a>
  </div>
</div>

<!-- ── Stat cards ──────────────────────────────────────────────────── -->
<div class="stats-grid stats-grid-row">

  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-icon"><?= View::icon('star') ?></span>
      <span class="stat-label">Total Owned</span>
    </div>
    <div class="stat-value"><?= (int) $total_listings ?></div>
    <div class="stat-pills">
      <?php if ($fan_pending > 0): ?>
        <span class="badge badge-pending"><?= (int) $fan_pending ?> Pending</span>
      <?php endif; ?>
      <?php if ($fan_upcoming > 0): ?>
        <span class="badge badge-upcoming"><?= (int) $fan_upcoming ?> Upcoming</span>
      <?php endif; ?>
      <?php if ($fan_pending === 0 && $fan_upcoming === 0): ?>
        <span class="badge badge-current">All Current</span>
      <?php endif; ?>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-icon"><?= View::icon('heart') ?></span>
      <span class="stat-label">Total Joined</span>
    </div>
    <div class="stat-value"><?= (int) $total_joined ?></div>
    <div class="stat-pills">
      <?php if ($total_joined_categories > 0): ?>
        <span class="badge badge-info"><?= (int) $total_joined_categories ?> Categories</span>
      <?php endif; ?>
      <?php if ($total_joined_inactive > 0): ?>
        <span class="badge badge-pending"><?= (int) $total_joined_inactive ?> Inactive</span>
      <?php endif; ?>
      <?php if ($total_joined === 0): ?>
        <span class="badge badge-muted">None yet</span>
      <?php endif; ?>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-icon"><?= View::icon('members') ?></span>
      <span class="stat-label">Total Members</span>
    </div>
    <div class="stat-value"><?= number_format((int) $total_members) ?></div>
    <div class="stat-pills">
      <?php if ($member_countries > 0): ?>
        <span class="badge badge-muted">Across <?= (int) $member_countries ?> <?= $member_countries === 1 ? 'Country' : 'Countries' ?></span>
      <?php endif; ?>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-icon"><?= View::icon('link') ?></span>
      <span class="stat-label">Total Affiliates</span>
    </div>
    <div class="stat-value"><?= (int) $total_affiliates ?></div>
    <div class="stat-pills">
      <?php if ($total_affiliates_collective > 0): ?>
        <span class="badge badge-muted"><?= (int) $total_affiliates_collective ?> Collective</span>
      <?php endif; ?>
      <?php if ($total_affiliates_per_fl > 0): ?>
        <span class="badge badge-muted"><?= (int) $total_affiliates_per_fl ?> Per-FL</span>
      <?php endif; ?>
      <?php if ($total_affiliates === 0): ?>
        <span class="badge badge-muted">None yet</span>
      <?php endif; ?>
    </div>
  </div>

</div><!-- /.stats-grid -->

<!-- ── Pending members banner ──────────────────────────────────────── -->
<?php if ($total_pending > 0): ?>
<div class="action-banner">
  <div class="action-banner-head">
    <div class="action-banner-left">
      <span class="action-banner-icon"><?= View::icon('bell') ?></span>
      <div>
        <span class="action-banner-title">Action Required!</span>
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
      <span class="action-banner-icon"><?= View::icon('caution') ?></span>
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
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
      <button type="submit" class="btn-ghost-sm">Update Entire Collective &rarr;</button>
    </form>
  </div>
  <div class="banner-listings">
    <?php foreach ($stale_listings as $staleItem): ?>
    <div class="banner-listing-row">
      <a href="<?= $b ?>/admin/listings/<?= (int) $staleItem['listing']->id ?>/edit"
         class="link"><?= View::e($staleItem['listing']->name) ?></a>
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
<div class="card">
  <div class="card-head">
      <div class="card-head-icon"><?= View::icon('sparkles') ?></div>
      <span class="card-head-title">At a Glance</span>
  </div>

  <!-- Recent Listings -->
  <div class="row-section">
    <div class="row-section-label">Recent Listings</div>
    <div class="row">
      <span class="row-label">Newest Joined</span>
      <?php if ($newest_joined_name !== null): ?>
        <a href="<?= $b ?>/admin/joined" class="link"><?= View::e($newest_joined_name) ?></a>
      <?php else: ?>
        <span class="row-val --text-dim">None yet</span>
      <?php endif; ?>
    </div>
    <div class="row">
      <span class="row-label">Newest Owned</span>
      <?php if ($newest_owned_name !== null): ?>
        <span>
          <a href="<?= $b ?>/admin/listings" class="link"><?= View::e($newest_owned_name) ?></a>
          <?php if (!empty($newest_owned_opened)): ?>
            <span class="row-sub">(Opened <?= View::e(date($admin_date_format, strtotime((string) $newest_owned_opened))) ?>)</span>
          <?php endif; ?>
        </span>
      <?php else: ?>
        <span class="row-val --text-dim">None yet</span>
      <?php endif; ?>
    </div>
    <?php if ($most_active_listing_name !== null && $most_active_listing_count > 0): ?>
    <div class="row">
      <span class="row-label">Most Active Listing</span>
      <span>
        <a href="<?= $b ?>/admin/members/<?= (int) $most_active_listing_id ?>" class="link"><?= View::e($most_active_listing_name) ?></a>
        <span class="row-sub">(<?= number_format($most_active_listing_count) ?> member<?= $most_active_listing_count !== 1 ? 's' : '' ?>)</span>
      </span>
    </div>
    <?php endif; ?>
  </div>

  <!-- Collective -->
  <div class="row-section">
    <div class="row-section-label">Collective</div>
    <div class="row">
      <span class="row-label">Total Active Listings</span>
      <span class="row-val"><?= (int) $fan_current ?></span>
    </div>
    <div class="row">
      <span class="row-label">Listings Up to Date</span>
      <?php $allUpToDate = $listings_up_to_date >= (int) $total_listings; ?>
      <span class="row-val <?= $allUpToDate ? 'row-val--success' : 'row-val--warn' ?>">
        <?= (int) $listings_up_to_date ?> of <?= (int) $total_listings ?>
      </span>
    </div>
    <?php if ($opened_this_year > 0): ?>
    <div class="row">
      <span class="row-label">Opened This Year</span>
      <span class="row-val"><?= (int) $opened_this_year ?></span>
    </div>
    <?php endif; ?>
    <?php if ($joined_this_year > 0): ?>
    <div class="row">
      <span class="row-label">Joined This Year</span>
      <span class="row-val"><?= (int) $joined_this_year ?></span>
    </div>
    <?php endif; ?>
  </div>

  <!-- Members -->
  <div class="row-section">
    <div class="row-section-label">Members</div>
    <div class="row">
      <span class="row-label">Total Members</span>
      <span class="row-val"><?= number_format((int) $total_members) ?></span>
    </div>
    <div class="row">
      <span class="row-label">Growth This Month</span>
      <div style="display:flex;align-items:center;gap:.4rem">
        <span class="row-val"><?= (int) $growth_this_month ?></span>
        <?php if ($growth_this_month > 0): ?>
        <span class="growth-pill growth-pill--up">
          <svg viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M2 7l3-4 3 4"/></svg>
          +<?= (int) $growth_this_month ?> this month<?= $growthDiff > 0 ? ' (+' . (int) $growthDiff . ' vs last)' : '' ?>
        </span>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($newestMember !== null): ?>
    <div class="row">
      <span class="row-label">Newest Member</span>
      <span class="row-val">
        <a href="<?= $b ?>/admin/members/<?= (int) $newestMember['listing_id'] ?>?search=<?= urlencode($newestMember['member']->email) ?>"
           class="link"><?= View::e($newestMember['member']->name) ?></a>
        (<?= View::e($newestMember['listing_name']) ?>)
        <?php if (!empty($newestMember['member']->added)): ?>
          &mdash; <?= dashTimeAgo((string) $newestMember['member']->added) ?>
        <?php endif; ?>
      </span>
    </div>
    <?php endif; ?>
  </div>

  <!-- Top Countries -->
  <?php if (!empty($top_countries)): ?>
  <div class="row-section">
    <div class="row-section-label">Top <?= count($top_countries) ?> Countries</div>
    <?php $rank = 1; foreach ($top_countries as $country => $count): ?>
    <div class="row-country">
      <?= $rank++ ?>.
      <span class="row-label"><?= View::e($country) ?></span>
      <span class="row-val"><?= (int) $count ?><?php if ($total_members > 0): ?> <span style="opacity:.65">(<?= round($count / $total_members * 100) ?>%)</span><?php endif; ?></span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div><!-- /.card -->
