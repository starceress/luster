<?php
/**
 * Generic error page — content fragment.
 *
 * Variables injected by Application::handleException() or controllers:
 *   $error_title    string  heading text
 *   $error_message  string  body text
 *   $base_url       string
 */

declare(strict_types=1);

use Luster\Core\View;
?>
<div class="fl-wrap">
  <h2 class="fl-title"><?= View::e($error_title) ?></h2>
  <p class="fl-message"><?= View::e($error_message) ?></p>
</div>
