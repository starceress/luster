<?php
/**
 * KIM List password reset — "check your email" page.
 *
 * Variables:
 *   $base_url  string
 */

declare(strict_types=1);

use Luster\Core\View;

$b = rtrim(View::e($base_url), '/');
?>
<div class="fl-wrap">
  <h2 class="fl-title">Check Your Email</h2>
  <p>
    If that email address is registered for the selected fanlisting, a password reset link
    has been sent. Links expire in 1 hour.
  </p>
  <p>
    If you do not receive an email within a few minutes, please check your spam folder.
  </p>

  <p class="fl-meta">
    <a href="<?= $b ?>/kim/reset">&larr; Back to Reset</a>
  </p>
</div>
