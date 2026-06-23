<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 KIM List overview content fragment (Keep In Mind add-on). Displays all
 fanlistings that have KIM sign-ups, with interest counts per listing and links
 to each fanlisting's subject page. Served as a full page via
 View::renderPublic() or as an embeddable fragment via View::renderFragment()
 from embed/kim-list.php. Supports custom overview templates.
*/

declare(strict_types=1);

use Luster\Core\View;

$b             = rtrim(View::e($base_url), '/');
$_linkSignup   = isset($link_signup) && $link_signup !== '' ? $link_signup : null;
$_linkListBase = isset($link_list_base) && $link_list_base !== '' ? $link_list_base : null;
?>
<?php if (isset($overview_template_html) && $overview_template_html !== null): ?>
<?= $overview_template_html ?>
<?php return; ?>
<?php endif; ?>
<div class="fl-kim">
  <h2 class="fl-kim-heading">KIM List</h2>
  <p class="fl-kim-intro">
    KIM (Keep In Mind) is an adoption interest registry. The numbers below show how many
    people have expressed interest in adopting each fanlisting if it ever becomes available.
    <?php if ($_linkSignup !== null): ?><a href="<?= View::e($_linkSignup) ?>">Sign up</a> to add your name to a fanlisting's list.<?php endif; ?>
  </p>

  <?php if (empty($fanlistings_with_counts)): ?>
    <p class="fl-empty">No fanlistings with sign-ups yet.</p>
  <?php else: ?>
    <ul class="fl-kim-list">
      <?php foreach ($fanlistings_with_counts as $entry): ?>
        <?php
          /** @var \Luster\Models\Listing $fl */
          $fl    = $entry['listing'];
          $count = (int) $entry['count'];
        ?>
        <li class="fl-kim-item">
          <a href="<?= $_linkListBase !== null ? View::e($_linkListBase) . '?id=' . (int) $fl->id : $b . '/kim/list/' . (int) $fl->id ?>">
            <?= View::e($fl->subject) ?>
          </a>
          <?php if ($count === 0): ?>
            <span class="fl-kim-count fl-kim-count--empty">(none yet)</span>
          <?php else: ?>
            <span class="fl-kim-count">(<?= View::e((string) $count) ?> interested)</span>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if ($_linkSignup !== null): ?>
  <p class="fl-kim-meta">
    <a href="<?= View::e($_linkSignup) ?>">Sign up for the KIM list</a>
  </p>
  <?php endif; ?>
</div>
