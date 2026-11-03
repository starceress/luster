<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Shared "Closed On" / "Adopted On" row for the listing Add and Edit pages: the
 date, its Set Today's Date and Reset buttons, and the "Date unknown" box.
 Expects $archivedAtOriginalVal (the saved date, Y-m-d or '') and
 $archivedAtUnknownOriginal (whether the saved date is unknown), plus $old and
 $fe. After a failed save both show what was sent: the Edit page sends the box
 back as '1' or '0', so a box the owner just unticked stays unticked even when
 the saved date is unknown, and on Add an unticked box simply isn't in $old.
 Reset puts both back to what's saved. While the box is ticked the date and
 Set Today's Date are disabled (admin.js), so no date is sent.
*/

use Luster\Core\View;

$_fe                   = $fe ?? [];
$_old                  = $old ?? [];
$archivedAtVal         = (string) ($_old['archived_at'] ?? $archivedAtOriginalVal);
$archivedAtUnknown     = isset($_old['archived_at_unknown'])
    ? (string) $_old['archived_at_unknown'] === '1'
    : $archivedAtUnknownOriginal;
?>
<div class="field-row" id="archived-at-row">
  <label class="field-label" for="fl-archived-at" id="fl-archived-at-label">Closed On</label>
  <div>
    <div class="actions-row actions-row--wrap">
      <input id="fl-archived-at" type="date" name="archived_at" class="form form--compact"
             value="<?= View::e($archivedAtVal) ?>" data-original-value="<?= View::e($archivedAtOriginalVal) ?>"
             <?= $archivedAtUnknown ? 'disabled' : '' ?>>
      <button type="button" class="btn btn-tertiary btn-sm" id="set-archived-today-btn"
              <?= $archivedAtUnknown ? 'disabled' : '' ?>>Set Today's Date</button>
      <button type="button" class="btn btn-quaternary btn-sm" id="reset-archived-date-btn">Reset</button>
      <label class="form-check">
        <input type="checkbox" name="archived_at_unknown" value="1" id="fl-archived-at-unknown"
               data-original-checked="<?= $archivedAtUnknownOriginal ? '1' : '0' ?>"
               <?= $archivedAtUnknown ? 'checked' : '' ?>>
        <span class="toggle-label">Date unknown</span>
      </label>
    </div>
    <?php if (!empty($_fe['archived_at'])): ?><p class="field-error"><?= View::e($_fe['archived_at'][0]) ?></p><?php endif; ?>
  </div>
</div>
