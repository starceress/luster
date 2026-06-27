<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Admin dashboard. Displays a welcome block with a live greeting and quick-action
 buttons, danger/warning banners for pending items, the "Collective at a Glance"
 summary card with listing counts, member stats, growth indicators, top countries,
 recent members, recent activity, and stale-listing alerts.
*/
use Luster\Core\UpdateStatus;
use Luster\Core\View;
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

<!-- ──── WELCOME BLOCK ───────────────────────────────────────────────── -->
<div class="welcome-block">
  <div>
    <div class="welcome-sub" id="live-datetime"></div>
    <div class="welcome-title"><?= View::e($welcome_greeting) ?></div>
    <div class="welcome-sub">
      <?php
        $_wParts = [];
        if ($total_pending > 0) {
            $n = (int) $total_pending;
            $_wParts[] = '<strong>' . $n . ' pending member' . ($n !== 1 ? 's' : '') . '</strong>';
        }
        if (!empty($stale_listings)) {
            $n = count($stale_listings);
            $_wParts[] = '<strong>' . $n . ' neglected listing' . ($n !== 1 ? 's' : '') . '</strong>';
        }
        if (($pending_affiliates ?? 0) > 0) {
            $n = (int) $pending_affiliates;
            $_wParts[] = '<strong>' . $n . ' affiliate application' . ($n !== 1 ? 's' : '') . '</strong>';
        }
        if (($pending_codes ?? 0) > 0) {
            $n = (int) $pending_codes;
            $_wParts[] = '<strong>' . $n . ' donated code' . ($n !== 1 ? 's' : '') . '</strong>';
        }
        if (($pending_adoptions ?? 0) > 0) {
            $n = (int) $pending_adoptions;
            $_wParts[] = '<strong>' . $n . ' adoption application' . ($n !== 1 ? 's' : '') . '</strong>';
        }
        if (($pending_kim ?? 0) > 0) {
            $n = (int) $pending_kim;
            $_wParts[] = '<strong>' . $n . ' KIM sign-up' . ($n !== 1 ? 's' : '') . '</strong>';
        }
        $_wCount = count($_wParts);
        if ($_wCount === 0) {
            $_wSentence = 'Everything is up to date!';
        } elseif ($_wCount === 1) {
            $_wSentence = 'You have ' . $_wParts[0] . ' that needs attention.';
        } elseif ($_wCount === 2) {
            $_wSentence = 'You have ' . $_wParts[0] . ' and ' . $_wParts[1] . ' that need attention.';
        } else {
            $_wLast = array_pop($_wParts);
            $_wSentence = 'You have ' . implode(', ', $_wParts) . ', and ' . $_wLast . ' that need attention.';
        }
      ?>
      <?= $_wSentence ?>
    </div>
  </div>
  <div class="actions-row" style="flex-shrink:0;position:relative;z-index:1">
    <a href="<?= $b ?>/admin/listings/add" class="btn btn-primary btn-sm">
      <?= View::icon('add') ?> Add Owned Listing
    </a>
    <a href="<?= $b ?>/admin/joined/add" class="btn btn-secondary btn-sm">
     <?= View::icon('add') ?> Add Joined Listing
    </a>
  </div>
</div>

