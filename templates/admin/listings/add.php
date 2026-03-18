<?php
/** @var array  $tfl_cats        [{id: int, name: string}] */
/** @var array  $tafl_cats       [{id: int, name: string}] */
/** @var array  $custom_networks string[] */
/** @var array  $old */
/** @var string $error */
/** @var array  $field_errors */
/** @var string $default_date */
/** @var string $base_url */
/** @var string $csrf_token */
$b  = rtrim(\Luster\Core\View::e($base_url), '/');
$fe = $field_errors ?? [];

$val = static function (string $k, string $def = '') use ($old): string {
    return isset($old[$k]) ? (string) $old[$k] : $def;
};

$selStatus = isset($old['status']) ? (int) $old['status'] : 2;

$dataTfl    = json_encode($tfl_cats      ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
$dataTafl   = json_encode($tafl_cats     ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
$dataCustom = json_encode($custom_networks ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
?>

<p style="margin-bottom:1rem">
  <a href="<?= $b ?>/admin/listings" class="neb-link">&larr; Back to Owned Listings</a>
</p>

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= \Luster\Core\View::e($error) ?></div>
<?php endif; ?>

<div class="form-card">
  <form method="POST" action="<?= $b ?>/admin/listings/add" enctype="multipart/form-data" class="js-warn-unsaved">
    <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">

    <!-- ── Listing Details ─────────────────────────────────────── -->
    <div class="form-section">
      <div class="form-section-label">Listing Details</div>

      <div class="form-group<?= !empty($fe['subject']) ? ' has-error' : '' ?>">
        <label class="form-label" for="fl-subject">Subject <span>*</span></label>
        <input id="fl-subject" type="text" name="subject" class="form-input" maxlength="255" required
               value="<?= \Luster\Core\View::e($val('subject')) ?>">
        <p class="form-hint">The subject of your listing (e.g. character name, show title).</p>
        <?php if (!empty($fe['subject'])): ?><p class="field-error"><?= \Luster\Core\View::e($fe['subject'][0]) ?></p><?php endif; ?>
      </div>

      <div class="form-group">
        <label class="form-label" for="fl-title">Title</label>
        <input id="fl-title" type="text" name="title" class="form-input" maxlength="255"
               value="<?= \Luster\Core\View::e($val('title')) ?>">
        <p class="form-hint">Optional display name — defaults to Subject if left blank.</p>
      </div>

      <div class="form-group<?= !empty($fe['url']) ? ' has-error' : '' ?>">
        <label class="form-label" for="fl-url">URL <span>*</span></label>
        <input id="fl-url" type="url" name="url" class="form-input" maxlength="500" placeholder="https://" required
               value="<?= \Luster\Core\View::e($val('url')) ?>">
        <p class="form-hint">The public URL of your fanlisting.</p>
        <?php if (!empty($fe['url'])): ?><p class="field-error"><?= \Luster\Core\View::e($fe['url'][0]) ?></p><?php endif; ?>
      </div>

      <div class="form-group">
        <label class="form-label" for="fl-email">Email</label>
        <input id="fl-email" type="email" name="email" class="form-input" maxlength="255"
               value="<?= \Luster\Core\View::e($val('email')) ?>">
        <p class="form-hint">Address listing emails are sent from.</p>
      </div>

      <div class="form-group">
        <label class="form-label" for="fl-image">Listing Image</label>
        <input id="fl-image" type="file" name="image"
               accept="image/jpeg,image/png,image/gif,image/webp">
        <p class="form-hint">JPEG, PNG, GIF, or WebP &mdash; max&nbsp;2&nbsp;MB.</p>
      </div>
    </div>

    <div class="section-divider"></div>

    <!-- ── Status & Schedule ────────────────────────────────────── -->
    <div class="form-section">
      <div class="form-section-label">Status &amp; Schedule</div>

      <div class="form-group">
        <label class="form-label">Status</label>
        <div style="display:flex;flex-direction:column;gap:0.35rem;margin-top:0.25rem">
          <label class="form-check">
            <input type="radio" name="status" value="2" <?= $selStatus === 2 ? 'checked' : '' ?>>
            <span class="form-check-label">Current — open and accepting members</span>
          </label>
          <label class="form-check">
            <input type="radio" name="status" value="1" <?= $selStatus === 1 ? 'checked' : '' ?>>
            <span class="form-check-label">Upcoming — not open yet</span>
          </label>
          <label class="form-check">
            <input type="radio" name="status" value="0" <?= $selStatus === 0 ? 'checked' : '' ?>>
            <span class="form-check-label">Pending — awaiting TFL approval</span>
          </label>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label" for="fl-opened">Opened On</label>
        <input id="fl-opened" type="date" name="opened" class="form-input" style="max-width:200px"
               value="<?= \Luster\Core\View::e($val('opened', $default_date)) ?>">
        <p class="form-hint">The date this fanlisting first opened.</p>
      </div>
    </div>

    <div class="section-divider"></div>

    <!-- ── Category ─────────────────────────────────────────────── -->
    <div class="form-section">
      <div class="form-section-label">Category</div>

      <div class="form-group">
        <div class="cat-widget" id="cat-widget"
             data-tfl='<?= $dataTfl ?>'
             data-tafl='<?= $dataTafl ?>'
             data-custom='<?= $dataCustom ?>'
             data-existing='[]'>
          <div id="cat-rows"></div>
          <a href="#" class="neb-link" id="cat-add" style="font-size:0.8125rem;display:inline-block;margin-top:0.4rem">+ Add another category</a>
        </div>
        <p class="form-hint">The network(s) and category(ies) this listing is listed under.</p>
      </div>
    </div>

    <div class="btn-row">
      <button type="submit" class="btn btn-primary">Add Listing</button>
      <button type="button" class="btn btn-ghost" id="clear-form-btn">Clear Form</button>
      <a href="<?= $b ?>/admin/listings" class="btn btn-ghost">Cancel</a>
    </div>

  </form>
</div>

