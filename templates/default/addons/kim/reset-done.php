<?php
/**
 * KIM List password reset — done page.
 *
 * Variables:
 *   $base_url  string
 */

declare(strict_types=1);

use Luster\Core\View;

$b = rtrim(View::e($base_url), '/');
?>
<div class="fl-wrap">
  <h2 class="fl-title">Password Updated</h2>
  <p>Your KIM List password has been updated successfully.</p>
  <p>You can now use it to update or manage your entry.</p>

  <p class="fl-meta" style="margin-top:1.5rem">
    <a href="<?= $b ?>/kim/update">Update your KIM entry</a> &middot;
    <a href="<?= $b ?>/kim/list">View the KIM list</a>
  </p>
</div>
