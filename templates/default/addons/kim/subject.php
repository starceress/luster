<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 KIM List subject page content fragment (Keep In Mind add-on). Shows approved
 KIM members for a single fanlisting, with interest count, member names/URLs,
 pagination, and links back to the overview and sign-up pages. Served as a full
 page as an embeddable fragment via
 View::renderFragment() from embed/kim-list.php. Supports custom member and
 subject templates.
*/

declare(strict_types=1);

use Luster\Core\View;

$b           = rtrim(View::e($base_url), '/');
$_linkList   = isset($link_list) && $link_list !== '' ? $link_list : null;
$_linkSignup = isset($link_signup) && $link_signup !== '' ? $link_signup : null;
?>
<?php if (isset($subject_template_html) && $subject_template_html !== null): ?>
<?= $subject_template_html ?>
<?php return; ?>
<?php endif; ?>
<div class="fl-kim">
  <h2 class="fl-kim-heading fl-kim-subject-heading">KIM — <?= View::e($listing->subject) ?></h2>

  <p class="fl-kim-meta">
    <?php if ($_linkList !== null): ?><a href="<?= View::e($_linkList) ?>">&larr; Back to KIM List</a><?php endif; ?>
  </p>

  <?php if (isset($kim_member_template_html) && $kim_member_template_html !== null): ?>
    <?= $kim_member_template_html ?>
    <?php if (($pagination ?? '') !== ''): ?>
      <div class="fl-pagination-wrap"><?= $pagination ?></div>
    <?php endif; ?>
  <?php elseif (empty($members)): ?>
    <p class="fl-empty">No one has signed up for this fanlisting yet.</p>
  <?php else: ?>
    <p class="fl-kim-count"><?= $total ?? count($members) ?> interested</p>
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
    <?php if (($pagination ?? '') !== ''): ?>
      <div class="fl-pagination-wrap"><?= $pagination ?></div>
    <?php endif; ?>
  <?php endif; ?>

  <p class="fl-kim-meta">
    <a href="<?= $_linkSignup !== null ? View::e($_linkSignup) : $b . '/kim' ?>">Sign up to be on this list</a>
  </p>
</div>
