<?php
/**
 * KIM List subject page — approved members for one fanlisting.
 *
 * Variables:
 *   $listing      Listing
 *   $members      KimMember[]
 *   $base_url     string
 *   $link_list    string|null  override URL for "Back to KIM List" link (optional)
 *   $link_signup  string|null  override URL for the sign-up link (optional)
 */

declare(strict_types=1);

use Luster\Core\View;

$b           = rtrim(View::e($base_url), '/');
$_linkList   = isset($link_list) && $link_list !== '' ? $link_list : null;
$_linkSignup = isset($link_signup) && $link_signup !== '' ? $link_signup : null;
?>
<div class="fl-wrap">
  <h2 class="fl-title">KIM — <?= View::e($listing->subject) ?></h2>

  <p class="fl-meta">
    <a href="<?= $_linkList !== null ? View::e($_linkList) : $b . '/kim/list' ?>">&larr; Back to KIM List</a>
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
    <a href="<?= $_linkSignup !== null ? View::e($_linkSignup) : $b . '/kim' ?>">Sign up to be on this list</a>
  </p>
</div>
