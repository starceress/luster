<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Members list content fragment. Displays approved members for a listing with
 sort-by controls (name, country, added), a country summary view, additional
 field values, and pagination. Rendered as an embeddable fragment via
 View::renderFragment() from embed/members.php.
 Supports custom member row templates.
*/

declare(strict_types=1);

use Luster\Core\View;
?>
<?php if (($members_html ?? null) !== null && $members_html !== ''): ?>
  <?= $members_html ?>
  <?php if ($pagination !== ''): ?>
    <div class="fl-pagination-wrap"><?= $pagination ?></div>
  <?php endif; ?>

<?php else: ?>

<?php if ($show_search ?? true): ?>
  <div class="fl-member-controls">
    <p class="fl-member-sort">
      Sort by:
      <?php
        $allUrl     = View::e($members_base . '?sort=added');
        $nameUrl    = View::e($members_base . '?sort=name');
        $countryUrl = View::e($members_base . '?sort=country');
      ?>
      <?= $sort === 'added'   ? '<strong aria-current="page" class="fl-member-sort-link">View All</strong>' : '<a class="fl-member-sort-link" href="' . $allUrl    . '">View All</a>' ?> |
      <?= $sort === 'name'    ? '<strong aria-current="page" class="fl-member-sort-link">Name</strong>'     : '<a class="fl-member-sort-link" href="' . $nameUrl   . '">Name</a>'    ?> |
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
<?php endif; ?>
