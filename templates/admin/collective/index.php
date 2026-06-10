<?php declare(strict_types=1);
use Luster\Core\View;
$b         = rtrim(View::e($base_url ?? ''), '/');
$installed = array_map('strtolower', array_map('strval', $nav_installed_addons ?? []));
$hasKim      = in_array('kim',      $installed, true);
$hasUpdates  = in_array('updates',  $installed, true);
$hasWishlist = in_array('wishlist', $installed, true);
$codes       = $collective_codes ?? ['total' => 0, 'pending' => 0];
$addons      = $addon_counts     ?? ['wishlist' => null, 'updates' => null];
?>

<div class="toolbar">
  <div class="actions-row"></div>
  <div class="actions-row actions-row--right">
    <a href="<?= $b ?>/admin/collective/templates" class="btn btn-quaternary btn-sm">Collective Templates</a>
    <a href="<?= $b ?>/admin/collective/settings" class="btn btn-quaternary btn-sm"><?= View::icon('gear') ?> Collective Settings</a>
  </div>
</div>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="stats-grid stats-grid-row">

  <div class="stat-card stat-card--link">
    <a href="<?= $b ?>/admin/listings" class="stat-card-cover" aria-label="Manage owned listings"></a>
    <div class="stat-header"><span class="stat-icon"><?= View::icon('star') ?></span> Total Owned</div>
    <div class="stat-value"><?= (int) ($total_listings ?? 0) ?></div>
    <div class="stat-pills">
      <?php if ((int) ($total_listings ?? 0) === 0): ?>
        <span class="badge badge-inactive">None yet</span>
      <?php else: ?>
        <?php if ((int) ($fan_pending ?? 0) > 0): ?>
          <span class="badge badge-pending"><?= (int) $fan_pending ?> Pending</span>
        <?php endif; ?>
        <?php if ((int) ($fan_upcoming ?? 0) > 0): ?>
          <span class="badge badge-upcoming"><?= (int) $fan_upcoming ?> Upcoming</span>
        <?php endif; ?>
        <?php if ((int) ($fan_pending ?? 0) === 0 && (int) ($fan_upcoming ?? 0) === 0): ?>
          <span class="badge badge-current">All Current</span>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <div class="stat-extra">
      <?php if (($newest_owned ?? null) !== null): ?>
        <strong>Newest Owned:</strong> <a href="<?= $b ?>/admin/listings/<?= (int) $newest_owned['id'] ?>/edit"><?= View::e($newest_owned['name']) ?></a>
      <?php else: ?>
        <strong>Newest Owned:</strong> None yet
      <?php endif; ?>
      <?php if (($most_active ?? null) !== null): ?>
        <br><strong>Most Active:</strong> <a href="<?= $b ?>/admin/members?listing_id=<?= (int) $most_active['id'] ?>&amp;status=approved"><?= View::e($most_active['name']) ?></a>
        (<?= number_format((int) $most_active['count']) ?>)
      <?php endif; ?>
    </div>
  </div>

  <div class="stat-card stat-card--link">
    <a href="<?= $b ?>/admin/joined" class="stat-card-cover" aria-label="Manage joined listings"></a>
    <div class="stat-header"><span class="stat-icon"><?= View::icon('heart') ?></span> Total Joined</div>
    <div class="stat-value"><?= (int) ($total_joined ?? 0) ?></div>
    <div class="stat-pills">
      <?php if ((int) ($total_joined_categories ?? 0) > 0): ?>
        <span class="badge badge-inactive"><?= (int) $total_joined_categories ?> <?= (int) $total_joined_categories === 1 ? 'Category' : 'Categories' ?></span>
      <?php endif; ?>
      <?php if ((int) ($total_joined_inactive ?? 0) > 0): ?>
        <span class="badge badge-pending"><?= (int) $total_joined_inactive ?> Pending</span>
      <?php endif; ?>
      <?php if ((int) ($total_joined ?? 0) === 0): ?>
        <span class="badge badge-inactive">None yet</span>
      <?php endif; ?>
    </div>
    <div class="stat-extra">
      <?php if (($newest_joined ?? null) !== null): ?>
        <strong>Newest Joined:</strong>
        <?php if (($newest_joined['url'] ?? '') !== ''): ?>
          <a href="<?= View::e($newest_joined['url']) ?>" target="_blank" rel="noopener noreferrer"><?= View::e($newest_joined['name']) ?></a>
        <?php else: ?>
          <a href="<?= $b ?>/admin/joined/<?= (int) $newest_joined['id'] ?>/edit"><?= View::e($newest_joined['name']) ?></a>
        <?php endif; ?>
      <?php else: ?>
        <strong>Newest Joined:</strong> None yet
      <?php endif; ?>
    </div>
  </div>

  <div class="stat-card stat-card--link">
    <a href="<?= $b ?>/admin/members" class="stat-card-cover" aria-label="Manage members"></a>
    <div class="stat-header"><span class="stat-icon"><?= View::icon('members') ?></span> Total Members</div>
    <div class="stat-value"><?= number_format((int) ($total_members ?? 0)) ?></div>
    <div class="stat-pills">
      <?php if ((int) ($total_members ?? 0) === 0): ?>
        <span class="badge badge-inactive">None yet</span>
      <?php elseif ((int) ($member_countries ?? 0) > 0): ?>
        <span class="badge badge-inactive"><?= (int) $member_countries ?> <?= (int) $member_countries === 1 ? 'Country' : 'Countries' ?></span>
      <?php endif; ?>
    </div>
    <div class="stat-extra">
      <?php if (($newest_member ?? null) !== null): ?>
        <strong>Newest Member:</strong> <?= View::e($newest_member['member']->name) ?>
        &mdash; <a href="<?= $b ?>/admin/members?listing_id=<?= (int) $newest_member['listing_id'] ?>&amp;status=all"><?= View::e($newest_member['listing_name']) ?></a>
      <?php else: ?>
        <strong>Newest Member:</strong> None yet
      <?php endif; ?>
    </div>
  </div>

  <div class="stat-card stat-card--link">
    <a href="<?= $b ?>/admin/affiliates?tab=approved&amp;scope=collective" class="stat-card-cover" aria-label="Manage collective affiliates"></a>
    <div class="stat-header"><span class="stat-icon"><?= View::icon('link') ?></span> Collective Affiliates</div>
    <div class="stat-value"><?= (int) ($total_affiliates_collective ?? 0) ?></div>
    <div class="stat-pills">
      <?php if ((int) ($total_affiliates_collective ?? 0) > 0): ?>
        <span class="badge badge-info"><?= (int) $total_affiliates_collective ?> Collective</span>
      <?php else: ?>
        <span class="badge badge-inactive">None yet</span>
      <?php endif; ?>
    </div>
    <div class="stat-extra">
      <?php if (($newest_affiliate ?? null) !== null): ?>
        <strong>Newest Affiliate:</strong> <a href="<?= View::e($newest_affiliate->url) ?>" target="_blank" rel="noopener noreferrer"><?= View::e($newest_affiliate->name) ?></a>
      <?php else: ?>
        <strong>Newest Affiliate:</strong> None yet
      <?php endif; ?>
    </div>
  </div>

