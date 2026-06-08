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
<div class="fl-embed fl-embed--kim-list">
  <div class="fl-kim">
    <h2 class="fl-kim-heading fl-kim-subject-heading">KIM — <?= View::e($listing->subject) ?></h2>

    <p class="fl-kim-meta">
      <a href="<?= $_linkList !== null ? View::e($_linkList) : $b . '/kim/list' ?>">&larr; Back to KIM List</a>
    </p>

    <?php if (isset($kim_member_template_html) && $kim_member_template_html !== null): ?>
      <?= $kim_member_template_html ?>
    <?php elseif (empty($members)): ?>
      <p class="fl-empty">No one has signed up for this fanlisting yet.</p>
    <?php else: ?>
      <p class="fl-kim-count"><?= count($members) ?> interested</p>
      <ul class="fl-kim-member-list">
        <?php foreach ($members as $member): ?>
          <li class="fl-kim-member-item">
            <?php if ($member->url !== ''): ?>
              <a class="fl-kim-member-name" href="<?= View::e($member->url) ?>" rel="noopener noreferrer" target="_blank">
                <?= View::e($member->name) ?>
              </a>
            <?php else: ?>
              <span class="fl-kim-member-name"><?= View::e($member->name) ?></span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <p class="fl-kim-meta">
      <a href="<?= $_linkSignup !== null ? View::e($_linkSignup) : $b . '/kim' ?>">Sign up to be on this list</a>
    </p>
  </div>
</div>