<!-- ──── STAT CARDS ──────────────────────────────────────────────────── -->
<div class="stats-grid stats-grid-row">

  <a href="<?= $b ?>/admin/listings" class="stat-card stat-card--link">
    <div class="stat-header"><span class="stat-icon"><?= View::icon('star') ?></span> Total Owned</div>
    <div class="stat-value"><?= (int) $total_listings ?></div>
    <div class="stat-pills">
      <?php if ($total_listings === 0): ?>
        <span class="badge badge-inactive">None yet</span>
      <?php else: ?>
        <?php if ($fan_pending > 0): ?>
          <span class="badge badge-pending"><?= (int) $fan_pending ?> Pending</span>
        <?php endif; ?>
        <?php if ($fan_upcoming > 0): ?>
          <span class="badge badge-upcoming"><?= (int) $fan_upcoming ?> Upcoming</span>
        <?php endif; ?>
        <?php if ($fan_pending === 0 && $fan_upcoming === 0): ?>
          <span class="badge badge-current">All Current</span>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </a>

  <a href="<?= $b ?>/admin/joined" class="stat-card stat-card--link">
    <div class="stat-header"><span class="stat-icon"><?= View::icon('heart') ?></span> Total Joined</div>
    <div class="stat-value"><?= (int) $total_joined ?></div>
    <div class="stat-pills">
      <?php if ($total_joined_categories > 0): ?>
        <span class="badge badge-inactive"><?= (int) $total_joined_categories ?> <?= $total_joined_categories === 1 ? 'Category' : 'Categories' ?></span>
      <?php endif; ?>
      <?php if ($total_joined_inactive > 0): ?>
        <span class="badge badge-pending"><?= (int) $total_joined_inactive ?> Pending</span>
      <?php endif; ?>
      <?php if ($total_joined === 0): ?>
        <span class="badge badge-inactive">None yet</span>
      <?php endif; ?>
    </div>
  </a>

  <a href="<?= $b ?>/admin/members" class="stat-card stat-card--link">
    <div class="stat-header"><span class="stat-icon"><?= View::icon('members') ?></span> Total Members</div>
    <div class="stat-value"><?= number_format((int) $total_members) ?></div>
    <div class="stat-pills">
      <?php if ($total_members === 0): ?>
        <span class="badge badge-inactive">None yet</span>
      <?php elseif ($member_countries > 0): ?>
        <span class="badge badge-inactive"><?= (int) $member_countries ?> <?= $member_countries === 1 ? 'Country' : 'Countries' ?></span>
      <?php endif; ?>
    </div>
  </a>

  <a href="<?= $b ?>/admin/affiliates" class="stat-card stat-card--link">
    <div class="stat-header"><span class="stat-icon"><?= View::icon('link') ?></span> Total Affiliates</div>
    <div class="stat-value"><?= (int) $total_affiliates ?></div>
    <div class="stat-pills">
      <?php if ($total_affiliates_collective > 0): ?>
        <span class="badge badge-info"><?= (int) $total_affiliates_collective ?> Collective</span>
      <?php endif; ?>
      <?php if ($total_affiliates_per_fl > 0): ?>
        <span class="badge badge-info2"><?= (int) $total_affiliates_per_fl ?> Per-FL</span>
      <?php endif; ?>
      <?php if ($total_affiliates === 0): ?>
        <span class="badge badge-inactive">None yet</span>
      <?php endif; ?>
    </div>
  </a>
</div>

<div class="overview-section-divider" aria-hidden="true"></div>

<?php if ($no_listings ?? false): ?>
<!-- ──── FIRST-TIME IMPORT CALLOUT ───────────────────────────────────── -->
<div class="onboard-callout">
  <div class="onboard-callout-inner">
    <div class="onboard-callout-heading">
      <span class="onboard-callout-sparke">&#10022;</span>
      Getting Started
    </div>
    <p class="onboard-callout-body">
      Migrating from Enthusiast, Listing Admin, Fanatically, CodeSort, or FanUpdate? Use the Import Data Wizard brings in your listings, members, affiliates, and images in minutes. 
    </p>
      <a href="<?= $b ?>/admin/import" class="btn btn-primary btn-sm">Open Import Data Wizard &rarr;</a> 
      <a href="<?= $b ?>/admin/listings/add" class="btn btn-quaternary btn-sm">Add First Listing Manually&rarr;</a>
  </div>
</div>
<?php endif; ?>

<!-- ──── HTTP APP URL WARNING ────────────────────────────────────────── -->
<?php if ($show_http_warning ?? false): ?>
<div class="reminder-banner">
  <div class="reminder-banner-head">
    <div class="reminder-banner-left">
      <span class="reminder-banner-icon"><?= View::icon('caution') ?></span>
      <div>
        <span class="reminder-banner-title">Your site URL uses http://</span>
        <span class="reminder-banner-msg">
          That's fine for local testing, but logins won't work correctly on a live site without HTTPS.
          <a href="<?= $b ?>/admin/settings#panel-general" class="link">Update your App URL before going live &rarr;</a>
        </span>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ──── LUSTER UPDATES ──────────────────────────────────────────────── -->
<?php if ($show_update_banner): ?>
<div class="reminder-banner reminder-banner--update">
  <div class="reminder-banner-head">
    <div class="reminder-banner-left">
      <span class="reminder-banner-icon"><?= View::icon('sparkles') ?></span>
      <div>
        <span class="reminder-banner-title">New Luster Version Available</span>
        <span class="reminder-banner-msg">
          <strong>Version <?= View::e($update_status->latest ?? '') ?></strong> is available
          <?php if ($update_status->releaseTitle !== null): ?>
            &mdash; <?= View::e($update_status->releaseTitle) ?>
          <?php endif; ?>
        </span>
      </div>
    </div>
    <div class="status-inline">
      <a href="<?= $b ?>/admin/update" class="btn btn-download btn-sm">
        Prepare Update &rarr;
      </a>
      <form method="POST" action="<?= $b ?>/admin/dismiss-update" class="action-form-inline">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
        <input type="hidden" name="version" value="<?= View::e($update_status->latest ?? '') ?>">
        <button type="submit" class="btn btn-quaternary btn-sm">Dismiss</button>
      </form>
    </div>
  </div>