</div>

<div class="overview-section-divider" aria-hidden="true"></div>

<div class="collective-columns">
  <div class="card">
    <div class="card-head">Quick Actions</div>
    <div class="collective-action-list">

      <a href="<?= $b ?>/admin/listings/add" class="collective-action-item">
        <span class="pc-head-icon pc-head-icon-bg"><?= View::icon('star') ?></span>
        <span class="collective-action-label">Add Owned Listing</span>
      </a>

      <a href="<?= $b ?>/admin/joined/add" class="collective-action-item">
        <span class="pc-head-icon pc-head-icon-bg"><?= View::icon('heart') ?></span>
        <span class="collective-action-label">Add Joined Listing</span>
      </a>

      <a href="<?= $b ?>/admin/codes?collective=1" class="collective-action-item">
        <span class="pc-head-icon pc-head-icon-bg"><?= View::icon('image') ?></span>
        <span class="collective-action-label">Add Collective Code</span>
      </a>

      <a href="<?= $b ?>/admin/affiliates/add" class="collective-action-item">
        <span class="pc-head-icon pc-head-icon-bg"><?= View::icon('link') ?></span>
        <span class="collective-action-label">Add Collective Affiliate</span>
      </a>

      <a href="<?= $hasUpdates ? $b . '/admin/updates/collective/add' : $b . '/admin/addons' ?>" class="collective-action-item<?= $hasUpdates ? '' : ' is-muted' ?>">
        <span class="pc-head-icon pc-head-icon-bg"><?= View::icon('updates') ?></span>
        <span>
          <span class="collective-action-label">Add Collective Update</span>
          <?php if (!$hasUpdates): ?>
            <span class="badge badge-inactive badge-inline-sm">Install add-on</span>
          <?php endif; ?>
        </span>
      </a>

      <a href="<?= $hasWishlist ? $b . '/admin/wishlist/add' : $b . '/admin/addons' ?>" class="collective-action-item<?= $hasWishlist ? '' : ' is-muted' ?>">
        <span class="pc-head-icon pc-head-icon-bg"><?= View::icon('wishlist') ?></span>
        <span>
          <span class="collective-action-label">Add Wishlist Entry</span>
          <?php if (!$hasWishlist): ?>
            <span class="badge badge-inactive badge-inline-sm">Install add-on</span>
          <?php endif; ?>
        </span>
      </a>

      <a href="<?= $hasKim ? $b . '/admin/kim/add' : $b . '/admin/addons' ?>" class="collective-action-item<?= $hasKim ? '' : ' is-muted' ?>">
        <span class="pc-head-icon pc-head-icon-bg"><?= View::icon('kim') ?></span>
        <span>
          <span class="collective-action-label">Add KIM Entry</span>
          <?php if (!$hasKim): ?>
            <span class="badge badge-inactive badge-inline-sm">Install add-on</span>
          <?php endif; ?>
        </span>
      </a>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><?= View::icon('embed') ?>> References</div>
    <div class="collective-action-list">

      <a href="<?= $b ?>/admin/collective/templates" class="collective-action-item">
        <span class="pc-head-icon pc-head-icon-bg"><?= View::icon('notes') ?></span>
        <span class="collective-action-label">Collective Templates</span>
      </a>

      <a href="<?= $b ?>/admin/collective/snippets" class="collective-action-item">
        <span class="pc-head-icon pc-head-icon-bg"><?= View::icon('embed') ?></span>
        <span class="collective-action-label">Collective Embed Snippets</span>
      </a>

      <a href="<?= $b ?>/admin/affiliates?tab=approved&amp;scope=collective" class="collective-action-item">
        <span class="pc-head-icon pc-head-icon-bg"><?= View::icon('link') ?></span>
        <span>
          <span class="collective-action-label">View All Collective Affiliates</span>
          <?php $_affCount = (int) ($total_affiliates_collective ?? 0); ?>
          <span class="badge <?= $_affCount > 0 ? 'badge-info' : 'badge-inactive' ?> badge-inline-sm"><?= $_affCount ?> <?= $_affCount === 1 ? 'Affiliate' : 'Affiliates' ?></span>
        </span>
      </a>

      <a href="<?= $b ?>/admin/codes?collective=1" class="collective-action-item">
        <span class="pc-head-icon pc-head-icon-bg"><?= View::icon('image') ?></span>
        <span>
          <span class="collective-action-label">View All Collective Codes</span>
          <?php $_codeTotal = (int) ($codes['total'] ?? 0); $_codePending = (int) ($codes['pending'] ?? 0); ?>
          <span class="badge <?= $_codeTotal > 0 ? 'badge-info' : 'badge-inactive' ?> badge-inline-sm"><?= $_codeTotal ?> <?= $_codeTotal === 1 ? 'Code' : 'Codes' ?></span>
          <?php if ($_codePending > 0): ?>
            <span class="badge badge-pending badge-inline-sm"><?= $_codePending ?> Pending</span>
          <?php endif; ?>
        </span>
      </a>

      <?php if ($hasUpdates): ?>
      <a href="<?= $b ?>/admin/updates/collective" class="collective-action-item">
        <span class="pc-head-icon pc-head-icon-bg"><?= View::icon('updates') ?></span>
        <span>
          <span class="collective-action-label">Collective Updates Index</span>
          <?php if ($addons['updates'] !== null): ?>
            <span class="badge badge-info-gold badge-inline-sm"><?= (int) $addons['updates'] ?> <?= (int) $addons['updates'] === 1 ? 'Update' : 'Updates' ?></span>
          <?php endif; ?>
        </span>
      </a>
      <?php endif; ?>

      <?php if ($hasWishlist): ?>
      <a href="<?= $b ?>/admin/wishlist" class="collective-action-item">
        <span class="pc-head-icon pc-head-icon-bg"><?= View::icon('wishlist') ?></span>
        <span>
          <span class="collective-action-label">Wishlist Index</span>
          <?php if ($addons['wishlist'] !== null): ?>
            <span class="badge badge-info-pink badge-inline-sm"><?= (int) $addons['wishlist'] ?> <?= (int) $addons['wishlist'] === 1 ? 'Entry' : 'Entries' ?></span>
          <?php endif; ?>
        </span>
      </a>
      <?php endif; ?>

      <?php if ($hasKim): ?>
      <a href="<?= $b ?>/admin/kim" class="collective-action-item">
        <span class="pc-head-icon pc-head-icon-bg"><?= View::icon('kim') ?></span>
        <span class="collective-action-label">Keep In Mind Index<?php if (($addons['kim'] ?? null) !== null): ?> <span class="badge badge-inactive badge-inline-sm"><?= (int) $addons['kim'] ?> sign-up<?= (int) $addons['kim'] !== 1 ? 's' : '' ?></span><?php endif; ?></span>
      </a>
      <?php endif; ?>

    </div>
  </div>
</div>
