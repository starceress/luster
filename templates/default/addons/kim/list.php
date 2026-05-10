<?php
/**
 * KIM List overview — all fanlistings with interest counts.
 *
 * Variables:
 *   $fanlistings_with_counts  array  [{listing: Listing, count: int}]
 *   $base_url                 string
 */

declare(strict_types=1);

use Luster\Core\View;

$b = rtrim(View::e($base_url), '/');
$_linkSignup = ($link_signup !== null && $link_signup !== '') ? $link_signup : null;
?>
<div class="fl-wrap">
  <h2 class="fl-title">KIM List</h2>
  <p class="fl-intro">
    KIM (Keep In Mind) is an adoption interest registry. The numbers below show how many
    people have expressed interest in adopting each fanlisting if it ever becomes available.
    <?php if ($_linkSignup !== null): ?><a href="<?= View::e($_linkSignup) ?>">Sign up</a> to add your name to a fanlisting's list.<?php endif; ?>
  </p>

  <?php if (empty($fanlistings_with_counts)): ?>
    <p class="fl-empty">No fanlistings found.</p>
  <?php else: ?>
    <ul class="fl-list">
      <?php foreach ($fanlistings_with_counts as $entry): ?>
        <?php
          /** @var \Luster\Models\Listing $fl */
          $fl    = $entry['listing'];
          $count = (int) $entry['count'];
        ?>
        <li class="fl-list-item">
          <a href="<?= $b ?>/kim/list/<?= View::e((string) $fl->id) ?>">
            <?= View::e($fl->subject) ?>
          </a>
          <?php if ($count === 0): ?>
            <span class="fl-count fl-count--empty">(none yet)</span>
          <?php else: ?>
            <span class="fl-count">(<?= View::e((string) $count) ?> interested)</span>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if ($_linkSignup !== null): ?>
  <p class="fl-meta">
    <a href="<?= View::e($_linkSignup) ?>">Sign up for the KIM list</a>
  </p>
  <?php endif; ?>
</div>