</div>

<div class="overview-section-divider" aria-hidden="true"></div>
<?php endif; ?>

<!-- ──── TO DO LIST ──────────────────────────────────────────────────── -->
<?php $hasTodo = $total_pending > 0 || !empty($stale_listings)
    || ($pending_affiliates ?? 0) > 0
    || ($pending_codes ?? 0) > 0
    || ($pending_adoptions ?? 0) > 0
    || ($pending_kim ?? 0) > 0; ?>
<div class="dash-main-grid">

  <?php if ($hasTodo): ?>
  <?php $todoTotal = (int) $total_pending + count($stale_listings)
      + ($pending_affiliates ?? 0)
      + ($pending_codes ?? 0)
      + ($pending_adoptions ?? 0)
      + ($pending_kim ?? 0); ?>
  <div class="card" style="margin-bottom:0">
    <div class="card-head"><?= View::icon('bell') ?> To Do List
      <span class="badge badge-error todo-head-badge" id="todo-total-badge"><?= $todoTotal ?></span>
    </div>

    <?php if ($total_pending > 0): ?>
    <div class="todo-section">
      <button class="todo-toggle todo-toggle--warn" data-target="todo-action">
        <div class="todo-toggle-left">
          <span class="todo-toggle-sigil"><?= View::icon('pending') ?></span>
          <span class="todo-toggle-title">Pending Members</span>
          <span class="badge badge-pending"><?= (int) $total_pending ?></span>
        </div>
        <span class="todo-chev">&#9650;</span>
      </button>
      <div class="todo-body" id="todo-action">
        <div class="todo-action-inner">
          <span><?= (int) $total_pending ?> member<?= $total_pending !== 1 ? 's are' : ' is' ?> awaiting approval</span>
          <a href="<?= $b ?>/admin/members" class="btn btn-preview btn-sm">Review Now &rarr;</a>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($stale_listings)): ?>
    <div class="todo-section">
      <button class="todo-toggle todo-toggle--warn" data-target="todo-maint">
        <div class="todo-toggle-left">
          <span class="todo-toggle-sigil"><?= View::icon('caution') ?></span>
          <span class="todo-toggle-title">Maintenance Needed</span>
          <span class="badge badge-pending" id="todo-maint-badge"><?= count($stale_listings) ?></span>
        </div>
        <span class="todo-chev">&#9650;</span>
      </button>
      <div class="todo-body" id="todo-maint">
        <?php /* Bulk-select form sits outside the per-row forms so no nesting occurs.
                  Checkboxes and "Mark Selected" use form="bulk-maint-form" to associate. */ ?>
        <form method="POST" action="<?= $b ?>/admin/maintenance/bulk-touch-updated"
              id="bulk-maint-form">
          <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
        </form>
        <div class="todo-maint-header">
          <label class="form-check" style="font-size:var(--fs-sm)">
            <input type="checkbox" id="bulk-select-all" aria-label="Select all neglected listings">
            <span>Select all</span>
          </label>
        </div>
        <?php foreach ($stale_listings as $staleItem): ?>
        <div class="todo-mrow">
          <div class="todo-mrow-left">
            <label class="todo-mrow-check">
              <input type="checkbox" name="listing_ids[]"
                     value="<?= (int) $staleItem['listing']->id ?>"
                     form="bulk-maint-form"
                     aria-label="Select <?= View::e($staleItem['listing']->subject) ?>">
            </label>
            <a href="<?= $b ?>/admin/listings/<?= (int) $staleItem['listing']->id ?>/edit"
               class="link"><?= View::e($staleItem['listing']->subject) ?></a>
          </div>
          <div class="todo-mrow-right">
            <?php
              $daysAgo    = $staleItem['days_ago'];
              $daysColor  = ($daysAgo === null || $daysAgo >= ($overdue_threshold_days ?? 55))
                              ? 'var(--danger)'
                              : 'var(--text-dim)';
            ?>
            <span class="todo-mrow-days" style="color:<?= $daysColor ?>">
              <?php if ($daysAgo !== null): ?>
                <?= (int) $daysAgo ?> day<?= $daysAgo !== 1 ? 's' : '' ?> ago
              <?php else: ?>
                Never updated
              <?php endif; ?>
            </span>
            <form method="POST"
                  action="<?= $b ?>/admin/listings/<?= (int) $staleItem['listing']->id ?>/touch-updated"
                  class="todo-mark-form">
              <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
              <button type="submit" id="btn-mark-updated" class="btn btn-success btn-xs">Update</button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
        <div class="todo-bulk" style="justify-content:space-between">
          <button type="submit" form="bulk-maint-form"
                  class="btn btn-tertiary btn-sm" id="bulk-mark-btn" disabled>
            Update Selected
          </button>
          <form method="POST" action="<?= $b ?>/admin/maintenance/touch-all-updated"
                data-confirm="Mark all <?= count($stale_listings) ?> neglected listing(s) as updated today?">
            <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
            <button type="submit" class="btn btn-success btn-sm">Update All &rarr;</button>
          </form>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if (($pending_affiliates ?? 0) > 0): ?>
    <div class="todo-section">
      <button class="todo-toggle todo-toggle--warn" data-target="todo-affiliates">
        <div class="todo-toggle-left">
          <span class="todo-toggle-sigil"><?= View::icon('link') ?></span>
          <span class="todo-toggle-title">Pending Affiliates</span>
          <span class="badge badge-pending"><?= (int) $pending_affiliates ?></span>
        </div>
        <span class="todo-chev">&#9650;</span>
      </button>
      <div class="todo-body" id="todo-affiliates">
        <div class="todo-action-inner">
          <span><?= (int) $pending_affiliates ?> affiliate application<?= (int) $pending_affiliates !== 1 ? 's are' : ' is' ?> awaiting review</span>
          <a href="<?= $b ?>/admin/affiliates/pending" class="btn btn-preview btn-sm">Review Now &rarr;</a>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if (($pending_codes ?? 0) > 0): ?>
    <div class="todo-section">
      <button class="todo-toggle todo-toggle--warn" data-target="todo-codes">
        <div class="todo-toggle-left">
          <span class="todo-toggle-sigil"><?= View::icon('image') ?></span>
          <span class="todo-toggle-title">Pending Codes</span>
          <span class="badge badge-pending"><?= (int) $pending_codes ?></span>
        </div>
        <span class="todo-chev">&#9650;</span>
      </button>
      <div class="todo-body" id="todo-codes">
        <div class="todo-action-inner">
          <span><?= (int) $pending_codes ?> code donation<?= (int) $pending_codes !== 1 ? 's are' : ' is' ?> awaiting approval</span>
          <a href="<?= $b ?>/admin/codes" class="btn btn-preview btn-sm">Review Now &rarr;</a>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if (($pending_adoptions ?? 0) > 0): ?>
    <div class="todo-section">
      <button class="todo-toggle todo-toggle--warn" data-target="todo-adoptions">
        <div class="todo-toggle-left">
          <span class="todo-toggle-sigil"><?= View::icon('adoptions') ?></span>
          <span class="todo-toggle-title">Pending Adoptions</span>
          <span class="badge badge-pending"><?= (int) $pending_adoptions ?></span>
        </div>
        <span class="todo-chev">&#9650;</span>
      </button>
      <div class="todo-body" id="todo-adoptions">
        <div class="todo-action-inner">
          <span><?= (int) $pending_adoptions ?> adoption application<?= (int) $pending_adoptions !== 1 ? 's are' : ' is' ?> awaiting review</span>
          <a href="<?= $b ?>/admin/adoptions" class="btn btn-preview btn-sm">Review Now &rarr;</a>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if (($pending_kim ?? 0) > 0): ?>
    <div class="todo-section">
      <button class="todo-toggle todo-toggle--warn" data-target="todo-kim">
        <div class="todo-toggle-left">
          <span class="todo-toggle-sigil"><?= View::icon('kim') ?></span>
          <span class="todo-toggle-title">Pending KIM Members</span>
          <span class="badge badge-pending"><?= (int) $pending_kim ?></span>
        </div>
        <span class="todo-chev">&#9650;</span>
      </button>
      <div class="todo-body" id="todo-kim">
        <div class="todo-action-inner">
          <span><?= (int) $pending_kim ?> Keep In Mind member<?= (int) $pending_kim !== 1 ? 's are' : ' is' ?> awaiting approval</span>
          <a href="<?= $b ?>/admin/kim" class="btn btn-preview btn-sm">Review Now &rarr;</a>
        </div>
      </div>
    </div>
    <?php endif; ?>

  </div>
  <?php endif; ?>

