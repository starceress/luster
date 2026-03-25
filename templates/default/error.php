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
<div>
  <h2><?= View::e($error_title) ?></h2>
  <p><?= View::e($error_message) ?></p>
</div>
