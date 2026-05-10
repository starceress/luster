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
<div>
  <?php if ($show_search ?? true): ?>
  <p>
    Sort by:
    <?php $nameUrl    = View::e($members_base . '?sort=name'); ?>
    <?php $countryUrl = View::e($members_base . '?sort=country'); ?>
    <?php if ($sort === 'name'): ?>
      <strong>Name</strong> |
      <a href="<?= $countryUrl ?>">Country</a>
    <?php else: ?>
      <a href="<?= $nameUrl ?>">Name</a> |
      <strong>Country</strong>
    <?php endif; ?>
  </p>

  <?php if ($country !== ''): ?>
    <p><a href="<?= View::e($members_base . '?sort=country') ?>">&larr; Back to countries</a></p>
  <?php endif; ?>
  <?php endif; ?>

  <p class="fl-hint"><?= View::e($stats_line) ?></p>

  <?php if (!empty($country_summary)): ?>
    <!-- Country summary view -->
    <ul>
      <?php foreach ($country_summary as $row): ?>
        <li>
          <a href="<?= View::e($row['url']) ?>"><?= View::e($row['country']) ?></a>
          (<?= (int) $row['fan_count'] ?>)
        </li>
      <?php endforeach; ?>
    </ul>

  <?php elseif (($members_html ?? null) !== null && $members_html !== ''): ?>
    <!-- Custom DB template member rows -->
    <ul>
      <?= $members_html ?>
    </ul>
    <?php if ($pagination !== ''): ?>
      <div><?= $pagination ?></div>
    <?php endif; ?>

  <?php elseif (!empty($members)): ?>
    <!-- Member list view -->
    <ul>
      <?php foreach ($members as $member): ?>
        <li>
          <?php if ($member->showurl && $member->url !== ''): ?>
            <a href="<?= View::e($member->url) ?>"><?= View::e($member->name) ?></a>
          <?php else: ?>
            <?= View::e($member->name) ?>
          <?php endif; ?>
          <?php if ($member->country !== ''): ?>
            &mdash; <?= View::e($member->country) ?>
          <?php endif; ?>
          <?php foreach ($fields as $field): ?>
            <?php $val = $member->additionalFields[$field->name] ?? ''; ?>
            <?php if ($val !== ''): ?>
              &bull; <?= View::e($field->label) ?>: <?= View::e($val) ?>
            <?php endif; ?>
          <?php endforeach; ?>
        </li>
      <?php endforeach; ?>
    </ul>

    <?php if ($pagination !== ''): ?>
      <div><?= $pagination ?></div>
    <?php endif; ?>

  <?php else: ?>
    <p>No members to display yet.</p>
  <?php endif; ?>
</div>