<!-- ──── AT A GLANCE ─────────────────────────────────────────────────── -->
  <?php if ($total_listings > 0 || $total_joined > 0 || $total_members > 0 || $total_affiliates > 0): ?>
  <div class="card<?= $hasTodo ? '' : ' dash-glance-full' ?>">
    <div class="card-head"><?= View::icon('sparkles') ?> At a Glance</div>
    <div class="row-section">
      <div class="row-section-label">Listings</div>
      <div class="row">
        <span class="row-label">Newest Joined</span>
        <?php if ($newest_joined_name !== null): ?>
          <a href="<?= $b ?>/admin/joined" class="link"><?= View::e($newest_joined_name) ?></a>
        <?php else: ?>
          <span class="row-val">None yet</span>
        <?php endif; ?>
      </div>
      <div class="row">
        <span class="row-label">Newest Owned</span>
        <?php if ($newest_owned_name !== null): ?>
          <span class="row-val">
            <a href="<?= $b ?>/admin/listings" class="link"><?= View::e($newest_owned_name) ?></a>
            <?php if (!empty($newest_owned_opened)): ?> (Opened <?= View::e(date($admin_date_format, strtotime((string) $newest_owned_opened))) ?>)
            <?php endif; ?>
          </span>
        <?php else: ?>
          <span class="row-val">None yet</span>
        <?php endif; ?>
      </div>
      <?php if ($most_active_listing_name !== null && $most_active_listing_count > 0): ?>
      <div class="row">
        <span class="row-label">Most Active Listing</span>
        <span class="row-val">
          <a href="<?= $b ?>/admin/members?listing_id=<?= (int) $most_active_listing_id ?>&status=all" class="link"><?= View::e($most_active_listing_name) ?></a>
          (<?= number_format($most_active_listing_count) ?> member<?= $most_active_listing_count !== 1 ? 's' : '' ?>)
        </span>
      </div>
      <?php endif; ?>
    </div>

    <div class="row-section">
      <div class="row-section-label">Collective</div>
      <div class="row">
        <span class="row-label">Total Active Listings</span>
        <span class="row-val"><?= (int) $fan_current ?></span>
      </div>
      <div class="row">
        <span class="row-label">Up to Date Listings</span>
        <?php
          $pct = $fan_current > 0 ? ($listings_up_to_date / $fan_current) * 100 : 100;
          if ($pct >= 100) {
              $utdClass = 'row-val--success'; $utdStyle = '';
          } elseif ($pct > 50) {
              $utdClass = 'row-val--warn';    $utdStyle = '';
          } else {
              $utdClass = '';                 $utdStyle = 'color:var(--danger)';
          }
        ?>
        <span class="row-val <?= $utdClass ?>"<?= $utdStyle ? ' style="' . $utdStyle . '"' : '' ?>>
          <?= (int) $listings_up_to_date ?> of <?= (int) $fan_current ?>
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

    <div class="row-section">
      <div class="row-section-label">Members</div>
      <div class="row">
        <span class="row-label">Total Members</span>
        <span class="row-val"><?= number_format((int) $total_members) ?></span>
      </div>
      <div class="row">
        <span class="row-label">New Members This Month</span>
        <div class="inline-link-row">
          <span class="row-val"><?= number_format((int) $growth_this_month) ?> member<?= $growth_this_month !== 1 ? 's' : '' ?></span>
          <?php if ($growthDiff > 0): ?>
          <span class="growth-pill growth-pill--up">
            <svg viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M2 7l3-4 3 4"/></svg>
            +<?= (int) $growthDiff ?> vs last month
          </span>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($newestMember !== null): ?>
      <div class="row">
        <span class="row-label">Newest Member</span>
        <span class="row-val">
          <a href="<?= $b ?>/admin/members?listing_id=<?= (int) $newestMember['listing_id'] ?>&status=all&search=<?= urlencode($newestMember['member']->email) ?>"
             class="link"><?= View::e($newestMember['member']->name) ?></a>
          (<?= View::e($newestMember['listing_title']) ?>)
          <?php if (!empty($newestMember['member']->added)): ?>
            &mdash; <?= dashTimeAgo((string) $newestMember['member']->added) ?>
          <?php endif; ?>
        </span>
      </div>
      <?php endif; ?>
    </div>

    <?php if (!empty($top_countries)): ?>
    <div class="row-section">
      <div class="row-section-label">Top <?= count($top_countries) ?> Countries</div>
      <?php $rank = 1; foreach ($top_countries as $country => $count): ?>
      <div class="row-country">
        <?= $rank++ ?>.
        <span class="row-label"><?= View::e($country) ?></span>
        <span class="row-val"><?= (int) $count ?><?php if ($total_members > 0): ?> <span class="row-val-subtle">(<?= round($count / $total_members * 100) ?>%)</span><?php endif; ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
