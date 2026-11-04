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
use Luster\Support\DateFormatter;
?>
<div class="fl-embed fl-embed--members">
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
      <?= $sort === 'added'   ? '<strong aria-current="page" class="fl-member-sort-link">Date Joined</strong>' : '<a class="fl-member-sort-link" href="' . $allUrl    . '">Date Joined</a>' ?> |
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
    <div class="fl-member-table">
      <div class="fl-member-table-head">
        <span class="fl-member-table-th">Name</span>
        <span class="fl-member-table-th">Country</span>
        <?php if (($sort ?? '') === 'added'): ?><span class="fl-member-table-th">Joined</span><?php endif; ?>
        <?php foreach ($fields as $field): ?>
          <span class="fl-member-table-th"><?= View::e($field->label) ?></span>
        <?php endforeach; ?>
      </div>

      <?php foreach ($members as $member): ?>
        <div class="fl-member-table-row">
          <span class="fl-member-table-cell">
            <?php if ($member->showurl && $member->url !== ''): ?>
              <a href="<?= View::e($member->url) ?>"><?= View::e($member->name) ?></a>
            <?php else: ?>
              <?= View::e($member->name) ?>
            <?php endif; ?>
          </span>

          <span class="fl-member-table-cell"><?= $member->country !== '' ? View::e($member->country) : '&ndash;' ?></span>
          <?php if (($sort ?? '') === 'added'): ?>
          <span class="fl-member-table-cell">
            <?php
              // No explicit format ("Automatic") falls back to the ordinal style,
              // matching MembersTemplateRenderer's {fan_added} default so the
              // custom-template and no-custom-template paths render dates the same way.
              $_addedFormat = $date_format ?? '';
              $_addedText   = $_addedFormat === ''
                  ? DateFormatter::format($member->added)
                  : DateFormatter::formatWith($member->added, $_addedFormat);
            ?>
            <?= View::e($_addedText) ?>
          </span>
          <?php endif; ?>
          <?php $_answers = $member->answers($fields); ?>
          <?php foreach ($fields as $field): ?>
            <?php $val = $_answers[$field->name] ?? ''; ?>
            <span class="fl-member-table-cell"><?= $val !== '' ? View::e($val) : '&ndash;' ?></span>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($pagination !== ''): ?>
      <div class="fl-pagination-wrap"><?= $pagination ?></div>
    <?php endif; ?>

  <?php else: ?>
    <p class="fl-empty">No members yet. <a href="<?= View::e($join_url) ?>">Want to join?</a></p>
  <?php endif; ?>
<?php endif; ?>
</div>
