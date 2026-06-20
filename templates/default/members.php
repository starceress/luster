<?php
/**
 * Members list — content fragment.
 *
 * Variables injected by MembersController:
 *   $listing_title   string
 *   $listing_url    string
 *   $join_url          string
 *   $members_base      string  base URL for sort/filter links
 *   $sort              string  'name', 'country', or 'added'
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
<div class="fl-embed fl-embed--members">
  <?php if ($show_search ?? true): ?>
  <div class="fl-member-controls">
    <p class="fl-member-sort">
      Sort by:
      <?php
        $allUrl     = View::e($members_base . '?sort=added');
        $nameUrl    = View::e($members_base . '?sort=name');
        $countryUrl = View::e($members_base . '?sort=country');
      ?>
      <?= $sort === 'added'   ? '<strong aria-current="page" class="fl-member-sort-link">View All</strong>' : '<a class="fl-member-sort-link" href="' . $allUrl    . '">View All</a>' ?> /
      <?= $sort === 'name'    ? '<strong aria-current="page" class="fl-member-sort-link">Name</strong>'     : '<a class="fl-member-sort-link" href="' . $nameUrl   . '">Name</a>'    ?> /
      <?= $sort === 'country' ? '<strong aria-current="page" class="fl-member-sort-link">Country</strong>'  : '<a class="fl-member-sort-link" href="' . $countryUrl . '">Country</a>' ?>
    </p>

    <?php if ($country !== ''): ?>
      <p class="fl-member-back"><a href="<?= View::e($members_base . '?sort=country') ?>">&larr; Back to countries</a></p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <p class="fl-member-stats"><?= View::e($stats_line) ?></p>

  <?php if (!empty($country_summary)): ?>
    <!-- Country summary view -->
    <ul class="fl-member-list">
      <?php foreach ($country_summary as $row): ?>
        <li class="fl-member-item">
          <a class="fl-member-name" href="<?= View::e($row['url']) ?>"><?= View::e($row['country']) ?></a>
          <span class="fl-member-country">(<?= (int) $row['fan_count'] ?>)</span>
        </li>
      <?php endforeach; ?>
    </ul>

  <?php elseif (($members_html ?? null) !== null && $members_html !== ''): ?>
    <!-- Custom DB template member rows -->
    <?= $members_html ?>
    <?php if ($pagination !== ''): ?>
      <div class="fl-pagination-wrap"><?= $pagination ?></div>
    <?php endif; ?>

  <?php elseif (!empty($members)): ?>
    <!-- Member list view -->
    <ul class="fl-member-list">
      <?php foreach ($members as $member): ?>
        <li class="fl-member-item">
          <?php if ($member->showurl && $member->url !== ''): ?>
            <a class="fl-member-name" href="<?= View::e($member->url) ?>"><?= View::e($member->name) ?></a>
          <?php else: ?>
            <span class="fl-member-name"><?= View::e($member->name) ?></span>
          <?php endif; ?>
          <?php if ($member->country !== ''): ?>
            <span class="fl-member-country"><?= View::e($member->country) ?></span>
          <?php endif; ?>
          <?php foreach ($fields as $field): ?>
            <?php $val = $member->additionalFields[$field->name] ?? ''; ?>
            <?php if ($val !== ''): ?>
              <span class="fl-member-field"><span class="fl-member-field-label"><?= View::e($field->label) ?>:</span> <span class="fl-member-field-value"><?= View::e($val) ?></span></span>
            <?php endif; ?>
          <?php endforeach; ?>
        </li>
      <?php endforeach; ?>
    </ul>

    <?php if ($pagination !== ''): ?>
      <div class="fl-pagination-wrap"><?= $pagination ?></div>
    <?php endif; ?>

  <?php else: ?>
    <p class="fl-empty">No members to display yet.</p>
  <?php endif; ?>
</div>
