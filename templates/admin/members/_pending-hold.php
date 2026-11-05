<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Shared queue-screen partial for a pending update that's been stopped for the owner
 to look at. Expects $puHold to be set before inclusion: a review_state from
 QueuedUpdateApplier::holdForScreen(). Both pending-update tables use it.
*/

use Luster\Core\View;
use Luster\Support\QueuedUpdateApplier;
?>
<div class="callout callout-warn">
  <?= View::icon('alert') ?>
  <div>
    <strong><?= View::e(QueuedUpdateApplier::holdLabel($puHold)) ?></strong><br>
    <em><?= View::e(QueuedUpdateApplier::holdText($puHold)) ?></em><br>
    <?= View::e(QueuedUpdateApplier::HOLD_EXPLANATION) ?>
  </div>
</div>
