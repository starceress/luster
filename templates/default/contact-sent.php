<?php
/**
 * Contact form sent — content fragment.
 *
 * Variables injected by ContactController:
 *   $listing        Listing|null  null for collective
 *   $listing_name   string
 *   $listing_url    string
 */

declare(strict_types=1);

use Luster\Core\View;
?>
<div>
  <p>Your message has been sent. Thank you for getting in touch!</p>
  <p><a href="<?= View::e($listing_url) ?>">Back to <?= View::e($listing_name) ?></a></p>
</div>
