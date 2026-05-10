<?php
/**
 * KIM List subject page — approved members for one fanlisting.
 *
 * Variables:
 *   $listing   Listing
 *   $members   KimMember[]
 *   $base_url  string
 */

declare(strict_types=1);

use Luster\Core\View;

$b = rtrim(View::e($base_url), '/');
?>
<div class="fl-wrap">
  <h2 class="fl-title">KIM — <?= View::e($listing->subject) ?></h2>

  <p class="fl-meta">
    <a href="<?= $b ?>/kim/list">&larr; Back to KIM List</a>
  </p>

  <?php if (empty($members)): ?>
    <p class="fl-empty">No one has signed up for this fanlisting yet.</p>
  <?php else: ?>
    <ul class="fl-members">
      <?php foreach ($members as $member): ?>
        <li class="fl-member">
          <?php if ($member->url !== ''): ?>
            <a href="<?= View::e($member->url) ?>" rel="noopener noreferrer" target="_blank">
              <?= View::e($member->name) ?>
            </a>
          <?php else: ?>
            <?= View::e($member->name) ?>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <p class="fl-meta">
    <a href="<?= $b ?>/kim">Sign up to be on this list</a>
  </p>
</div>
