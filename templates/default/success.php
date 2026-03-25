<?php
/**
 * Generic success page — content fragment.
 *
 * Variables injected by controllers:
 *   $page_title        string  (used by layout.php)
 *   $success_heading   string  heading text
 *   $success_message   string  body text
 *   $base_url          string
 */

declare(strict_types=1);

use Luster\Core\View;
?>
<div>
  <h2><?= View::e($success_heading) ?></h2>
  <p><?= View::e($success_message) ?></p>
</div>
