<?php
/**
 * KIM List generic success page.
 *
 * Variables:
 *   $heading   string
 *   $message   string
 *   $base_url  string
 */

declare(strict_types=1);

use Luster\Core\View;

$b = rtrim(View::e($base_url), '/');
?>
<div class="fl-wrap">
  <h2 class="fl-title"><?= View::e($heading) ?></h2>
  <p><?= View::e($message) ?></p>

  <p class="fl-meta" style="margin-top:1.5rem">
    <a href="<?= $b ?>/kim">Sign up for another fanlisting</a> &middot;
    <a href="<?= $b ?>/kim/list">View the KIM list</a>
  </p>
</div>
