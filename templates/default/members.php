<?php
/**
 * Members list — content fragment.
 *
 * Variables injected by MembersController:
 *   $listing_name   string
 *   $listing_url    string
 *   $join_url          string
 *   $members_base      string  base URL for sort/filter links
 *   $sort              string  'name' or 'country'
 *   $country           string  active country filter, or ''
 *   $members           Member[]  current page members (empty for country summary)
 *   $country_summary   array   [{country, fan_count, url}, ...] (empty for member lists)
 *   $fields            AdditionalField[]
 *   $pagination        string  pre-built pagination HTML, or ''
 *   $stats_line        string
 *   $base_url          string
 */

declare(strict_types=1);

use Luster\Core\View;
?>
<div class="fl-wrap">
  <h2 class="fl-title">Members of <?= View::e($listing_name) ?></h2>

  <?php if ($show_search ?? true): ?>
  <p>
    <a class="fl-link" href="<?= View::e($join_url) ?>">Join this listing</a>
    &nbsp;&mdash;&nbsp;
    Sort by:
    <?php $nameUrl    = View::e($members_base . '?sort=name'); ?>
    <?php $countryUrl = View::e($members_base . '?sort=country'); ?>
    <?php if ($sort === 'name'): ?>
      <strong>Name</strong> |
      <a class="fl-link" href="<?= $countryUrl ?>">Country</a>
    <?php else: ?>
      <a class="fl-link" href="<?= $nameUrl ?>">Name</a> |
      <strong>Country</strong>
    <?php endif; ?>
  </p>

  <?php if ($country !== ''): ?>
    <p><a class="fl-link" href="<?= View::e($members_base . '?sort=country') ?>">&larr; Back to countries</a></p>
  <?php endif; ?>
  <?php endif; ?>

  <p class="fl-muted" style="font-size:.875rem"><?= View::e($stats_line) ?></p>

  <?php if (!empty($country_summary)): ?>
    <!-- Country summary view -->
    <ul class="fl-member-list">
      <?php foreach ($country_summary as $row): ?>
        <li class="fl-member">
          <a class="fl-link" href="<?= View::e($row['url']) ?>"><?= View::e($row['country']) ?></a>
          <span class="fl-muted">(<?= (int) $row['fan_count'] ?>)</span>
        </li>
      <?php endforeach; ?>
    </ul>

  <?php elseif (($members_html ?? null) !== null && $members_html !== ''): ?>
    <!-- Custom DB template member rows -->
    <?= $members_html ?>
    <?php if ($pagination !== ''): ?>
      <div class="fl-pagination"><?= $pagination ?></div>
    <?php endif; ?>

  <?php elseif (!empty($members)): ?>
    <!-- Member list view -->
    <ul class="fl-member-list">
      <?php foreach ($members as $member): ?>
        <li class="fl-member">
          <?php if ($member->showurl && $member->url !== ''): ?>
            <a class="fl-link" href="<?= View::e($member->url) ?>"><?= View::e($member->name) ?></a>
          <?php else: ?>
            <?= View::e($member->name) ?>
          <?php endif; ?>
          <?php if ($member->country !== ''): ?>
            <span class="fl-muted">&mdash; <?= View::e($member->country) ?></span>
          <?php endif; ?>
          <?php foreach ($fields as $field): ?>
            <?php $val = $member->additionalFields[$field->name] ?? ''; ?>
            <?php if ($val !== ''): ?>
              <span class="fl-muted">&bull; <?= View::e($field->label) ?>: <?= View::e($val) ?></span>
            <?php endif; ?>
          <?php endforeach; ?>
        </li>
      <?php endforeach; ?>
    </ul>

    <?php if ($pagination !== ''): ?>
      <div class="fl-pagination"><?= $pagination ?></div>
    <?php endif; ?>

  <?php else: ?>
    <p class="fl-empty">No members to display yet.</p>
  <?php endif; ?>
</div>
